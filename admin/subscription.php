<?php
// ========================================================
// AGENDOU - Minha Assinatura & Planos Oficiais
// Pagamentos via PIX Mercado Pago com Aprovação Instantânea
// ========================================================

$pageTitle = 'Minha Assinatura';
$activeNav = 'subscription';
require_once __DIR__ . '/header.php';

$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

$currentPlanKey = strtolower($tenant['plan'] ?? 'free');
if (!in_array($currentPlanKey, ['free', 'starter', 'plus'])) {
    $currentPlanKey = ($currentPlanKey === 'premium') ? 'plus' : (($currentPlanKey === 'pro') ? 'starter' : 'free');
}

$monthlyPrice = (float)($tenant['monthly_price'] ?? ($currentPlanKey === 'plus' ? 39.90 : ($currentPlanKey === 'starter' ? 19.90 : 0.00)));
$dueDate = $tenant['next_due_date'] ? date('d/m/Y', strtotime($tenant['next_due_date'])) : 'Não definida';
$subStatus = $tenant['subscription_status'] ?? 'active';
$recurringType = $tenant['recurring_type'] ?? 'manual_pix';
$preapprovalStatus = $tenant['preapproval_status'] ?? '';
$isAutoRecurring = ($recurringType === 'auto_recurring' && in_array($preapprovalStatus, ['authorized', 'pending']));

$today = date('Y-m-d');
$isPastDue = $tenant['next_due_date'] && $today > $tenant['next_due_date'] && $currentPlanKey !== 'free';
$daysDiff = $tenant['next_due_date'] ? (int)((strtotime($tenant['next_due_date']) - strtotime($today)) / 86400) : 30;

// Verificação de retorno do checkout de assinatura do Mercado Pago
$returnedFromMP = isset($_GET['sub_status']) && $_GET['sub_status'] === 'returned';

// Buscar histórico de faturas do estabelecimento
$stmtInv = $pdo->prepare("SELECT * FROM invoices WHERE tenant_id = ? ORDER BY id DESC LIMIT 5");
$stmtInv->execute([$tenantId]);
$invoices = $stmtInv->fetchAll();
?>

<div class="content-header">
    <div>
        <h1>Minha Assinatura • Planos & Pagamentos</h1>
        <p>Gerencie sua assinatura mensal com renovação automática no cartão ou pagamento avulso via PIX Mercado Pago.</p>
    </div>
</div>

<?php if ($returnedFromMP): ?>
<div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.5); border-radius: 16px; padding: 18px 24px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
    <div style="display: flex; align-items: center; gap: 14px;">
        <span style="font-size: 2rem;">🎉</span>
        <div>
            <div style="font-size: 1.05rem; font-weight: 800; color: #10b981;">Assinatura Recorrente Registrada no Mercado Pago!</div>
            <div style="font-size: 0.85rem; color: #fff;">Estamos sincronizando a autorização com o seu estabelecimento. O seu sistema será renovado todo mês automaticamente.</div>
        </div>
    </div>
    <button type="button" class="btn-emerald" onclick="verificarAssinatura()" style="padding: 10px 18px; font-size: 0.85rem;">
        🔄 Atualizar Status
    </button>
</div>
<?php endif; ?>

