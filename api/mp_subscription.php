<?php
// ========================================================
// AGENDOU - API: Assinatura Mensal Recorrente (Mercado Pago Preapproval)
// Suporta cobrança automática mensal recorrente no cartão / conta MP
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

$mpToken = $config['mercadopago']['access_token'] ?? '';
if (empty($mpToken)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Credenciais do Mercado Pago não configuradas']);
    exit;
}

$action = strtolower(trim($_REQUEST['action'] ?? 'create'));
$appUrl = rtrim($config['app_url'] ?? 'https://4u.ia.br/app/agendou', '/');

// ------------------------------------------------------------------------
// ACTION 1: CRIAR NOVA ASSINATURA RECORRENTE MENSAL
// ------------------------------------------------------------------------
if ($action === 'create') {
    $plan = strtolower(trim($_REQUEST['plan'] ?? $tenant['plan'] ?? 'starter'));
    if ($plan !== 'plus') {
        $plan = 'starter';
    }

    $amount = ($plan === 'plus') ? 39.90 : 19.90;
    $planName = strtoupper($plan);

    // E-mail do pagador
    $payerEmail = !empty($tenant['email']) ? $tenant['email'] : 'financeiro@4u.ia.br';
    if (!filter_var($payerEmail, FILTER_VALIDATE_EMAIL)) {
        $payerEmail = 'financeiro@4u.ia.br';
    }

    $backUrl = $appUrl . "/admin/subscription.php?sub_status=returned&tenant_id={$tenantId}";

    $subReason = mb_substr("AGENDOU " . $planName . " - " . $tenant['name'], 0, 58, 'UTF-8');

    $payload = [
        'payer_email' => $payerEmail,
        'back_url' => $backUrl,
        'reason' => $subReason,
        'auto_recurring' => [
            'frequency' => 1,
            'frequency_type' => 'months',
            'transaction_amount' => (float)$amount,
            'currency_id' => 'BRL',
        ],
        'external_reference' => "tenant_{$tenantId}_plan_{$plan}",
        'status' => 'pending'
    ];

    $ch = curl_init('https://api.mercadopago.com/preapproval');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $mpToken,
        'Content-Type: application/json'
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($res, true);

    if ($httpCode >= 200 && $httpCode < 300 && !empty($result['id'])) {
        $preapprovalId = (string)$result['id'];
        $initPoint = $result['init_point'] ?? '';

        // Registrar preapproval_id no estabelecimento
        $stmtUp = $pdo->prepare("
            UPDATE tenants 
            SET mp_preapproval_id = ?, 
                preapproval_status = 'pending'
            WHERE id = ?
        ");
        $stmtUp->execute([$preapprovalId, $tenantId]);

        echo json_encode([
            'success' => true,
            'preapproval_id' => $preapprovalId,
            'init_point' => $initPoint,
            'plan' => $planName,
            'amount' => $amount,
            'amount_formatted' => number_format($amount, 2, ',', '.')
        ]);
        exit;
    } else {
        $errMsg = $result['message'] ?? 'Erro ao criar assinatura recorrente no Mercado Pago';
        if (!empty($result['cause'][0]['description'])) {
            $errMsg .= ': ' . $result['cause'][0]['description'];
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $errMsg, 'raw' => $result]);
        exit;
    }
}

// ------------------------------------------------------------------------
// ACTION 2: VERIFICAR STATUS DA ASSINATURA RECORRENTE
// ------------------------------------------------------------------------
if ($action === 'check') {
    $preapprovalId = trim($_REQUEST['preapproval_id'] ?? $tenant['mp_preapproval_id'] ?? '');

    if (!$preapprovalId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID da assinatura não informado']);
        exit;
    }

    $ch = curl_init("https://api.mercadopago.com/preapproval/{$preapprovalId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $mpToken,
        'Content-Type: application/json'
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($res, true);
    $status = $data['status'] ?? 'unknown';

    if ($status === 'authorized') {
        // Extrair plano e valor
        $amount = (float)($data['auto_recurring']['transaction_amount'] ?? 19.90);
        $plan = ($amount >= 35.0) ? 'plus' : 'starter';

        // Atualizar estabelecimento como ativo recorrente
        $stmtUpT = $pdo->prepare("
            UPDATE tenants 
            SET subscription_status = 'active', 
                status = 'active', 
                recurring_type = 'auto_recurring', 
                preapproval_status = 'authorized',
                plan = ?, 
                monthly_price = ?,
                next_due_date = date('now', '+30 days'), 
                blocked_reason = '' 
            WHERE id = ?
        ");
        $stmtUpT->execute([$plan, $amount, $tenantId]);

        // Registrar ou atualizar fatura para o mês
        $refMonth = date('m/Y');
        $stmtChkInv = $pdo->prepare("SELECT id FROM invoices WHERE tenant_id = ? AND reference_month = ? AND status = 'paid'");
        $stmtChkInv->execute([$tenantId, $refMonth]);
        if (!$stmtChkInv->fetch()) {
            $nextDue = date('Y-m-d', strtotime('+30 days'));
            $stmtInv = $pdo->prepare("
                INSERT INTO invoices (tenant_id, amount, due_date, status, paid_at, payment_method, mp_preapproval_id, reference_month)
                VALUES (?, ?, ?, 'paid', CURRENT_TIMESTAMP, 'auto_recurring', ?, ?)
            ");
            $stmtInv->execute([$tenantId, $amount, $nextDue, $preapprovalId, $refMonth]);
        }

        echo json_encode([
            'success' => true,
            'status' => 'authorized',
            'recurring_type' => 'auto_recurring',
            'plan' => strtoupper($plan),
            'message' => 'Assinatura recorrente autorizada com sucesso! Seu sistema agora renova automaticamente todo mês.',
            'next_due_date' => date('d/m/Y', strtotime('+30 days'))
        ]);
        exit;
    } elseif ($status === 'cancelled') {
        $stmtUpT = $pdo->prepare("
            UPDATE tenants 
            SET recurring_type = 'manual_pix', 
                preapproval_status = 'cancelled'
            WHERE id = ?
        ");
        $stmtUpT->execute([$tenantId]);

        echo json_encode([
            'success' => true,
            'status' => 'cancelled',
            'recurring_type' => 'manual_pix',
            'message' => 'A assinatura recorrente está cancelada.'
        ]);
        exit;
    } else {
        echo json_encode([
            'success' => true,
            'status' => $status,
            'message' => "Status atual da assinatura: {$status}"
        ]);
        exit;
    }
}

// ------------------------------------------------------------------------
// ACTION 3: CANCELAR ASSINATURA RECORRENTE (Voltar para PIX manual)
// ------------------------------------------------------------------------
if ($action === 'cancel') {
    $preapprovalId = trim($_REQUEST['preapproval_id'] ?? $tenant['mp_preapproval_id'] ?? '');

    if ($preapprovalId) {
        $ch = curl_init("https://api.mercadopago.com/preapproval/{$preapprovalId}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['status' => 'cancelled']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $mpToken,
            'Content-Type: application/json'
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    $stmtUpT = $pdo->prepare("
        UPDATE tenants 
        SET recurring_type = 'manual_pix', 
            preapproval_status = 'cancelled'
        WHERE id = ?
    ");
    $stmtUpT->execute([$tenantId]);

    echo json_encode([
        'success' => true,
        'message' => 'Renovação automática cancelada com sucesso. Seu sistema permanecerá ativo até o fim do período já pago e as próximas renovações serão manuais via PIX.'
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação inválida']);
