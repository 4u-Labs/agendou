<?php
// ========================================================
// AGENDOU - Tenant Settings & QR Code Generator
// ========================================================

$pageTitle = 'Configurações & Acesso';
$activeNav = 'settings';
require_once __DIR__ . '/header.php';

$pdo = Database::getConnection();
$msg = '';
$errMsg = '';

// Identificar usuário administrador do estabelecimento
$stmtOwner = $pdo->prepare("SELECT * FROM users WHERE tenant_id = ? AND role = 'tenant_admin' LIMIT 1");
$stmtOwner->execute([$tenantId]);
$ownerUser = $stmtOwner->fetch();

if (!$ownerUser && ($currentUser['role'] ?? '') === 'tenant_admin') {
    $ownerUser = $currentUser;
}

// 1. Salvar Dados do Negócio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $name = trim($_POST['name'] ?? '');
    $slug = strtolower(preg_replace('/[^a-z0-9_-]/', '', trim($_POST['slug'] ?? '')));
    $whatsapp = preg_replace('/[^0-9]/', '', $_POST['whatsapp'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name && $slug && $whatsapp) {
        // Check if slug is unique
        $stmtChk = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
        $stmtChk->execute([$slug, $tenantId]);
        if ($stmtChk->fetch()) {
            $errMsg = 'Este link/slug já está em uso por outro estabelecimento. Escolha outro.';
        } else {
            $stmtUp = $pdo->prepare("
                UPDATE tenants 
                SET name = ?, slug = ?, whatsapp = ?, category = ?, address = ?, city = ?, state = ?, description = ?
                WHERE id = ?
            ");
            $stmtUp->execute([$name, $slug, $whatsapp, $category, $address, $city, $state, $description, $tenantId]);
            $msg = 'Configurações do negócio atualizadas com sucesso!';
            // Refresh tenant e atualizar link curto
            $currentTenant = $pdo->query("SELECT * FROM tenants WHERE id = $tenantId")->fetch();
            require_once __DIR__ . '/../app/Services/UrlShortenerService.php';
            UrlShortenerService::ensureTenantShortLink($currentTenant);
        }
    } else {
        $errMsg = 'Preencha os campos obrigatórios do negócio (Nome, Link e WhatsApp).';
    }
}

// 2. Salvar E-mail e Senha de Acesso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_credentials'])) {
    $newEmail = strtolower(trim($_POST['user_email'] ?? ''));
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $errMsg = 'Por favor, informe um endereço de e-mail válido.';
    } elseif (!$ownerUser) {
        $errMsg = 'Usuário administrador deste estabelecimento não foi localizado no sistema.';
    } else {
        // Verificar se outro usuário já usa esse e-mail
        $stmtChk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmtChk->execute([$newEmail, (int)$ownerUser['id']]);
        if ($stmtChk->fetch()) {
            $errMsg = 'Este e-mail já está sendo utilizado por outro usuário no sistema.';
        } else {
            // Verificar alteração de senha
            if (!empty($newPassword)) {
                if (strlen($newPassword) < 6) {
                    $errMsg = 'A nova senha deve ter no mínimo 6 caracteres.';
                } elseif ($newPassword !== $confirmPassword) {
                    $errMsg = 'A confirmação de senha não confere com a nova senha digitada.';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $stmtUpUser = $pdo->prepare("UPDATE users SET email = ?, password = ? WHERE id = ?");
                    $stmtUpUser->execute([$newEmail, $hash, (int)$ownerUser['id']]);
                    $msg = 'E-mail e senha de acesso atualizados com sucesso!';
                }
            } else {
                $stmtUpUser = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                $stmtUpUser->execute([$newEmail, (int)$ownerUser['id']]);
                $msg = 'E-mail de acesso atualizado com sucesso!';
            }

            if (empty($errMsg)) {
                // Atualizar dados em memória
                $stmtOwner = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmtOwner->execute([(int)$ownerUser['id']]);
                $ownerUser = $stmtOwner->fetch();
                if (($currentUser['id'] ?? 0) === ($ownerUser['id'] ?? 0)) {
                    $currentUser['email'] = $ownerUser['email'];
                }
            }
        }
    }
}

