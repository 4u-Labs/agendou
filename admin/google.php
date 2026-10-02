<?php
// ========================================================
// AGENDOU - Google Calendar OAuth 2.0 Center
// ========================================================

$pageTitle = 'Google Calendar';
$activeNav = 'google';
require_once __DIR__ . '/header.php';

$pdo = Database::getConnection();
$config = require __DIR__ . '/../config/config.php';
$googleCfg = $config['google'];

// Handle Disconnect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect_google'])) {
    $stmt = $pdo->prepare("DELETE FROM google_integrations WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    header("Location: /app/agendou/admin/google.php?disconnected=1");
    exit;
}

// Fetch integration status
$stmt = $pdo->prepare("SELECT * FROM google_integrations WHERE tenant_id = ?");
$stmt->execute([$tenantId]);
$integration = $stmt->fetch();
$isConnected = $integration && !empty($integration['access_token']) && $integration['sync_enabled'];

// Build OAuth URL
$oauthParams = [
    'client_id' => $googleCfg['client_id'],
    'redirect_uri' => $googleCfg['redirect_uri'],
    'response_type' => 'code',
    'scope' => implode(' ', $googleCfg['scopes']),
    'access_type' => 'offline',
    'prompt' => 'consent select_account',
    'state' => (string)$tenantId
];
$oauthUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($oauthParams);
?>

<div class="content-header">
    <div>
        <h1>Integração Oficial com Google Calendar</h1>
        <p>Sincronização bidirecional em tempo real utilizando a API oficial do Google.</p>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--primary); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.85rem; margin-bottom: 24px;">
        ✓ Google Calendar conectado com sucesso! Seus agendamentos já estão sincronizando automaticamente.
    </div>
<?php elseif (isset($_GET['disconnected'])): ?>
    <div style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); color: var(--orange); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.85rem; margin-bottom: 24px;">
        Google Calendar desconectado deste estabelecimento.
    </div>
<?php elseif (isset($_GET['error'])): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: var(--red); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.85rem; margin-bottom: 24px;">
        ⚠️ <?= htmlspecialchars($_GET['error']) ?>
    </div>
<?php endif; ?>

<div class="card-box" style="border-color: <?= $isConnected ? 'rgba(16, 185, 129, 0.3)' : 'rgba(255, 255, 255, 0.1)' ?>;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 56px; height: 56px; border-radius: 16px; background: rgba(66, 133, 244, 0.1); border: 1px solid rgba(66, 133, 244, 0.3); display: flex; align-items: center; justify-content: center; font-size: 28px;">
                📅
            </div>
            <div>
                <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 4px;">Status da Conexão</h2>
                <?php if ($isConnected): ?>
                    <span class="badge-status badge-confirmed" style="font-size: 0.8rem;">
                        ✓ CONECTADO: <?= htmlspecialchars($integration['email'] ?: 'Conta Google') ?>
                    </span>
                <?php else: ?>
                    <span class="badge-status badge-cancelled" style="font-size: 0.8rem;">
                        ✕ NÃO CONECTADO
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <?php if ($isConnected): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Deseja realmente desconectar o Google Calendar?');">
                    <input type="hidden" name="disconnect_google" value="1">
                    <button type="submit" class="btn-secondary" style="color: var(--red);">Desconectar Conta Google</button>
                </form>
            <?php else: ?>
                <a href="<?= htmlspecialchars($oauthUrl) ?>" class="btn-emerald" style="padding: 12px 22px; font-size: 0.9rem;">
                    <span>CONECTAR GOOGLE AGENDA</span>
                    <span>→</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div style="border-top: 1px solid var(--border-color); padding-top: 20px;">
        <h3 style="font-size: 1rem; color: #fff; margin-bottom: 12px;">Como funciona a sincronização inteligente:</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 16px;">
                <h4 style="font-size: 0.9rem; color: var(--primary); margin-bottom: 6px;">1. Entrada Automática de Agendamentos</h4>
                <p style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.5;">
                    Sempre que um cliente confirma um horário no site, o evento é criado instantaneamente no seu Google Calendar com o nome do cliente, serviço, profissional e telefone.
                </p>
            </div>
            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 16px;">
                <h4 style="font-size: 0.9rem; color: var(--cyan); margin-bottom: 6px;">2. Bloqueio de Horários Pessoais</h4>
                <p style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.5;">
                    Se você criar um compromisso particular diretamente no seu aplicativo do Google Agenda no celular, aquele horário ficará automaticamente indisponível para novos agendamentos no sistema.
                </p>
            </div>
            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 16px;">
                <h4 style="font-size: 0.9rem; color: #facc15; margin-bottom: 6px;">3. Cancelamento em Tempo Real</h4>
                <p style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.5;">
                    Se o cliente ou você cancelar o agendamento, o evento no Google Calendar é removido ou atualizado automaticamente sem deixar resíduos.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