<!-- Status Atual da Assinatura -->
<div class="card-box" style="margin-bottom: 28px; border-color: <?= $isPastDue ? 'rgba(239, 68, 68, 0.4)' : ($isAutoRecurring ? 'rgba(14, 165, 233, 0.5)' : 'rgba(16, 185, 129, 0.4)') ?>;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; color: var(--text-muted); margin-bottom: 4px;">Plano Atual do Seu Estabelecimento</div>
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <h2 style="font-size: 1.8rem; font-weight: 800; color: #fff; margin: 0;">
                    <?php if ($currentPlanKey === 'free'): ?>
                        Plano FREE <span style="font-size: 1rem; color: var(--text-muted); font-weight: 500;">(Gratuito)</span>
                    <?php elseif ($currentPlanKey === 'starter'): ?>
                        🚀 Plano STARTER <span style="font-size: 1.1rem; color: #facc15; font-weight: 700;">(R$ 19,90/mês)</span>
                    <?php else: ?>
                        ⭐ Plano PLUS <span style="font-size: 1.1rem; color: #facc15; font-weight: 700;">(R$ 39,90/mês)</span>
                    <?php endif; ?>
                </h2>

                <?php if ($subStatus === 'suspended'): ?>
                    <span class="badge-status badge-cancelled">🚫 SUSPENSO / BLOQUEADO</span>
                <?php elseif ($isPastDue): ?>
                    <span class="badge-status badge-pending">⚠️ MENSALIDADE VENCIDA</span>
                <?php else: ?>
                    <span class="badge-status badge-confirmed">✓ ATIVO & EM DIA</span>
                <?php endif; ?>

                <?php if ($isAutoRecurring): ?>
                    <span class="badge-status" style="background: rgba(14, 165, 233, 0.2); color: #38bdf8; border: 1px solid rgba(14, 165, 233, 0.4);">
                        🔄 RENOVAÇÃO AUTOMÁTICA ATIVA
                    </span>
                <?php endif; ?>
            </div>

            <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 8px;">
                <?php if ($currentPlanKey === 'free'): ?>
                    Você está no plano de entrada. Ative uma assinatura para ter Google Calendar, mais profissionais e agendamentos ampliados.
                <?php elseif ($isAutoRecurring): ?>
                    <strong style="color: #38bdf8;">Assinatura Recorrente Ativa:</strong> Renovação automática agendada no Mercado Pago para <strong><?= $dueDate ?></strong> (R$ <?= number_format($monthlyPrice, 2, ',', '.') ?>/mês). Você não precisa se preocupar em lembrar de pagar!
                <?php elseif ($isPastDue): ?>
                    <strong style="color: var(--red);">Sua mensalidade venceu em <?= $dueDate ?>!</strong> Pague abaixo via PIX ou ative a assinatura recorrente para restabelecer seus agendamentos imediatamente.
                <?php else: ?>
                    Próximo vencimento: <strong style="color: var(--primary);"><?= $dueDate ?></strong> (faltam <?= max(0, $daysDiff) ?> dias).
                <?php endif; ?>
            </p>
        </div>

        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <?php if ($isAutoRecurring): ?>
                <button type="button" class="btn-secondary" onclick="cancelarAssinaturaRecorrente()" style="padding: 10px 18px; font-weight: 700; font-size: 0.85rem; border-color: rgba(239,68,68,0.4); color: #f87171;">
                    ✕ Cancelar Renovação Automática
                </button>
            <?php elseif ($currentPlanKey === 'free'): ?>
                <button type="button" class="btn-primary" onclick="iniciarAssinaturaRecorrente('starter')" style="padding: 12px 20px; font-weight: 800; font-size: 0.9rem; background: linear-gradient(135deg, #0284c7, #0369a1);">
                    <span>🔄 Assinar STARTER Recorrente</span>
                    <span>→</span>
                </button>
                <button type="button" class="btn-emerald" onclick="iniciarPix('starter')" style="padding: 12px 18px; font-weight: 700; font-size: 0.85rem;">
                    <span>⚡ PIX Avulso (R$ 19,90)</span>
                </button>
            <?php else: ?>
                <button type="button" class="btn-primary" onclick="iniciarAssinaturaRecorrente('<?= $currentPlanKey ?>')" style="padding: 12px 20px; font-weight: 800; font-size: 0.9rem; background: linear-gradient(135deg, #0284c7, #0369a1);">
                    <span>🔄 Ativar Assinatura Recorrente Mensal</span>
                    <span>→</span>
                </button>
                <button type="button" class="btn-emerald" onclick="iniciarPix('<?= $currentPlanKey ?>')" style="padding: 12px 18px; font-weight: 700; font-size: 0.85rem;">
                    <span>⚡ Pagar Mês com PIX</span>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- VITRINE DOS 3 PLANOS OFICIAIS -->
