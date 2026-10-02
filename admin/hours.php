<?php
// ========================================================
// AGENDOU - Business Hours & Blocked Times
// ========================================================

$pageTitle = 'Horários & Bloqueios';
$activeNav = 'hours';
require_once __DIR__ . '/header.php';

$pdo = Database::getConnection();
$msg = '';

// Save Business Hours
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hours'])) {
    $days = $_POST['days'] ?? [];
    $stmtUp = $pdo->prepare("
        INSERT INTO business_hours (tenant_id, day_of_week, open_time, close_time, break_start, break_end, is_closed)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(tenant_id, day_of_week) DO UPDATE SET
            open_time = excluded.open_time,
            close_time = excluded.close_time,
            break_start = excluded.break_start,
            break_end = excluded.break_end,
            is_closed = excluded.is_closed
    ");

    for ($d = 0; $d <= 6; $d++) {
        $open = $_POST['open_' . $d] ?? '08:00';
        $close = $_POST['close_' . $d] ?? '19:00';
        $breakStart = $_POST['bstart_' . $d] ?? '12:00';
        $breakEnd = $_POST['bend_' . $d] ?? '13:00';
        $isClosed = isset($_POST['closed_' . $d]) ? 1 : 0;

        $stmtUp->execute([$tenantId, $d, $open, $close, $breakStart, $breakEnd, $isClosed]);
    }
    $msg = 'Horários de funcionamento atualizados com sucesso!';
}

// Add Blocked Time
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_block'])) {
    $pId = !empty($_POST['block_prof_id']) ? (int)$_POST['block_prof_id'] : null;
    $start = $_POST['block_start'] ?? '';
    $end = $_POST['block_end'] ?? '';
    $reason = trim($_POST['block_reason'] ?? 'Compromisso pessoal');

    if ($start && $end) {
        $stmtB = $pdo->prepare("
            INSERT INTO blocked_times (tenant_id, professional_id, start_datetime, end_datetime, reason)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmtB->execute([$tenantId, $pId, $start, $end, $reason]);
        $msg = 'Bloqueio de horário registrado!';
    }
}

// Delete Blocked Time
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_block'])) {
    $bId = (int)$_POST['block_id'];
    $stmtDel = $pdo->prepare("DELETE FROM blocked_times WHERE id = ? AND tenant_id = ?");
    $stmtDel->execute([$bId, $tenantId]);
    $msg = 'Bloqueio removido.';
}

// Fetch hours
$stmtH = $pdo->prepare("SELECT * FROM business_hours WHERE tenant_id = ? ORDER BY day_of_week ASC");
$stmtH->execute([$tenantId]);
$hoursRows = $stmtH->fetchAll();
$hoursMap = [];
foreach ($hoursRows as $hr) {
    $hoursMap[$hr['day_of_week']] = $hr;
}

// Fetch active blocked times
$stmtBlocked = $pdo->prepare("
    SELECT b.*, p.name as professional_name 
    FROM blocked_times b
    LEFT JOIN professionals p ON p.id = b.professional_id
    WHERE b.tenant_id = ? AND b.end_datetime >= datetime('now')
    ORDER BY b.start_datetime ASC
");
$stmtBlocked->execute([$tenantId]);
$blockedList = $stmtBlocked->fetchAll();

// Fetch professionals for block dropdown
$stmtP = $pdo->prepare("SELECT id, name FROM professionals WHERE tenant_id = ? AND status = 'active'");
$stmtP->execute([$tenantId]);
$professionals = $stmtP->fetchAll();

$dayNames = [
    0 => 'Domingo',
    1 => 'Segunda-feira',
    2 => 'Terça-feira',
    3 => 'Quarta-feira',
    4 => 'Quinta-feira',
    5 => 'Sexta-feira',
    6 => 'Sábado'
];
?>

<div class="content-header">
    <div>
        <h1>Horários de Atendimento & Bloqueios</h1>
        <p>Defina a grade de funcionamento semanal e bloqueie datas especiais para folgas ou feriados.</p>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--primary); padding: 12px 16px; border-radius: var(--radius-md); font-size: 0.85rem; margin-bottom: 20px;">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<!-- 1. Weekly Business Hours -->
