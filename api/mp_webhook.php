<?php
// ========================================================
// AGENDOU - Webhook Mercado Pago
// Recebe notificações de Pagamentos Avulsos e Assinaturas Recorrentes
// ========================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
$config = require __DIR__ . '/../config/config.php';
$pdo = Database::getConnection();

$mpToken = $config['mercadopago']['access_token'] ?? '';
if (empty($mpToken)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Token do Mercado Pago não configurado']);
    exit;
}

// 1. Capturar corpo da notificação (JSON ou Query String)
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true) ?: [];

$type = $body['type'] ?? $_GET['type'] ?? $_GET['topic'] ?? '';
$action = $body['action'] ?? '';
$resourceId = $body['data']['id'] ?? $_GET['data_id'] ?? $_GET['id'] ?? null;

// Log para auditoria
$logDir = __DIR__ . '/../database';
if (is_dir($logDir) && is_writable($logDir)) {
    $logLine = sprintf("[%s] Webhook MP: type=%s, action=%s, id=%s\n", date('Y-m-d H:i:s'), $type, $action, $resourceId ?: 'null');
    @file_put_contents($logDir . '/webhook.log', $logLine, FILE_APPEND);
}

if (!$resourceId) {
    // Responde 200 para testes ou pings do MP
    echo json_encode(['status' => 'ok', 'message' => 'Nenhum recurso para processar']);
    exit;
}

