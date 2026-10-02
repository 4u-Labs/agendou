<?php
// ========================================================
// AGENDOU - Tela de Bloqueio por Vencimento de Mensalidade
// Exibida para o Dono da Barbearia quando a assinatura está vencida
// Suporta Pagamento Automático Instantâneo via Mercado Pago
// ========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['agendou_user_id'])) {
    header("Location: /app/agendou/admin/login.php");
    exit;
}

$pdo = Database::getConnection();
$stmtU = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtU->execute([(int)$_SESSION['agendou_user_id']]);
$currentUser = $stmtU->fetch();

$tenantId = (int)($currentUser['tenant_id'] ?? 1);
$stmtT = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmtT->execute([$tenantId]);
$tenant = $stmtT->fetch();

$plan = strtolower($tenant['plan'] ?? 'starter');
if ($plan === 'free') $plan = 'starter';
$monthlyPrice = (float)($tenant['monthly_price'] ?? ($plan === 'plus' ? 39.90 : 19.90));
$dueDate = !empty($tenant['next_due_date']) ? date('d/m/Y', strtotime($tenant['next_due_date'])) : 'Vencida';
$blockedReason = $tenant['blocked_reason'] ?: 'Assinatura mensal pendente de renovação.';

// Suporte WhatsApp Oficial 4U.IA.BR
$supportWhatsapp = '5534999999999';
$waMessage = urlencode("Olá suporte 4U.IA.BR! Sou {$currentUser['name']} da empresa {$tenant['name']} (ID #{$tenant['id']}). Gostaria de enviar o comprovante do PIX da mensalidade do AGENDOU para reativação do meu sistema.");
$waUrl = "https://wa.me/{$supportWhatsapp}?text={$waMessage}";
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Acesso Suspenso • AGENDOU!!</title>
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
    <link href="/app/agendou/public/css/landing.css?v=1.0" rel="stylesheet"/>
    <style>
        .block-card {
            max-width: 520px;
            margin: 40px auto 60px;
            background: #121215;
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 24px;
            padding: 32px 28px;
            box-shadow: 0 25px 60px rgba(239, 68, 68, 0.15);
            text-align: center;
        }
        .block-badge {
            width: 68px;
            height: 68px;
            border-radius: 20px;
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 16px;
        }
        .btn-mp-pix {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            font-weight: 800;
            padding: 16px 20px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: none;
            width: 100%;
            cursor: pointer;
            font-size: 1rem;
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
            transition: transform 0.2s;
        }
        .btn-mp-pix:hover { transform: translateY(-2px); }
    </style>