require_once __DIR__ . '/../app/Services/UrlShortenerService.php';
$shortLinkUrl = UrlShortenerService::ensureTenantShortLink($currentTenant);
$publicUrl = $shortLinkUrl;
$qrCodeApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($publicUrl);
?>

<style>
    .settings-grid-layout {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 24px;
        align-items: start;
    }
    @media (max-width: 900px) {
        .settings-grid-layout {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="content-header">
    <div>
        <h1>Configurações do Estabelecimento & Acesso</h1>
        <p>Personalize os dados da sua empresa, credenciais de login e baixe o QR Code do balcão.</p>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #10b981; padding: 12px 16px; border-radius: var(--radius-md); font-size: 0.85rem; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 1.1rem;">✅</span> <strong><?= htmlspecialchars($msg) ?></strong>
    </div>
<?php endif; ?>

<?php if ($errMsg): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 12px 16px; border-radius: var(--radius-md); font-size: 0.85rem; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 1.1rem;">⚠️</span> <strong><?= htmlspecialchars($errMsg) ?></strong>
    </div>
<?php endif; ?>

<div class="settings-grid-layout">
    <!-- Left Column: Business Settings & Login Credentials -->
    <div>
        <!-- Card 1: Business Settings -->
        <div class="card-box">
            <h2 style="font-size: 1.15rem; color: #fff; margin-bottom: 18px;">Dados do Negócio</h2>
            <form method="POST">
                <input type="hidden" name="save_settings" value="1">

                <div class="admin-form-group">
                    <label class="admin-label">Nome Comercial do Estabelecimento *</label>
                    <input type="text" name="name" class="admin-input" value="<?= htmlspecialchars($currentTenant['name']) ?>" required>
                </div>

                <div class="form-grid-2">
                    <div class="admin-form-group">
                        <label class="admin-label">Link Personalizado (Slug da URL) *</label>
                        <input type="text" name="slug" class="admin-input" value="<?= htmlspecialchars($currentTenant['slug']) ?>" required>
                        <small style="font-size: 0.72rem; color: var(--text-muted);">Ex: <code>barbearia1</code> gera <code>4u.ia.br/barbearia1</code></small>
                    </div>

                    <div class="admin-form-group">
                        <label class="admin-label">WhatsApp Comercial *</label>
                        <input type="text" name="whatsapp" class="admin-input" value="<?= htmlspecialchars($currentTenant['whatsapp']) ?>" required>
                    </div>
                </div>

                <div class="admin-form-group">
                    <label class="admin-label">Segmento / Categoria</label>
                    <input type="text" name="category" class="admin-input" value="<?= htmlspecialchars($currentTenant['category'] ?? '') ?>" placeholder="Ex: Barbearia, Salão de Beleza, Clínica">
                </div>

                <div class="form-grid-2">
                    <div class="admin-form-group">
                        <label class="admin-label">Endereço Completo</label>
                        <input type="text" name="address" class="admin-input" value="<?= htmlspecialchars($currentTenant['address'] ?? '') ?>" placeholder="Rua, número e bairro">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-label">Cidade / Estado</label>
                        <div style="display: grid; grid-template-columns: 3fr 1fr; gap: 8px;">
                            <input type="text" name="city" class="admin-input" value="<?= htmlspecialchars($currentTenant['city'] ?? '') ?>" placeholder="Cidade">
                            <input type="text" name="state" class="admin-input" value="<?= htmlspecialchars($currentTenant['state'] ?? '') ?>" placeholder="UF" maxlength="2">
                        </div>
                    </div>
                </div>

                <div class="admin-form-group">
                    <label class="admin-label">Descrição / Apresentação</label>
                    <textarea name="description" class="admin-input" rows="3"><?= htmlspecialchars($currentTenant['description'] ?? '') ?></textarea>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="submit" class="btn-emerald">Salvar Alterações do Negócio</button>
                </div>
            </form>
        </div>

        <!-- Card 2: Login Credentials (Email & Password) -->
        <div class="card-box">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                <div>
                    <h2 style="font-size: 1.15rem; color: #fff; margin: 0 0 4px;">🔐 Acesso ao Painel (E-mail & Senha)</h2>
                    <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Atualize seu e-mail de login e crie uma nova senha de acesso.</p>
                </div>
                <?php if ($ownerUser): ?>
                    <span style="font-size: 0.75rem; background: rgba(56, 189, 248, 0.12); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); padding: 4px 10px; border-radius: 8px; font-weight: 700;">
                        👤 <?= htmlspecialchars($ownerUser['name'] ?? 'Dono do Estabelecimento') ?>
                    </span>
                <?php endif; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="save_credentials" value="1">

                <div class="admin-form-group">
                    <label class="admin-label">E-mail de Acesso (Login) *</label>
                    <input type="email" name="user_email" class="admin-input" value="<?= htmlspecialchars($ownerUser['email'] ?? '') ?>" required placeholder="seuemail@exemplo.com">
                    <small style="font-size: 0.72rem; color: var(--text-muted);">E-mail utilizado para fazer login no sistema em <code>4u.ia.br/app/agendou/admin/login.php</code></small>
                </div>

                <div class="form-grid-2">
                    <div class="admin-form-group">
                        <label class="admin-label">Nova Senha</label>
                        <input type="password" name="new_password" class="admin-input" placeholder="•••••••• (deixe vazio para manter atual)" minlength="6">
                        <small style="font-size: 0.72rem; color: var(--text-muted);">Mínimo de 6 caracteres.</small>
                    </div>

                    <div class="admin-form-group">
                        <label class="admin-label">Confirmar Nova Senha</label>
                        <input type="password" name="confirm_password" class="admin-input" placeholder="•••••••• (repita a nova senha)" minlength="6">
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 18px; flex-wrap: wrap; gap: 12px;">
                    <span style="font-size: 0.75rem; color: #64748b;">
                        💡 Se desejar trocar apenas o e-mail, deixe os campos de senha em branco.
                    </span>
                    <button type="submit" class="btn-emerald">
                        Atualizar E-mail e Senha
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Right: QR Code & Bio Link Widget -->
    <div>
        <div class="card-box" style="text-align: center; padding: 24px; position: sticky; top: 90px;">
            <h3 style="font-size: 1.1rem; color: #fff; margin-bottom: 8px;">QR Code do Balcão</h3>
            <p style="font-size: 0.78rem; color: var(--text-muted); margin-bottom: 18px;">
                Imprima este código para colocar no seu balcão ou nas mesas de atendimento.
            </p>

            <div style="background: #fff; padding: 14px; border-radius: 16px; display: inline-block; margin-bottom: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.4);">
                <img src="<?= htmlspecialchars($qrCodeApiUrl) ?>" alt="QR Code de Agendamento" style="width: 180px; height: 180px; display: block;">
            </div>

            <p style="font-size: 0.8rem; font-weight: 700; color: #fff; margin-bottom: 12px; word-break: break-all;">
                <?= htmlspecialchars($publicUrl) ?>
            </p>

            <div style="display: flex; flex-direction: column; gap: 8px;">
                <a href="<?= htmlspecialchars($qrCodeApiUrl) ?>" download="qrcode_agendou.png" class="btn-emerald" style="justify-content: center;">
                    📥 Baixar QR Code para Imprimir
                </a>
                <a href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" class="btn-secondary" style="justify-content: center;">
                    🔗 Testar Link da Bio
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