// ------------------------------------------------------------------------
// TIPO 1: ASSINATURA RECORRENTE (subscription_preapproval / preapproval)
// ------------------------------------------------------------------------
if ($type === 'subscription_preapproval' || $type === 'preapproval') {
    $ch = curl_init("https://api.mercadopago.com/preapproval/{$resourceId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $mpToken,
        'Content-Type: application/json'
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($res, true);
    $subStatus = $data['status'] ?? '';
    $extRef = $data['external_reference'] ?? '';

    $tenantId = null;
    $planFromRef = null;
    if (preg_match('/tenant_(\d+)(?:_plan_([a-z]+))?/', $extRef, $m)) {
        $tenantId = (int)$m[1];
        $planFromRef = $m[2] ?? null;
    }

    if (!$tenantId && !empty($resourceId)) {
        // Tentar localizar pelo preapproval_id gravado
        $stmtT = $pdo->prepare("SELECT id FROM tenants WHERE mp_preapproval_id = ?");
        $stmtT->execute([$resourceId]);
        $tenantId = (int)$stmtT->fetchColumn();
    }

    if ($tenantId) {
        if ($subStatus === 'authorized') {
            $amount = (float)($data['auto_recurring']['transaction_amount'] ?? 19.90);
            $plan = $planFromRef ?: (($amount >= 35.0) ? 'plus' : 'starter');

            $stmtUp = $pdo->prepare("
                UPDATE tenants 
                SET subscription_status = 'active', 
                    status = 'active', 
                    recurring_type = 'auto_recurring', 
                    preapproval_status = 'authorized',
                    mp_preapproval_id = ?,
                    plan = ?, 
                    monthly_price = ?,
                    next_due_date = date('now', '+30 days'), 
                    blocked_reason = '' 
                WHERE id = ?
            ");
            $stmtUp->execute([$resourceId, $plan, $amount, $tenantId]);

            // Registrar fatura do mês se ainda não registrada
            $refMonth = date('m/Y');
            $stmtChkInv = $pdo->prepare("SELECT id FROM invoices WHERE tenant_id = ? AND reference_month = ? AND status = 'paid'");
            $stmtChkInv->execute([$tenantId, $refMonth]);
            if (!$stmtChkInv->fetch()) {
                $nextDue = date('Y-m-d', strtotime('+30 days'));
                $stmtInv = $pdo->prepare("
                    INSERT INTO invoices (tenant_id, amount, due_date, status, paid_at, payment_method, mp_preapproval_id, reference_month)
                    VALUES (?, ?, ?, 'paid', CURRENT_TIMESTAMP, 'auto_recurring', ?, ?)
                ");
                $stmtInv->execute([$tenantId, $amount, $nextDue, $resourceId, $refMonth]);
            }
        } elseif ($subStatus === 'cancelled' || $subStatus === 'paused') {
            $stmtUp = $pdo->prepare("
                UPDATE tenants 
                SET recurring_type = 'manual_pix', 
                    preapproval_status = ? 
                WHERE id = ?
            ");
            $stmtUp->execute([$subStatus, $tenantId]);
        }
    }

    echo json_encode(['status' => 'ok', 'processed' => 'preapproval', 'id' => $resourceId]);
    exit;
}

// ------------------------------------------------------------------------
// TIPO 2: COBRANÇA RECORRENTE MENSAL EFETIVADA (subscription_authorized_payment)
// ------------------------------------------------------------------------
if ($type === 'subscription_authorized_payment') {
    $ch = curl_init("https://api.mercadopago.com/authorized_payments/{$resourceId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $mpToken,
        'Content-Type: application/json'
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($res, true);
    $payStatus = $data['status'] ?? '';
    $preapprovalId = $data['preapproval_id'] ?? '';
    $amount = (float)($data['transaction_amount'] ?? 19.90);

    if ($payStatus === 'approved' && !empty($preapprovalId)) {
        // Encontrar tenant pelo preapproval_id
        $stmtT = $pdo->prepare("SELECT * FROM tenants WHERE mp_preapproval_id = ?");
        $stmtT->execute([$preapprovalId]);
        $tenant = $stmtT->fetch();

        if ($tenant) {
            $tenantId = (int)$tenant['id'];
            $refMonth = date('m/Y');
            $nextDue = date('Y-m-d', strtotime('+30 days'));

            // Registrar fatura aprovada
            $stmtInv = $pdo->prepare("
                INSERT INTO invoices (tenant_id, amount, due_date, status, paid_at, payment_method, mp_id, mp_preapproval_id, reference_month)
                VALUES (?, ?, ?, 'paid', CURRENT_TIMESTAMP, 'auto_recurring', ?, ?, ?)
            ");
            $stmtInv->execute([$tenantId, $amount, $nextDue, (string)$resourceId, $preapprovalId, $refMonth]);

            // Reativar / estender vencimento por mais 30 dias
            $stmtUp = $pdo->prepare("
                UPDATE tenants 
                SET subscription_status = 'active', 
                    status = 'active', 
                    next_due_date = date('now', '+30 days'), 
                    blocked_reason = '' 
                WHERE id = ?
            ");
            $stmtUp->execute([$tenantId]);
        }
    }

    echo json_encode(['status' => 'ok', 'processed' => 'authorized_payment', 'id' => $resourceId]);
    exit;
}

// ------------------------------------------------------------------------
// TIPO 3: PAGAMENTO AVULSO PIX / CARTÃO (payment)
// ------------------------------------------------------------------------
if ($type === 'payment' || $action === 'payment.created' || $action === 'payment.updated') {
    $ch = curl_init("https://api.mercadopago.com/v1/payments/{$resourceId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $mpToken,
        'Content-Type: application/json'
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($res, true);
    $status = $data['status'] ?? '';

    if ($status === 'approved') {
        // 1. Tentar localizar pela fatura pendente associada ao mp_id
        $stmtInv = $pdo->prepare("SELECT * FROM invoices WHERE mp_id = ?");
        $stmtInv->execute([(string)$resourceId]);
        $invoice = $stmtInv->fetch();

        $tenantId = null;
        $amount = (float)($data['transaction_amount'] ?? 19.90);

        if ($invoice) {
            $tenantId = (int)$invoice['tenant_id'];
            $stmtUpInv = $pdo->prepare("UPDATE invoices SET status = 'paid', paid_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmtUpInv->execute([$invoice['id']]);
        } else {
            // Tentar extrair do external_reference (tenant_X_plan_Y)
            $extRef = $data['external_reference'] ?? '';
            if (preg_match('/tenant_(\d+)/', $extRef, $m)) {
                $tenantId = (int)$m[1];
            }
        }

        if ($tenantId) {
            $plan = ($amount >= 35.00) ? 'plus' : 'starter';

            $stmtUpT = $pdo->prepare("
                UPDATE tenants 
                SET subscription_status = 'active', 
                    status = 'active', 
                    plan = ?, 
                    monthly_price = ?,
                    next_due_date = date('now', '+30 days'), 
                    blocked_reason = '' 
                WHERE id = ?
            ");
            $stmtUpT->execute([$plan, $amount, $tenantId]);
        }
    }

    echo json_encode(['status' => 'ok', 'processed' => 'payment', 'id' => $resourceId]);
    exit;
}

// Notificação recebida e reconhecida
echo json_encode(['status' => 'ok', 'type' => $type, 'id' => $resourceId]);