<div style="margin-bottom: 32px;">
    <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 6px;">Escolha o Plano Ideal para seu Negócio</h2>
    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">Você pode assinar com renovação mensal automática (cartão/conta Mercado Pago) ou pagar mensalmente via PIX.</p>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
        
        <!-- PLANO FREE -->
        <div class="card-box" style="display: flex; flex-direction: column; justify-content: space-between; border-color: <?= $currentPlanKey === 'free' ? 'var(--primary)' : 'rgba(255,255,255,0.1)' ?>; position: relative;">
            <?php if ($currentPlanKey === 'free'): ?>
                <div style="position: absolute; top: -12px; right: 20px; background: var(--primary); color: #000; font-size: 0.7rem; font-weight: 800; padding: 3px 10px; border-radius: 20px; text-transform: uppercase;">
                    Plano Atual
                </div>
            <?php endif; ?>
            <div>
                <div style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 4px;">FREE</div>
                <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 16px;">Para quem está começando</div>
                <div style="font-size: 2rem; font-weight: 800; color: #fff; margin-bottom: 20px;">
                    R$ 0 <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500;">/ grátis</span>
                </div>

                <ul style="list-style: none; padding: 0; margin: 0 0 24px 0; font-size: 0.82rem; color: var(--text-secondary); line-height: 1.9;">
                    <li>✓ <strong>30 agendamentos</strong> / mês</li>
                    <li>✓ <strong>1 profissional</strong> (o próprio dono)</li>
                    <li>✓ Até 5 serviços no catálogo</li>
                    <li>✓ Página pública de agendamento</li>
                    <li>✓ Link personalizado & QR Code padrão</li>
                    <li>✓ Notificações & Lembretes WhatsApp</li>
                    <li>✓ Agenda interna e clientes</li>
                    <li style="color: #ef4444;">✕ Sem Google Calendar no celular</li>
                    <li style="color: #ef4444;">✕ Sem controle financeiro / comissões</li>
                    <li style="color: #ef4444;">✕ Sem reagendamento online pelo cliente</li>
                    <li style="color: #ef4444;">✕ Horário de atendimento fixo</li>
                </ul>
            </div>
            <div>
                <?php if ($currentPlanKey === 'free'): ?>
                    <button class="btn-secondary" style="width: 100%; opacity: 0.7; cursor: default;" disabled>Plano Ativo</button>
                <?php else: ?>
                    <button class="btn-secondary" style="width: 100%;" onclick="alert('Entre em contato com o suporte para downgrade.');">Downgrade para Free</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- PLANO STARTER -->
        <div class="card-box" style="display: flex; flex-direction: column; justify-content: space-between; border-color: <?= $currentPlanKey === 'starter' ? 'var(--primary)' : 'rgba(16, 185, 129, 0.4)' ?>; background: rgba(16, 185, 129, 0.03); position: relative;">
            <div style="position: absolute; top: -12px; right: 20px; background: linear-gradient(135deg, var(--primary), #059669); color: #000; font-size: 0.7rem; font-weight: 800; padding: 3px 12px; border-radius: 20px; text-transform: uppercase;">
                Mais Popular 🚀
            </div>
            <div>
                <div style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 4px;">STARTER</div>
                <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 16px;">Profissionais e pequenos negócios</div>
                <div style="font-size: 2rem; font-weight: 800; color: #facc15; margin-bottom: 20px;">
                    R$ 19,90 <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500;">/ mês</span>
                </div>

                <ul style="list-style: none; padding: 0; margin: 0 0 24px 0; font-size: 0.82rem; color: var(--text-secondary); line-height: 1.9;">
                    <li>✓ <strong>150 agendamentos</strong> / mês</li>
                    <li>✓ <strong>Até 3 profissionais / cadeiras</strong></li>
                    <li>✓ <strong>Serviços ilimitados</strong></li>
                    <li>✓ <strong>Google Calendar nativo (celular)</strong></li>
                    <li>✓ <strong>Link curto oficial 4u.ia.br/sua-marca</strong></li>
                    <li>✓ <strong>Reagendamento & cancelamento online</strong></li>
                    <li>✓ Lembretes automáticos 2h e 15m antes</li>
                    <li>✓ <strong>Painel financeiro com faturamento diário</strong></li>
                    <li>✓ <strong>Pausas de almoço e bloqueio de horários</strong></li>
                    <li>✓ Confirmações & WhatsApp em cada atendimento</li>
                    <li>✓ <strong>Recorrência Automática (Cartão/PIX)</strong></li>
                    <li>✓ Suporte rápido via WhatsApp</li>
                </ul>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <button type="button" class="btn-primary" style="width: 100%; padding: 11px; font-weight: 800; background: linear-gradient(135deg, #0284c7, #0369a1); font-size: 0.88rem;" onclick="iniciarAssinaturaRecorrente('starter')">
                    🔄 Assinar Recorrente (R$ 19,90/mês)
                </button>
                <button type="button" class="btn-emerald" style="width: 100%; padding: 9px; font-weight: 700; font-size: 0.82rem;" onclick="iniciarPix('starter')">
                    ⚡ Pagar 1 Mês Avulso via PIX
                </button>
            </div>
        </div>

        <!-- PLANO PLUS -->
        <div class="card-box" style="display: flex; flex-direction: column; justify-content: space-between; border-color: <?= $currentPlanKey === 'plus' ? '#facc15' : 'rgba(250, 204, 21, 0.4)' ?>; background: rgba(250, 204, 21, 0.02); position: relative;">
            <div style="position: absolute; top: -12px; right: 20px; background: #facc15; color: #000; font-size: 0.7rem; font-weight: 800; padding: 3px 12px; border-radius: 20px; text-transform: uppercase;">
                Completo ⭐
            </div>
            <div>
                <div style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 4px;">PLUS</div>
                <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 16px;">Para alto movimento e expansão</div>
                <div style="font-size: 2rem; font-weight: 800; color: #facc15; margin-bottom: 20px;">
                    R$ 39,90 <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500;">/ mês</span>
                </div>

                <ul style="list-style: none; padding: 0; margin: 0 0 24px 0; font-size: 0.82rem; color: var(--text-secondary); line-height: 1.9;">
                    <li>✓ <strong>500 agendamentos / mês (alta capacidade)</strong></li>
                    <li>✓ <strong>Profissionais & cadeiras ilimitados</strong></li>
                    <li>✓ <strong>Serviços, clientes e histórico ilimitados</strong></li>
                    <li>✓ <strong>Google Calendar Multi-agendas (por profissional)</strong></li>
                    <li>✓ <strong>Link curto 4u.ia.br + QR Code Balcão VIP</strong></li>
                    <li>✓ <strong>Reagendamento inteligente com reposição de vaga</strong></li>
                    <li>✓ Lembretes automáticos 2h e 15m antes</li>
                    <li>✓ <strong>Financeiro completo com comissões por barbeiro</strong></li>
                    <li>✓ <strong>Escala flexível: folgas, férias e turnos</strong></li>
                    <li>✓ Múltiplos calendários e unidades/filiais</li>
                    <li>✓ <strong>Recorrência Automática (Cartão/PIX)</strong></li>
                    <li>✓ <strong>Suporte Prioritário VIP 4U.IA.BR</strong></li>
                </ul>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <button type="button" class="btn-primary" style="width: 100%; padding: 11px; font-weight: 800; background: linear-gradient(135deg, #facc15, #eab308); color: #000; font-size: 0.88rem;" onclick="iniciarAssinaturaRecorrente('plus')">
                    🔄 Assinar Recorrente (R$ 39,90/mês)
                </button>
                <button type="button" class="btn-emerald" style="width: 100%; padding: 9px; font-weight: 700; font-size: 0.82rem;" onclick="iniciarPix('plus')">
                    ⚡ Pagar 1 Mês Avulso via PIX
                </button>
            </div>
        </div>

    </div>
