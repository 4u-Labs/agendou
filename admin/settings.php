<?php
// ========================================================
// AGENDOU - Tenant Settings & QR Code Generator
// ========================================================

$pageTitle = 'Configurações & QR Code';
$activeNav = 'settings';
require_once __DIR__ . '/header.php';

$pdo = Database::getConnection();
$msg = '';

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
            $msg = 'Este link/slug já está em uso por outro estabelecimento. Escolha outro.';
        } else {
            $stmtUp = $pdo->prepare("
                UPDATE tenants 
                SET name = ?, slug = ?, whatsapp = ?, category = ?, address = ?, city = ?, state = ?, description = ?
                WHERE id = ?
            ");
            $stmtUp->execute([$name, $slug, $whatsapp, $category, $address, $city, $state, $description, $tenantId]);
            $msg = 'Configurações atualizadas com sucesso!';
            // Refresh tenant
            $currentTenant = $pdo->query("SELECT * FROM tenants WHERE id = $tenantId")->fetch();
        }
    }
}

$publicUrl = "https://4u.ia.br/app/agendou/?slug=" . urlencode($currentTenant['slug']);
$qrCodeApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($publicUrl);
?>

<div class="content-header">
    <div>
        <h1>Configurações do Estabelecimento & QR Code</h1>
        <p>Personalize os dados da sua empresa e baixe o QR Code para colocar no balcão.</p>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--primary); padding: 12px 16px; border-radius: var(--radius-md); font-size: 0.85rem; margin-bottom: 20px;">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    <!-- Left: Settings Form -->
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
                    <small style="font-size: 0.72rem; color: var(--text-muted);">Ex: <code>pedromendes</code> gera <code>4u.ia.br/app/agendou/pedromendes</code></small>
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
                <button type="submit" class="btn-emerald">Salvar Alterações</button>
            </div>
        </form>
    </div>

    <!-- Right: QR Code & Bio Link Widget -->
    <div>
        <div class="card-box" style="text-align: center; padding: 24px;">
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
