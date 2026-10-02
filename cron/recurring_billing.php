<?php
// ========================================================
// AGENDOU - Cron Job: Cobrança Recorrente Proativa & Lembretes WhatsApp
// Execução diária via CLI ou Web (para automação e retenção de clientes)
// ========================================================

if (php_sapi_name() !== 'cli') {
    // Verificação de segurança para acesso Web
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $secretParam = $_GET['secret'] ?? '';
    $isSuper = ($_SESSION['agendou_user_id'] ?? null) && (($_SESSION['agendou_role'] ?? '') === 'superadmin');
    
    if ($secretParam !== '4u_agendou_cron_2026' && !$isSuper) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Acesso não autorizado ao cron']);
        exit;
    }
}

require_once __DIR__ . '/../config/database.php';
$config = require __DIR__ . '/../config/config.php';
$pdo = Database::getConnection();

$mpToken = $config['mercadopago']['access_token'] ?? '';
$appUrl = rtrim($config['app_url'] ?? 'https://4u.ia.br/app/agendou', '/');

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// Buscar todos os estabelecimentos com planos pagos
$stmt = $pdo->query("
    SELECT * FROM tenants 
    WHERE plan != 'free' 
    ORDER BY id ASC
");
$tenants = $stmt->fetchAll();

$report = [
    'executed_at' => $now,
    'total_checked' => count($tenants),
    'auto_recurring_count' => 0,
    'pix_reminders_generated' => 0,
    'overdue_warnings' => 0,
    'suspended_count' => 0,
    'details' => []
];

foreach ($tenants as $t) {
    $tenantId = (int)$t['id'];
    $tenantName = $t['name'];
    $dueDate = $t['next_due_date'];
    $plan = strtolower($t['plan'] ?: 'starter');
    $amount = (float)($t['monthly_price'] ?: ($plan === 'plus' ? 39.90 : 19.90));
    $recurringType = $t['recurring_type'] ?? 'manual_pix';
    $subStatus = $t['subscription_status'] ?? 'active';

    if (!$dueDate) {
        continue;
    }

    $daysDiff = (int)((strtotime($dueDate) - strtotime($today)) / 86400);

    $entry = [
        'tenant_id' => $tenantId,
        'tenant_name' => $tenantName,
        'plan' => strtoupper($plan),
        'amount' => $amount,
        'due_date' => $dueDate,
        'days_diff' => $daysDiff,
        'recurring_type' => $recurringType,
        'action_taken' => 'none'
    ];

    // ------------------------------------------------------------------------
    // MODO 1: ASSINATURA RECORRENTE AUTOMÁTICA (Mercado Pago Preapproval)
    // ------------------------------------------------------------------------
    if ($recurringType === 'auto_recurring' && !empty($t['mp_preapproval_id'])) {
        $report['auto_recurring_count']++;
        $entry['action_taken'] = 'auto_recurring_monitored';
        $entry['status_detail'] = "Cobrança automática agendada no Mercado Pago (Vence em {$daysDiff} dias)";
        $report['details'][] = $entry;
        continue;
    }

    // ------------------------------------------------------------------------
    // MODO 2: PIX MENSAL PROATIVO (Lembretes antes de vencer & tolerância)
    // ------------------------------------------------------------------------
    if ($daysDiff <= 3 && $daysDiff >= 0) {
        // Vencendo em breve (3 dias, 2 dias, 1 dia ou hoje)
        $refMonth = date('m/Y', strtotime($dueDate));

        // Verificar se já existe fatura com PIX gerado para este vencimento
        $stmtInv = $pdo->prepare("
            SELECT * FROM invoices 
            WHERE tenant_id = ? AND due_date = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmtInv->execute([$tenantId, $dueDate]);
        $invoice = $stmtInv->fetch();

        $pixCopiaCola = $invoice['pix_copia_cola'] ?? null;
        $mpId = $invoice['mp_id'] ?? null;

        // Se ainda não gerou o PIX no Mercado Pago, gerar agora
        if ((empty($pixCopiaCola) || empty($mpId)) && !empty($mpToken)) {
            $payload = [
                'transaction_amount' => $amount,
                'description' => "Renovação AGENDOU - Plano " . strtoupper($plan) . " ({$tenantName})",
                'payment_method_id' => 'pix',
                'payer' => [
                    'email' => !empty($t['email']) ? $t['email'] : 'financeiro@4u.ia.br',
                    'first_name' => substr($tenantName, 0, 30)
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
                'X-Idempotency-Key: ' . uniqid('cron_pix_' . $tenantId . '_' . $dueDate . '_', true)
            ]);
            $res = curl_exec($ch);
            curl_close($ch);

            $mpRes = json_decode($res, true);
            if (!empty($mpRes['id'])) {
                $mpId = (string)$mpRes['id'];
                $pixCopiaCola = $mpRes['point_of_interaction']['transaction_data']['qr_code'] ?? '';
                $qrBase64 = $mpRes['point_of_interaction']['transaction_data']['qr_code_base64'] ?? '';

                if ($invoice) {
                    $stmtUpInv = $pdo->prepare("
                        UPDATE invoices 
                        SET mp_id = ?, pix_copia_cola = ?, pix_qr_base64 = ? 
                        WHERE id = ?
                    ");
                    $stmtUpInv->execute([$mpId, $pixCopiaCola, $qrBase64, $invoice['id']]);
                } else {
                    $stmtNewInv = $pdo->prepare("
                        INSERT INTO invoices (tenant_id, amount, due_date, status, payment_method, mp_id, pix_copia_cola, pix_qr_base64, reference_month)
                        VALUES (?, ?, ?, 'pending', 'pix', ?, ?, ?, ?)
                    ");
                    $stmtNewInv->execute([$tenantId, $amount, $dueDate, $mpId, $pixCopiaCola, $qrBase64, $refMonth]);
                }
            }
        }

        // Montar mensagem para WhatsApp
        $formattedDate = date('d/m/Y', strtotime($dueDate));
        $formattedPrice = number_format($amount, 2, ',', '.');
        $cleanPhone = preg_replace('/[^\d]/', '', $t['whatsapp'] ?: $t['phone'] ?: '');
        if (strlen($cleanPhone) >= 10 && !str_starts_with($cleanPhone, '55')) {
            $cleanPhone = '55' . $cleanPhone;
        }

        $waMsg = "Olá, *{$tenantName}*! 👋\n"
               . "Passando para lembrar que a mensalidade do seu sistema *AGENDOU (Plano " . strtoupper($plan) . ")* vence em *{$formattedDate}* (R$ {$formattedPrice}).\n\n"
               . "Para manter sua agenda ativa e o link dos seus clientes funcionando sem nenhuma pausa, você pode pagar em 1 segundo via PIX Copia e Cola abaixo:\n\n"
               . "```{$pixCopiaCola}```\n\n"
               . "Se preferir ativar a renovação automática no cartão ou conferir sua fatura, acesse seu painel:\n"
               . "{$appUrl}/admin/subscription.php\n\n"
               . "Muito obrigado pela parceria! 🚀";

        $waUrl = !empty($cleanPhone) ? "https://wa.me/{$cleanPhone}?text=" . urlencode($waMsg) : null;

        $report['pix_reminders_generated']++;
        $entry['action_taken'] = 'pix_reminder_prepared';
        $entry['pix_copia_cola'] = $pixCopiaCola;
        $entry['whatsapp_url'] = $waUrl;
        $report['details'][] = $entry;
        continue;
    }

    // ------------------------------------------------------------------------
    // MODO 3: VENCIDO - PERÍODO DE TOLERÂNCIA (1 a 2 dias de atraso)
    // ------------------------------------------------------------------------
    if ($daysDiff < 0 && $daysDiff >= -2) {
        $formattedDate = date('d/m/Y', strtotime($dueDate));
        $formattedPrice = number_format($amount, 2, ',', '.');
        $cleanPhone = preg_replace('/[^\d]/', '', $t['whatsapp'] ?: $t['phone'] ?: '');
        if (strlen($cleanPhone) >= 10 && !str_starts_with($cleanPhone, '55')) {
            $cleanPhone = '55' . $cleanPhone;
        }

        // Buscar última fatura pendente
        $stmtInv = $pdo->prepare("SELECT * FROM invoices WHERE tenant_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $stmtInv->execute([$tenantId]);
        $inv = $stmtInv->fetch();
        $pixCode = $inv['pix_copia_cola'] ?? 'Consulte no seu painel administrativo';

        $waMsg = "⚠️ *Aviso Importante AGENDOU*:\n"
               . "Olá, *{$tenantName}*! Sua mensalidade venceu em *{$formattedDate}* (R$ {$formattedPrice}).\n\n"
               . "Seu acesso e a agenda dos seus clientes entrarão em suspensão automática em breve.\n\n"
               . "Pague agora com o PIX Copia e Cola para regularizar instantaneamente:\n"
               . "```{$pixCode}```\n\n"
               . "Ou acesse: {$appUrl}/admin/subscription.php";

        $waUrl = !empty($cleanPhone) ? "https://wa.me/{$cleanPhone}?text=" . urlencode($waMsg) : null;

        // Atualizar status para past_due se ainda estiver active
        if ($subStatus === 'active') {
            $pdo->prepare("UPDATE tenants SET subscription_status = 'past_due' WHERE id = ?")->execute([$tenantId]);
        }

        $report['overdue_warnings']++;
        $entry['action_taken'] = 'overdue_warning_sent';
        $entry['whatsapp_url'] = $waUrl;
        $report['details'][] = $entry;
        continue;
    }

    // ------------------------------------------------------------------------
    // MODO 4: SUSPENSÃO AUTOMÁTICA (Mais de 2 dias de atraso)
    // ------------------------------------------------------------------------
    if ($daysDiff < -2) {
        if ($subStatus !== 'suspended') {
            $stmtSuspend = $pdo->prepare("
                UPDATE tenants 
                SET subscription_status = 'suspended', 
                    status = 'suspended', 
                    blocked_reason = 'Assinatura suspensa por falta de pagamento (tolerância de 2 dias esgotada)' 
                WHERE id = ?
            ");
            $stmtSuspend->execute([$tenantId]);
            $entry['action_taken'] = 'tenant_suspended';
        } else {
            $entry['action_taken'] = 'already_suspended';
        }

        $report['suspended_count']++;
        $report['details'][] = $entry;
    }
}

// Retornar relatório
if (php_sapi_name() === 'cli') {
    echo "========================================================\n";
    echo " AGENDOU - Relatório do Cron de Cobrança Recorrente\n";
    echo " Executado em: {$report['executed_at']}\n";
    echo " Total avaliado: {$report['total_checked']}\n";
    echo " Assinaturas automáticas MP monitoradas: {$report['auto_recurring_count']}\n";
    echo " Lembretes PIX gerados: {$report['pix_reminders_generated']}\n";
    echo " Alertas de atraso: {$report['overdue_warnings']}\n";
    echo " Estabelecimentos suspensos: {$report['suspended_count']}\n";
    echo "========================================================\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
