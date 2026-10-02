<?php
// ========================================================
// AGENDOU - Customers Management (CRM)
// ========================================================

$pageTitle = 'Clientes';
$activeNav = 'customers';
require_once __DIR__ . '/header.php';

$pdo = Database::getConnection();

// Fetch customers with metrics (total bookings, total spent, last visit)
$stmt = $pdo->prepare("
    SELECT c.*, 
           COUNT(a.id) as total_appointments,
           SUM(CASE WHEN a.status IN ('confirmed', 'completed') THEN a.price ELSE 0 END) as total_spent,
           MAX(a.appointment_date) as last_appointment
    FROM customers c
    LEFT JOIN appointments a ON a.customer_id = c.id
    WHERE c.tenant_id = ?
    GROUP BY c.id
    ORDER BY total_spent DESC, c.name ASC
");
$stmt->execute([$tenantId]);
$customers = $stmt->fetchAll();
?>

<div class="content-header">
    <div>
        <h1>Base de Clientes (CRM)</h1>
        <p>Cadastrados automaticamente a cada agendamento feito pelo WhatsApp.</p>
    </div>
</div>

<div class="card-box">
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Nome do Cliente</th>
                    <th>WhatsApp</th>
                    <th>E-mail</th>
                    <th>Agendamentos</th>
                    <th>Total Gasto</th>
                    <th>Última Visita</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="7" style="text-align: center; padding: 30px;">Nenhum cliente cadastrado ainda.</td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td><strong style="color: #fff;"><?= htmlspecialchars($c['name']) ?></strong></td>
                            <td>
                                <?php $wa = preg_replace('/[^0-9]/', '', $c['whatsapp']); ?>
                                <a href="https://wa.me/55<?= $wa ?>" target="_blank" style="color: #25D366; text-decoration: none; font-weight: 600;">
                                    💬 <?= htmlspecialchars($c['whatsapp']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($c['email'] ?: '--') ?></td>
                            <td><span class="badge-status badge-confirmed"><?= (int)$c['total_appointments'] ?> vezes</span></td>
                            <td><strong style="color: var(--primary);">R$ <?= number_format((float)$c['total_spent'], 2, ',', '.') ?></strong></td>
                            <td><?= $c['last_appointment'] ? date('d/m/Y', strtotime($c['last_appointment'])) : '--' ?></td>
                            <td>
                                <a href="https://wa.me/55<?= $wa ?>" target="_blank" class="btn-secondary" style="font-size: 0.72rem; padding: 4px 8px; color: #25D366;">
                                    Conversar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