</div>

<!-- HISTÓRICO DE MENSALIDADES -->
<div class="card-box">
    <div class="card-box-header">
        <h2>Histórico de Pagamentos</h2>
    </div>
    <?php if (empty($invoices)): ?>
        <p style="font-size: 0.85rem; color: var(--text-muted);">Nenhum pagamento registrado ainda.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Fatura</th>
                        <th>Valor</th>
                        <th>Data</th>
                        <th>Método</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td>#<?= $inv['id'] ?></td>
                            <td><strong style="color: #fff;">R$ <?= number_format((float)$inv['amount'], 2, ',', '.') ?></strong></td>
                            <td><?= $inv['paid_at'] ? date('d/m/Y H:i', strtotime($inv['paid_at'])) : date('d/m/Y', strtotime($inv['created_at'])) ?></td>
                            <td>
                                <?php if ($inv['payment_method'] === 'auto_recurring'): ?>
                                    <span style="color: #38bdf8; font-weight: 700;">🔄 Assinatura Automática MP</span>
                                <?php else: ?>
                                    <span>⚡ PIX Mercado Pago</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($inv['status'] === 'paid'): ?>
                                    <span class="badge-status badge-confirmed">✓ PAGO & APROVADO</span>
                                <?php else: ?>
                                    <span class="badge-status badge-pending">PENDENTE</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL PIX MERCADO PAGO -->