</head>
<body>
    <div class="landing-ambient" style="background: radial-gradient(circle at 50% 20%, rgba(239, 68, 68, 0.15), transparent 70%);"></div>

    <div class="block-card">
        <div class="block-badge">🚫</div>
        
        <h1 style="font-size: 1.4rem; font-weight: 800; color: #fff; margin-bottom: 6px;">Sistema Temporariamente Suspenso</h1>
        <p style="font-size: 0.85rem; color: #ef4444; font-weight: 700; margin-bottom: 14px;">
            <?= htmlspecialchars($blockedReason) ?>
        </p>

        <div style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.6; margin-bottom: 20px;">
            Olá, <strong><?= htmlspecialchars($tenant['name']) ?></strong>.<br>
            A assinatura mensal da sua plataforma venceu em <strong><?= $dueDate ?></strong>.<br>
            Para restabelecer sua agenda e o link dos clientes imediatamente:
        </div>

        <div style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-color); border-radius: 16px; padding: 18px; margin-bottom: 20px; text-align: left;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 800;">Plano Contratado:</span>
                <span class="badge-status badge-confirmed" style="text-transform: uppercase;">PLANO <?= htmlspecialchars($plan) ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: baseline;">
                <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 800;">Valor da Renovação:</span>
                <span style="font-size: 1.6rem; font-weight: 900; color: #facc15;">R$ <?= number_format($monthlyPrice, 2, ',', '.') ?></span>
            </div>
        </div>

        <button type="button" onclick="abrirAssinaturaRecorrente()" style="background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; font-weight: 800; padding: 16px 20px; border-radius: 14px; display: flex; align-items: center; justify-content: center; gap: 10px; border: none; width: 100%; cursor: pointer; font-size: 0.95rem; margin-bottom: 12px; box-shadow: 0 10px 25px rgba(2, 132, 199, 0.3);">
            <span>🔄 REATIVAR COM ASSINATURA AUTOMÁTICA (NÃO BLOQUEIA MAIS)</span>
            <span>→</span>
        </button>

        <button type="button" class="btn-mp-pix" onclick="abrirPixMercadoPago()">
            <span>⚡ REATIVAR COM PIX AVULSO DESTE MÊS</span>
            <span>→</span>
        </button>

        <div style="margin-top: 16px;">
            <a href="<?= $waUrl ?>" target="_blank" style="color: #25d366; font-size: 0.82rem; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                <span>💬 Prefere enviar comprovante manual no WhatsApp? Clique aqui</span>
            </a>
        </div>

        <div style="margin-top: 24px; border-top: 1px solid var(--border-color); padding-top: 16px; display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem;">
            <a href="/app/agendou/admin/logout.php" style="color: var(--text-muted); text-decoration: none;">🚪 Sair do Sistema</a>
            <span style="color: var(--text-muted);">Suporte 4U.IA.BR</span>
        </div>
    </div>

    <!-- MODAL PIX MERCADO PAGO -->
    <div id="modalPixMP" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 99999; align-items: center; justify-content: center; padding: 20px;">
        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 24px; width: 100%; max-width: 460px; padding: 28px; box-shadow: 0 25px 50px rgba(0,0,0,0.8); text-align: center;">
            
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 22px;">⚡</span>
                    <span style="font-size: 0.95rem; font-weight: 800; color: #fff;">PIX MERCADO PAGO</span>
                </div>
                <button type="button" onclick="fecharModalPix()" style="background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer;">✕</button>
            </div>

            <div id="pixLoading" style="padding: 30px 0;">
                <div style="font-size: 32px; animation: spin 1s infinite linear;">⏳</div>
                <p style="font-size: 0.9rem; color: var(--text-muted); margin-top: 14px;">Gerando seu PIX oficial no Mercado Pago...</p>
            </div>

            <div id="pixContent" style="display: none;">
                <div style="font-size: 1.6rem; font-weight: 900; color: #facc15; margin-bottom: 12px;" id="pixAmountLabel"></div>

                <div style="background: #fff; padding: 14px; border-radius: 16px; display: inline-block; margin-bottom: 14px;">
                    <img id="pixQrImg" src="" alt="QR Code PIX" style="width: 190px; height: 190px; display: block;">
                </div>

                <p style="font-size: 0.78rem; color: var(--text-muted); margin-bottom: 10px;">
                    Abra o app do seu banco, escolha <strong>Pagar via Pix &gt; Ler QR Code</strong> ou use o Copia e Cola:
                </p>

                <div style="position: relative; margin-bottom: 14px;">
                    <input type="text" id="pixCopiaCola" readonly style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 8px 10px; color: #fff; font-size: 0.75rem; font-family: monospace;">
                </div>

                <button type="button" class="btn-primary" onclick="copiarPixCode()" style="width: 100%; padding: 10px; font-weight: 800; margin-bottom: 12px; background: #10b981;">
                    📋 COPIAR CÓDIGO PIX
                </button>
                <div id="copiedSuccess" style="display: none; color: var(--primary); font-size: 0.8rem; font-weight: 700; margin-bottom: 8px;">✓ Código PIX Copiado!</div>

                <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 10px; padding: 8px; font-size: 0.75rem; color: var(--primary);">
                    🟢 Aguardando pagamento... Reativação automática imediata.
                </div>
            </div>

            <div id="pixApproved" style="display: none; padding: 24px 0;">
                <div style="font-size: 48px; margin-bottom: 12px;">🎉</div>
                <h3 style="font-size: 1.3rem; color: var(--primary); font-weight: 800; margin-bottom: 6px;">Pagamento Aprovado!</h3>
                <p style="font-size: 0.88rem; color: #fff; margin-bottom: 18px;">
                    Seu sistema foi reativado com sucesso! Redirecionando para o seu painel...
                </p>
            </div>
        </div>
    </div>

    <!-- MODAL ASSINATURA RECORRENTE MERCADO PAGO -->
    <div id="modalSubMP" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 99999; align-items: center; justify-content: center; padding: 20px;">
        <div style="background: var(--bg-card, #18181b); border: 1px solid var(--border-color, #27272a); border-radius: 24px; width: 100%; max-width: 460px; padding: 28px; box-shadow: 0 25px 50px rgba(0,0,0,0.8); text-align: center;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 22px;">🔄</span>
                    <span style="font-size: 0.95rem; font-weight: 800; color: #fff;">ASSINATURA RECORRENTE AUTOMÁTICA</span>
                </div>
                <button type="button" onclick="fecharModalSub()" style="background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer;">✕</button>
            </div>

            <div id="subLoading" style="padding: 30px 0;">
                <div style="font-size: 32px; animation: spin 1s infinite linear;">⏳</div>
                <p style="font-size: 0.9rem; color: #a1a1aa; margin-top: 14px;">Preparando checkout no Mercado Pago...</p>
            </div>

            <div id="subContent" style="display: none; text-align: left;">
                <div style="text-align: center; margin-bottom: 18px;">
                    <div style="font-size: 0.85rem; color: #a1a1aa; margin-bottom: 4px;">Reativação Automática: <strong style="color: #fff;">Plano <?= strtoupper($plan) ?></strong></div>
                    <div style="font-size: 1.8rem; font-weight: 900; color: #38bdf8; margin-bottom: 6px;">R$ <?= number_format($monthlyPrice, 2, ',', '.') ?> / mês</div>
                    <div style="font-size: 0.78rem; color: #71717a;">Cobrança mensal no cartão / conta MP • Nunca mais fique bloqueado</div>
                </div>

                <div style="background: rgba(14, 165, 233, 0.08); border: 1px solid rgba(14, 165, 233, 0.25); border-radius: 14px; padding: 14px; margin-bottom: 20px;">
                    <div style="font-size: 0.75rem; font-weight: 800; color: #38bdf8; margin-bottom: 6px; text-transform: uppercase;">Benefícios:</div>
                    <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.78rem; color: #d4d4d8; line-height: 1.7;">
                        <li>✓ <strong>Reativação Imediata:</strong> seu sistema e clientes liberados na hora.</li>
                        <li>✓ <strong>Sem novos bloqueios:</strong> renova sozinho todo mês.</li>
                        <li>✓ <strong>Sem fidelidade:</strong> cancele pelo painel quando quiser.</li>
                    </ul>
                </div>

                <button type="button" id="btnIrCheckoutMP" style="background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; font-weight: 800; padding: 14px; border-radius: 12px; display: flex; align-items: center; justify-content: center; gap: 8px; border: none; width: 100%; cursor: pointer; font-size: 0.95rem;">
                    <span>Concluir Assinatura no Mercado Pago</span>
                    <span>→</span>
                </button>
            </div>
        </div>
    </div>

    <script>
    let pollInterval = null;

    function abrirAssinaturaRecorrente() {
        const modal = document.getElementById('modalSubMP');
        modal.style.display = 'flex';
        document.getElementById('subLoading').style.display = 'block';
        document.getElementById('subContent').style.display = 'none';

        fetch('/app/agendou/api/mp_subscription.php?action=create&plan=<?= $plan ?>&tenant_id=<?= $tenantId ?>')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.init_point) {
                    document.getElementById('subLoading').style.display = 'none';
                    document.getElementById('subContent').style.display = 'block';

                    const btn = document.getElementById('btnIrCheckoutMP');
                    btn.onclick = function() {
                        window.location.href = data.init_point;
                    };
                } else {
                    alert('Erro ao configurar assinatura: ' + (data.error || 'Tente novamente.'));
                    fecharModalSub();
                }
            })
            .catch(() => {
                alert('Erro na comunicação com o servidor.');
                fecharModalSub();
            });
    }

    function fecharModalSub() {
        document.getElementById('modalSubMP').style.display = 'none';
    }

    function abrirPixMercadoPago() {
        const modal = document.getElementById('modalPixMP');
        modal.style.display = 'flex';
        document.getElementById('pixLoading').style.display = 'block';
        document.getElementById('pixContent').style.display = 'none';
        document.getElementById('pixApproved').style.display = 'none';

        fetch('/app/agendou/api/mp_pix.php?plan=<?= $plan ?>&tenant_id=<?= $tenantId ?>')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('pixLoading').style.display = 'none';
                    document.getElementById('pixContent').style.display = 'block';
                    document.getElementById('pixAmountLabel').innerText = 'R$ ' + data.amount_formatted;
                    document.getElementById('pixQrImg').src = 'data:image/png;base64,' + data.qr_code_base64;
                    document.getElementById('pixCopiaCola').value = data.qr_code;

                    iniciarPolling(data.payment_id);
                } else {
                    alert('Erro ao gerar PIX: ' + (data.error || 'Tente novamente.'));
                    fecharModalPix();
                }
            })
            .catch(() => {
                alert('Erro ao conectar com servidor.');
                fecharModalPix();
            });
    }

    function copiarPixCode() {
        const input = document.getElementById('pixCopiaCola');
        input.select();
        navigator.clipboard.writeText(input.value).then(() => {
            const msg = document.getElementById('copiedSuccess');
            msg.style.display = 'block';
            setTimeout(() => msg.style.display = 'none', 3000);
        });
    }

    function iniciarPolling(paymentId) {
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(() => {
            fetch('/app/agendou/api/mp_check.php?payment_id=' + paymentId)
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'approved') {
                        clearInterval(pollInterval);
                        document.getElementById('pixContent').style.display = 'none';
                        document.getElementById('pixApproved').style.display = 'block';
                        setTimeout(() => {
                            window.location.href = '/app/agendou/admin/index.php';
                        }, 3000);
                    }
                })
                .catch(() => {});
        }, 3000);
    }

    function fecharModalPix() {
        if (pollInterval) clearInterval(pollInterval);
        document.getElementById('modalPixMP').style.display = 'none';
    }
    </script>
</body>
</html>
