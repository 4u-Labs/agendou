<?php
// ========================================================
// AGENDOU - API: Gerar Pagamento PIX via Mercado Pago
// ========================================================

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
$config = require __DIR__ . '/../config/config.php';
$pdo = Database::getConnection();

// Auth check
$userId = $_SESSION['agendou_user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$stmtU = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtU->execute([$userId]);
$currentUser = $stmtU->fetch();

$tenantId = (int)($_REQUEST['tenant_id'] ?? $currentUser['tenant_id'] ?? 1);
if (($currentUser['role'] ?? '') !== 'superadmin' && (int)$currentUser['tenant_id'] !== $tenantId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado para este estabelecimento']);
    exit;
}

$stmtT = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmtT->execute([$tenantId]);
$tenant = $stmtT->fetch();

if (!$tenant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Estabelecimento não encontrado']);
    exit;
}

$plan = strtolower(trim($_REQUEST['plan'] ?? $tenant['plan'] ?? 'starter'));
if ($plan !== 'plus') {
    $plan = 'starter';
}

$amount = ($plan === 'plus') ? 39.90 : 19.90;
$planName = strtoupper($plan);

$mpToken = $config['mercadopago']['access_token'] ?? '';
if (empty($mpToken)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Credenciais do Mercado Pago não configuradas no servidor']);
    exit;
}

$payload = [
    'transaction_amount' => (float)$amount,
    'description' => "Assinatura AGENDOU - Plano {$planName} ({$tenant['name']})",
    'payment_method_id' => 'pix',
    'payer' => [
        'email' => !empty($tenant['email']) ? $tenant['email'] : 'financeiro@4u.ia.br',
        'first_name' => substr($tenant['name'], 0, 30)
    ],
    'external_reference' => "tenant_{$tenantId}_plan_{$plan}"
];

$ch = curl_init('https://api.mercadopago.com/v1/payments');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $mpToken,
    'Content-Type: application/json',
    'X-Idempotency-Key: ' . uniqid('mp_pix_' . $tenantId . '_', true)
]);

$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($res, true);

if ($httpCode >= 200 && $httpCode < 300 && isset($result['id'])) {
    $mpId = (string)$result['id'];
    $qrCode = $result['point_of_interaction']['transaction_data']['qr_code'] ?? '';
    $qrCodeBase64 = $result['point_of_interaction']['transaction_data']['qr_code_base64'] ?? '';

    // Salvar fatura pendente
    $refMonth = date('m/Y');
    $nextDue = date('Y-m-d', strtotime('+30 days'));
    $stmtInv = $pdo->prepare("
        INSERT INTO invoices (tenant_id, amount, due_date, status, payment_method, mp_id, reference_month)
        VALUES (?, ?, ?, 'pending', 'pix', ?, ?)
    ");
    $stmtInv->execute([$tenantId, $amount, $nextDue, $mpId, $refMonth]);

    echo json_encode([
        'success' => true,
        'payment_id' => $mpId,
        'qr_code' => $qrCode,
        'qr_code_base64' => $qrCodeBase64,
        'amount' => $amount,
        'amount_formatted' => number_format($amount, 2, ',', '.'),
        'plan' => $planName,
        'tenant_name' => $tenant['name']
    ]);
} else {
    $errMsg = $result['message'] ?? 'Erro ao comunicar com Mercado Pago';
    if (!empty($result['cause'][0]['description'])) {
        $errMsg .= ': ' . $result['cause'][0]['description'];
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $errMsg, 'raw' => $result]);
}