<div id="modalPixMP" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 99999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 24px; width: 100%; max-width: 480px; padding: 32px; box-shadow: 0 25px 50px rgba(0,0,0,0.8); text-align: center;">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 24px;">⚡</span>
                <span style="font-size: 0.95rem; font-weight: 800; color: #fff;">PIX MERCADO PAGO</span>
            </div>
            <button type="button" onclick="fecharModalPix()" style="background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer;">✕</button>
        </div>

        <div id="pixLoading" style="padding: 40px 0;">
            <div style="font-size: 32px; animation: spin 1s infinite linear;">⏳</div>
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-top: 14px;">Gerando seu QR Code PIX oficial no Mercado Pago...</p>
        </div>

        <div id="pixContent" style="display: none;">
            <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 4px;">Assinatura AGENDOU: <strong id="pixPlanLabel" style="color: #fff;"></strong></div>
            <div style="font-size: 1.8rem; font-weight: 900; color: #facc15; margin-bottom: 16px;" id="pixAmountLabel"></div>

            <div style="background: #fff; padding: 16px; border-radius: 16px; display: inline-block; margin-bottom: 16px;">
                <img id="pixQrImg" src="" alt="QR Code PIX" style="width: 200px; height: 200px; display: block;">
            </div>

            <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 12px;">
                Abra o app do seu banco, escolha <strong>Pagar via Pix &gt; Ler QR Code</strong> ou use o código Copia e Cola:
            </p>

            <div style="position: relative; margin-bottom: 16px;">
                <input type="text" id="pixCopiaCola" readonly style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 12px; color: #fff; font-size: 0.75rem; font-family: monospace;">
            </div>

            <button type="button" class="btn-emerald" onclick="copiarPixCode()" style="width: 100%; padding: 12px; font-weight: 800; margin-bottom: 14px;">
                📋 COPIAR CÓDIGO PIX
            </button>
            <div id="copiedSuccess" style="display: none; color: var(--primary); font-size: 0.8rem; font-weight: 700; margin-bottom: 10px;">✓ Código PIX Copiado com sucesso!</div>

            <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 10px; padding: 10px; font-size: 0.78rem; color: var(--primary); display: flex; align-items: center; justify-content: center; gap: 8px;">
                <span style="animation: pulse 1.5s infinite;">🟢</span>
                <span>Aguardando pagamento... Liberação automática imediata.</span>
            </div>
        </div>

        <div id="pixApproved" style="display: none; padding: 30px 0;">
            <div style="font-size: 54px; margin-bottom: 12px;">🎉</div>
            <h3 style="font-size: 1.4rem; color: var(--primary); font-weight: 800; margin-bottom: 8px;">Pagamento Aprovado!</h3>
            <p style="font-size: 0.9rem; color: #fff; margin-bottom: 20px;" id="approvedMsg">
                Seu plano foi ativado com sucesso por mais 30 dias.
            </p>
            <button type="button" class="btn-primary" onclick="window.location.reload()" style="padding: 12px 24px; font-weight: 800;">
                Continuar para o Painel →
            </button>
        </div>

    </div>
