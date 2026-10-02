<?php
// ========================================================
// AGENDOU - Gestão de Clubes & Pacotes de Assinatura
// Planos Ilimitados + Pacotes de Créditos/Combos
// ========================================================

$pageTitle = 'Clubes & Pacotes';
$activeNav = 'pacotes';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../app/Services/UrlShortenerService.php';

$pdo = Database::getConnection();
$msgSuccess = '';
$msgError = '';

$shortLinkUrl = UrlShortenerService::ensureTenantShortLink($currentTenant);

// --- PROCESSAMENTO DE FORMULÁRIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Criar ou Editar Pacote / Plano
    if ($action === 'save_package') {
        $pkgId = (int)($_POST['package_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (float)str_replace(',', '.', $_POST['price'] ?? 0);
        $validityDays = max(1, (int)($_POST['validity_days'] ?? 30));
        $type = in_array($_POST['type'] ?? '', ['unlimited', 'credits']) ? $_POST['type'] : 'unlimited';
        
        $selectedServices = $_POST['services'] ?? [];
        $creditQuantities = $_POST['service_quantities'] ?? [];

        if (empty($name) || $price <= 0) {
            $msgError = 'Por favor, informe o nome e o valor do pacote.';
        } elseif (empty($selectedServices)) {
            $msgError = 'Selecione ao menos um serviço para incluir no pacote.';
        } else {
            // Montar JSON de regras dos serviços
            $rules = [];
            if ($type === 'unlimited') {
                $serviceIds = array_map('intval', (array)$selectedServices);
                $rules = [
                    'unlimited' => true,
                    'services' => $serviceIds,
                    'label' => 'Serviços Ilimitados no Mês'
                ];
            } else {
                $serviceCredits = [];
                $labelParts = [];
                foreach ($selectedServices as $sId) {
                    $sId = (int)$sId;
                    $qty = max(1, (int)($creditQuantities[$sId] ?? 1));
                    $serviceCredits[(string)$sId] = $qty;
                    
                    // Buscar nome do serviço para o rótulo
                    $stmtSN = $pdo->prepare("SELECT name FROM services WHERE id = ?");
                    $stmtSN->execute([$sId]);
                    $sName = $stmtSN->fetchColumn() ?: "Serviço #$sId";
                    $labelParts[] = "{$qty}x {$sName}";
                }
                $rules = [
                    'unlimited' => false,
                    'services' => $serviceCredits,
                    'label' => implode(' + ', $labelParts)
                ];
            }
            $rulesJson = json_encode($rules, JSON_UNESCAPED_UNICODE);

            if ($pkgId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE packages 
                    SET name = ?, description = ?, price = ?, validity_days = ?, type = ?, services_rules = ?
                    WHERE id = ? AND tenant_id = ?
                ");
                $stmt->execute([$name, $description, $price, $validityDays, $type, $rulesJson, $pkgId, $tenantId]);
                $msgSuccess = 'Pacote atualizado com sucesso!';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO packages (tenant_id, name, description, price, validity_days, type, services_rules, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$tenantId, $name, $description, $price, $validityDays, $type, $rulesJson]);
                $msgSuccess = 'Novo pacote criado com sucesso!';
            }
        }
    }

    // 2. Alternar Status do Pacote (Ativo / Inativo)
    elseif ($action === 'toggle_package_status') {
        $pkgId = (int)($_POST['package_id'] ?? 0);
        $newStatus = $_POST['new_status'] === 'active' ? 'active' : 'inactive';
        $stmt = $pdo->prepare("UPDATE packages SET status = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$newStatus, $pkgId, $tenantId]);
        $msgSuccess = 'Status do pacote alterado.';
    }

    // 3. Vender / Ativar Assinatura para um Cliente
    elseif ($action === 'subscribe_customer') {
        $pkgId = (int)($_POST['package_id'] ?? 0);
        $cId = (int)($_POST['customer_id'] ?? 0);
        $newCustName = trim($_POST['new_customer_name'] ?? '');
        $newCustPhone = preg_replace('/\D/', '', $_POST['new_customer_phone'] ?? '');
        $pricePaid = (float)str_replace(',', '.', $_POST['price_paid'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'pix');
        $startDate = trim($_POST['start_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');

        // Buscar detalhes do pacote
        $stmtP = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ?");
        $stmtP->execute([$pkgId, $tenantId]);
        $pkg = $stmtP->fetch();

        if (!$pkg) {
            $msgError = 'Pacote não encontrado.';
        } else {
            // Se informou novo cliente rápido
            if ($cId === 0 && !empty($newCustName) && !empty($newCustPhone)) {
                $stmtCheckC = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND whatsapp = ?");
                $stmtCheckC->execute([$tenantId, $newCustPhone]);
                $existingCId = $stmtCheckC->fetchColumn();

                if ($existingCId) {
                    $cId = (int)$existingCId;
                } else {
                    $stmtNewC = $pdo->prepare("INSERT INTO customers (tenant_id, name, whatsapp) VALUES (?, ?, ?)");
                    $stmtNewC->execute([$tenantId, $newCustName, $newCustPhone]);
                    $cId = (int)$pdo->lastInsertId();
                }
            }

            if ($cId <= 0) {
                $msgError = 'Selecione ou cadastre o cliente que está adquirindo o pacote.';
            } else {
                // Calcular validade
                $validityDays = (int)$pkg['validity_days'];
                $endDate = date('Y-m-d', strtotime("{$startDate} + {$validityDays} days"));

                try {
                    $pdo->beginTransaction();

                    // Salvar no customer_packages
                    $stmtSub = $pdo->prepare("
                        INSERT INTO customer_packages (
                            tenant_id, customer_id, package_id, status, price_paid, 
                            payment_method, start_date, end_date, usage_log, notes, created_at
                        ) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, '[]', ?, CURRENT_TIMESTAMP)
                    ");
                    $stmtSub->execute([$tenantId, $cId, $pkgId, $pricePaid, $paymentMethod, $startDate, $endDate, $notes]);
                    $subId = (int)$pdo->lastInsertId();

                    // Lançar automaticamente no Caixa / Financeiro!
                    $desc = "Assinatura " . $pkg['name'];
                    $stmtTrans = $pdo->prepare("
                        INSERT INTO financial_transactions (
                            tenant_id, customer_package_id, customer_id, type, description, 
                            amount, payment_method, transaction_date, created_at
                        ) VALUES (?, ?, ?, 'package', ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmtTrans->execute([$tenantId, $subId, $cId, $desc, $pricePaid, $paymentMethod, $startDate]);

                    $pdo->commit();
                    $msgSuccess = 'Assinatura ativada com sucesso e valor lançado automaticamente no Caixa!';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $msgError = 'Erro ao ativar pacote: ' . $e->getMessage();
                }
            }
        }
    }

    // 4. Renovar Assinatura Existente
    elseif ($action === 'renew_subscription') {
        $subId = (int)($_POST['sub_id'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'pix');
        
        $stmtSub = $pdo->prepare("
            SELECT cp.*, p.name as pkg_name, p.price as pkg_price, p.validity_days
            FROM customer_packages cp
            JOIN packages p ON p.id = cp.package_id
            WHERE cp.id = ? AND cp.tenant_id = ?
        ");
        $stmtSub->execute([$subId, $tenantId]);
        $sub = $stmtSub->fetch();

        if ($sub) {
            $baseDate = ($sub['end_date'] >= date('Y-m-d')) ? $sub['end_date'] : date('Y-m-d');
            $newEndDate = date('Y-m-d', strtotime("{$baseDate} + {$sub['validity_days']} days"));
            $renewPrice = (float)$sub['pkg_price'];

            try {
                $pdo->beginTransaction();

                $stmtUp = $pdo->prepare("
                    UPDATE customer_packages 
                    SET status = 'active', end_date = ?, price_paid = ?, payment_method = ?, usage_log = '[]'
                    WHERE id = ? AND tenant_id = ?
                ");
                $stmtUp->execute([$newEndDate, $renewPrice, $paymentMethod, $subId, $tenantId]);

                // Lança no financeiro a renovação
                $desc = "Renovação: " . $sub['pkg_name'];
                $stmtTrans = $pdo->prepare("
                    INSERT INTO financial_transactions (
                        tenant_id, customer_package_id, customer_id, type, description, 
                        amount, payment_method, transaction_date, created_at
                    ) VALUES (?, ?, ?, 'package', ?, ?, ?, CURRENT_DATE, CURRENT_TIMESTAMP)
                ");
                $stmtTrans->execute([$tenantId, $subId, $sub['customer_id'], $desc, $renewPrice, $paymentMethod]);

                $pdo->commit();
                $msgSuccess = 'Assinatura renovada por +' . $sub['validity_days'] . ' dias e valor lançado no caixa!';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msgError = 'Erro ao renovar: ' . $e->getMessage();
            }
        }
    }

    // 5. Cancelar Assinatura de Cliente
    elseif ($action === 'cancel_subscription') {
        $subId = (int)($_POST['sub_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE customer_packages SET status = 'cancelled' WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$subId, $tenantId]);
        $msgSuccess = 'Assinatura cancelada.';
    }
}

// --- DADOS PARA EXIBIÇÃO ---

// Pacotes criados pelo estabelecimento
$stmtPackages = $pdo->prepare("SELECT * FROM packages WHERE tenant_id = ? ORDER BY id DESC");
$stmtPackages->execute([$tenantId]);
$packages = $stmtPackages->fetchAll();

// Assinantes ativos e recentes
$stmtSubs = $pdo->prepare("
    SELECT cp.*, c.name as customer_name, c.whatsapp as customer_whatsapp, c.email as customer_email,
           p.name as package_name, p.type as package_type, p.services_rules
    FROM customer_packages cp
    JOIN customers c ON c.id = cp.customer_id
    JOIN packages p ON p.id = cp.package_id
    WHERE cp.tenant_id = ?
    ORDER BY cp.status ASC, cp.end_date DESC
");
$stmtSubs->execute([$tenantId]);
$subscribers = $stmtSubs->fetchAll();

// Catálogo de serviços ativos para montar o pacote
$stmtServices = $pdo->prepare("SELECT id, name, price, duration_minutes FROM services WHERE tenant_id = ? AND status = 'active' ORDER BY name ASC");
$stmtServices->execute([$tenantId]);
$allServices = $stmtServices->fetchAll();

// Clientes para o select
$stmtCustomers = $pdo->prepare("SELECT id, name, whatsapp FROM customers WHERE tenant_id = ? ORDER BY name ASC");
$stmtCustomers->execute([$tenantId]);
$allCustomers = $stmtCustomers->fetchAll();

// Métricas de Assinaturas
$totalActiveSubs = 0;
$mrrClube = 0;
foreach ($subscribers as $s) {
    if ($s['status'] === 'active' && $s['end_date'] >= date('Y-m-d')) {
        $totalActiveSubs++;
        $mrrClube += (float)$s['price_paid'];
    }
}
?>

<div class="content-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1>Clubes & Pacotes de Assinatura</h1>
        <p>Crie planos ilimitados ("Corte à vontade"), combos mensais e fidelize clientes com receita previsível.</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <button type="button" class="btn-primary" onclick="openNewPackageModal()" style="font-weight: 800; padding: 10px 18px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);">
            <span>➕ Criar Novo Pacote / Plano</span>
        </button>
        <button type="button" class="btn-emerald" onclick="openNewSubModal()" style="font-weight: 800; padding: 10px 18px; display: inline-flex; align-items: center; gap: 6px;">
            <span>🤝 Ativar Pacote para Cliente</span>
        </button>
    </div>
</div>

<?php if ($msgSuccess): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: var(--primary); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 20px;">
        ✓ <?= htmlspecialchars($msgSuccess) ?>
    </div>
<?php endif; ?>

<?php if ($msgError): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: var(--red); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 20px;">
        ✕ <?= htmlspecialchars($msgError) ?>
    </div>
<?php endif; ?>

<!-- Resumo dos Clubes -->
<div class="metrics-row" style="margin-bottom: 24px;">
    <div class="metric-box">
        <div class="metric-box-header">
            <span>Assinantes Ativos</span>
            <span style="color: var(--primary);">👑</span>
        </div>
        <div class="metric-big-val" style="color: var(--primary);"><?= $totalActiveSubs ?></div>
        <div class="metric-sub">Clientes com plano em vigor</div>
    </div>

    <div class="metric-box">
        <div class="metric-box-header">
            <span>Receita Recorrente dos Clubes</span>
            <span style="color: #facc15;">💰</span>
        </div>
        <div class="metric-big-val" style="color: #facc15;">R$ <?= number_format($mrrClube, 2, ',', '.') ?></div>
        <div class="metric-sub">Faturamento garantido dos pacotes</div>
    </div>

    <div class="metric-box">
        <div class="metric-box-header">
            <span>Modelos de Pacotes Criados</span>
            <span style="color: var(--cyan);">📦</span>
        </div>
        <div class="metric-big-val" style="color: var(--cyan);"><?= count($packages) ?></div>
        <div class="metric-sub">Opções disponíveis para venda</div>
    </div>
</div>

<!-- ABAS: PACOTES CRIADOS vs ASSINANTES -->
<div style="display: flex; gap: 12px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
    <button type="button" id="tabBtnPackages" onclick="switchTab('packages')" class="btn-primary" style="padding: 8px 18px; font-weight: 700;">
        📦 Meus Modelos de Pacotes (<?= count($packages) ?>)
    </button>
    <button type="button" id="tabBtnSubs" onclick="switchTab('subs')" class="btn-secondary" style="padding: 8px 18px; font-weight: 700;">
        👥 Clientes Assinantes (<?= count($subscribers) ?>)
    </button>
</div>

<!-- SEÇÃO 1: CARDS DOS PACOTES -->
<div id="sectionPackages">
    <?php if (empty($packages)): ?>
        <div class="card-box" style="text-align: center; padding: 48px 24px;">
            <div style="font-size: 3rem; margin-bottom: 12px;">📦</div>
            <h3 style="color: #fff; margin-bottom: 8px;">Nenhum pacote ou clube criado ainda</h3>
            <p style="color: var(--text-muted); max-width: 500px; margin: 0 auto 20px;">
                Crie seu primeiro clube (ex: <em>"Cortes Ilimitados por R$ 89,90/mês"</em> ou <em>"Combo 4 Cortes + 4 Barbas por R$ 139,90"</em>) e aumente sua previsibilidade financeira.
            </p>
            <button type="button" class="btn-primary" onclick="openNewPackageModal()" style="font-weight: 800; padding: 10px 20px;">
                ➕ Criar Primeiro Pacote
            </button>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px;">
            <?php foreach ($packages as $pkg): 
                $rules = json_decode($pkg['services_rules'] ?? '{}', true) ?: [];
                $isUnlimited = ($pkg['type'] === 'unlimited');
                $isActive = ($pkg['status'] === 'active');
            ?>
                <div class="card-box" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: space-between; border-color: <?= $isActive ? 'rgba(16, 185, 129, 0.3)' : 'rgba(255,255,255,0.08)' ?>; position: relative; overflow: hidden;">
                    
                    <?php if ($isUnlimited): ?>
                        <div style="position: absolute; top: 12px; right: -28px; background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-size: 0.65rem; font-weight: 900; text-transform: uppercase; padding: 4px 32px; transform: rotate(45deg); letter-spacing: 0.05em; box-shadow: 0 2px 10px rgba(0,0,0,0.5);">
                            ILIMITADO
                        </div>
                    <?php else: ?>
                        <div style="position: absolute; top: 12px; right: -28px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; font-size: 0.65rem; font-weight: 900; text-transform: uppercase; padding: 4px 32px; transform: rotate(45deg); letter-spacing: 0.05em; box-shadow: 0 2px 10px rgba(0,0,0,0.5);">
                            COMBO
                        </div>
                    <?php endif; ?>

                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <span style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; padding: 2px 8px; border-radius: 6px; background: <?= $isActive ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)' ?>; color: <?= $isActive ? 'var(--primary)' : 'var(--red)' ?>;">
                                <?= $isActive ? '● Ativo' : '○ Pausado' ?>
                            </span>
                            <span style="font-size: 0.75rem; color: var(--text-muted); font-family: var(--font-mono);">Validade: <?= (int)$pkg['validity_days'] ?> dias</span>
                        </div>

                        <h3 style="color: #fff; font-size: 1.25rem; font-weight: 800; margin-bottom: 8px; line-height: 1.3;">
                            <?= htmlspecialchars($pkg['name']) ?>
                        </h3>

                        <p style="color: var(--text-secondary); font-size: 0.82rem; margin-bottom: 16px; line-height: 1.4;">
                            <?= htmlspecialchars($pkg['description'] ?: 'Sem descrição detalhada.') ?>
                        </p>

                        <!-- Detalhes dos Serviços Inclusos -->
                        <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; margin-bottom: 18px;">
                            <span style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 6px;">Serviços Inclusos:</span>
                            <strong style="color: #fff; font-size: 0.88rem; display: flex; align-items: center; gap: 6px;">
                                <span><?= $isUnlimited ? '♾️' : '✂️' ?></span>
                                <span><?= htmlspecialchars($rules['label'] ?? 'Conforme especificado') ?></span>
                            </strong>
                        </div>
                    </div>

                    <div>
                        <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 16px; border-top: 1px solid var(--border-color); padding-top: 14px;">
                            <span style="font-size: 0.8rem; color: var(--text-muted);">Mensalidade:</span>
                            <div>
                                <span style="font-size: 1.5rem; font-weight: 900; color: #facc15; font-family: var(--font-mono);">
                                    R$ <?= number_format($pkg['price'], 2, ',', '.') ?>
                                </span>
                                <span style="font-size: 0.72rem; color: var(--text-muted);">/ mês</span>
                            </div>
                        </div>

                        <div style="display: flex; gap: 8px;">
                            <button type="button" class="btn-emerald" style="flex: 1; justify-content: center; font-size: 0.8rem; padding: 8px;" onclick="sellThisPackage(<?= $pkg['id'] ?>, '<?= addslashes($pkg['name']) ?>', <?= $pkg['price'] ?>)">
                                🤝 Vender Plano
                            </button>
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="action" value="toggle_package_status">
                                <input type="hidden" name="package_id" value="<?= $pkg['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= $isActive ? 'inactive' : 'active' ?>">
                                <button type="submit" class="btn-secondary" style="padding: 8px 12px; font-size: 0.8rem;" title="<?= $isActive ? 'Pausar Pacote' : 'Ativar Pacote' ?>">
                                    <?= $isActive ? '⏸️' : '▶️' ?>
                                </button>
                            </form>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- SEÇÃO 2: LISTA DE ASSINANTES DO CLUBE -->
<div id="sectionSubs" style="display: none;">
    <div class="card-box">
        <div class="card-box-header">
            <div>
                <h2>Clientes Assinantes</h2>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Acompanhe prazos de vencimento, renove planos com 1 clique e envie lembretes no WhatsApp.</p>
            </div>
            <button type="button" class="btn-emerald" onclick="openNewSubModal()" style="font-size: 0.8rem; padding: 7px 14px; font-weight: 700;">
                ➕ Nova Assinatura
            </button>
        </div>

        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>WhatsApp</th>
                        <th>Plano / Pacote</th>
                        <th>Tipo</th>
                        <th>Valor Pago</th>
                        <th>Validade / Vencimento</th>
                        <th>Status</th>
                        <th>Ações Rápidas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subscribers)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                Nenhum cliente com plano ou pacote ativo no momento.<br>
                                <button type="button" onclick="openNewSubModal()" class="btn-emerald" style="margin-top: 12px; font-size: 0.8rem; padding: 6px 14px;">
                                    🤝 Ativar Primeiro Cliente
                                </button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($subscribers as $sub): 
                            $cleanPhone = preg_replace('/\D/', '', $sub['customer_whatsapp']);
                            $isExpired = ($sub['end_date'] < date('Y-m-d'));
                            $isUnlimited = ($sub['package_type'] === 'unlimited');
                            $endDateFmt = date('d/m/Y', strtotime($sub['end_date']));
                            $startDateFmt = date('d/m/Y', strtotime($sub['start_date']));

                            // Dias restantes
                            $daysRemaining = (int)ceil((strtotime($sub['end_date']) - strtotime(date('Y-m-d'))) / 86400);

                            // WhatsApp mensagem de renovação/boas-vindas
                            $waMsg = urlencode("Olá {$sub['customer_name']}! Tudo bem?\nPassando da *{$currentTenant['name']}* para lembrar que sua assinatura do *{$sub['package_name']}* está ativa até o dia *{$endDateFmt}*.\n\nVocê pode agendar seus horários direto pelo nosso link: {$shortLinkUrl}\n\nQualquer dúvida estamos à disposição! 👍");
                            $waUrl = "https://wa.me/55{$cleanPhone}?text={$waMsg}";
                        ?>
                            <tr>
                                <td>
                                    <strong style="color: #fff;"><?= htmlspecialchars($sub['customer_name']) ?></strong>
                                    <?php if (!empty($sub['customer_email'])): ?>
                                        <div style="font-size: 0.72rem; color: var(--text-muted);"><?= htmlspecialchars($sub['customer_email']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= $waUrl ?>" target="_blank" style="padding: 4px 10px; background: rgba(37, 211, 102, 0.15); border: 1px solid rgba(37, 211, 102, 0.4); color: #25D366; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; font-size: 0.78rem;">
                                        <span>💬 WhatsApp</span>
                                    </a>
                                </td>
                                <td>
                                    <strong style="color: #fff;"><?= htmlspecialchars($sub['package_name']) ?></strong>
                                    <?php if (!empty($sub['notes'])): ?>
                                        <div style="font-size: 0.72rem; color: var(--text-muted);"><?= htmlspecialchars($sub['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size: 0.72rem; font-weight: 800; padding: 2px 8px; border-radius: 4px; background: <?= $isUnlimited ? 'rgba(16, 185, 129, 0.15)' : 'rgba(59, 130, 246, 0.15)' ?>; color: <?= $isUnlimited ? 'var(--primary)' : '#60a5fa' ?>;">
                                        <?= $isUnlimited ? '♾️ Ilimitado' : '✂️ Combo' ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: #facc15; font-family: var(--font-mono);">
                                        R$ <?= number_format($sub['price_paid'], 2, ',', '.') ?>
                                    </strong>
                                    <div style="font-size: 0.68rem; color: var(--text-muted); text-transform: uppercase;">
                                        <?= htmlspecialchars($sub['payment_method']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-family: var(--font-mono); font-size: 0.85rem; color: #fff;">
                                        <?= $endDateFmt ?>
                                    </div>
                                    <?php if ($sub['status'] === 'active'): ?>
                                        <?php if ($daysRemaining > 0): ?>
                                            <span style="font-size: 0.72rem; color: var(--primary);">Restam <?= $daysRemaining ?> dia(s)</span>
                                        <?php elseif ($daysRemaining === 0): ?>
                                            <span style="font-size: 0.72rem; color: #facc15; font-weight: 700;">Vence Hoje!</span>
                                        <?php else: ?>
                                            <span style="font-size: 0.72rem; color: var(--red); font-weight: 700;">Expirado há <?= abs($daysRemaining) ?> dia(s)</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sub['status'] === 'active' && !$isExpired): ?>
                                        <span class="badge-status badge-confirmed">✓ Ativo</span>
                                    <?php elseif ($sub['status'] === 'active' && $isExpired): ?>
                                        <span class="badge-status" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);">⚠️ Vencido</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-cancelled">✕ Cancelado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <!-- Renovar -->
                                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Confirmar renovação da assinatura de <?= addslashes($sub['customer_name']) ?>? O valor será lançado no Caixa.');">
                                            <input type="hidden" name="action" value="renew_subscription">
                                            <input type="hidden" name="sub_id" value="<?= $sub['id'] ?>">
                                            <input type="hidden" name="payment_method" value="<?= htmlspecialchars($sub['payment_method']) ?>">
                                            <button type="submit" class="btn-emerald" style="padding: 5px 10px; font-size: 0.72rem;" title="Renovar por mais um período e lançar no caixa">
                                                🔄 Renovar
                                            </button>
                                        </form>

                                        <?php if ($sub['status'] === 'active'): ?>
                                            <!-- Cancelar -->
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Deseja realmente cancelar este plano?');">
                                                <input type="hidden" name="action" value="cancel_subscription">
                                                <input type="hidden" name="sub_id" value="<?= $sub['id'] ?>">
                                                <button type="submit" class="btn-secondary" style="padding: 5px 8px; font-size: 0.72rem; color: var(--red);" title="Cancelar Plano">
                                                    ✕
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL 1: CRIAR NOVO PACOTE -->
<div id="modalNewPackage" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; width: 100%; max-width: 580px; max-height: 90vh; overflow-y: auto; padding: 28px; box-shadow: 0 25px 50px rgba(0,0,0,0.8);">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 14px;">
            <div>
                <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 4px;">Novo Pacote ou Clube de Assinatura</h2>
                <p style="font-size: 0.8rem; color: var(--text-muted);">Defina o preço, validade e a composição de serviços do pacote.</p>
            </div>
            <button type="button" onclick="closeNewPackageModal()" style="background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer;">✕</button>
        </div>

        <form method="POST" action="/app/agendou/admin/pacotes.php">
            <input type="hidden" name="action" value="save_package">
            <input type="hidden" name="package_id" id="formPkgId" value="0">

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Nome do Pacote / Clube *</label>
                <input type="text" name="name" id="formPkgName" required class="form-input" placeholder="Ex: Clube Cabelo Ilimitado ou Combo VIP: 4 Cortes + 4 Barbas" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Descrição Comercial (para o cliente ver)</label>
                <textarea name="description" id="formPkgDesc" rows="2" class="form-input" placeholder="Ex: Cortes à vontade durante 30 dias para manter o visual sempre alinhado." style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff; resize: vertical;"></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Preço Mensal (R$) *</label>
                    <input type="text" name="price" id="formPkgPrice" required class="form-input" placeholder="Ex: 89,90" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #facc15; font-size: 1.1rem; font-weight: 800; font-family: var(--font-mono);">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Validade (dias) *</label>
                    <input type="number" name="validity_days" id="formPkgValidity" value="30" min="1" required class="form-input" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 8px;">Tipo do Plano *</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <label style="display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 10px 14px; border-radius: 10px; cursor: pointer;">
                        <input type="radio" name="type" value="unlimited" checked onchange="toggleTypeFields('unlimited')">
                        <div>
                            <strong style="color: #fff; font-size: 0.85rem; display: block;">♾️ Ilimitado</strong>
                            <span style="font-size: 0.72rem; color: var(--text-muted);">Uso livre no mês</span>
                        </div>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 10px 14px; border-radius: 10px; cursor: pointer;">
                        <input type="radio" name="type" value="credits" onchange="toggleTypeFields('credits')">
                        <div>
                            <strong style="color: #fff; font-size: 0.85rem; display: block;">✂️ Combo com Quantidade</strong>
                            <span style="font-size: 0.72rem; color: var(--text-muted);">Ex: 4 cortes + 4 barbas</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Seleção de Serviços e Quantidades -->
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 8px;">Serviços Inclusos no Plano *</label>
                
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 10px; max-height: 220px; overflow-y: auto;">
                    <?php foreach ($allServices as $s): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px; border-radius: 8px; background: rgba(255,255,255,0.02);">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; flex: 1;">
                                <input type="checkbox" name="services[]" value="<?= $s['id'] ?>" class="pkg-service-check" onchange="handleServiceCheck(this, <?= $s['id'] ?>)">
                                <div>
                                    <strong style="color: #fff; font-size: 0.85rem;"><?= htmlspecialchars($s['name']) ?></strong>
                                    <span style="font-size: 0.72rem; color: var(--text-muted); display: block;">Valor avulso: R$ <?= number_format($s['price'], 2, ',', '.') ?></span>
                                </div>
                            </label>

                            <div id="qtyWrapper_<?= $s['id'] ?>" style="display: none; align-items: center; gap: 6px;">
                                <span style="font-size: 0.72rem; color: var(--text-muted);">Qtd/mês:</span>
                                <input type="number" name="service_quantities[<?= $s['id'] ?>]" value="4" min="1" max="99" style="width: 55px; background: #18181b; border: 1px solid var(--border-color); border-radius: 6px; padding: 4px 6px; color: #fff; text-align: center; font-size: 0.82rem;">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeNewPackageModal()" class="btn-secondary" style="padding: 10px 18px;">Cancelar</button>
                <button type="submit" class="btn-primary" style="padding: 10px 24px; font-weight: 800;">✓ Salvar Pacote</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: ATIVAR PACOTE PARA CLIENTE (VENDA DE BALCÃO) -->
<div id="modalNewSub" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; width: 100%; max-width: 540px; max-height: 90vh; overflow-y: auto; padding: 28px; box-shadow: 0 25px 50px rgba(0,0,0,0.8);">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 14px;">
            <div>
                <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 4px;">Ativar Plano para Cliente</h2>
                <p style="font-size: 0.8rem; color: var(--text-muted);">O valor recebido entrará automaticamente no Caixa da barbearia.</p>
            </div>
            <button type="button" onclick="closeNewSubModal()" style="background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer;">✕</button>
        </div>

        <form method="POST" action="/app/agendou/admin/pacotes.php">
            <input type="hidden" name="action" value="subscribe_customer">

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Selecione o Pacote / Clube *</label>
                <select name="package_id" id="subSelectPackage" required class="form-input" onchange="onPackageSelected(this)" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                    <option value="">-- Escolha um pacote --</option>
                    <?php foreach ($packages as $pkg): ?>
                        <option value="<?= $pkg['id'] ?>" data-price="<?= $pkg['price'] ?>" data-validity="<?= $pkg['validity_days'] ?>">
                            <?= htmlspecialchars($pkg['name']) ?> - R$ <?= number_format($pkg['price'], 2, ',', '.') ?> (<?= $pkg['validity_days'] ?> dias)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Selecionar Cliente Existente ou Cadastrar Rápido -->
            <div style="margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                    <label style="font-size: 0.8rem; font-weight: 700; color: var(--text-secondary);">Cliente *</label>
                    <a href="javascript:void(0)" onclick="toggleNewCustomerFields()" id="toggleCustBtn" style="font-size: 0.72rem; color: var(--primary); text-decoration: underline;">+ Novo Cliente</a>
                </div>

                <div id="existingCustomerWrapper">
                    <select name="customer_id" id="subCustomerId" class="form-input" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                        <option value="0">-- Selecione um cliente da base --</option>
                        <?php foreach ($allCustomers as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['whatsapp']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="newCustomerWrapper" style="display: none; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; margin-top: 8px;">
                    <div style="margin-bottom: 8px;">
                        <input type="text" name="new_customer_name" id="newCustName" placeholder="Nome completo do cliente" class="form-input" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: #fff; font-size: 0.85rem;">
                    </div>
                    <div>
                        <input type="text" name="new_customer_phone" id="newCustPhone" placeholder="WhatsApp (DDD + Número)" class="form-input" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: #fff; font-size: 0.85rem;">
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Valor Cobrado (R$) *</label>
                    <input type="text" name="price_paid" id="subPricePaid" required class="form-input" placeholder="0,00" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #facc15; font-size: 1.1rem; font-weight: 800; font-family: var(--font-mono);">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Forma de Pagamento *</label>
                    <select name="payment_method" required class="form-input" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                        <option value="pix">⚡ PIX</option>
                        <option value="dinheiro">💵 Dinheiro</option>
                        <option value="cartao_credito">💳 Cartão de Crédito</option>
                        <option value="cartao_debito">💳 Cartão de Débito</option>
                        <option value="transferencia">🏦 Transferência</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Data de Início *</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required class="form-input" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Observação Interna</label>
                    <input type="text" name="notes" placeholder="Ex: Pago adiantado no balcão" class="form-input" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
            </div>

            <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 10px; padding: 12px; font-size: 0.78rem; color: var(--text-secondary); margin-bottom: 20px;">
                💰 <strong>Entrada no Caixa:</strong> Este valor será creditado no Caixa & Faturamento de hoje na forma de pagamento selecionada.
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeNewSubModal()" class="btn-secondary" style="padding: 10px 18px;">Cancelar</button>
                <button type="submit" class="btn-emerald" style="padding: 10px 24px; font-weight: 800;">✓ Confirmar e Ativar Plano</button>
            </div>
        </form>
    </div>
</div>

<script>
// Alternar abas
function switchTab(tab) {
    if (tab === 'packages') {
        document.getElementById('sectionPackages').style.display = 'block';
        document.getElementById('sectionSubs').style.display = 'none';
        document.getElementById('tabBtnPackages').className = 'btn-primary';
        document.getElementById('tabBtnSubs').className = 'btn-secondary';
    } else {
        document.getElementById('sectionPackages').style.display = 'none';
        document.getElementById('sectionSubs').style.display = 'block';
        document.getElementById('tabBtnPackages').className = 'btn-secondary';
        document.getElementById('tabBtnSubs').className = 'btn-emerald';
    }
}

// Modais
function openNewPackageModal() {
    document.getElementById('modalNewPackage').style.display = 'flex';
}
function closeNewPackageModal() {
    document.getElementById('modalNewPackage').style.display = 'none';
}

function openNewSubModal() {
    document.getElementById('modalNewSub').style.display = 'flex';
}
function closeNewSubModal() {
    document.getElementById('modalNewSub').style.display = 'none';
}

function sellThisPackage(pkgId, pkgName, pkgPrice) {
    const sel = document.getElementById('subSelectPackage');
    sel.value = pkgId;
    document.getElementById('subPricePaid').value = Number(pkgPrice).toFixed(2).replace('.', ',');
    openNewSubModal();
}

function onPackageSelected(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.price) {
        document.getElementById('subPricePaid').value = Number(opt.dataset.price).toFixed(2).replace('.', ',');
    }
}

function toggleNewCustomerFields() {
    const newWrap = document.getElementById('newCustomerWrapper');
    const existWrap = document.getElementById('existingCustomerWrapper');
    const btn = document.getElementById('toggleCustBtn');
    if (newWrap.style.display === 'none') {
        newWrap.style.display = 'block';
        existWrap.style.display = 'none';
        document.getElementById('subCustomerId').value = '0';
        btn.textContent = '← Escolher da base';
    } else {
        newWrap.style.display = 'none';
        existWrap.style.display = 'block';
        btn.textContent = '+ Novo Cliente';
    }
}

function toggleTypeFields(type) {
    const checks = document.querySelectorAll('.pkg-service-check');
    checks.forEach(chk => {
        const sId = chk.value;
        const qtyWrap = document.getElementById('qtyWrapper_' + sId);
        if (qtyWrap) {
            qtyWrap.style.display = (type === 'credits' && chk.checked) ? 'flex' : 'none';
        }
    });
}

function handleServiceCheck(chk, sId) {
    const isCredits = document.querySelector('input[name="type"]:checked').value === 'credits';
    const qtyWrap = document.getElementById('qtyWrapper_' + sId);
    if (qtyWrap) {
        qtyWrap.style.display = (isCredits && chk.checked) ? 'flex' : 'none';
    }
}

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeNewPackageModal();
        closeNewSubModal();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
