<?php
// ========================================================
// AGENDOU - Professionals Management (CRUD)
// ========================================================

$pageTitle = 'Profissionais';
$activeNav = 'professionals';
require_once __DIR__ . '/header.php';

$pdo = Database::getConnection();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $specialty = trim($_POST['specialty'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $avatarUrl = trim($_POST['avatar_url'] ?? '');

        if ($name) {
            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO professionals (tenant_id, name, specialty, email, phone, bio, avatar_url, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([$tenantId, $name, $specialty, $email, $phone, $bio, $avatarUrl]);
                $newProfId = (int)$pdo->lastInsertId();

                // Auto-link to all services by default
                $allServices = $pdo->query("SELECT id FROM services WHERE tenant_id = $tenantId")->fetchAll(PDO::FETCH_COLUMN);
                $stmtLink = $pdo->prepare("INSERT OR IGNORE INTO professional_services (tenant_id, professional_id, service_id) VALUES (?, ?, ?)");
                foreach ($allServices as $sId) {
                    $stmtLink->execute([$tenantId, $newProfId, $sId]);
                }

                $msg = 'Profissional cadastrado com sucesso!';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE professionals 
                    SET name = ?, specialty = ?, email = ?, phone = ?, bio = ?, avatar_url = ?
                    WHERE id = ? AND tenant_id = ?
                ");
                $stmt->execute([$name, $specialty, $email, $phone, $bio, $avatarUrl, $id, $tenantId]);
                $msg = 'Profissional atualizado com sucesso!';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE professionals SET status = 'inactive' WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenantId]);
        $msg = 'Profissional desativado.';
    }
}

// Fetch all active professionals
$stmt = $pdo->prepare("SELECT * FROM professionals WHERE tenant_id = ? AND status = 'active' ORDER BY name ASC");
$stmt->execute([$tenantId]);
$professionals = $stmt->fetchAll();
?>

<div class="content-header">
    <div>
        <h1>Equipe & Profissionais</h1>
        <p>Cadastre os membros da equipe que atendem os clientes do estabelecimento.</p>
    </div>
    <button class="btn-emerald" onclick="openProfModal()">+ Novo Profissional</button>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--primary); padding: 12px 16px; border-radius: var(--radius-md); font-size: 0.85rem; margin-bottom: 20px;">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 18px;">
    <?php foreach ($professionals as $p): ?>
        <div class="card-box" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 12px;">
                    <div style="width: 52px; height: 52px; border-radius: 50%; background: #1e293b; overflow: hidden; border: 2px solid var(--border-color); display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                        <?php if (!empty($p['avatar_url'])): ?>
                            <img src="<?= htmlspecialchars($p['avatar_url']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <span><?= strtoupper(substr($p['name'], 0, 1)) ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3 style="font-size: 1.1rem; color: #fff; margin-bottom: 2px;"><?= htmlspecialchars($p['name']) ?></h3>
                        <span class="badge-status badge-confirmed"><?= htmlspecialchars($p['specialty'] ?? 'Profissional') ?></span>
                    </div>
                </div>
                <p style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.4; margin-bottom: 14px;">
                    <?= htmlspecialchars($p['bio'] ?? 'Sem biografia informada.') ?>
                </p>
                <div style="font-size: 0.78rem; color: var(--text-secondary); margin-bottom: 14px;">
                    📱 WhatsApp: <?= htmlspecialchars($p['phone'] ?: '--') ?><br>
                    ✉️ E-mail: <?= htmlspecialchars($p['email'] ?: '--') ?>
                </div>
            </div>

            <div style="display: flex; gap: 8px; border-top: 1px solid var(--border-color); padding-top: 14px;">
                <button class="btn-secondary" style="font-size: 0.78rem; padding: 6px 12px;" onclick='editProf(<?= json_encode($p) ?>)'>✏️ Editar</button>
                <form method="POST" style="margin-left: auto;" onsubmit="return confirm('Deseja desativar este profissional?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn-secondary" style="font-size: 0.78rem; padding: 6px 12px; color: var(--red);">🗑️</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal Form -->
<div id="profModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 100; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-box" style="width: 100%; max-width: 480px; position: relative; background: #0c1017;">
        <h2 id="profModalTitle" style="margin-bottom: 18px; color: #fff; font-size: 1.2rem;">Cadastrar Profissional</h2>
        <form method="POST">
            <input type="hidden" name="action" id="profAction" value="create">
            <input type="hidden" name="id" id="profId" value="">

            <div class="admin-form-group">
                <label class="admin-label">Nome Completo *</label>
                <input type="text" name="name" id="profName" class="admin-input" placeholder="Ex: Pedro Mendes" required>
            </div>

            <div class="admin-form-group">
                <label class="admin-label">Especialidade / Cargo</label>
                <input type="text" name="specialty" id="profSpecialty" class="admin-input" placeholder="Ex: Master Barber & Fade">
            </div>

            <div class="form-grid-2">
                <div class="admin-form-group">
                    <label class="admin-label">Telefone / WhatsApp</label>
                    <input type="tel" name="phone" id="profPhone" class="admin-input" placeholder="(38) 99999-9999">
                </div>
                <div class="admin-form-group">
                    <label class="admin-label">E-mail</label>
                    <input type="email" name="email" id="profEmail" class="admin-input" placeholder="pedro@exemplo.com">
                </div>
            </div>

            <div class="admin-form-group">
                <label class="admin-label">URL da Foto de Perfil</label>
                <input type="url" name="avatar_url" id="profAvatar" class="admin-input" placeholder="https://exemplo.com/foto.jpg">
            </div>

            <div class="admin-form-group">
                <label class="admin-label">Biografia Curta</label>
                <textarea name="bio" id="profBio" class="admin-input" rows="2" placeholder="Experiência, formação e especialidades..."></textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn-secondary" onclick="closeProfModal()">Cancelar</button>
                <button type="submit" class="btn-emerald">Salvar Profissional</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openProfModal() {
        document.getElementById('profAction').value = 'create';
        document.getElementById('profId').value = '';
        document.getElementById('profName').value = '';
        document.getElementById('profSpecialty').value = '';
        document.getElementById('profPhone').value = '';
        document.getElementById('profEmail').value = '';
        document.getElementById('profAvatar').value = '';
        document.getElementById('profBio').value = '';
        document.getElementById('profModalTitle').textContent = 'Cadastrar Profissional';
        document.getElementById('profModal').style.display = 'flex';
    }

    function editProf(p) {
        document.getElementById('profAction').value = 'update';
        document.getElementById('profId').value = p.id;
        document.getElementById('profName').value = p.name;
        document.getElementById('profSpecialty').value = p.specialty || '';
        document.getElementById('profPhone').value = p.phone || '';
        document.getElementById('profEmail').value = p.email || '';
        document.getElementById('profAvatar').value = p.avatar_url || '';
        document.getElementById('profBio').value = p.bio || '';
        document.getElementById('profModalTitle').textContent = 'Editar Profissional';
        document.getElementById('profModal').style.display = 'flex';
    }

    function closeProfModal() {
        document.getElementById('profModal').style.display = 'none';
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