</div>

<!-- MODAL ASSINATURA RECORRENTE MERCADO PAGO -->
<div id="modalSubMP" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 99999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 24px; width: 100%; max-width: 480px; padding: 32px; box-shadow: 0 25px 50px rgba(0,0,0,0.8); text-align: center;">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 24px;">🔄</span>
                <span style="font-size: 0.95rem; font-weight: 800; color: #fff;">ASSINATURA RECORRENTE AUTOMÁTICA</span>
            </div>
            <button type="button" onclick="fecharModalSub()" style="background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer;">✕</button>
        </div>

        <div id="subLoading" style="padding: 40px 0;">
            <div style="font-size: 32px; animation: spin 1s infinite linear;">⏳</div>
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-top: 14px;">Preparando seu checkout seguro no Mercado Pago...</p>
        </div>

        <div id="subContent" style="display: none; text-align: left;">
            <div style="text-align: center; margin-bottom: 20px;">
                <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 4px;">Plano Selecionado: <strong id="subPlanLabel" style="color: #fff;"></strong></div>
                <div style="font-size: 2rem; font-weight: 900; color: #38bdf8; margin-bottom: 6px;" id="subAmountLabel"></div>
                <div style="font-size: 0.8rem; color: var(--text-muted);">Cobrança automática mensal no Cartão ou Saldo Mercado Pago</div>
            </div>

            <div style="background: rgba(14, 165, 233, 0.08); border: 1px solid rgba(14, 165, 233, 0.25); border-radius: 16px; padding: 16px; margin-bottom: 24px;">
                <div style="font-size: 0.8rem; font-weight: 800; color: #38bdf8; margin-bottom: 8px; text-transform: uppercase;">Por que ativar a recorrência?</div>
                <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.8rem; color: var(--text-secondary); line-height: 1.8;">
                    <li>✓ <strong>Sem bloqueios por esquecimento:</strong> sua agenda fica 100% ativa.</li>
                    <li>✓ <strong>Segurança Mercado Pago:</strong> dados protegidos e criptografados.</li>
                    <li>✓ <strong>Sem carência nem fidelidade:</strong> cancele quando quiser em 1 clique.</li>
                </ul>
            </div>

            <button type="button" id="btnIrCheckoutMP" class="btn-primary" style="width: 100%; padding: 14px; font-weight: 800; font-size: 0.95rem; background: linear-gradient(135deg, #0284c7, #0369a1); display: flex; align-items: center; justify-content: center; gap: 8px;">
                <span>Ir para Checkout Seguro Mercado Pago</span>
                <span>→</span>
            </button>
            <div style="text-align: center; margin-top: 10px;">
                <span style="font-size: 0.75rem; color: var(--text-muted);">Você será redirecionado para autorizar o débito seguro no Mercado Pago.</span>
            </div>
        </div>

    </div>
</div>

<script>
let pollInterval = null;

// ========================================================
// RECORRÊNCIA MERCADO PAGO (PREAPPROVAL)
// ========================================================
function iniciarAssinaturaRecorrente(plan) {
    const modal = document.getElementById('modalSubMP');
    modal.style.display = 'flex';
    document.getElementById('subLoading').style.display = 'block';
    document.getElementById('subContent').style.display = 'none';

    fetch('/app/agendou/api/mp_subscription.php?action=create&plan=' + encodeURIComponent(plan) + '&tenant_id=<?= $tenantId ?>')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.init_point) {
                document.getElementById('subLoading').style.display = 'none';
                document.getElementById('subContent').style.display = 'block';

                document.getElementById('subPlanLabel').innerText = 'Plano ' + data.plan;
                document.getElementById('subAmountLabel').innerText = 'R$ ' + data.amount_formatted + ' / mês';

                const btn = document.getElementById('btnIrCheckoutMP');
                btn.onclick = function() {
                    window.location.href = data.init_point;
                };
            } else {
                alert('Erro ao configurar assinatura: ' + (data.error || 'Tente novamente.'));
                fecharModalSub();
            }
        })
        .catch(err => {
            alert('Falha na comunicação com o servidor para assinatura.');
            fecharModalSub();
        });
}

