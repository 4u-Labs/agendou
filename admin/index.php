<?php
// ========================================================
// AGENDOU - Admin Dashboard & Agenda Diária
// Agendamento pelo estabelecimento + Botão WhatsApp + Google Calendar
// ========================================================

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../app/Services/GoogleCalendarService.php';
require_once __DIR__ . '/../app/Services/AvailabilityService.php';

$pdo = Database::getConnection();
$today = date('Y-m-d');
$manualErr = '';

// Handle POST actions (complete, adjust_appointment, cancel, manual_appointment)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'complete' || $action === 'adjust_appointment') {
        $apptId = (int)($_POST['appointment_id'] ?? 0);
        $basePrice = (float)str_replace(',', '.', $_POST['base_price'] ?? 0);
        $priceAdjustment = (float)str_replace(',', '.', $_POST['price_adjustment'] ?? 0);
        $adjReason = trim($_POST['adjustment_reason'] ?? '');
        $paymentMethod = trim($_POST['payment_method'] ?? 'pix');
        
        $finalPrice = ($paymentMethod === 'pacote') ? 0.00 : max(0, $basePrice + $priceAdjustment);

        if ($apptId) {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE appointments 
                SET status = 'completed',
                    price_adjustment = ?,
                    adjustment_reason = ?,
                    final_price = ?,
                    payment_method = ?,
                    paid_at = CURRENT_TIMESTAMP
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$priceAdjustment, $adjReason, $finalPrice, $paymentMethod, $apptId, $tenantId]);

            // Detalhes do atendimento para vincular cliente, barbeiro e descrição
            $stmtD = $pdo->prepare("
                SELECT a.*, s.name as service_name, c.name as customer_name, p.name as professional_name
                FROM appointments a
                JOIN services s ON s.id = a.service_id
                JOIN customers c ON c.id = a.customer_id
                JOIN professionals p ON p.id = a.professional_id
                WHERE a.id = ? AND a.tenant_id = ?
            ");
            $stmtD->execute([$apptId, $tenantId]);
            $appt = $stmtD->fetch();

            if ($appt) {
                $stmtCheckT = $pdo->prepare("SELECT id FROM financial_transactions WHERE appointment_id = ? AND tenant_id = ?");
                $stmtCheckT->execute([$apptId, $tenantId]);
                $existingTransId = $stmtCheckT->fetchColumn();

                $desc = "Atendimento: " . $appt['service_name'] . " - " . $appt['customer_name'];
                if (!empty($adjReason)) {
                    $desc .= " (" . ($priceAdjustment >= 0 ? "+" : "") . number_format($priceAdjustment, 2, ',', '.') . " " . $adjReason . ")";
                }

                if ($existingTransId) {
                    $stmtUT = $pdo->prepare("
                        UPDATE financial_transactions
                        SET amount = ?, payment_method = ?, description = ?, professional_id = ?
                        WHERE id = ? AND tenant_id = ?
                    ");
                    $stmtUT->execute([$finalPrice, $paymentMethod, $desc, $appt['professional_id'], $existingTransId, $tenantId]);
                } else {
                    $stmtIT = $pdo->prepare("
                        INSERT INTO financial_transactions (
                            tenant_id, appointment_id, customer_id, professional_id,
                            type, description, amount, payment_method, transaction_date, created_at
                        ) VALUES (?, ?, ?, ?, 'appointment', ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmtIT->execute([
                        $tenantId, $apptId, $appt['customer_id'], $appt['professional_id'],
                        $desc, $finalPrice, $paymentMethod, $appt['appointment_date']
                    ]);
                }
            }

            $pdo->commit();
        }
        header("Location: /app/agendou/admin/index.php?updated=1");
        exit;
    } elseif ($action === 'cancel') {
        $apptId = (int)($_POST['appointment_id'] ?? 0);
        if ($apptId) {
            $stmt = $pdo->prepare("SELECT google_event_id FROM appointments WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$apptId, $tenantId]);
            $gEventId = $stmt->fetchColumn();

            $stmt = $pdo->prepare("UPDATE appointments SET status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$apptId, $tenantId]);

            // Remove transação financeira correspondente se houver
            $stmtDT = $pdo->prepare("DELETE FROM financial_transactions WHERE appointment_id = ? AND tenant_id = ?");
            $stmtDT->execute([$apptId, $tenantId]);

            if ($gEventId) {
                GoogleCalendarService::deleteEvent($tenantId, $gEventId);
            }
        }
        header("Location: /app/agendou/admin/index.php?cancelled=1");
        exit;
    } elseif ($action === 'manual_appointment') {
        $cName = trim($_POST['customer_name'] ?? '');
        $cPhone = preg_replace('/\D/', '', $_POST['customer_whatsapp'] ?? '');
        $cEmail = trim($_POST['customer_email'] ?? '');
        $sId = (int)($_POST['service_id'] ?? 0);
        $pId = (int)($_POST['professional_id'] ?? 0);
        $appDate = trim($_POST['appointment_date'] ?? $today);
        $startTime = trim($_POST['start_time'] ?? '09:00');

        if ($cName && $cPhone && $sId && $pId && $appDate && $startTime) {
            try {
                $pdo->beginTransaction();

                $stmtS = $pdo->prepare("SELECT name, price, duration_minutes FROM services WHERE id = ? AND tenant_id = ?");
                $stmtS->execute([$sId, $tenantId]);
                $service = $stmtS->fetch();

                $stmtP = $pdo->prepare("SELECT name FROM professionals WHERE id = ? AND tenant_id = ?");
                $stmtP->execute([$pId, $tenantId]);
                $professional = $stmtP->fetch();

                if (!$service || !$professional) {
                    throw new Exception("Serviço ou profissional inválido.");
                }

                $duration = (int)$service['duration_minutes'];
                $endTime = date('H:i:s', strtotime("{$appDate} {$startTime} + {$duration} minutes"));
                $price = (float)$service['price'];

                // 1. Cliente
                $stmtC = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND whatsapp = ?");
                $stmtC->execute([$tenantId, $cPhone]);
                $customerId = $stmtC->fetchColumn();

                if ($customerId) {
                    $stmtUC = $pdo->prepare("UPDATE customers SET name = ?, email = COALESCE(NULLIF(?, ''), email), updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmtUC->execute([$cName, $cEmail, $customerId]);
                } else {
                    $stmtIC = $pdo->prepare("INSERT INTO customers (tenant_id, name, whatsapp, email) VALUES (?, ?, ?, ?)");
                    $stmtIC->execute([$tenantId, $cName, $cPhone, $cEmail]);
                    $customerId = (int)$pdo->lastInsertId();
                }

                // 2. Conflito
                $isFree = AvailabilityService::isSlotFree($tenantId, $pId, $appDate, $startTime, $endTime);
                if (!$isFree) {
                    throw new Exception("O profissional selecionado já possui um agendamento ou bloqueio neste horário.");
                }

                // 3. Agendamento
                $token = bin2hex(random_bytes(16));
                $stmtA = $pdo->prepare("
                    INSERT INTO appointments (
                        tenant_id, customer_id, professional_id, service_id, 
                        appointment_date, start_time, end_time, price, status, cancellation_token
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?)
                ");
                $stmtA->execute([$tenantId, $customerId, $pId, $sId, $appDate, $startTime, $endTime, $price, $token]);
                $appointmentId = (int)$pdo->lastInsertId();

                $pdo->commit();

                // 4. Google Calendar Duplo com Lembretes de 2h e 15m
                try {
                    $googleEventId = GoogleCalendarService::createEvent($tenantId, [
                        'date' => $appDate,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'service_name' => $service['name'],
                        'customer_name' => $cName,
                        'customer_email' => $cEmail,
                        'customer_whatsapp' => $cPhone,
                        'professional_name' => $professional['name'],
                        'price' => $price,
                        'notes' => 'Agendado diretamente pelo estabelecimento'
                    ]);
                    if ($googleEventId) {
                        $stmtGE = $pdo->prepare("UPDATE appointments SET google_event_id = ? WHERE id = ?");
                        $stmtGE->execute([$googleEventId, $appointmentId]);
                    }
                } catch (Throwable $ge) {
                    error_log("Google sync notice: " . $ge->getMessage());
                }

                header("Location: /app/agendou/admin/index.php?created=1");
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $manualErr = 'Erro ao agendar: ' . $e->getMessage();
            }
        } else {
            $manualErr = 'Por favor, preencha todos os campos obrigatórios.';
        }
    }
}

// 1. Metrics for Today
$stmtToday = $pdo->prepare("
    SELECT 
        COUNT(*) as total_today,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_today,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_today,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_today,
        SUM(CASE WHEN status IN ('confirmed', 'completed') THEN price ELSE 0 END) as revenue_today
    FROM appointments
    WHERE tenant_id = ? AND appointment_date = ?
");
$stmtToday->execute([$tenantId, $today]);
$metricsToday = $stmtToday->fetch() ?: [];

// 1.1 Métricas Financeiras Reais do Caixa
$stmtRealToday = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE tenant_id = ? AND transaction_date = ? AND amount > 0");
$stmtRealToday->execute([$tenantId, $today]);
$realIncomeToday = (float)$stmtRealToday->fetchColumn();

$stmtRealMonth = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE tenant_id = ? AND transaction_date LIKE ? AND amount > 0");
$stmtRealMonth->execute([$tenantId, date('Y-m') . '%']);
$realIncomeMonth = (float)$stmtRealMonth->fetchColumn();

$stmtRealTotal = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE tenant_id = ? AND amount > 0");
$stmtRealTotal->execute([$tenantId]);
$realIncomeTotal = (float)$stmtRealTotal->fetchColumn();

// 1.2 Mapear Clientes com Pacotes / Clubes Ativos
$stmtActivePackages = $pdo->prepare("
    SELECT cp.customer_id, p.name as package_name, cp.end_date
    FROM customer_packages cp
    JOIN packages p ON p.id = cp.package_id
    WHERE cp.tenant_id = ? AND cp.status = 'active' AND cp.end_date >= ?
");
$stmtActivePackages->execute([$tenantId, $today]);
$customerPackagesMap = [];
foreach ($stmtActivePackages->fetchAll() as $row) {
    $customerPackagesMap[$row['customer_id']] = $row['package_name'];
}

// 2. Total Customers
$stmtCust = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id = ?");
$stmtCust->execute([$tenantId]);
$totalCustomers = (int)$stmtCust->fetchColumn();

// 3. Appointments list (Today + Upcoming)
$stmtList = $pdo->prepare("
    SELECT a.*, s.name as service_name, p.name as professional_name, c.name as customer_name, c.whatsapp as customer_whatsapp, c.email as customer_email
    FROM appointments a
    JOIN services s ON s.id = a.service_id
    JOIN professionals p ON p.id = a.professional_id
    JOIN customers c ON c.id = a.customer_id
    WHERE a.tenant_id = ? AND a.appointment_date >= ?
    ORDER BY a.appointment_date ASC, a.start_time ASC
    LIMIT 30
");
$stmtList->execute([$tenantId, $today]);
$upcomingAppointments = $stmtList->fetchAll();

// 4. Professionals & Services for Manual Modal
$stmtP = $pdo->prepare("SELECT id, name FROM professionals WHERE tenant_id = ? AND status = 'active' ORDER BY name ASC");
$stmtP->execute([$tenantId]);
$professionals = $stmtP->fetchAll();

$stmtS = $pdo->prepare("SELECT id, name, price, duration_minutes FROM services WHERE tenant_id = ? AND status = 'active' ORDER BY name ASC");
$stmtS->execute([$tenantId]);
$services = $stmtS->fetchAll();

// 5. Google Calendar Status
$stmtG = $pdo->prepare("SELECT * FROM google_integrations WHERE tenant_id = ?");
$stmtG->execute([$tenantId]);
$googleIntegration = $stmtG->fetch();
$isGoogleConnected = $googleIntegration && !empty($googleIntegration['access_token']) && $googleIntegration['sync_enabled'];

// 6. Link Curto Oficial do Estabelecimento
require_once __DIR__ . '/../app/Services/UrlShortenerService.php';
$shortLinkUrl = UrlShortenerService::ensureTenantShortLink($currentTenant);
$shortStats = UrlShortenerService::getStats($currentTenant['slug']);
$shortClicks = (int)($shortStats['link']['clicks'] ?? 0);
?>

<div class="content-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1>Dashboard & Atendimentos</h1>
        <p>Acompanhe agendamentos, envie mensagens no WhatsApp com 1 clique e lance horários do balcão.</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <button type="button" class="btn-primary" onclick="openManualModal()" style="font-weight: 800; padding: 10px 18px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);">
            <span>➕ Novo Agendamento (Balcão)</span>
        </button>
        <a href="/app/agendou/admin/agenda.php" class="btn-secondary" style="font-weight: 600; padding: 10px 16px;">
            📅 Ver Grade Semanal
        </a>
        <a href="<?= htmlspecialchars($shortLinkUrl) ?>" target="_blank" class="btn-emerald" style="padding: 10px 16px; font-weight: 700;" title="Abrir link de agendamento">
            <span>👉 4u.ia.br/<?= htmlspecialchars($currentTenant['slug']) ?></span>
        </a>
    </div>
</div>

<!-- BANNER DO LINK CURTO OFICIAL DE DIVULGAÇÃO -->
<div class="card-box" style="margin-bottom: 24px; padding: 20px 24px; background: linear-gradient(135deg, rgba(250, 204, 21, 0.05), rgba(16, 185, 129, 0.05)); border: 1px solid rgba(250, 204, 21, 0.3);">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                <span style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; background: rgba(250, 204, 21, 0.15); color: #facc15; padding: 2px 8px; border-radius: 6px;">
                    ⚡ Link Curto Oficial de Divulgação
                </span>
                <span style="font-size: 0.72rem; color: var(--text-muted);">Ideal para Bio do Instagram, Cartão e WhatsApp</span>
            </div>
            <div style="display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap;">
                <a href="<?= htmlspecialchars($shortLinkUrl) ?>" target="_blank" style="font-size: 1.4rem; font-weight: 900; color: #fff; text-decoration: none; letter-spacing: -0.02em;">
                    👉 <?= htmlspecialchars(str_replace('https://', '', $shortLinkUrl)) ?>
                </a>
                <span style="font-size: 0.8rem; color: var(--text-secondary); background: rgba(255,255,255,0.05); padding: 4px 10px; border-radius: 20px;">
                    👁️ <strong><?= $shortClicks ?></strong> clique(s) de clientes
                </span>
            </div>
        </div>

        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            <button type="button" class="btn-primary" onclick="navigator.clipboard.writeText('<?= addslashes($shortLinkUrl) ?>').then(()=>alert('Link curto copiado com sucesso!'));" style="padding: 10px 16px; font-weight: 800; font-size: 0.82rem;">
                📋 Copiar Link Curto
            </button>
            <a href="https://wa.me/?text=<?= urlencode("Olá! Agende seu horário na *" . $currentTenant['name'] . "* pelo nosso link rápido: " . $shortLinkUrl) ?>" target="_blank" class="btn-emerald" style="padding: 10px 16px; font-weight: 800; font-size: 0.82rem;" title="Divulgar no WhatsApp">
                📱 Compartilhar no WhatsApp
            </a>
            <a href="/links.php?qr=<?= urlencode($currentTenant['slug']) ?>" target="_blank" class="btn-secondary" style="padding: 10px 14px; font-size: 0.82rem; font-weight: 700; color: #facc15;" title="Baixar QR Code de balcão">
                🖼️ QR Code de Balcão
            </a>
        </div>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: var(--primary); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 20px;">
        ✓ Agendamento criado com sucesso e sincronizado no Google Agenda com lembretes automáticos!
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: var(--primary); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 20px;">
        ✓ Atendimento marcado como concluído com sucesso.
    </div>
<?php endif; ?>

<?php if (isset($_GET['cancelled'])): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: var(--red); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 20px;">
        ✓ Agendamento cancelado e horário liberado na agenda.
    </div>
<?php endif; ?>

<?php if ($manualErr): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: var(--red); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 20px;">
        ✕ <?= htmlspecialchars($manualErr) ?>
    </div>
<?php endif; ?>

<!-- Google Calendar Status Ribbon -->
<div class="card-box" style="padding: 16px 20px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; border-color: <?= $isGoogleConnected ? 'rgba(16, 185, 129, 0.3)' : 'rgba(255, 255, 255, 0.1)' ?>;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <div style="font-size: 1.5rem;"><?= $isGoogleConnected ? '📅' : '⚠️' ?></div>
        <div>
            <strong style="color: #fff; font-size: 0.9rem;">
                Google Calendar: <?= $isGoogleConnected ? '<span style="color: var(--primary);">Conectado e Sincronizado</span>' : '<span style="color: var(--orange);">Desconectado</span>' ?>
            </strong>
            <p style="font-size: 0.75rem; color: var(--text-muted); margin: 0;">
                <?= $isGoogleConnected ? 'Agendamentos entram automaticamente no seu Google Agenda e no do cliente com lembretes 2h e 15m antes.' : 'Conecte sua conta Google para sincronizar horários ocupados e agendamentos.' ?>
            </p>
        </div>
    </div>
    <a href="/app/agendou/admin/google.php" class="btn-secondary" style="font-size: 0.78rem; padding: 6px 12px;">
        <?= $isGoogleConnected ? '⚙️ Configurações do Google' : 'Conectar Google Agenda →' ?>
    </a>
</div>

<!-- Metrics Row -->
<div class="metrics-row">
    <div class="metric-box">
        <div class="metric-box-header">
            <span>Faturado Hoje</span>
            <span style="color: var(--primary);">💵</span>
        </div>
        <div class="metric-big-val" style="color: var(--primary);">
            R$ <?= number_format($realIncomeToday, 2, ',', '.') ?>
        </div>
        <div class="metric-sub"><a href="/app/agendou/admin/financeiro.php?period=today" style="color: var(--primary); text-decoration: none;">Ver extrato do dia →</a></div>
    </div>

    <div class="metric-box">
        <div class="metric-box-header">
            <span>Faturado no Mês</span>
            <span style="color: #facc15;">📅</span>
        </div>
        <div class="metric-big-val" style="color: #facc15;">
            R$ <?= number_format($realIncomeMonth, 2, ',', '.') ?>
        </div>
        <div class="metric-sub"><a href="/app/agendou/admin/financeiro.php?period=month" style="color: #facc15; text-decoration: none;">Ver faturamento mensal →</a></div>
    </div>

    <div class="metric-box">
        <div class="metric-box-header">
            <span>Acumulado Total</span>
            <span style="color: #38bdf8;">📈</span>
        </div>
        <div class="metric-big-val" style="color: #38bdf8;">
            R$ <?= number_format($realIncomeTotal, 2, ',', '.') ?>
        </div>
        <div class="metric-sub"><a href="/app/agendou/admin/financeiro.php?period=all" style="color: #38bdf8; text-decoration: none;">Histórico acumulado →</a></div>
    </div>

    <div class="metric-box">
        <div class="metric-box-header">
            <span>Atendimentos Hoje</span>
            <span>📅</span>
        </div>
        <div class="metric-big-val"><?= (int)($metricsToday['total_today'] ?? 0) ?></div>
        <div class="metric-sub"><?= (int)($metricsToday['confirmed_today'] ?? 0) ?> aguardando • <?= (int)($metricsToday['completed_today'] ?? 0) ?> concluídos</div>
    </div>

    <div class="metric-box">
        <div class="metric-box-header">
            <span>Clientes & Clubes</span>
            <span style="color: #a855f7;">👑</span>
        </div>
        <div class="metric-big-val"><?= $totalCustomers ?></div>
        <div class="metric-sub"><a href="/app/agendou/admin/pacotes.php" style="color: #a855f7; text-decoration: none;"><?= count($customerPackagesMap) ?> assinantes ativos →</a></div>
    </div>
</div>

<!-- Recent & Today's Appointments Table -->
<div class="card-box">
    <div class="card-box-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2>Próximos Atendimentos</h2>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Clique em <strong>✓ Concluir</strong> para lançar o valor no caixa, registrar acréscimos (ex: pomada) ou descontos.</p>
        </div>
        <span style="font-size: 0.8rem; color: var(--text-muted); font-family: var(--font-mono);">Hoje: <?= date('d/m/Y') ?></span>
    </div>

    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Data / Hora</th>
                    <th>Cliente</th>
                    <th>WhatsApp do Cliente</th>
                    <th>Serviço</th>
                    <th>Profissional</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($upcomingAppointments)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">
                            Nenhum agendamento futuro encontrado para este estabelecimento.<br>
                            <button type="button" onclick="openManualModal()" class="btn-primary" style="margin-top: 12px; font-size: 0.8rem; padding: 6px 14px;">
                                ➕ Criar Primeiro Agendamento
                            </button>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($upcomingAppointments as $a): 
                        $cleanWa = preg_replace('/\D/', '', $a['customer_whatsapp']);
                        $cName = $a['customer_name'];
                        $sName = $a['service_name'];
                        $pName = $a['professional_name'];
                        $timeFmt = substr($a['start_time'], 0, 5);
                        $dateFmt = date('d/m/Y', strtotime($a['appointment_date']));
                        $tenantName = $currentTenant['name'];

                        $waText = urlencode("Olá {$cName}! Tudo bem?\nPassando para confirmar seu horário de *{$sName}* com *{$pName}* no dia *{$dateFmt}* às *{$timeFmt}* aqui na *{$tenantName}*.\n\nTe esperamos! 👍");
                        $waUrl = "https://wa.me/55{$cleanWa}?text={$waText}";
                    ?>
                        <tr>
                            <td>
                                <strong style="color: #fff; font-family: var(--font-mono);">
                                    <?= date('d/m', strtotime($a['appointment_date'])) ?> às <?= substr($a['start_time'], 0, 5) ?>
                                </strong>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($a['customer_name']) ?></strong>
                                <?php if (isset($customerPackagesMap[$a['customer_id']])): ?>
                                    <div style="font-size: 0.68rem; color: #a855f7; font-weight: 700; margin-top: 2px;">
                                        👑 Assinante: <?= htmlspecialchars($customerPackagesMap[$a['customer_id']]) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($a['customer_email'])): ?>
                                    <div style="font-size: 0.72rem; color: var(--text-muted);"><?= htmlspecialchars($a['customer_email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!-- BOTÃO DEDICADO DE WHATSAPP AO LADO DE CADA AGENDAMENTO -->
                                <a href="<?= $waUrl ?>" target="_blank" style="padding: 6px 12px; background: rgba(37, 211, 102, 0.15); border: 1px solid rgba(37, 211, 102, 0.4); color: #25D366; font-weight: 700; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-size: 0.8rem;" title="Clique para enviar mensagem pré-formatada no WhatsApp">
                                    <span>💬 Enviar WhatsApp</span>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($a['service_name']) ?></td>
                            <td><?= htmlspecialchars($a['professional_name']) ?></td>
                            <td>
                                <strong style="color: var(--primary); font-family: var(--font-mono);">
                                    R$ <?= number_format((float)($a['final_price'] ?? $a['price']), 2, ',', '.') ?>
                                </strong>
                                <?php if (!empty($a['price_adjustment']) && (float)$a['price_adjustment'] != 0): ?>
                                    <div style="font-size: 0.68rem; color: <?= (float)$a['price_adjustment'] > 0 ? '#38bdf8' : '#f59e0b' ?>;">
                                        <?= (float)$a['price_adjustment'] > 0 ? '+' : '' ?>R$ <?= number_format((float)$a['price_adjustment'], 2, ',', '.') ?> (<?= htmlspecialchars($a['adjustment_reason'] ?: 'ajuste') ?>)
                                    </div>
                                <?php endif; ?>
                                <?php if ($a['status'] === 'completed' && !empty($a['payment_method'])): ?>
                                    <div style="font-size: 0.65rem; color: var(--text-muted); text-transform: uppercase;">
                                        <?= htmlspecialchars($a['payment_method']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($a['status'] === 'confirmed'): ?>
                                    <span class="badge-status badge-confirmed">✓ Confirmado</span>
                                <?php elseif ($a['status'] === 'completed'): ?>
                                    <span class="badge-status badge-completed">✓ Concluído</span>
                                <?php elseif ($a['status'] === 'cancelled'): ?>
                                    <span class="badge-status badge-cancelled">✕ Cancelado</span>
                                <?php else: ?>
                                    <span class="badge-status"><?= htmlspecialchars($a['status']) ?></span>
                                <?php endif; ?>

                                <?php if (!empty($a['google_event_id'])): ?>
                                    <div style="font-size: 0.68rem; color: var(--cyan); margin-top: 3px;" title="Sincronizado no Google Agenda">
                                        ✓ Google Agenda
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($a['status'] === 'confirmed'): ?>
                                    <div style="display: flex; gap: 6px;">
                                        <button type="button" class="btn-emerald" style="padding: 5px 10px; font-size: 0.72rem; font-weight: 700;" onclick='openCompleteModal(<?= htmlspecialchars(json_encode([
                                            'id' => (int)$a['id'],
                                            'customer_name' => $a['customer_name'],
                                            'service_name' => $a['service_name'],
                                            'professional_name' => $a['professional_name'],
                                            'base_price' => (float)$a['price'],
                                            'price_adjustment' => (float)($a['price_adjustment'] ?? 0),
                                            'adjustment_reason' => $a['adjustment_reason'] ?? '',
                                            'final_price' => (float)($a['final_price'] ?? $a['price']),
                                            'payment_method' => $a['payment_method'] ?: 'pix',
                                            'has_package' => isset($customerPackagesMap[$a['customer_id']]),
                                            'package_name' => $customerPackagesMap[$a['customer_id']] ?? ''
                                        ]), ENT_QUOTES, "UTF-8") ?>)' title="Concluir Atendimento e Lançar no Caixa">
                                            ✓ Concluir
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Deseja realmente cancelar este agendamento?');">
                                            <input type="hidden" name="appointment_id" value="<?= $a['id'] ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <button type="submit" class="btn-secondary" style="padding: 5px 10px; font-size: 0.72rem; color: var(--red);" title="Cancelar Agendamento">✕</button>
                                        </form>
                                    </div>
                                <?php elseif ($a['status'] === 'completed'): ?>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <button type="button" class="btn-secondary" style="padding: 4px 8px; font-size: 0.7rem; color: #facc15;" onclick='openCompleteModal(<?= htmlspecialchars(json_encode([
                                            'id' => (int)$a['id'],
                                            'customer_name' => $a['customer_name'],
                                            'service_name' => $a['service_name'],
                                            'professional_name' => $a['professional_name'],
                                            'base_price' => (float)$a['price'],
                                            'price_adjustment' => (float)($a['price_adjustment'] ?? 0),
                                            'adjustment_reason' => $a['adjustment_reason'] ?? '',
                                            'final_price' => (float)($a['final_price'] ?? $a['price']),
                                            'payment_method' => $a['payment_method'] ?: 'pix',
                                            'has_package' => isset($customerPackagesMap[$a['customer_id']]),
                                            'package_name' => $customerPackagesMap[$a['customer_id']] ?? ''
                                        ]), ENT_QUOTES, "UTF-8") ?>)' title="Editar valores, acréscimos ou forma de pagamento">
                                            ✏️ Ajustar
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 0.75rem;">--</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL NOVO AGENDAMENTO MANUAL (BALCÃO / TELEFONE) -->
<div id="modalManualApp" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; width: 100%; max-width: 520px; padding: 28px; box-shadow: 0 25px 50px rgba(0,0,0,0.8);">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 14px;">
            <div>
                <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 4px;">Novo Agendamento (Balcão)</h2>
                <p style="font-size: 0.8rem; color: var(--text-muted);">Lance horários de clientes que agendaram por telefone ou no balcão.</p>
            </div>
            <button type="button" onclick="closeManualModal()" style="background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer;">✕</button>
        </div>

        <form method="POST" action="/app/agendou/admin/index.php">
            <input type="hidden" name="action" value="manual_appointment">

            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Nome do Cliente *</label>
                <input type="text" name="customer_name" required class="form-input" placeholder="Ex: Carlos Eduardo" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">WhatsApp do Cliente *</label>
                    <input type="text" name="customer_whatsapp" required class="form-input" placeholder="DDD + Número" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">E-mail (Google Calendar)</label>
                    <input type="email" name="customer_email" class="form-input" placeholder="cliente@gmail.com" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Serviço *</label>
                    <select name="service_id" required class="form-input" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                        <?php foreach ($services as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (R$ <?= number_format($s['price'], 2, ',', '.') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Profissional *</label>
                    <select name="professional_id" required class="form-input" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                        <?php foreach ($professionals as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Data do Agendamento *</label>
                    <input type="date" name="appointment_date" value="<?= htmlspecialchars($today) ?>" required class="form-input" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Horário de Início *</label>
                    <input type="time" name="start_time" value="09:00" required class="form-input" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
            </div>

            <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 10px; padding: 12px; font-size: 0.78rem; color: var(--text-secondary); margin-bottom: 20px;">
                📅 <strong>Google Calendar Duplo:</strong> Este horário será sincronizado automaticamente no Google Agenda com lembretes programados para 2h antes e 15 minutos antes.
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeManualModal()" class="btn-secondary" style="padding: 10px 18px;">Cancelar</button>
                <button type="submit" class="btn-primary" style="padding: 10px 24px; font-weight: 800;">✓ Confirmar Agendamento</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL CONCLUIR / AJUSTAR ATENDIMENTO & LANÇAR NO CAIXA -->
<div id="modalCompleteApp" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; width: 100%; max-width: 500px; padding: 28px; box-shadow: 0 25px 50px rgba(0,0,0,0.8);">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; border-bottom: 1px solid var(--border-color); padding-bottom: 14px;">
            <div>
                <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 4px;">✓ Concluir / Ajustar Atendimento</h2>
                <p style="font-size: 0.8rem; color: var(--text-muted);">Confirme os valores, acréscimos ou descontos para o Caixa.</p>
            </div>
            <button type="button" onclick="closeCompleteModal()" style="background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer;">✕</button>
        </div>

        <form method="POST" action="/app/agendou/admin/index.php" id="formCompleteApp">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="appointment_id" id="cmpApptId" value="0">
            <input type="hidden" name="base_price" id="cmpBasePriceHidden" value="0">

            <!-- Banner Informativo do Atendimento -->
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 0.75rem; color: var(--text-muted);">Cliente:</span>
                    <strong style="color: #fff; font-size: 0.9rem;" id="cmpCustomerName">--</strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 0.75rem; color: var(--text-muted);">Serviço:</span>
                    <span style="color: #fff; font-size: 0.85rem;" id="cmpServiceName">--</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.75rem; color: var(--text-muted);">Valor Base do Serviço:</span>
                    <strong style="color: var(--primary); font-family: var(--font-mono); font-size: 0.95rem;" id="cmpBasePriceDisplay">R$ 0,00</strong>
                </div>
            </div>

            <!-- Banner se cliente tiver pacote ativo -->
            <div id="cmpPackageBanner" style="display: none; background: rgba(168, 85, 247, 0.12); border: 1px solid rgba(168, 85, 247, 0.35); border-radius: 10px; padding: 12px; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                    <div>
                        <span style="font-size: 0.72rem; font-weight: 800; color: #c084fc; text-transform: uppercase; display: block;">👑 Assinante de Clube</span>
                        <span style="font-size: 0.82rem; color: #fff;" id="cmpPackageName">Plano Ativo</span>
                    </div>
                    <button type="button" class="btn-primary" onclick="usePackagePayment()" style="padding: 5px 10px; font-size: 0.75rem; font-weight: 800;">
                        Cobrir pelo Pacote (R$ 0,00)
                    </button>
                </div>
            </div>

            <!-- Campos de Ajuste (+/-) e Motivo -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">
                        Ajuste (+ ou - R$)
                    </label>
                    <input type="text" name="price_adjustment" id="cmpAdjustment" value="0,00" oninput="recalcCompleteTotal()" class="form-input" placeholder="+15,00 ou -5,00" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff; font-family: var(--font-mono);">
                    <span style="font-size: 0.68rem; color: var(--text-muted); display: block; margin-top: 3px;">Ex: +15 (pomada) ou -5 (desconto)</span>
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">
                        Motivo do Ajuste
                    </label>
                    <input type="text" name="adjustment_reason" id="cmpReason" class="form-input" placeholder="Ex: Pomada matte, desconto..." style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
            </div>

            <!-- Forma de Pagamento -->
            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Forma de Pagamento *</label>
                <select name="payment_method" id="cmpPaymentMethod" onchange="onPaymentMethodChange()" required class="form-input" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                    <option value="pix">⚡ PIX</option>
                    <option value="dinheiro">💵 Dinheiro</option>
                    <option value="cartao_credito">💳 Cartão de Crédito</option>
                    <option value="cartao_debito">💳 Cartão de Débito</option>
                    <option value="pacote">📦 Pacote / Clube de Assinatura (R$ 0,00)</option>
                    <option value="outro">🔄 Outro</option>
                </select>
            </div>

            <!-- Total Final Grande em Destaque -->
            <div style="background: rgba(250, 204, 21, 0.08); border: 1px solid rgba(250, 204, 21, 0.3); border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <span style="font-size: 0.75rem; color: var(--text-muted); display: block;">Valor Final a Lançar no Caixa:</span>
                    <span style="font-size: 0.72rem; color: var(--text-secondary);" id="cmpMathFormula">R$ 0,00 + R$ 0,00</span>
                </div>
                <div>
                    <span id="cmpFinalDisplay" style="font-size: 1.7rem; font-weight: 900; font-family: var(--font-mono); color: #facc15;">R$ 0,00</span>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeCompleteModal()" class="btn-secondary" style="padding: 10px 18px;">Cancelar</button>
                <button type="submit" class="btn-emerald" style="padding: 10px 24px; font-weight: 800;">✓ Confirmar Recebimento</button>
            </div>
        </form>
    </div>
</div>

<script>
function openManualModal() {
    document.getElementById('modalManualApp').style.display = 'flex';
}
function closeManualModal() {
    document.getElementById('modalManualApp').style.display = 'none';
}

// Modal Concluir / Ajustar
let currentCompleteData = null;

function openCompleteModal(data) {
    currentCompleteData = data;
    document.getElementById('cmpApptId').value = data.id;
    document.getElementById('cmpBasePriceHidden').value = data.base_price;
    document.getElementById('cmpCustomerName').textContent = data.customer_name;
    document.getElementById('cmpServiceName').textContent = data.service_name + ' (' + data.professional_name + ')';
    document.getElementById('cmpBasePriceDisplay').textContent = 'R$ ' + Number(data.base_price).toFixed(2).replace('.', ',');
    
    // Ajustes
    const adjInput = document.getElementById('cmpAdjustment');
    adjInput.value = data.price_adjustment ? Number(data.price_adjustment).toFixed(2).replace('.', ',') : '0,00';
    document.getElementById('cmpReason').value = data.adjustment_reason || '';
    
    // Forma de Pagamento
    const methodSel = document.getElementById('cmpPaymentMethod');
    methodSel.value = data.payment_method || 'pix';

    // Banner de Pacote
    const pkgBanner = document.getElementById('cmpPackageBanner');
    if (data.has_package) {
        pkgBanner.style.display = 'block';
        document.getElementById('cmpPackageName').textContent = data.package_name;
    } else {
        pkgBanner.style.display = 'none';
    }

    recalcCompleteTotal();
    document.getElementById('modalCompleteApp').style.display = 'flex';
}

function closeCompleteModal() {
    document.getElementById('modalCompleteApp').style.display = 'none';
}

function usePackagePayment() {
    document.getElementById('cmpPaymentMethod').value = 'pacote';
    document.getElementById('cmpAdjustment').value = '0,00';
    document.getElementById('cmpReason').value = 'Cobberto por ' + (currentCompleteData ? currentCompleteData.package_name : 'Pacote');
    recalcCompleteTotal();
}

function onPaymentMethodChange() {
    recalcCompleteTotal();
}

function recalcCompleteTotal() {
    if (!currentCompleteData) return;
    const base = parseFloat(currentCompleteData.base_price) || 0;
    const adjStr = document.getElementById('cmpAdjustment').value.replace(',', '.');
    const adj = parseFloat(adjStr) || 0;
    const method = document.getElementById('cmpPaymentMethod').value;

    let final = 0;
    if (method === 'pacote') {
        final = 0;
        document.getElementById('cmpMathFormula').textContent = 'Coberto pelo Clube/Pacote (R$ 0,00)';
    } else {
        final = Math.max(0, base + adj);
        const adjSign = adj >= 0 ? '+ R$ ' : '- R$ ';
        document.getElementById('cmpMathFormula').textContent = 'R$ ' + base.toFixed(2).replace('.', ',') + ' ' + adjSign + Math.abs(adj).toFixed(2).replace('.', ',');
    }

    document.getElementById('cmpFinalDisplay').textContent = 'R$ ' + final.toFixed(2).replace('.', ',');
}

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeManualModal();
        closeCompleteModal();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
