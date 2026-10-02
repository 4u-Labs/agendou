<?php
// ========================================================
// AGENDOU - Visão do Cliente: Estabelecimento Suspenso
// ========================================================
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title><?= htmlspecialchars($tenant['name'] ?? 'Estabelecimento') ?> • Sistema Indisponível</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/app/agendou/public/icons/favicon-32x32.png"/>
    <link rel="icon" type="image/png" sizes="16x16" href="/app/agendou/public/icons/favicon-16x16.png"/>
    <link rel="apple-touch-icon" href="/app/agendou/public/icons/apple-touch-icon.png"/>
    <link rel="shortcut icon" href="/app/agendou/public/icons/favicon.ico"/>
    <link rel="manifest" href="/app/agendou/manifest.json"/>
    <meta name="theme-color" content="#0284c7"/>
    <meta name="apple-mobile-web-app-capable" content="yes"/>
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
    <meta name="apple-mobile-web-app-title" content="AGENDOU!!"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="/app/agendou/public/css/customer.css?v=1.0" rel="stylesheet"/>
    <style>
        .suspended-card {
            max-width: 480px;
            margin: 60px auto;
            background: #141417;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 36px 28px;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0,0,0,0.6);
        }
        .suspended-icon {
            width: 64px;
            height: 64px;
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 20px;
        }
    </style>
</head>
<body>
    <div class="customer-wrapper">
        <div class="suspended-card">
            <div class="suspended-icon">⚠️</div>
            <h1 style="font-size: 1.35rem; color: #fff; font-weight: 800; margin-bottom: 8px;">
                <?= htmlspecialchars($tenant['name'] ?? 'Estabelecimento') ?>
            </h1>
            <p style="color: #facc15; font-size: 0.95rem; font-weight: 700; margin-bottom: 16px;">
                Agendamentos Online Temporariamente Indisponíveis
            </p>
            <p style="color: var(--text-muted); font-size: 0.85rem; line-height: 1.6; margin-bottom: 24px;">
                O sistema de agendamento online deste estabelecimento está passando por manutenção ou atualização temporária.
                Por favor, entre em contato diretamente pelo WhatsApp para agendar seu horário.
            </p>

            <?php if (!empty($tenant['whatsapp'])): ?>
                <a href="https://wa.me/55<?= preg_replace('/\D/', '', $tenant['whatsapp']) ?>?text=<?= urlencode('Olá! Gostaria de saber sobre horários para agendamento.') ?>" target="_blank" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; background: #25d366; color: #000; font-weight: 800; padding: 14px 20px; border-radius: 14px; text-decoration: none; font-size: 0.95rem;">
                    <span>💬 Falar no WhatsApp da Barbearia</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