function fecharModalSub() {
    document.getElementById('modalSubMP').style.display = 'none';
}

function verificarAssinatura() {
    fetch('/app/agendou/api/mp_subscription.php?action=check&tenant_id=<?= $tenantId ?>')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.status === 'authorized') {
                alert('✓ ' + data.message);
                window.location.href = '/app/agendou/admin/subscription.php';
            } else {
                alert('Status da assinatura: ' + (data.message || data.status || 'Pendente de aprovação'));
            }
        })
        .catch(() => {
            alert('Não foi possível verificar status da assinatura.');
        });
}

function cancelarAssinaturaRecorrente() {
    if (!confirm('Deseja realmente cancelar a renovação automática da sua assinatura?\n\nO seu sistema continuará ativo normalmente até o fim do período já pago, mas a partir do próximo mês o pagamento voltará a ser manual via PIX.')) {
        return;
    }

    fetch('/app/agendou/api/mp_subscription.php?action=cancel&tenant_id=<?= $tenantId ?>')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert('Erro ao cancelar: ' + (data.error || 'Tente novamente.'));
            }
        })
        .catch(() => {
            alert('Falha na comunicação com o servidor.');
        });
}

// Auto-check se acabou de retornar do checkout MP
<?php if ($returnedFromMP): ?>
window.addEventListener('DOMContentLoaded', () => {
    setTimeout(verificarAssinatura, 1200);
});
<?php endif; ?>

// ========================================================
// PIX MERCADO PAGO AVULSO
// ========================================================
function iniciarPix(plan) {
    const modal = document.getElementById('modalPixMP');
    modal.style.display = 'flex';
    document.getElementById('pixLoading').style.display = 'block';
    document.getElementById('pixContent').style.display = 'none';
    document.getElementById('pixApproved').style.display = 'none';

    fetch('/app/agendou/api/mp_pix.php?plan=' + encodeURIComponent(plan) + '&tenant_id=<?= $tenantId ?>')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('pixLoading').style.display = 'none';
                document.getElementById('pixContent').style.display = 'block';

                document.getElementById('pixPlanLabel').innerText = 'Plano ' + data.plan;
                document.getElementById('pixAmountLabel').innerText = 'R$ ' + data.amount_formatted;
                document.getElementById('pixQrImg').src = 'data:image/png;base64,' + data.qr_code_base64;
                document.getElementById('pixCopiaCola').value = data.qr_code;

                // Iniciar verificação automática de aprovação a cada 3 segundos
                iniciarPollingAprovacao(data.payment_id);
            } else {
                alert('Erro ao gerar PIX: ' + (data.error || 'Tente novamente.'));
                fecharModalPix();
            }
        })
        .catch(err => {
            alert('Falha na conexão com servidor ao gerar PIX.');
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

function iniciarPollingAprovacao(paymentId) {
    if (pollInterval) clearInterval(pollInterval);

    pollInterval = setInterval(() => {
        fetch('/app/agendou/api/mp_check.php?payment_id=' + paymentId)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'approved') {
                    clearInterval(pollInterval);
                    document.getElementById('pixContent').style.display = 'none';
                    document.getElementById('pixApproved').style.display = 'block';
                    if (data.message) {
                        document.getElementById('approvedMsg').innerText = data.message;
                    }
                    setTimeout(() => {
                        window.location.reload();
                    }, 4000);
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

<?php require_once __DIR__ . '/footer.php'; ?>
