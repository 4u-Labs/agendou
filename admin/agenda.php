<?php
// ========================================================
// AGENDOU - Admin Visual Schedule (Agenda Semanal / Diária)
// Agendamento pelo Dono/Atendente + Botão WhatsApp Dedicado
// ========================================================

$pageTitle = 'Agenda Visual';
$activeNav = 'agenda';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Services/GoogleCalendarService.php';
$pdo = Database::getConnection();

// Date filter (defaults to today)
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedProfId = !empty($_GET['professional_id']) ? (int)$_GET['professional_id'] : null;

// Handle manual appointment creation by the shop owner
$manualMsg = '';
$manualErr = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_appointment') {
    require_once __DIR__ . '/auth_check.php';

    $cName = trim($_POST['customer_name'] ?? '');
    $cPhone = preg_replace('/\D/', '', $_POST['customer_whatsapp'] ?? '');
    $cEmail = trim($_POST['customer_email'] ?? '');
    $sId = (int)($_POST['service_id'] ?? 0);
    $pId = (int)($_POST['professional_id'] ?? 0);
    $appDate = $_POST['appointment_date'] ?? $selectedDate;
    $appTime = $_POST['start_time'] ?? '09:00';

    if (!$cName || !$cPhone || !$sId || !$pId || !$appDate || !$appTime) {
        $manualErr = 'Preencha todos os campos obrigatórios para o agendamento.';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Resolve Service
            $stmtS = $pdo->prepare("SELECT * FROM services WHERE id = ? AND tenant_id = ?");
            $stmtS->execute([$sId, $tenantId]);
            $service = $stmtS->fetch();

            $duration = (int)($service['duration_minutes'] ?? 30);
            $startTime = date('H:i:s', strtotime("$appDate $appTime"));
            $endTime = date('H:i:s', strtotime("$appDate $appTime + {$duration} minutes"));
            $price = (float)($service['price'] ?? 0);

            // 2. Resolve Professional
            $stmtP = $pdo->prepare("SELECT * FROM professionals WHERE id = ? AND tenant_id = ?");
            $stmtP->execute([$pId, $tenantId]);
            $professional = $stmtP->fetch();

            // 3. Upsert Customer
            $stmtC = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND whatsapp = ?");
            $stmtC->execute([$tenantId, $cPhone]);
            $customerId = $stmtC->fetchColumn();

            if ($customerId) {
                $stmtUpC = $pdo->prepare("UPDATE customers SET name = ?, email = COALESCE(NULLIF(?, ''), email), updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmtUpC->execute([$cName, $cEmail, $customerId]);
            } else {
                $stmtInC = $pdo->prepare("INSERT INTO customers (tenant_id, name, whatsapp, email) VALUES (?, ?, ?, ?)");
                $stmtInC->execute([$tenantId, $cName, $cPhone, $cEmail]);
                $customerId = (int)$pdo->lastInsertId();
            }

            // 4. Insert Appointment
            $token = bin2hex(random_bytes(16));
            $stmtA = $pdo->prepare("
                INSERT INTO appointments (
                    tenant_id, customer_id, professional_id, service_id, appointment_date, 
                    start_time, end_time, price, status, cancellation_token
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?)
            ");
            $stmtA->execute([$tenantId, $customerId, $pId, $sId, $appDate, $startTime, $endTime, $price, $token]);
            $appointmentId = (int)$pdo->lastInsertId();

            $pdo->commit();

            // 5. Google Calendar Duplo com Lembretes de 2 horas e 15 minutos
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

            header("Location: /app/agendou/admin/agenda.php?date={$appDate}&created=1");
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $manualErr = 'Erro ao agendar: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/header.php';

// Fetch all professionals
$stmtP = $pdo->prepare("SELECT id, name FROM professionals WHERE tenant_id = ? AND status = 'active' ORDER BY name ASC");
$stmtP->execute([$tenantId]);
$professionals = $stmtP->fetchAll();

// Fetch all services for the manual modal
$stmtS = $pdo->prepare("SELECT id, name, price, duration_minutes FROM services WHERE tenant_id = ? AND status = 'active' ORDER BY name ASC");
$stmtS->execute([$tenantId]);
$services = $stmtS->fetchAll();

// Fetch appointments for selected date
$query = "
    SELECT a.*, s.name as service_name, s.duration_minutes, p.name as professional_name, c.name as customer_name, c.whatsapp as customer_whatsapp, c.email as customer_email
    FROM appointments a
    JOIN services s ON s.id = a.service_id
    JOIN professionals p ON p.id = a.professional_id
    JOIN customers c ON c.id = a.customer_id
    WHERE a.tenant_id = ? AND a.appointment_date = ?
";
$params = [$tenantId, $selectedDate];
if ($selectedProfId) {
    $query .= " AND a.professional_id = ?";
    $params[] = $selectedProfId;
}
$query .= " ORDER BY a.start_time ASC";

$stmtApp = $pdo->prepare($query);
$stmtApp->execute($params);
$dayAppointments = $stmtApp->fetchAll();

// Group by professional
$byProf = [];
foreach ($professionals as $p) {
    $byProf[$p['id']] = [
        'name' => $p['name'],
        'items' => []
    ];
}
foreach ($dayAppointments as $app) {
    $pId = (int)$app['professional_id'];
    if (isset($byProf[$pId])) {
        $byProf[$pId]['items'][] = $app;
    }
}
?>

<div class="content-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1>Agenda Visual de Atendimentos</h1>
        <p>Acompanhe os horários, agende pelo balcão e confirme com seus clientes via WhatsApp com 1 clique.</p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <input type="date" value="<?= htmlspecialchars($selectedDate) ?>" class="admin-input" style="width: auto;" onchange="window.location.href='?date=' + this.value + '<?= $selectedProfId ? '&professional_id=' . $selectedProfId : '' ?>'">
        <a href="?date=<?= date('Y-m-d') ?>" class="btn-secondary">Hoje</a>
        <button type="button" class="btn-primary" onclick="openManualModal()" style="font-weight: 800; padding: 10px 18px; display: inline-flex; align-items: center; gap: 6px;">
            <span>➕ Novo Agendamento (Balcão)</span>
        </button>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: var(--primary); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 20px;">
        ✓ <strong>Agendamento confirmado com sucesso!</strong> Já sincronizado no Google Agenda com lembretes programados.
    </div>
<?php elseif ($manualErr): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: var(--red); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 20px;">
        ⚠️ <?= htmlspecialchars($manualErr) ?>
    </div>
<?php endif; ?>

<div class="card-box" style="margin-bottom: 20px; padding: 14px 20px;">
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 700;">FILTRAR PROFISSIONAL:</span>
        <a href="?date=<?= urlencode($selectedDate) ?>" class="btn-secondary" style="font-size: 0.75rem; padding: 5px 12px; <?= !$selectedProfId ? 'background: rgba(16,185,129,0.15); border-color: var(--primary); color: var(--primary);' : '' ?>">Todos</a>
        <?php foreach ($professionals as $p): ?>
            <a href="?date=<?= urlencode($selectedDate) ?>&professional_id=<?= $p['id'] ?>" class="btn-secondary" style="font-size: 0.75rem; padding: 5px 12px; <?= $selectedProfId === (int)$p['id'] ? 'background: rgba(16,185,129,0.15); border-color: var(--primary); color: var(--primary);' : '' ?>">
                <?= htmlspecialchars($p['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Colunas de Profissionais com Botão WhatsApp Dedicado -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px;">
    <?php foreach ($byProf as $pId => $col): ?>
        <?php if ($selectedProfId && $selectedProfId !== $pId) continue; ?>
        <div class="card-box" style="padding: 18px;">
            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 16px;">
                <h3 style="font-size: 1.05rem; color: #fff;"><?= htmlspecialchars($col['name']) ?></h3>
                <span class="badge-status badge-confirmed"><?= count($col['items']) ?> horário(s)</span>
            </div>

            <?php if (empty($col['items'])): ?>
                <p style="text-align: center; padding: 30px 10px; color: var(--text-muted); font-size: 0.85rem;">
                    Nenhum agendamento para este profissional nesta data.
                </p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php foreach ($col['items'] as $item): 
                        $cleanWa = preg_replace('/\D/', '', $item['customer_whatsapp']);
                        $cName = $item['customer_name'];
                        $sName = $item['service_name'];
                        $pName = $col['name'];
                        $timeFmt = substr($item['start_time'], 0, 5);
                        $dateFmt = date('d/m/Y', strtotime($selectedDate));
                        $tenantName = $currentTenant['name'];

                        $waText = urlencode("Olá {$cName}! Tudo bem?\nPassando para confirmar seu horário de *{$sName}* com *{$pName}* no dia *{$dateFmt}* às *{$timeFmt}* aqui na *{$tenantName}*.\n\nTe esperamos! 👍");
                        $waUrl = "https://wa.me/55{$cleanWa}?text={$waText}";
                    ?>
                        <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 14px; border-left: 4px solid <?= $item['status'] === 'confirmed' ? 'var(--primary)' : ($item['status'] === 'completed' ? 'var(--cyan)' : 'var(--red)') ?>;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <strong style="color: #fff; font-size: 1rem; font-family: var(--font-mono);">
                                    <?= substr($item['start_time'], 0, 5) ?> - <?= substr($item['end_time'], 0, 5) ?>
                                </strong>
                                <span class="badge-status <?= $item['status'] === 'confirmed' ? 'badge-confirmed' : ($item['status'] === 'completed' ? 'badge-completed' : 'badge-cancelled') ?>" style="font-size: 0.7rem;">
                                    <?= htmlspecialchars($item['status']) ?>
                                </span>
                            </div>

                            <div style="font-size: 0.95rem; color: #fff; font-weight: 700; margin-bottom: 2px;">
                                <?= htmlspecialchars($item['customer_name']) ?>
                            </div>

                            <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 12px;">
                                ✂️ <?= htmlspecialchars($item['service_name']) ?> • <strong style="color: var(--primary);">R$ <?= number_format($item['price'], 2, ',', '.') ?></strong>
                            </div>

                            <!-- BOTÃO DE WHATSAPP DEDICADO AO LADO DE CADA AGENDAMENTO -->
                            <div style="display: flex; gap: 8px; align-items: center; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 10px;">
                                <a href="<?= $waUrl ?>" target="_blank" class="btn-secondary" style="font-size: 0.78rem; padding: 7px 14px; background: rgba(37, 211, 102, 0.15); border: 1px solid rgba(37, 211, 102, 0.4); color: #25D366; font-weight: 800; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;" title="Conversar com o cliente no WhatsApp">
                                    <span>💬 Enviar WhatsApp</span>
                                </a>
                                <?php if (!empty($item['google_event_id'])): ?>
                                    <span style="font-size: 0.7rem; color: var(--cyan); display: inline-flex; align-items: center; gap: 4px;" title="Sincronizado no Google Calendar">
                                        <span>✓</span> Google Agenda
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
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

        <form method="POST" action="/app/agendou/admin/agenda.php">
            <input type="hidden" name="action" value="manual_appointment">

            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Nome do Cliente *</label>
                <input type="text" name="customer_name" required class="form-input" placeholder="Ex: João da Silva" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
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
                            <option value="<?= $p['id'] ?>" <?= $selectedProfId === $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Data do Agendamento *</label>
                    <input type="date" name="appointment_date" value="<?= htmlspecialchars($selectedDate) ?>" required class="form-input" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
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

<script>
function openManualModal() {
    document.getElementById('modalManualApp').style.display = 'flex';
}
function closeManualModal() {
    document.getElementById('modalManualApp').style.display = 'none';
}
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeManualModal();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
