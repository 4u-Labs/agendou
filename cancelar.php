<?php
// ========================================================
// AGENDOU - Cancel & Reschedule Public Portal
// ========================================================

require_once __DIR__ . '/config/database.php';

$token = trim($_GET['token'] ?? '');
if (!$token) {
    die("Token de agendamento inválido.");
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare("
    SELECT a.*, t.name as tenant_name, t.slug as tenant_slug, t.address, t.city, t.whatsapp as tenant_whatsapp,
           s.name as service_name, s.duration_minutes,
           p.name as professional_name,
           c.name as customer_name, c.whatsapp as customer_whatsapp
    FROM appointments a
    JOIN tenants t ON t.id = a.tenant_id
    JOIN services s ON s.id = a.service_id
    JOIN professionals p ON p.id = a.professional_id
    JOIN customers c ON c.id = a.customer_id
    WHERE a.cancellation_token = ?
");
$stmt->execute([$token]);
$appt = $stmt->fetch();

if (!$appt) {
    die("Agendamento não encontrado.");
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Gerenciar Agendamento • AGENDOU!!</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/app/agendou/public/icons/favicon-32x32.png"/>
    <link rel="icon" type="image/png" sizes="16x16" href="/app/agendou/public/icons/favicon-16x16.png"/>
    <link rel="apple-touch-icon" href="/app/agendou/public/icons/apple-touch-icon.png"/>
    <link rel="shortcut icon" href="/app/agendou/public/icons/favicon.ico"/>
    <link rel="manifest" href="/app/agendou/manifest.json"/>
    <meta name="theme-color" content="#0284c7"/>
    <meta name="apple-mobile-web-app-capable" content="yes"/>
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
    <meta name="apple-mobile-web-app-title" content="AGENDOU!!"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=JetBrains+Mono:wght@700&display=swap" rel="stylesheet">
    <link href="/app/agendou/public/css/booking.css?v=1.0" rel="stylesheet"/>
</head>
<body>
    <div class="booking-wrapper" style="max-width: 520px; margin-top: 20px;">
        <header class="business-hero">
            <h1 class="business-title"><?= htmlspecialchars($appt['tenant_name']) ?></h1>
            <p class="business-category">Gerenciamento de Agendamento</p>
        </header>

        <div class="booking-card">
            <div class="step-heading">
                <h2>Seu Agendamento</h2>
                <p>Status atual: 
                    <?php if ($appt['status'] === 'confirmed'): ?>
                        <strong style="color: var(--primary);">✓ Confirmado</strong>
                    <?php elseif ($appt['status'] === 'cancelled'): ?>
                        <strong style="color: #ef4444;">✕ Cancelado</strong>
                    <?php else: ?>
                        <strong><?= htmlspecialchars($appt['status']) ?></strong>
                    <?php endif; ?>
                </p>
            </div>

            <div class="booking-summary-card">
                <div class="summary-row">
                    <span>Cliente:</span>
                    <strong><?= htmlspecialchars($appt['customer_name']) ?></strong>
                </div>
                <div class="summary-row">
                    <span>Serviço:</span>
                    <strong><?= htmlspecialchars($appt['service_name']) ?></strong>
                </div>
                <div class="summary-row">
                    <span>Profissional:</span>
                    <strong><?= htmlspecialchars($appt['professional_name']) ?></strong>
                </div>
                <div class="summary-row">
                    <span>Data & Hora:</span>
                    <strong style="color: var(--primary);"><?= date('d/m/Y', strtotime($appt['appointment_date'])) ?> às <?= substr($appt['start_time'], 0, 5) ?></strong>
                </div>
                <div class="summary-row summary-total">
                    <span>Valor:</span>
                    <strong>R$ <?= number_format($appt['price'], 2, ',', '.') ?></strong>
                </div>
            </div>

            <?php if ($appt['status'] === 'confirmed'): ?>
                <div id="cancelActionArea">
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px;">
                        Precisa desmarcar seu horário? Ao cancelar, o horário será imediatamente liberado na agenda do estabelecimento.
                    </p>

                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <button type="button" class="btn-confirm-booking" style="background: #ef4444; box-shadow: 0 10px 20px rgba(239, 68, 68, 0.3);" onclick="confirmCancel()">
                            <span>CANCELAR MEU AGENDAMENTO</span>
                        </button>
                        <a href="/app/agendou/?slug=<?= urlencode($appt['tenant_slug']) ?>" class="btn-calendar-action" style="text-align: center;">
                            <span>Reagendar para Outra Data / Horário</span>
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 20px 0;">
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 16px;">
                        Este agendamento já foi cancelado em <?= $appt['cancelled_at'] ? date('d/m/Y H:i', strtotime($appt['cancelled_at'])) : '' ?>.
                    </p>
                    <a href="/app/agendou/?slug=<?= urlencode($appt['tenant_slug']) ?>" class="btn-confirm-booking" style="text-decoration: none;">
                        <span>FAZER UM NOVO AGENDAMENTO</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        async function confirmCancel() {
            if (!confirm('Tem certeza de que deseja cancelar este agendamento?')) {
                return;
            }

            const token = "<?= htmlspecialchars($token) ?>";
            try {
                const res = await fetch('/app/agendou/api/cancel.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: token, reason: 'Cancelado pelo cliente no portal' })
                });
                const data = await res.json();
                if (data.success) {
                    alert('Agendamento cancelado com sucesso.');
                    window.location.reload();
                } else {
                    alert(data.error || 'Erro ao cancelar.');
                }
            } catch (_) {
                alert('Erro de conexão ao cancelar.');
            }
        }
    </script>
    <script src="/app/agendou/public/js/pwa-installer.js?v=3.0"></script>
</body>
</html>
