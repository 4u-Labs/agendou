<?php
// ========================================================
// AGENDOU - Services Management (CRUD)
// ========================================================

$pageTitle = 'Serviços';
$activeNav = 'services';
require_once __DIR__ . '/header.php';

$pdo = Database::getConnection();
$msg = '';

// Handle Create / Update / Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? 'Geral');
        $price = (float)str_replace(',', '.', $_POST['price'] ?? 0);
        $duration = (int)($_POST['duration_minutes'] ?? 30);
        $description = trim($_POST['description'] ?? '');

        if ($name && $price > 0 && $duration > 0) {
            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO services (tenant_id, name, category, price, duration_minutes, description, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([$tenantId, $name, $category, $price, $duration, $description]);
                $msg = 'Serviço cadastrado com sucesso!';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE services 
                    SET name = ?, category = ?, price = ?, duration_minutes = ?, description = ?
                    WHERE id = ? AND tenant_id = ?
                ");
                $stmt->execute([$name, $category, $price, $duration, $description, $id, $tenantId]);
                $msg = 'Serviço atualizado com sucesso!';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE services SET status = 'inactive' WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenantId]);
        $msg = 'Serviço desativado.';
    }
}

// Fetch all services
$stmt = $pdo->prepare("SELECT * FROM services WHERE tenant_id = ? AND status = 'active' ORDER BY category ASC, price ASC");
$stmt->execute([$tenantId]);
$services = $stmt->fetchAll();
?>

<div class="content-header">
    <div>
        <h1>Catálogo de Serviços</h1>
        <p>Cadastre valores e durações para o cálculo automático de horários livres.</p>
    </div>
    <button class="btn-emerald" onclick="openServiceModal()">+ Novo Serviço</button>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--primary); padding: 12px 16px; border-radius: var(--radius-md); font-size: 0.85rem; margin-bottom: 20px;">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<div class="card-box">
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Nome do Serviço</th>
                    <th>Categoria</th>
                    <th>Duração</th>
                    <th>Preço</th>
                    <th>Descrição</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($services)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 30px;">Nenhum serviço cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($services as $s): ?>
                        <tr>
                            <td><strong style="color: #fff;"><?= htmlspecialchars($s['name']) ?></strong></td>
                            <td><span class="badge-status badge-confirmed"><?= htmlspecialchars($s['category'] ?? 'Geral') ?></span></td>
                            <td><?= (int)$s['duration_minutes'] ?> minutos</td>
                            <td><strong style="color: var(--primary);">R$ <?= number_format($s['price'], 2, ',', '.') ?></strong></td>
                            <td style="max-width: 250px; font-size: 0.78rem;"><?= htmlspecialchars($s['description'] ?? '--') ?></td>
                            <td>
                                <button class="btn-secondary" style="padding: 4px 8px; font-size: 0.72rem;" onclick='editService(<?= json_encode($s) ?>)'>✏️ Editar</button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Deseja desativar este serviço?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn-secondary" style="padding: 4px 8px; font-size: 0.72rem; color: var(--red);">🗑️</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Service Form -->
<div id="serviceModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 100; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-box" style="width: 100%; max-width: 480px; position: relative; background: #0c1017;">
        <h2 id="serviceModalTitle" style="margin-bottom: 18px; color: #fff; font-size: 1.2rem;">Cadastrar Serviço</h2>
        <form method="POST">
            <input type="hidden" name="action" id="srvAction" value="create">
            <input type="hidden" name="id" id="srvId" value="">

            <div class="admin-form-group">
                <label class="admin-label">Nome do Serviço *</label>
                <input type="text" name="name" id="srvName" class="admin-input" placeholder="Ex: Corte Masculino" required>
            </div>

            <div class="form-grid-2">
                <div class="admin-form-group">
                    <label class="admin-label">Preço (R$) *</label>
                    <input type="number" step="0.01" name="price" id="srvPrice" class="admin-input" placeholder="40.00" required>
                </div>
                <div class="admin-form-group">
                    <label class="admin-label">Duração (Minutos) *</label>
                    <input type="number" step="5" name="duration_minutes" id="srvDuration" class="admin-input" placeholder="30" required>
                </div>
            </div>

            <div class="admin-form-group">
                <label class="admin-label">Categoria</label>
                <input type="text" name="category" id="srvCategory" class="admin-input" placeholder="Ex: Cabelo, Barba, Estética">
            </div>

            <div class="admin-form-group">
                <label class="admin-label">Descrição</label>
                <textarea name="description" id="srvDesc" class="admin-input" rows="2" placeholder="Detalhes do que está incluso no procedimento..."></textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn-secondary" onclick="closeServiceModal()">Cancelar</button>
                <button type="submit" class="btn-emerald">Salvar Serviço</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openServiceModal() {
        document.getElementById('srvAction').value = 'create';
        document.getElementById('srvId').value = '';
        document.getElementById('srvName').value = '';
        document.getElementById('srvPrice').value = '';
        document.getElementById('srvDuration').value = '30';
        document.getElementById('srvCategory').value = '';
        document.getElementById('srvDesc').value = '';
        document.getElementById('serviceModalTitle').textContent = 'Cadastrar Serviço';
        document.getElementById('serviceModal').style.display = 'flex';
    }

    function editService(s) {
        document.getElementById('srvAction').value = 'update';
        document.getElementById('srvId').value = s.id;
        document.getElementById('srvName').value = s.name;
        document.getElementById('srvPrice').value = s.price;
        document.getElementById('srvDuration').value = s.duration_minutes;
        document.getElementById('srvCategory').value = s.category || '';
        document.getElementById('srvDesc').value = s.description || '';
        document.getElementById('serviceModalTitle').textContent = 'Editar Serviço';
        document.getElementById('serviceModal').style.display = 'flex';
    }

    function closeServiceModal() {
        document.getElementById('serviceModal').style.display = 'none';
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
