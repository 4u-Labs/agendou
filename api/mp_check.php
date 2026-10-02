<?php
// ========================================================
// AGENDOU - API: Verificar Status de Pagamento Mercado Pago
// ========================================================

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
$config = require __DIR__ . '/../config/config.php';
$pdo = Database::getConnection();

$mpId = trim($_GET['payment_id'] ?? '');
if (!$mpId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID do pagamento não informado']);
    exit;
}

$mpToken = $config['mercadopago']['access_token'] ?? '';
$ch = curl_init("https://api.mercadopago.com/v1/payments/{$mpId}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$mpToken}"]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($res, true);
$status = $data['status'] ?? 'pending';

if ($status === 'approved') {
    // Buscar fatura associada
    $stmtInv = $pdo->prepare("SELECT * FROM invoices WHERE mp_id = ?");
    $stmtInv->execute([$mpId]);
    $invoice = $stmtInv->fetch();

    $tenantId = null;
    $amount = (float)($data['transaction_amount'] ?? 19.90);

    if ($invoice) {
        $tenantId = (int)$invoice['tenant_id'];
        $stmtUpInv = $pdo->prepare("UPDATE invoices SET status = 'paid', paid_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmtUpInv->execute([$invoice['id']]);
    } else {
        // Tentar extrair de external_reference (tenant_X_plan_Y)
        $extRef = $data['external_reference'] ?? '';
        if (preg_match('/tenant_(\d+)/', $extRef, $m)) {
            $tenantId = (int)$m[1];
        }
    }

    if ($tenantId) {
        // Identificar plano pelo valor
        $plan = ($amount >= 35.00) ? 'plus' : 'starter';

        $stmtUpT = $pdo->prepare("
            UPDATE tenants 
            SET subscription_status = 'active', status = 'active', 
                plan = ?, monthly_price = ?,
                next_due_date = date('now', '+30 days'), 
                blocked_reason = '' 
            WHERE id = ?
        ");
        $stmtUpT->execute([$plan, $amount, $tenantId]);
    }

    echo json_encode([
        'success' => true,
        'status' => 'approved',
        'message' => 'Pagamento confirmado com sucesso! Seu sistema está 100% ativo.',
        'next_due_date' => date('d/m/Y', strtotime('+30 days'))
    ]);
} else {
    echo json_encode([
        'success' => true,
        'status' => $status
    ]);
}
