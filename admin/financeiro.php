<?php
// ========================================================
// AGENDOU - Caixa & Faturamento para Barbearias e Salões
// Visão de Entradas, Ajustes, Acumulado e Divisão por Barbeiro
// ========================================================

$pageTitle = 'Caixa & Faturamento';
$activeNav = 'financeiro';
require_once __DIR__ . '/header.php';

$pdo = Database::getConnection();
$msgSuccess = '';
$msgError = '';

$today = date('Y-m-d');
$currentMonth = date('Y-m');

// --- PROCESSAMENTO DE LANÇAMENTOS MANUAIS (AVULSO / DESPESA) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Lançar Venda Avulsa / Produto (Pomada, Cerveja, Barba rápida)
    if ($action === 'create_quick_income') {
        $desc = trim($_POST['description'] ?? '');
        $amount = (float)str_replace(',', '.', $_POST['amount'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'pix');
        $profId = !empty($_POST['professional_id']) ? (int)$_POST['professional_id'] : null;
        $tDate = trim($_POST['transaction_date'] ?? $today);

        if (empty($desc) || $amount <= 0) {
            $msgError = 'Por favor, informe a descrição e o valor da venda.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO financial_transactions (
                    tenant_id, professional_id, type, description, amount, 
                    payment_method, transaction_date, created_at
                ) VALUES (?, ?, 'product', ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$tenantId, $profId, $desc, $amount, $paymentMethod, $tDate]);
            $msgSuccess = 'Venda lançada no caixa com sucesso!';
        }
    }

    // 2. Lançar Saída / Despesa do Salão (Lâminas, Lanche, Descartáveis)
    elseif ($action === 'create_expense') {
        $desc = trim($_POST['description'] ?? '');
        $amount = (float)str_replace(',', '.', $_POST['amount'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'dinheiro');
        $tDate = trim($_POST['transaction_date'] ?? $today);

        if (empty($desc) || $amount <= 0) {
            $msgError = 'Por favor, informe a descrição e o valor da despesa.';
        } else {
            // Valor negativo para saída
            $amountNegative = -abs($amount);
            $stmt = $pdo->prepare("
                INSERT INTO financial_transactions (
                    tenant_id, type, description, amount, 
                    payment_method, transaction_date, created_at
                ) VALUES (?, 'expense', ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$tenantId, $desc, $amountNegative, $paymentMethod, $tDate]);
            $msgSuccess = 'Despesa registrada no caixa.';
        }
    }

    // 3. Excluir Lançamento Manual
    elseif ($action === 'delete_transaction') {
        $transId = (int)($_POST['transaction_id'] ?? 0);
        if ($transId > 0) {
            $stmt = $pdo->prepare("DELETE FROM financial_transactions WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$transId, $tenantId]);
            $msgSuccess = 'Lançamento removido com sucesso.';
        }
    }
}

// --- FILTROS DE PERÍODO E PROFISSIONAL ---
$period = $_GET['period'] ?? 'month';
$selectedProf = !empty($_GET['professional_id']) ? (int)$_GET['professional_id'] : 0;
$selectedMethod = trim($_GET['payment_method'] ?? '');

$filterStartDate = $today;
$filterEndDate = $today;

if ($period === 'today') {
    $filterStartDate = $today;
    $filterEndDate = $today;
} elseif ($period === 'week') {
    $filterStartDate = date('Y-m-d', strtotime('-7 days'));
    $filterEndDate = $today;
} elseif ($period === 'month') {
    $filterStartDate = date('Y-m-01');
    $filterEndDate = date('Y-m-t');
} elseif ($period === 'all') {
    $filterStartDate = '2000-01-01';
    $filterEndDate = '2099-12-31';
} elseif ($period === 'custom') {
    $filterStartDate = $_GET['start_date'] ?? date('Y-m-01');
    $filterEndDate = $_GET['end_date'] ?? $today;
}

// --- MÉTRICAS GLOBAIS DE CABEÇALHO ---
// 1. Faturado Hoje (Entradas)
$stmtToday = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM financial_transactions 
    WHERE tenant_id = ? AND transaction_date = ? AND amount > 0
");
$stmtToday->execute([$tenantId, $today]);
$faturadoHoje = (float)$stmtToday->fetchColumn();

// 2. Faturado no Mês Corrente (Entradas)
$stmtMonth = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM financial_transactions 
    WHERE tenant_id = ? AND transaction_date LIKE ? AND amount > 0
");
$stmtMonth->execute([$tenantId, "{$currentMonth}%"]);
$faturadoMes = (float)$stmtMonth->fetchColumn();

// 3. Acumulado Geral Histórico
$stmtTotal = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM financial_transactions 
    WHERE tenant_id = ? AND amount > 0
");
$stmtTotal->execute([$tenantId]);
$faturadoAcumulado = (float)$stmtTotal->fetchColumn();

// 4. Receita de Pacotes / Clubes no Mês
$stmtPkgMonth = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM financial_transactions 
    WHERE tenant_id = ? AND type = 'package' AND transaction_date LIKE ?
");
$stmtPkgMonth->execute([$tenantId, "{$currentMonth}%"]);
$faturadoPacotesMes = (float)$stmtPkgMonth->fetchColumn();

// --- CONSULTA DO PERÍODO FILTRADO ---
$whereClause = "WHERE t.tenant_id = ? AND t.transaction_date BETWEEN ? AND ?";
$params = [$tenantId, $filterStartDate, $filterEndDate];

if ($selectedProf > 0) {
    $whereClause .= " AND t.professional_id = ?";
    $params[] = $selectedProf;
}
if (!empty($selectedMethod)) {
    $whereClause .= " AND t.payment_method = ?";
    $params[] = $selectedMethod;
}

$stmtTrans = $pdo->prepare("
    SELECT t.*, p.name as professional_name, c.name as customer_name, c.whatsapp as customer_whatsapp
    FROM financial_transactions t
    LEFT JOIN professionals p ON p.id = t.professional_id
    LEFT JOIN customers c ON c.id = t.customer_id
    $whereClause
    ORDER BY t.transaction_date DESC, t.id DESC
");
$stmtTrans->execute($params);
$transactions = $stmtTrans->fetchAll();

// Totais do Período Filtrado
$totalEntradasPeriodo = 0;
$totalSaidasPeriodo = 0;
$countEntradas = 0;

$byMethod = [
    'pix' => 0,
    'dinheiro' => 0,
    'cartao_credito' => 0,
    'cartao_debito' => 0,
    'pacote' => 0,
    'outro' => 0,
];

$byProfessional = [];

foreach ($transactions as $tr) {
    $amt = (float)$tr['amount'];
    if ($amt > 0) {
        $totalEntradasPeriodo += $amt;
        $countEntradas++;

        // Por forma de pagamento
        $m = strtolower($tr['payment_method'] ?? 'outro');
        if (isset($byMethod[$m])) {
            $byMethod[$m] += $amt;
        } else {
            $byMethod['outro'] += $amt;
        }

        // Por profissional
        $pName = $tr['professional_name'] ?: 'Geral / Barbearia';
        if (!isset($byProfessional[$pName])) {
            $byProfessional[$pName] = 0;
        }
        $byProfessional[$pName] += $amt;
    } else {
        $totalSaidasPeriodo += abs($amt);
    }
}

$saldoLiquidoPeriodo = $totalEntradasPeriodo - $totalSaidasPeriodo;
$ticketMedio = $countEntradas > 0 ? ($totalEntradasPeriodo / $countEntradas) : 0;

// Buscar lista de profissionais para filtro e formulário
$stmtProfs = $pdo->prepare("SELECT id, name FROM professionals WHERE tenant_id = ? ORDER BY name ASC");
$stmtProfs->execute([$tenantId]);
$allProfessionals = $stmtProfs->fetchAll();
?>

<div class="content-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1>Caixa & Faturamento</h1>
        <p>Acompanhe o faturamento dos cortes, acréscimos de produtos, receitas de pacotes e o acumulado.</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <button type="button" class="btn-primary" onclick="openIncomeModal()" style="font-weight: 800; padding: 10px 18px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);">
            <span>➕ Venda Avulsa / Produto</span>
        </button>
        <button type="button" class="btn-secondary" onclick="openExpenseModal()" style="font-weight: 700; padding: 10px 16px; color: var(--red);">
            <span>➖ Lançar Saída / Despesa</span>
        </button>
        <a href="/app/agendou/admin/pacotes.php" class="btn-emerald" style="font-weight: 700; padding: 10px 16px;">
            <span>📦 Ver Clubes & Pacotes</span>
        </a>
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

<!-- 5 CARDS DE MÉTRICAS FINANCEIRAS -->
<div class="metrics-row" style="margin-bottom: 24px;">
    <div class="metric-box">
        <div class="metric-box-header">
            <span>Faturado Hoje</span>
            <span style="color: var(--primary);">💵</span>
        </div>
        <div class="metric-big-val" style="color: var(--primary);">
            R$ <?= number_format($faturadoHoje, 2, ',', '.') ?>
        </div>
        <div class="metric-sub">Entradas recebidas hoje (<?= date('d/m') ?>)</div>
    </div>

    <div class="metric-box">
        <div class="metric-box-header">
            <span>Faturado no Mês</span>
            <span style="color: #facc15;">📅</span>
        </div>
        <div class="metric-big-val" style="color: #facc15;">
            R$ <?= number_format($faturadoMes, 2, ',', '.') ?>
        </div>
        <div class="metric-sub">Entradas em <?= date('F/Y') ?></div>
    </div>

    <div class="metric-box">
        <div class="metric-box-header">
            <span>Acumulado Histórico</span>
            <span style="color: #38bdf8;">📈</span>
        </div>
        <div class="metric-big-val" style="color: #38bdf8;">
            R$ <?= number_format($faturadoAcumulado, 2, ',', '.') ?>
        </div>
        <div class="metric-sub">Total registrado no sistema</div>
    </div>

    <div class="metric-box">
        <div class="metric-box-header">
            <span>Clubes & Assinaturas</span>
            <span style="color: #a855f7;">📦</span>
        </div>
        <div class="metric-big-val" style="color: #a855f7;">
            R$ <?= number_format($faturadoPacotesMes, 2, ',', '.') ?>
        </div>
        <div class="metric-sub">Receita de pacotes no mês</div>
    </div>

    <div class="metric-box">
        <div class="metric-box-header">
            <span>Ticket Médio</span>
            <span style="color: #10b981;">🎯</span>
        </div>
        <div class="metric-big-val">
            R$ <?= number_format($ticketMedio, 2, ',', '.') ?>
        </div>
        <div class="metric-sub">Média por atendimento/venda</div>
    </div>
</div>

<!-- BARRA DE FILTROS RÁPIDOS -->
<div class="card-box" style="padding: 16px 20px; margin-bottom: 24px;">
    <form method="GET" action="/app/agendou/admin/financeiro.php" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
        
        <!-- Botões de Período Rápido -->
        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
            <a href="/app/agendou/admin/financeiro.php?period=today" class="<?= $period === 'today' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.78rem; padding: 6px 12px;">Hoje</a>
            <a href="/app/agendou/admin/financeiro.php?period=week" class="<?= $period === 'week' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.78rem; padding: 6px 12px;">Últimos 7 dias</a>
            <a href="/app/agendou/admin/financeiro.php?period=month" class="<?= $period === 'month' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.78rem; padding: 6px 12px;">Este Mês</a>
            <a href="/app/agendou/admin/financeiro.php?period=all" class="<?= $period === 'all' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.78rem; padding: 6px 12px;">Acumulado Total</a>
        </div>

        <!-- Filtro por Barbeiro e Forma de Pagamento -->
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <select name="professional_id" onchange="this.form.submit()" class="form-input" style="background: #18181b; border: 1px solid var(--border-color); border-radius: 8px; padding: 6px 10px; color: #fff; font-size: 0.8rem;">
                <option value="0">Todos os Barbeiros</option>
                <?php foreach ($allProfessionals as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $selectedProf == $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="payment_method" onchange="this.form.submit()" class="form-input" style="background: #18181b; border: 1px solid var(--border-color); border-radius: 8px; padding: 6px 10px; color: #fff; font-size: 0.8rem;">
                <option value="">Todas as Formas</option>
                <option value="pix" <?= $selectedMethod === 'pix' ? 'selected' : '' ?>>PIX</option>
                <option value="dinheiro" <?= $selectedMethod === 'dinheiro' ? 'selected' : '' ?>>Dinheiro</option>
                <option value="cartao_credito" <?= $selectedMethod === 'cartao_credito' ? 'selected' : '' ?>>Cartão Crédito</option>
                <option value="cartao_debito" <?= $selectedMethod === 'cartao_debito' ? 'selected' : '' ?>>Cartão Débito</option>
                <option value="pacote" <?= $selectedMethod === 'pacote' ? 'selected' : '' ?>>Pacote / Clube</option>
            </select>

            <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
        </div>
    </form>
</div>

<!-- GRID DE DISTRIBUIÇÃO: FORMAS DE PAGAMENTO & DIVISÃO POR BARBEIRO -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 24px;">
    
    <!-- CARD 1: FORMAS DE PAGAMENTO -->
    <div class="card-box" style="margin-bottom: 0;">
        <div class="card-box-header" style="margin-bottom: 14px;">
            <h2 style="font-size: 1rem;">💳 Formas de Pagamento (<?= htmlspecialchars($period) ?>)</h2>
            <span style="font-size: 0.85rem; font-weight: 800; color: #facc15; font-family: var(--font-mono);">
                Total: R$ <?= number_format($totalEntradasPeriodo, 2, ',', '.') ?>
            </span>
        </div>

        <div style="display: flex; flex-direction: column; gap: 10px;">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: rgba(255,255,255,0.02); border-radius: 8px;">
                <span style="font-size: 0.82rem; color: #fff;">⚡ PIX</span>
                <strong style="font-family: var(--font-mono); color: var(--primary);">R$ <?= number_format($byMethod['pix'], 2, ',', '.') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: rgba(255,255,255,0.02); border-radius: 8px;">
                <span style="font-size: 0.82rem; color: #fff;">💵 Dinheiro</span>
                <strong style="font-family: var(--font-mono); color: #facc15;">R$ <?= number_format($byMethod['dinheiro'], 2, ',', '.') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: rgba(255,255,255,0.02); border-radius: 8px;">
                <span style="font-size: 0.82rem; color: #fff;">💳 Cartão de Crédito</span>
                <strong style="font-family: var(--font-mono); color: #38bdf8;">R$ <?= number_format($byMethod['cartao_credito'], 2, ',', '.') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: rgba(255,255,255,0.02); border-radius: 8px;">
                <span style="font-size: 0.82rem; color: #fff;">💳 Cartão de Débito</span>
                <strong style="font-family: var(--font-mono); color: #818cf8;">R$ <?= number_format($byMethod['cartao_debito'], 2, ',', '.') ?></strong>
            </div>
            <?php if ($byMethod['pacote'] > 0): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: rgba(255,255,255,0.02); border-radius: 8px;">
                    <span style="font-size: 0.82rem; color: #fff;">📦 Pacote / Cortesia</span>
                    <strong style="font-family: var(--font-mono); color: #a855f7;">R$ <?= number_format($byMethod['pacote'], 2, ',', '.') ?></strong>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CARD 2: FATURAMENTO POR BARBEIRO -->
    <div class="card-box" style="margin-bottom: 0;">
        <div class="card-box-header" style="margin-bottom: 14px;">
            <h2 style="font-size: 1rem;">👤 Faturamento por Barbeiro</h2>
            <span style="font-size: 0.72rem; color: var(--text-muted);">Ideal para comissões</span>
        </div>

        <?php if (empty($byProfessional)): ?>
            <p style="font-size: 0.8rem; color: var(--text-muted); text-align: center; padding: 24px;">
                Nenhum faturamento registrado por barbeiro neste período.
            </p>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($byProfessional as $barberName => $barberTotal): 
                    $pct = $totalEntradasPeriodo > 0 ? round(($barberTotal / $totalEntradasPeriodo) * 100) : 0;
                ?>
                    <div style="padding: 10px 14px; background: rgba(255,255,255,0.02); border-radius: 8px; border: 1px solid var(--border-color);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <strong style="color: #fff; font-size: 0.88rem;"><?= htmlspecialchars($barberName) ?></strong>
                            <strong style="font-family: var(--font-mono); color: var(--primary);">
                                R$ <?= number_format($barberTotal, 2, ',', '.') ?>
                            </strong>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="flex: 1; height: 6px; background: rgba(255,255,255,0.06); border-radius: 4px; overflow: hidden;">
                                <div style="height: 100%; width: <?= $pct ?>%; background: linear-gradient(90deg, #10b981, #06b6d4); border-radius: 4px;"></div>
                            </div>
                            <span style="font-size: 0.72rem; color: var(--text-muted); font-family: var(--font-mono);"><?= $pct ?>%</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- TABELA DE LANÇAMENTOS DO CAIXA -->
<div class="card-box" style="margin-top: 24px;">
    <div class="card-box-header">
        <div>
            <h2>Extrato de Lançamentos</h2>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Histórico de todos os agendamentos concluídos, produtos vendidos e despesas.</p>
        </div>
        <div style="display: flex; align-items: baseline; gap: 8px;">
            <span style="font-size: 0.8rem; color: var(--text-muted);">Saldo Líquido do Período:</span>
            <strong style="font-size: 1.25rem; font-family: var(--font-mono); color: <?= $saldoLiquidoPeriodo >= 0 ? 'var(--primary)' : 'var(--red)' ?>;">
                R$ <?= number_format($saldoLiquidoPeriodo, 2, ',', '.') ?>
            </strong>
        </div>
    </div>

    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Tipo</th>
                    <th>Descrição</th>
                    <th>Cliente</th>
                    <th>Profissional</th>
                    <th>Pagamento</th>
                    <th>Valor</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">
                            Nenhum lançamento financeiro encontrado para este período.<br>
                            <button type="button" onclick="openIncomeModal()" class="btn-primary" style="margin-top: 12px; font-size: 0.8rem; padding: 6px 14px;">
                                ➕ Lançar Primeira Venda
                            </button>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $t): 
                        $isExpense = ((float)$t['amount'] < 0);
                        $amtVal = abs((float)$t['amount']);
                        $dateFmt = date('d/m/Y', strtotime($t['transaction_date']));
                    ?>
                        <tr>
                            <td style="font-family: var(--font-mono); color: #fff; font-size: 0.8rem;">
                                <?= $dateFmt ?>
                            </td>
                            <td>
                                <?php if ($t['type'] === 'appointment'): ?>
                                    <span class="badge-status badge-confirmed">✂️ Corte / Serviço</span>
                                <?php elseif ($t['type'] === 'package'): ?>
                                    <span class="badge-status" style="background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3);">📦 Pacote / Clube</span>
                                <?php elseif ($t['type'] === 'product'): ?>
                                    <span class="badge-status" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3);">🛍️ Produto / Balcão</span>
                                <?php else: ?>
                                    <span class="badge-status badge-cancelled">➖ Saída / Despesa</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color: #fff;"><?= htmlspecialchars($t['description']) ?></strong>
                            </td>
                            <td>
                                <?= htmlspecialchars($t['customer_name'] ?: 'Consumidor Balcão') ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($t['professional_name'] ?: '--') ?>
                            </td>
                            <td>
                                <span style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); background: rgba(255,255,255,0.04); padding: 3px 8px; border-radius: 6px;">
                                    <?= htmlspecialchars($t['payment_method'] ?: 'N/A') ?>
                                </span>
                            </td>
                            <td>
                                <strong style="font-family: var(--font-mono); font-size: 0.95rem; color: <?= $isExpense ? 'var(--red)' : 'var(--primary)' ?>;">
                                    <?= $isExpense ? '-' : '+' ?> R$ <?= number_format($amtVal, 2, ',', '.') ?>
                                </strong>
                            </td>
                            <td>
                                <?php if ($t['type'] === 'product' || $t['type'] === 'expense'): ?>
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Deseja excluir este lançamento manual?');">
                                        <input type="hidden" name="action" value="delete_transaction">
                                        <input type="hidden" name="transaction_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn-secondary" style="padding: 4px 8px; font-size: 0.7rem; color: var(--red);" title="Excluir Lançamento">
                                            ✕
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size: 0.72rem; color: var(--text-muted);">Auto</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL 1: LANÇAR VENDA AVULSA / PRODUTO -->
<div id="modalIncome" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; width: 100%; max-width: 480px; padding: 28px; box-shadow: 0 25px 50px rgba(0,0,0,0.8);">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 14px;">
            <div>
                <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 4px;">➕ Venda Avulsa / Produto</h2>
                <p style="font-size: 0.8rem; color: var(--text-muted);">Lance venda de pomada, minoxidil, bebidas ou serviço de balcão.</p>
            </div>
            <button type="button" onclick="closeIncomeModal()" style="background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer;">✕</button>
        </div>

        <form method="POST" action="/app/agendou/admin/financeiro.php">
            <input type="hidden" name="action" value="create_quick_income">

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Descrição do Produto / Venda *</label>
                <input type="text" name="description" required class="form-input" placeholder="Ex: Pomada Modeladora Matte ou Bebida" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Valor (R$) *</label>
                    <input type="text" name="amount" required class="form-input" placeholder="Ex: 35,00" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #facc15; font-size: 1.1rem; font-weight: 800; font-family: var(--font-mono);">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Forma de Pagamento *</label>
                    <select name="payment_method" required class="form-input" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                        <option value="pix">⚡ PIX</option>
                        <option value="dinheiro">💵 Dinheiro</option>
                        <option value="cartao_credito">💳 Cartão de Crédito</option>
                        <option value="cartao_debito">💳 Cartão de Débito</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Barbeiro (para comissão)</label>
                    <select name="professional_id" class="form-input" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                        <option value="">Geral / Barbearia</option>
                        <?php foreach ($allProfessionals as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Data do Lançamento *</label>
                    <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required class="form-input" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeIncomeModal()" class="btn-secondary" style="padding: 10px 18px;">Cancelar</button>
                <button type="submit" class="btn-primary" style="padding: 10px 24px; font-weight: 800;">✓ Salvar Entrada</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: LANÇAR SAÍDA / DESPESA -->
<div id="modalExpense" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; width: 100%; max-width: 480px; padding: 28px; box-shadow: 0 25px 50px rgba(0,0,0,0.8);">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 14px;">
            <div>
                <h2 style="font-size: 1.25rem; color: #fff; margin-bottom: 4px;">➖ Lançar Saída / Despesa</h2>
                <p style="font-size: 0.8rem; color: var(--text-muted);">Registre compras rápidas como descartáveis, lâminas, produtos de limpeza.</p>
            </div>
            <button type="button" onclick="closeExpenseModal()" style="background: none; border: none; color: var(--text-muted); font-size: 22px; cursor: pointer;">✕</button>
        </div>

        <form method="POST" action="/app/agendou/admin/financeiro.php">
            <input type="hidden" name="action" value="create_expense">

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Descrição da Despesa *</label>
                <input type="text" name="description" required class="form-input" placeholder="Ex: Lâminas descartáveis, descartáveis, lanche..." style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Valor da Saída (R$) *</label>
                    <input type="text" name="amount" required class="form-input" placeholder="Ex: 25,00" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: var(--red); font-size: 1.1rem; font-weight: 800; font-family: var(--font-mono);">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Forma de Pagamento *</label>
                    <select name="payment_method" required class="form-input" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                        <option value="dinheiro">💵 Dinheiro do Caixa</option>
                        <option value="pix">⚡ PIX</option>
                        <option value="cartao_debito">💳 Débito</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeExpenseModal()" class="btn-secondary" style="padding: 10px 18px;">Cancelar</button>
                <button type="submit" class="btn-secondary" style="padding: 10px 24px; font-weight: 800; color: var(--red); border-color: rgba(239, 68, 68, 0.4);">✓ Registrar Saída</button>
            </div>
        </form>
    </div>
</div>

<script>
function openIncomeModal() {
    document.getElementById('modalIncome').style.display = 'flex';
}
function closeIncomeModal() {
    document.getElementById('modalIncome').style.display = 'none';
}
function openExpenseModal() {
    document.getElementById('modalExpense').style.display = 'flex';
}
function closeExpenseModal() {
    document.getElementById('modalExpense').style.display = 'none';
}
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeIncomeModal();
        closeExpenseModal();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