<div class="card-box">
    <div class="card-box-header">
        <h2>Expediente Semanal</h2>
        <span style="font-size: 0.8rem; color: var(--text-muted);">Horários que aparecem disponíveis para o cliente</span>
    </div>

    <form method="POST">
        <input type="hidden" name="save_hours" value="1">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Dia da Semana</th>
                        <th>Fechado?</th>
                        <th>Abertura</th>
                        <th>Fechamento</th>
                        <th>Início Almoço</th>
                        <th>Fim Almoço</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($d = 0; $d <= 6; $d++): 
                        $cfg = $hoursMap[$d] ?? ['open_time' => '08:00', 'close_time' => '19:00', 'break_start' => '12:00', 'break_end' => '13:00', 'is_closed' => ($d === 0 ? 1 : 0)];
                    ?>
                        <tr>
                            <td><strong><?= $dayNames[$d] ?></strong></td>
                            <td>
                                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; color: var(--red);">
                                    <input type="checkbox" name="closed_<?= $d ?>" value="1" <?= $cfg['is_closed'] ? 'checked' : '' ?>>
                                    <span>Folga</span>
                                </label>
                            </td>
                            <td><input type="time" name="open_<?= $d ?>" value="<?= htmlspecialchars($cfg['open_time']) ?>" class="admin-input" style="width: 110px;"></td>
                            <td><input type="time" name="close_<?= $d ?>" value="<?= htmlspecialchars($cfg['close_time']) ?>" class="admin-input" style="width: 110px;"></td>
                            <td><input type="time" name="bstart_<?= $d ?>" value="<?= htmlspecialchars($cfg['break_start'] ?? '') ?>" class="admin-input" style="width: 110px;"></td>
                            <td><input type="time" name="bend_<?= $d ?>" value="<?= htmlspecialchars($cfg['break_end'] ?? '') ?>" class="admin-input" style="width: 110px;"></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 20px; text-align: right;">
            <button type="submit" class="btn-emerald">Salvar Grade de Horários</button>
        </div>
    </form>
</div>

<!-- 2. Blocked Times (Férias, Compromissos, Feriados) -->
<div class="card-box">
    <div class="card-box-header">
        <h2>Bloquear Horários Específicos</h2>
        <span style="font-size: 0.8rem; color: var(--text-muted);">Impedir agendamentos em datas ou intervalos específicos</span>
    </div>

    <form method="POST" style="margin-bottom: 24px; background: rgba(255,255,255,0.02); padding: 18px; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
        <input type="hidden" name="add_block" value="1">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; align-items: flex-end;">
            <div class="admin-form-group" style="margin: 0;">
                <label class="admin-label">Profissional</label>
                <select name="block_prof_id" class="admin-input">
                    <option value="">Toda a Equipe</option>
                    <?php foreach ($professionals as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="admin-form-group" style="margin: 0;">
                <label class="admin-label">Início do Bloqueio *</label>
                <input type="datetime-local" name="block_start" class="admin-input" required>
            </div>

            <div class="admin-form-group" style="margin: 0;">
                <label class="admin-label">Fim do Bloqueio *</label>
                <input type="datetime-local" name="block_end" class="admin-input" required>
            </div>

            <div class="admin-form-group" style="margin: 0;">
                <label class="admin-label">Motivo</label>
                <input type="text" name="block_reason" class="admin-input" placeholder="Ex: Feriado, Compromisso...">
            </div>

            <button type="submit" class="btn-emerald" style="height: 42px;">+ Inserir Bloqueio</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Profissional</th>
                    <th>Início</th>
                    <th>Fim</th>
                    <th>Motivo</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($blockedList)): ?>
                    <tr><td colspan="5" style="text-align: center; padding: 24px; color: var(--text-muted);">Nenhum bloqueio futuro ativo.</td></tr>
                <?php else: ?>
                    <?php foreach ($blockedList as $b): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($b['professional_name'] ?? 'Toda a Equipe') ?></strong></td>
                            <td><?= date('d/m/Y H:i', strtotime($b['start_datetime'])) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($b['end_datetime'])) ?></td>
                            <td><?= htmlspecialchars($b['reason'] ?: 'Bloqueio administrativo') ?></td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Desbloquear este horário?');">
                                    <input type="hidden" name="delete_block" value="1">
                                    <input type="hidden" name="block_id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn-secondary" style="padding: 4px 8px; font-size: 0.72rem; color: var(--red);">Desbloquear</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
