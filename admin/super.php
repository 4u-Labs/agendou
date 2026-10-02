<?php
// ========================================================
// AGENDOU - Super Admin: SaaS Billing, Inadimplentes, Faturas & Configurações
// Painel do Fundador • 4U.IA.BR
// ========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
$pdo = Database::getConnection();

// Check Superadmin permissions
if (empty($_SESSION['agendou_user_id'])) {
    header("Location: /app/agendou/admin/login.php");
    exit;
}
$stmtU = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtU->execute([(int)$_SESSION['agendou_user_id']]);
$currentUser = $stmtU->fetch();

if (($currentUser['role'] ?? '') !== 'superadmin') {
    die("Acesso restrito ao Super Administrador da plataforma.");
}

$successMsg = '';
$errorMsg = '';
$activeTab = $_GET['tab'] ?? 'all';

// Helper function to update .env
function saveEnvConfig(array $updates): bool {
    $envPath = __DIR__ . '/../.env';
    $content = file_exists($envPath) ? file_get_contents($envPath) : '';
    foreach ($updates as $k => $v) {
        $k = trim($k);
        $v = trim($v);
        if (preg_match("/^{$k}=.*/m", $content)) {
            $content = preg_replace("/^{$k}=.*/m", "{$k}={$v}", $content);
        } else {
            $content .= "\n{$k}={$v}";
        }
    }
    return file_put_contents($envPath, trim($content) . "\n") !== false;
}

// -------------------------------------------------------------
// POST / GET ACTION HANDLERS
// -------------------------------------------------------------

// 1. ACTION: Salvar Configurações Globais do SaaS & Chave PIX
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_global_settings') {
    $pixKey = trim($_POST['default_pix_key'] ?? 'contato@4u.ia.br');
    $graceDays = max(1, (int)($_POST['grace_period_days'] ?? 2));
    $mpAccessToken = trim($_POST['mp_access_token'] ?? '');
    $mpPublicKey = trim($_POST['mp_public_key'] ?? '');
    $googleClientId = trim($_POST['google_client_id'] ?? '');
    $googleClientSecret = trim($_POST['google_client_secret'] ?? '');

    $saved = saveEnvConfig([
        'DEFAULT_PIX_KEY' => $pixKey,
        'GRACE_PERIOD_DAYS' => $graceDays,
        'MP_ACCESS_TOKEN' => $mpAccessToken,
        'MP_PUBLIC_KEY' => $mpPublicKey,
        'GOOGLE_CLIENT_ID' => $googleClientId,
        'GOOGLE_CLIENT_SECRET' => $googleClientSecret
    ]);

    if (!empty($_POST['update_all_tenants_pix']) && $pixKey) {
        $stmtAllPix = $pdo->prepare("UPDATE tenants SET pix_key = ?");
        $stmtAllPix->execute([$pixKey]);
    }

    header("Location: /app/agendou/admin/super.php?tab=configuracoes&msg=settings_saved#configuracoes");
    exit;
}

// 2. ACTION: Executar Varredura Automática de Vencimentos e Cortes
if (isset($_GET['action']) && $_GET['action'] === 'run_billing_check') {
    $today = date('Y-m-d');
    $cutoff = date('Y-m-d', strtotime('-2 days')); // 2 dias de tolerância padrão

    // Cortar os que venceram há mais de 2 dias
    $stmtCut = $pdo->prepare("
        UPDATE tenants 
        SET subscription_status = 'suspended', status = 'suspended', 
            blocked_reason = 'Assinatura suspensa por falta de pagamento (tolerância de 2 dias esgotada)'
        WHERE next_due_date IS NOT NULL 
          AND next_due_date < ? 
          AND subscription_status != 'suspended'
    ");
    $stmtCut->execute([$cutoff]);
    $cutCount = $stmtCut->rowCount();

    // Marcar como em atraso os que venceram hoje ou até 2 dias atrás
    $stmtPast = $pdo->prepare("
        UPDATE tenants 
        SET subscription_status = 'past_due'
        WHERE next_due_date IS NOT NULL 
          AND next_due_date < ? 
          AND subscription_status = 'active'
    ");
    $stmtPast->execute([$today]);
    $pastCount = $stmtPast->rowCount();

    header("Location: /app/agendou/admin/super.php?tab=inadimplentes&billing_checked=1&cut={$cutCount}&past={$pastCount}#inadimplentes");
    exit;
}

// 3. ACTION: Cortar / Bloquear Sistema Imediatamente
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'suspend_tenant') {
    $tenantId = (int)$_POST['tenant_id'];
    $reason = trim($_POST['reason'] ?? 'Sistema suspenso por falta de pagamento da mensalidade.');

    $stmt = $pdo->prepare("
        UPDATE tenants 
        SET subscription_status = 'suspended', status = 'suspended', blocked_reason = ? 
        WHERE id = ?
    ");
    $stmt->execute([$reason, $tenantId]);

    header("Location: /app/agendou/admin/super.php?tab=inadimplentes&msg=suspended#inadimplentes");
    exit;
}

// 4. ACTION: Renovar / Desbloquear (+30 dias e registrar pagamento)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'renew_tenant') {
    $tenantId = (int)$_POST['tenant_id'];
    
    $stmtT = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmtT->execute([$tenantId]);
    $t = $stmtT->fetch();

    if ($t) {
        $isFree = ($t['plan'] === 'free');
        $amount = $isFree ? 0.00 : (float)($t['monthly_price'] ?: 19.90);
        $baseDate = ($t['next_due_date'] && $t['next_due_date'] > date('Y-m-d')) ? $t['next_due_date'] : date('Y-m-d');
        $newDueDate = $isFree ? null : date('Y-m-d', strtotime($baseDate . ' +30 days'));

        $stmtUp = $pdo->prepare("
            UPDATE tenants 
            SET subscription_status = 'active', status = 'active', 
                next_due_date = ?, blocked_reason = '' 
            WHERE id = ?
        ");
        $stmtUp->execute([$newDueDate, $tenantId]);

        // Registrar na tabela invoices somente se houver valor ou for plano pago
        if (!$isFree && $amount > 0) {
            $refMonth = date('m/Y');
            $stmtInv = $pdo->prepare("
                INSERT INTO invoices (tenant_id, amount, due_date, status, paid_at, reference_month)
                VALUES (?, ?, ?, 'paid', CURRENT_TIMESTAMP, ?)
            ");
            $stmtInv->execute([$tenantId, $amount, $newDueDate, $refMonth]);
        }

        header("Location: /app/agendou/admin/super.php?tab=faturas&msg=renewed&tenant=" . urlencode($t['name']) . "#faturas");
        exit;
    }
}

// 5. ACTION: Editar Dados da Assinatura (Valor e Vencimento)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_subscription') {
    $tenantId = (int)$_POST['tenant_id'];
    $plan = in_array($_POST['plan'] ?? '', ['free', 'starter', 'plus']) ? $_POST['plan'] : 'starter';
    
    if ($plan === 'free') {
        $monthlyPrice = 0.00;
        $nextDueDate = null;
    } else {
        $defaultPrice = ($plan === 'plus') ? 39.90 : 19.90;
        $monthlyPrice = (float)str_replace(',', '.', $_POST['monthly_price'] ?? $defaultPrice);
        if ($monthlyPrice <= 0) {
            $monthlyPrice = $defaultPrice;
        }
        $nextDueDate = !empty($_POST['next_due_date']) ? $_POST['next_due_date'] : null;
    }

    $subStatus = $_POST['subscription_status'] ?? 'active';
    $pixKey = trim($_POST['pix_key'] ?? 'contato@4u.ia.br');

    $sysStatus = ($subStatus === 'suspended') ? 'suspended' : 'active';
    $blockedReason = ($subStatus === 'suspended') ? 'Assinatura suspensa por falta de pagamento' : '';

    $stmt = $pdo->prepare("
        UPDATE tenants 
        SET monthly_price = ?, next_due_date = ?, subscription_status = ?, 
            status = ?, plan = ?, pix_key = ?, blocked_reason = ?
        WHERE id = ?
    ");
    $stmt->execute([$monthlyPrice, $nextDueDate, $subStatus, $sysStatus, $plan, $pixKey, $blockedReason, $tenantId]);

    header("Location: /app/agendou/admin/super.php?tab=assinantes&msg=updated#assinantes");
    exit;
}

// 6. ACTION: Criar Nova Barbearia / Empresa
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_tenant') {
    $name = trim($_POST['name'] ?? '');
    $slugInput = trim($_POST['slug'] ?? '');
    $category = trim($_POST['category'] ?? 'Barbearia');
    $whatsapp = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = strtoupper(trim($_POST['state'] ?? ''));
    $plan = in_array($_POST['plan'] ?? '', ['free', 'starter', 'plus']) ? $_POST['plan'] : 'starter';
    if ($plan === 'free') {
        $monthlyPrice = 0.00;
        $nextDueDate = null;
    } else {
        $defaultPrice = ($plan === 'plus') ? 39.90 : 19.90;
        $monthlyPrice = (float)str_replace(',', '.', $_POST['monthly_price'] ?? $defaultPrice);
        if ($monthlyPrice <= 0) $monthlyPrice = $defaultPrice;
        $nextDueDate = !empty($_POST['next_due_date']) ? $_POST['next_due_date'] : date('Y-m-d', strtotime('+30 days'));
    }

    $adminName = trim($_POST['admin_name'] ?? '');
    if (!$adminName) $adminName = "Responsável " . $name;
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '123456';

    $cleanSlug = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace([' ', '.'], '-', $slugInput ?: $name)));
    if (!$cleanSlug) $cleanSlug = 'empresa-' . time();

    if (!$name || !$whatsapp || !$adminEmail) {
        $errorMsg = "Preencha os campos obrigatórios (Nome, WhatsApp e E-mail de login).";
    } else {
        $stmtSlug = $pdo->prepare("SELECT id FROM tenants WHERE slug = ?");
        $stmtSlug->execute([$cleanSlug]);
        if ($stmtSlug->fetch()) $cleanSlug .= '-' . rand(100, 999);

        $stmtEmail = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmtEmail->execute([$adminEmail]);
        if ($stmtEmail->fetch()) {
            $errorMsg = "O e-mail de login '{$adminEmail}' já está cadastrado em outro usuário.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmtInT = $pdo->prepare("
                    INSERT INTO tenants (name, slug, category, email, whatsapp, city, state, plan, monthly_price, next_due_date, subscription_status, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'active')
                ");
                $stmtInT->execute([$name, $cleanSlug, $category, $email ?: $adminEmail, $whatsapp, $city, $state, $plan, $monthlyPrice, $nextDueDate]);
                $newTenantId = (int)$pdo->lastInsertId();

                $passHash = password_hash($adminPassword, PASSWORD_DEFAULT);
                $stmtInU = $pdo->prepare("
                    INSERT INTO users (tenant_id, name, email, password, role)
                    VALUES (?, ?, ?, ?, 'tenant_admin')
                ");
                $stmtInU->execute([$newTenantId, $adminName, $adminEmail, $passHash]);

                // Seed default business hours
                $stmtH = $pdo->prepare("
                    INSERT INTO business_hours (tenant_id, day_of_week, open_time, close_time, break_start, break_end, is_closed)
                    VALUES (?, ?, '08:00', '19:00', '12:00', '13:00', ?)
                ");
                $stmtH->execute([$newTenantId, 0, 1]); // Dom fechado
                for ($d = 1; $d <= 6; $d++) {
                    $stmtH->execute([$newTenantId, $d, 0]); // Seg a Sab aberto
                }

                // Seed default professional
                $stmtPro = $pdo->prepare("
                    INSERT INTO professionals (tenant_id, name, specialty, email, phone, status)
                    VALUES (?, ?, 'Barbeiro Especialista', ?, ?, 'active')
                ");
                $stmtPro->execute([$newTenantId, $adminName, $adminEmail, $whatsapp]);
                $proId = (int)$pdo->lastInsertId();

                // Seed popular services
                $defaultServices = [
                    ['name' => 'Corte Masculino Degradê / Social', 'price' => 35.00, 'duration' => 35, 'cat' => 'Cabelo'],
                    ['name' => 'Barba Terapia com Toalha Quente', 'price' => 30.00, 'duration' => 30, 'cat' => 'Barba'],
                    ['name' => 'Combo Corte + Barba VIP', 'price' => 60.00, 'duration' => 50, 'cat' => 'Combos'],
                    ['name' => 'Acabamento / Pezinho', 'price' => 15.00, 'duration' => 15, 'cat' => 'Acabamento']
                ];
                $stmtS = $pdo->prepare("INSERT INTO services (tenant_id, name, category, price, duration_minutes, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $stmtPS = $pdo->prepare("INSERT INTO professional_services (tenant_id, professional_id, service_id) VALUES (?, ?, ?)");
                foreach ($defaultServices as $ds) {
                    $stmtS->execute([$newTenantId, $ds['name'], $ds['cat'], $ds['price'], $ds['duration']]);
                    $svcId = (int)$pdo->lastInsertId();
                    $stmtPS->execute([$newTenantId, $proId, $svcId]);
                }

                $pdo->commit();

                // Gerar Link Curto Oficial (ex: 4u.ia.br/{slug})
                require_once __DIR__ . '/../app/Services/UrlShortenerService.php';
                UrlShortenerService::ensureTenantShortLink(['name' => $name, 'slug' => $cleanSlug]);

                header("Location: /app/agendou/admin/super.php?tab=assinantes&created=1&new_name=" . urlencode($name) . "&new_login=" . urlencode($adminEmail) . "&new_pass=" . urlencode($adminPassword) . "#assinantes");
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errorMsg = "Erro ao cadastrar estabelecimento: " . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// CONSULTAS DE DADOS & MÉTRICAS
// -------------------------------------------------------------
$today = date('Y-m-d');

// 1. Total de estabelecimentos
$totalTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();

// 2. Receita Recorrente Mensal (MRR) - planos pagos não suspensos
$totalMRR = (float)$pdo->query("SELECT SUM(monthly_price) FROM tenants WHERE subscription_status != 'suspended' AND plan != 'free'")->fetchColumn();

// 3. Assinantes em dia (inclui planos free ativos e planos pagos em dia)
$activeTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE subscription_status = 'active' AND (plan = 'free' OR next_due_date IS NULL OR next_due_date >= '$today')")->fetchColumn();

// 4. Em atraso (apenas planos pagos!)
$pastDueTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE plan != 'free' AND (subscription_status = 'past_due' OR (subscription_status = 'active' AND next_due_date < '$today')) AND subscription_status != 'suspended'")->fetchColumn();

// 5. Cortados / Suspensos
$suspendedTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE subscription_status = 'suspended' OR status = 'suspended'")->fetchColumn();

// 6. Listagem de estabelecimentos com dados de assinante
$tenants = $pdo->query("
    SELECT t.*, 
           u.email as owner_login,
           u.name as owner_name,
           (SELECT COUNT(*) FROM appointments a WHERE a.tenant_id = t.id) as total_appointments
    FROM tenants t
    LEFT JOIN users u ON u.tenant_id = t.id AND u.role = 'tenant_admin'
    GROUP BY t.id
    ORDER BY 
        CASE 
            WHEN t.subscription_status = 'suspended' THEN 1
            WHEN t.plan != 'free' AND t.next_due_date < '$today' THEN 2
            ELSE 3
        END,
        t.id ASC
")->fetchAll();

// 7. Lista Específica de Inadimplentes e Cortados
$inadimplentesList = [];
foreach ($tenants as $t) {
    $subStatus = $t['subscription_status'] ?? 'active';
    $sysStatus = $t['status'] ?? 'active';
    $isFreePlan = ($t['plan'] === 'free' || (float)$t['monthly_price'] <= 0);
    $dueDate = $t['next_due_date'];
    $isPast = (!$isFreePlan && $dueDate && $dueDate < $today);
    // Planos FREE só entram aqui se estiverem suspensos/bloqueados pela moderação
    if ($subStatus === 'suspended' || $sysStatus === 'suspended' || (!$isFreePlan && ($subStatus === 'past_due' || $isPast))) {
        $inadimplentesList[] = $t;
    }
}

// 8. Histórico de faturas pagas
$invoices = $pdo->query("
    SELECT i.*, t.name as tenant_name 
    FROM invoices i
    JOIN tenants t ON t.id = i.tenant_id
    ORDER BY i.id DESC
    LIMIT 50
")->fetchAll();

$totalInvoicesPaid = (float)$pdo->query("SELECT SUM(amount) FROM invoices WHERE status = 'paid'")->fetchColumn();

// 9. Configurações Globais (.env)
$envPath = __DIR__ . '/../.env';
$envVars = [];
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && !str_starts_with(trim($line), '#')) {
            [$k, $v] = explode('=', $line, 2);
            $envVars[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
        }
    }
}

$defaultPixKey = $envVars['DEFAULT_PIX_KEY'] ?? 'contato@4u.ia.br';
$gracePeriodDays = (int)($envVars['GRACE_PERIOD_DAYS'] ?? 2);
$mpAccessToken = $envVars['MP_ACCESS_TOKEN'] ?? 'APP_USR-4404962015981699-111621-7eb905e9749a5abcac15f4e322da4b03-124159657';
$mpPublicKey = $envVars['MP_PUBLIC_KEY'] ?? 'APP_USR-cbe6db67-0f0a-4149-82f9-abfc0a57f55f';
$googleClientId = $envVars['GOOGLE_CLIENT_ID'] ?? 'YOUR_GOOGLE_CLIENT_ID';
$googleClientSecret = $envVars['GOOGLE_CLIENT_SECRET'] ?? 'YOUR_GOOGLE_CLIENT_SECRET';

$pageTitle = 'Gestão SaaS & Assinaturas';
$activeNav = 'super';
require_once __DIR__ . '/header.php';
?>

<!-- CABEÇALHO DO FUNDADOR -->
<div class="content-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
    <div>
        <h1 style="display: flex; align-items: center; gap: 10px;">
            <span style="color: #facc15;">👑</span> Painel do Fundador • Controle SaaS & Pagamentos
        </h1>
        <p>Acompanhe o faturamento recorrente (MRR), mensalidades, vencimentos, aplique cortes e configure o sistema.</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="/app/agendou/admin/super.php?action=run_billing_check" class="btn-secondary" style="border-color: rgba(245, 158, 11, 0.4); color: #facc15; font-size: 0.85rem; padding: 10px 16px;" title="Verifica vencimentos e aplica cortes automáticos para quem passou de 2 dias de atraso">
            <span>⚡ Varredura de Cortes Automáticos</span>
        </a>
        <button type="button" class="btn-primary" onclick="openNewTenantModal()" style="font-weight: 800; padding: 10px 18px; font-size: 0.88rem;">
            <span>➕ Cadastrar Nova Barbearia</span>
        </button>
    </div>
</div>

<!-- ABAS DE NAVEGAÇÃO RÁPIDA (TABS) -->
<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px; border-bottom: 1px solid var(--border-color); padding-bottom: 14px;">
    <a href="/app/agendou/admin/super.php" class="btn-secondary super-tab-btn <?= empty($curTab) ? 'active-tab' : '' ?>" style="font-size: 0.82rem; padding: 8px 16px; text-decoration: none; border-radius: 10px;">
        📊 Visão Geral & Métricas
    </a>
    <a href="/app/agendou/admin/super.php?tab=assinantes#assinantes" class="btn-secondary super-tab-btn <?= $curTab === 'assinantes' ? 'active-tab' : '' ?>" style="font-size: 0.82rem; padding: 8px 16px; text-decoration: none; border-radius: 10px;">
        🏢 Barbearias & Assinantes (<?= $totalTenants ?>)
    </a>
    <a href="/app/agendou/admin/super.php?tab=inadimplentes#inadimplentes" class="btn-secondary super-tab-btn <?= $curTab === 'inadimplentes' ? 'active-tab' : '' ?>" style="font-size: 0.82rem; padding: 8px 16px; text-decoration: none; border-radius: 10px; <?= count($inadimplentesList) > 0 ? 'border-color: rgba(239, 68, 68, 0.5); color: #f87171;' : '' ?>">
        🚫 Inadimplentes & Cortes (<?= count($inadimplentesList) ?>)
    </a>
    <a href="/app/agendou/admin/super.php?tab=faturas#faturas" class="btn-secondary super-tab-btn <?= $curTab === 'faturas' ? 'active-tab' : '' ?>" style="font-size: 0.82rem; padding: 8px 16px; text-decoration: none; border-radius: 10px;">
        💳 Histórico de Pagamentos (<?= count($invoices) ?>)
    </a>
    <a href="/app/agendou/admin/super.php?tab=configuracoes#configuracoes" class="btn-secondary super-tab-btn <?= $curTab === 'configuracoes' ? 'active-tab' : '' ?>" style="font-size: 0.82rem; padding: 8px 16px; text-decoration: none; border-radius: 10px;">
        ⚙️ Configurações & Chave PIX
    </a>
</div>

<style>
.super-tab-btn.active-tab {
    background: var(--primary) !important;
    color: #000 !important;
    font-weight: 800 !important;
    border-color: var(--primary) !important;
}
.super-section {
    margin-bottom: 30px;
    scroll-margin-top: 80px;
}
</style>

<!-- ALERTAS DO SISTEMA -->
<?php if (isset($_GET['billing_checked'])): ?>
    <div style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.4); color: #facc15; padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 24px;">
        ✓ Varredura concluída: <strong><?= (int)$_GET['cut'] ?></strong> estabelecimentos cortados por atraso grave (&gt;2 dias de carência) e <strong><?= (int)$_GET['past'] ?></strong> marcados como vencidos.
    </div>
<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'settings_saved'): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: var(--primary); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 24px;">
        ✓ <strong>Configurações Globais Salvas com Sucesso!</strong> Chave PIX, credenciais do Mercado Pago e tolerância atualizadas.
    </div>
<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'suspended'): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: var(--red); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 24px;">
        🚫 <strong>Sistema Cortado com Sucesso!</strong> O painel da barbearia foi bloqueado e o link público de agendamentos foi suspenso.
    </div>
<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'renewed'): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: var(--primary); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 24px;">
        ✓ <strong>Pagamento Confirmado!</strong> <?= htmlspecialchars($_GET['tenant'] ?? '') ?> renovada por +30 dias e sistema totalmente reativado.
    </div>
<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: var(--primary); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 24px;">
        ✓ Dados da assinatura atualizados com sucesso.
    </div>
<?php elseif (isset($_GET['created'])): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: var(--primary); padding: 16px 20px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 24px;">
        🎉 <strong><?= htmlspecialchars($_GET['new_name'] ?? '') ?></strong> cadastrada com sucesso! Login: <code><?= htmlspecialchars($_GET['new_login'] ?? '') ?></code> | Senha: <code><?= htmlspecialchars($_GET['new_pass'] ?? '') ?></code>
    </div>
<?php elseif ($errorMsg): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: var(--red); padding: 14px 18px; border-radius: var(--radius-md); font-size: 0.88rem; margin-bottom: 24px;">
        ⚠️ <?= htmlspecialchars($errorMsg) ?>
    </div>
<?php endif; ?>

<!-- SAAS FINANCIAL METRICS ROW -->
<div class="metrics-row" style="margin-bottom: 28px;">
    <!-- MRR -->
    <div class="metric-box" style="border-color: rgba(250, 204, 21, 0.3);">
        <div class="metric-box-header"><span>MRR (Receita Recorrente)</span><span style="color: #facc15;">💰</span></div>
        <div class="metric-big-val" style="color: #facc15;">R$ <?= number_format($totalMRR, 2, ',', '.') ?></div>
        <div class="metric-sub">Faturamento mensal contratado</div>
    </div>
    <!-- Assinantes em dia -->
    <div class="metric-box" style="border-color: rgba(16, 185, 129, 0.3);">
        <div class="metric-box-header"><span>Assinantes em Dia</span><span style="color: var(--primary);">🟢</span></div>
        <div class="metric-big-val" style="color: var(--primary);"><?= $activeTenants ?></div>
        <div class="metric-sub">Sistemas 100% liberados</div>
    </div>
    <!-- Em atraso -->
    <div class="metric-box" style="border-color: rgba(245, 158, 11, 0.3);">
        <div class="metric-box-header"><span>Mensalidades em Atraso</span><span style="color: var(--orange);">⚠️</span></div>
        <div class="metric-big-val" style="color: var(--orange);"><?= $pastDueTenants ?></div>
        <div class="metric-sub">Aguardando regularização</div>
    </div>
    <!-- Cortados / Suspensos -->
    <div class="metric-box" style="border-color: rgba(239, 68, 68, 0.3);">
        <div class="metric-box-header"><span>Sistemas Cortados</span><span style="color: var(--red);">🚫</span></div>
        <div class="metric-big-val" style="color: var(--red);"><?= $suspendedTenants ?></div>
        <div class="metric-sub">Bloqueados por inadimplência</div>
    </div>
</div>

<!-- ======================================================== -->
<!-- SEÇÃO 1: INADIMPLENTES & CORTES (ID: inadimplentes) -->
<!-- ======================================================== -->
<section class="super-section card-box" id="inadimplentes" style="border-color: <?= count($inadimplentesList) > 0 ? 'rgba(239, 68, 68, 0.4)' : 'var(--border-color)' ?>;">
    <div class="card-box-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 style="display: flex; align-items: center; gap: 8px; color: #fff;">
                <span style="color: var(--red);">🚫</span> Inadimplentes & Gestão de Cortes Imediatos
            </h2>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">
                Barbearias com mensalidades vencidas ou sistemas já cortados por falta de pagamento.
            </p>
        </div>
        <span class="badge-status <?= count($inadimplentesList) > 0 ? 'badge-cancelled' : 'badge-confirmed' ?>" style="font-size: 0.8rem;">
            <?= count($inadimplentesList) ?> Inadimplente(s)
        </span>
    </div>

    <?php if (empty($inadimplentesList)): ?>
        <div style="text-align: center; padding: 40px 20px; background: rgba(16, 185, 129, 0.04); border-radius: var(--radius-md); border: 1px dashed rgba(16, 185, 129, 0.3);">
            <div style="font-size: 2.2rem; margin-bottom: 8px;">🎉</div>
            <strong style="color: var(--primary); font-size: 1.1rem; display: block; margin-bottom: 4px;">Nenhuma Inadimplência Registrada!</strong>
            <p style="color: var(--text-secondary); font-size: 0.85rem; max-width: 480px; margin: 0 auto;">
                Todos os estabelecimentos estão em dia com seus pagamentos. Quando alguma mensalidade vencer, ela aparecerá aqui automaticamente com os botões de cobrança no WhatsApp e corte imediato.
            </p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Estabelecimento</th>
                        <th>Dono / WhatsApp</th>
                        <th>Valor & Plano</th>
                        <th>Vencimento & Tolerância</th>
                        <th>Situação Atual</th>
                        <th>Ações de Cobrança & Corte</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inadimplentesList as $t): 
                        $subStatus = $t['subscription_status'] ?? 'active';
                        $sysStatus = $t['status'] ?? 'active';
                        $isSuspended = ($subStatus === 'suspended' || $sysStatus === 'suspended');
                        $isFreePlan = ($t['plan'] === 'free' || (float)$t['monthly_price'] <= 0);
                        $dueDate = $t['next_due_date'];
                        
                        $diff = 0;
                        if ($dueDate) {
                            $diff = (int)((strtotime($dueDate) - strtotime($today)) / 86400);
                        }
                        $cleanWa = preg_replace('/\D/', '', $t['whatsapp']);
                        $waOwner = $t['owner_name'] ?: $t['name'];
                        $dueDateFormatted = $dueDate ? date('d/m/Y', strtotime($dueDate)) : 'a regularizar';
                        $valFormatted = $isFreePlan ? 'Gratuito' : ('R$ ' . number_format((float)$t['monthly_price'], 2, ',', '.'));
                        $pixKeyToPay = $t['pix_key'] ?: $defaultPixKey;

                        $cobrancaMsg = urlencode("Olá {$waOwner}! Tudo bem?\nPassando para lembrar da mensalidade do sistema AGENDOU da *{$t['name']}*.\n\n📅 *Vencimento:* {$dueDateFormatted}\n💰 *Valor:* {$valFormatted}\n🔑 *Chave PIX:* {$pixKeyToPay}\n\nAssim que efetuar o pagamento via PIX, nos envie o comprovante para manter seus agendamentos ativos. Obrigado! 👍");
                        $waCobrancaUrl = "https://wa.me/55{$cleanWa}?text={$cobrancaMsg}";

                        $avisoCorteMsg = urlencode("⚠️ *AVISO IMPORTANTE - AGENDOU*\n\nOlá {$waOwner}! Informamos que o sistema de agendamento online da *{$t['name']}* foi temporariamente suspenso.\n\nPara reativar sua agenda e o link dos clientes imediatamente, entre em contato conosco por aqui!");
                        $waCorteUrl = "https://wa.me/55{$cleanWa}?text={$avisoCorteMsg}";
                    ?>
                        <tr style="background: rgba(239, 68, 68, 0.06); border-left: 4px solid var(--red);">
                            <td>
                                <strong style="color: #fff; font-size: 0.95rem;"><?= htmlspecialchars($t['name']) ?></strong><br>
                                <span style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($t['city'] ?: '--') ?>/<?= htmlspecialchars($t['state'] ?: '--') ?></span>
                            </td>
                            <td>
                                <a href="https://wa.me/55<?= $cleanWa ?>" target="_blank" style="color: #25d366; text-decoration: none; font-weight: 700; font-size: 0.85rem;">
                                    📱 <?= htmlspecialchars($t['whatsapp']) ?>
                                </a><br>
                                <span style="font-size: 0.75rem; color: var(--text-secondary);"><?= htmlspecialchars($t['owner_name'] ?: 'Proprietário') ?></span>
                            </td>
                            <td>
                                <?php if ($isFreePlan): ?>
                                    <strong style="color: #10b981; font-size: 0.95rem;">Gratuito</strong><br>
                                    <span class="badge-status" style="font-size: 0.65rem; padding: 2px 6px; background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">PLANO FREE</span>
                                <?php else: ?>
                                    <strong style="color: #facc15; font-size: 0.95rem;"><?= $valFormatted ?></strong><br>
                                    <span class="badge-status badge-confirmed" style="font-size: 0.65rem;">PLANO <?= strtoupper($t['plan']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isFreePlan): ?>
                                    <strong style="color: var(--text-muted); font-size: 0.88rem;">--</strong><br>
                                    <span style="color: #10b981; font-size: 0.75rem; font-weight: 700;">Isento</span>
                                <?php else: ?>
                                    <strong style="color: #fff; font-size: 0.88rem;"><?= $dueDateFormatted ?></strong><br>
                                    <span style="color: var(--red); font-size: 0.75rem; font-weight: 700;">
                                        <?= $diff < 0 ? "Venceu há " . abs($diff) . " dia(s)" : "Vence HOJE" ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isSuspended): ?>
                                    <span class="badge-status badge-cancelled" style="font-size: 0.72rem; padding: 4px 8px;">
                                        🚫 SISTEMA CORTADO
                                    </span>
                                <?php else: ?>
                                    <span class="badge-status badge-pending" style="font-size: 0.72rem; padding: 4px 8px;">
                                        ⚠️ EM ATRASO (CARÊNCIA)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    <!-- Cobrar WhatsApp -->
                                    <a href="<?= $isSuspended ? $waCorteUrl : $waCobrancaUrl ?>" target="_blank" class="btn-secondary" style="padding: 5px 10px; font-size: 0.75rem; background: rgba(37, 211, 102, 0.15); border-color: rgba(37, 211, 102, 0.4); color: #25d366; font-weight: 700; text-decoration: none;" title="Enviar mensagem de cobrança no WhatsApp">
                                        💬 Cobrar no WhatsApp
                                    </a>

                                    <!-- Cortar / Bloquear Imediatamente -->
                                    <?php if (!$isSuspended): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('ATENÇÃO: Deseja realmente CORTAR o acesso desta barbearia agora?');">
                                            <input type="hidden" name="action" value="suspend_tenant">
                                            <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                                            <input type="hidden" name="reason" value="Mensalidade em atraso">
                                            <button type="submit" class="btn-secondary" style="padding: 5px 10px; font-size: 0.75rem; border-color: rgba(239, 68, 68, 0.4); color: var(--red); font-weight: 700;" title="Cortar sistema imediatamente">
                                                🚫 Cortar Acesso
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Pago (+30 dias) -->
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Confirmar pagamento desta barbearia e renovar por +30 dias?');">
                                        <input type="hidden" name="action" value="renew_tenant">
                                        <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn-emerald" style="padding: 5px 10px; font-size: 0.75rem; font-weight: 800;" title="Quitar mensalidade e estender prazo">
                                            ✓ Pago (+30d)
                                        </button>
                                    </form>

                                    <!-- Editar -->
                                    <button type="button" class="btn-secondary" onclick='openEditModal(<?= json_encode($t) ?>)' style="padding: 5px 8px; font-size: 0.75rem;" title="Editar assinatura">
                                        ✏️
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- ======================================================== -->
<!-- SEÇÃO 2: TODAS AS BARBEARIAS & ASSINANTES (ID: assinantes) -->
<!-- ======================================================== -->
<section class="super-section card-box" id="assinantes">
    <div class="card-box-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2>🏢 Barbearias & Todos os Assinantes</h2>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">
                Visão completa de estabelecimentos cadastrados, planos, vencimentos e links públicos.
            </p>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-primary" onclick="openNewTenantModal()" style="font-size: 0.82rem; padding: 8px 14px;">
                ➕ Nova Barbearia
            </button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Empresa / Barbearia</th>
                    <th>WhatsApp / Dono</th>
                    <th>Plano / Mensalidade</th>
                    <th>Próximo Vencimento</th>
                    <th>Status do Pagamento</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenants as $t): 
                    $subStatus = $t['subscription_status'] ?? 'active';
                    $sysStatus = $t['status'] ?? 'active';
                    $isSuspended = ($subStatus === 'suspended' || $sysStatus === 'suspended');
                    $isFreePlan = ($t['plan'] === 'free' || (float)$t['monthly_price'] <= 0);
                    $dueDate = $t['next_due_date'];
                    
                    $isOverdue = false;
                    $daysText = '';
                    if (!$isFreePlan && $dueDate) {
                        $diff = (int)((strtotime($dueDate) - strtotime($today)) / 86400);
                        if ($diff < 0) {
                            $isOverdue = true;
                            $daysText = "Venceu há " . abs($diff) . " dia(s)";
                        } elseif ($diff === 0) {
                            $daysText = "Vence HOJE";
                        } else {
                            $daysText = "Vence em {$diff} dia(s)";
                        }
                    } elseif ($isFreePlan) {
                        $daysText = "Sem mensalidade";
                    }

                    $cleanWa = preg_replace('/\D/', '', $t['whatsapp']);
                    $waOwner = $t['owner_name'] ?: $t['name'];
                    $dueDateFormatted = $dueDate ? date('d/m/Y', strtotime($dueDate)) : 'a regularizar';
                    $valFormatted = $isFreePlan ? 'Gratuito' : ('R$ ' . number_format((float)$t['monthly_price'], 2, ',', '.'));
                    $pixKeyToPay = $t['pix_key'] ?: $defaultPixKey;

                    if ($isFreePlan) {
                        $cobrancaMsg = urlencode("Olá {$waOwner}! Tudo bem?\nPassando para acompanhar seu uso do sistema AGENDOU da *{$t['name']}* (Plano Gratuito).\n\nQualquer dúvida ou caso queira fazer upgrade para recursos ilimitados e Google Agenda, conte conosco! 👍");
                    } else {
                        $cobrancaMsg = urlencode("Olá {$waOwner}! Tudo bem?\nPassando para lembrar da mensalidade do sistema AGENDOU da *{$t['name']}*.\n\n📅 *Vencimento:* {$dueDateFormatted}\n💰 *Valor:* {$valFormatted}\n🔑 *Chave PIX:* {$pixKeyToPay}\n\nAssim que efetuar o pagamento via PIX, nos envie o comprovante para manter seus agendamentos ativos. Obrigado! 👍");
                    }
                    $waCobrancaUrl = "https://wa.me/55{$cleanWa}?text={$cobrancaMsg}";
                ?>
                    <tr style="<?= $isSuspended ? 'background: rgba(239, 68, 68, 0.05);' : '' ?>">
                        <td>
                            <strong style="color: #fff; font-size: 0.95rem;"><?= htmlspecialchars($t['name']) ?></strong><br>
                            <span style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($t['category'] ?? 'Barbearia') ?> • <?= htmlspecialchars($t['city'] ?: '--') ?>/<?= htmlspecialchars($t['state'] ?: '--') ?></span>
                            <div style="margin-top: 6px; display: flex; flex-direction: column; gap: 3px;">
                                <a href="/<?= urlencode($t['slug']) ?>" target="_blank" style="font-size: 0.74rem; color: #facc15; text-decoration: none; font-weight: 700;" title="Link Curto de Divulgação">
                                    👉 4u.ia.br/<?= htmlspecialchars($t['slug']) ?>
                                </a>
                                <a href="/app/agendou/?slug=<?= urlencode($t['slug']) ?>" target="_blank" style="font-size: 0.68rem; color: var(--cyan); text-decoration: none;">
                                    🔗 Link do App →
                                </a>
                            </div>
                        </td>
                        <td>
                            <a href="https://wa.me/55<?= $cleanWa ?>" target="_blank" style="color: #25d366; text-decoration: none; font-weight: 700; font-size: 0.85rem;">
                                📱 <?= htmlspecialchars($t['whatsapp']) ?>
                            </a><br>
                            <span style="font-size: 0.75rem; color: var(--text-secondary);"><?= htmlspecialchars($t['owner_name'] ?: 'Proprietário') ?></span>
                        </td>
                        <td>
                            <?php if ($isFreePlan): ?>
                                <strong style="color: #10b981; font-size: 0.95rem;">Gratuito</strong><br>
                                <span class="badge-status" style="font-size: 0.65rem; padding: 2px 6px; background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">PLANO FREE</span>
                            <?php else: ?>
                                <strong style="color: #facc15; font-size: 0.95rem;"><?= $valFormatted ?></strong><br>
                                <span class="badge-status badge-confirmed" style="font-size: 0.65rem; padding: 2px 6px;">PLANO <?= strtoupper($t['plan']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isFreePlan): ?>
                                <strong style="color: var(--text-muted); font-size: 0.88rem;">--</strong><br>
                                <span style="font-size: 0.72rem; color: #10b981; font-weight: 600;">Isento (Gratuito)</span>
                            <?php else: ?>
                                <strong style="color: #fff; font-size: 0.88rem;">
                                    <?= $dueDate ? date('d/m/Y', strtotime($dueDate)) : '--' ?>
                                </strong><br>
                                <span style="font-size: 0.72rem; color: <?= $isOverdue ? '#ef4444' : 'var(--primary)' ?>; font-weight: 600;">
                                    <?= $daysText ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isSuspended): ?>
                                <span class="badge-status badge-cancelled" style="font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;">
                                    <span>🚫</span> CORTADO / BLOQUEADO
                                </span>
                            <?php elseif ($isFreePlan): ?>
                                <span class="badge-status badge-confirmed" style="font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px; background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                                    <span>🟢</span> GRATUITO
                                </span>
                            <?php elseif ($isOverdue): ?>
                                <span class="badge-status badge-pending" style="font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;">
                                    <span>⚠️</span> EM ATRASO
                                </span>
                            <?php else: ?>
                                <span class="badge-status badge-confirmed" style="font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;">
                                    <span>🟢</span> EM DIA
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <a href="/app/agendou/admin/switch.php?role=barber&tenant=<?= $t['id'] ?>" class="btn-secondary" style="padding: 5px 8px; font-size: 0.72rem; color: #38bdf8; text-decoration: none; font-weight: 700; border-color: rgba(56, 189, 248, 0.4);" title="Acessar painel como este estabelecimento">
                                    👁️ Entrar
                                </a>

                                <a href="<?= $waCobrancaUrl ?>" target="_blank" class="btn-secondary" style="padding: 5px 8px; font-size: 0.72rem; color: #25d366; text-decoration: none;" title="<?= $isFreePlan ? 'Mensagem no WhatsApp de acompanhamento' : 'Cobrança via WhatsApp' ?>">
                                    💬
                                </a>

                                <?php if ($isSuspended): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Deseja reativar esta barbearia?');">
                                        <input type="hidden" name="action" value="renew_tenant">
                                        <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn-emerald" style="padding: 5px 10px; font-size: 0.72rem; font-weight: 800;" title="Reativar barbearia">
                                            ✓ Reativar
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <?php if (!$isFreePlan): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Confirmar pagamento desta barbearia e renovar por +30 dias?');">
                                            <input type="hidden" name="action" value="renew_tenant">
                                            <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                                            <button type="submit" class="btn-emerald" style="padding: 5px 8px; font-size: 0.72rem; font-weight: 800;" title="Quitar mensalidade">
                                                ✓ Pago
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Deseja realmente CORTAR o sistema desta barbearia agora?');">
                                        <input type="hidden" name="action" value="suspend_tenant">
                                        <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                                        <input type="hidden" name="reason" value="<?= $isFreePlan ? 'Suspenso pela administração' : 'Mensalidade vencida' ?>">
                                        <button type="submit" class="btn-secondary" style="padding: 5px 8px; font-size: 0.72rem; color: var(--red);" title="Cortar sistema imediatamente">
                                            🚫 Cortar
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <button type="button" class="btn-secondary" onclick='openEditModal(<?= json_encode($t) ?>)' style="padding: 5px 8px; font-size: 0.72rem;" title="Editar assinatura">
                                    ✏️
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ======================================================== -->
<!-- SEÇÃO 3: HISTÓRICO DE PAGAMENTOS (ID: faturas) -->
<!-- ======================================================== -->
<section class="super-section card-box" id="faturas">
    <div class="card-box-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 style="display: flex; align-items: center; gap: 8px;">
                <span>💳</span> Histórico de Pagamentos Confirmados (Quitações)
            </h2>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">
                Registro de mensalidades quitadas via PIX do Mercado Pago ou confirmadas manualmente pelo Fundador.
            </p>
        </div>
        <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); padding: 6px 14px; border-radius: 20px; font-size: 0.82rem; color: var(--primary); font-weight: 700;">
            Total Quitado: R$ <?= number_format($totalInvoicesPaid, 2, ',', '.') ?>
        </div>
    </div>

    <?php if (empty($invoices)): ?>
        <p style="font-size: 0.85rem; color: var(--text-muted); padding: 20px 0; text-align: center;">
            Nenhum pagamento registrado ainda. Ao clicar em "✓ Pago (+30 Dias)" ou quando um lojista pagar via PIX, a quitação será salva aqui automaticamente.
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID Fatura</th>
                        <th>Estabelecimento</th>
                        <th>Valor Quitado</th>
                        <th>Data do Pagamento</th>
                        <th>Referência / Vencimento</th>
                        <th>Forma de Pagamento</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><strong style="color: var(--text-secondary); font-family: var(--font-mono);">#<?= $inv['id'] ?></strong></td>
                            <td><strong style="color: #fff;"><?= htmlspecialchars($inv['tenant_name']) ?></strong></td>
                            <td><strong style="color: #facc15;">R$ <?= number_format((float)$inv['amount'], 2, ',', '.') ?></strong></td>
                            <td><?= date('d/m/Y H:i', strtotime($inv['paid_at'])) ?></td>
                            <td>
                                <?= htmlspecialchars($inv['reference_month'] ?: 'Mensalidade') ?><br>
                                <span style="font-size: 0.72rem; color: var(--text-muted);">Novo venc: <?= date('d/m/Y', strtotime($inv['due_date'])) ?></span>
                            </td>
                            <td><span style="font-size: 0.75rem; color: var(--cyan);">⚡ PIX Mercado Pago</span></td>
                            <td><span class="badge-status badge-confirmed">✓ PAGO</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- ======================================================== -->
<!-- SEÇÃO 4: CONFIGURAÇÕES GLOBAIS DO SAAS (ID: configuracoes) -->
<!-- ======================================================== -->
<section class="super-section card-box" id="configuracoes">
    <div class="card-box-header">
        <h2 style="display: flex; align-items: center; gap: 8px;">
            <span>⚙️</span> Configurações Globais do SaaS & Chave PIX
        </h2>
        <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">
            Configure a chave PIX padrão de recebimento do SaaS, dias de carência antes do corte e chaves de API.
        </p>
    </div>

    <form method="POST" action="/app/agendou/admin/super.php">
        <input type="hidden" name="action" value="save_global_settings">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 20px;">
            
            <!-- Chave PIX Oficial -->
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px; padding: 18px;">
                <label style="display: block; font-size: 0.85rem; font-weight: 800; color: #fff; margin-bottom: 6px;">
                    🔑 Chave PIX Padrão do Fundador (Recebimento)
                </label>
                <input type="text" name="default_pix_key" value="<?= htmlspecialchars($defaultPixKey) ?>" required class="form-input" style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; color: #fff; font-family: var(--font-mono); margin-bottom: 8px;">
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 12px;">
                    Exibida no comprovante e na tela de cobrança manual quando o lojista optar por PIX direto.
                </p>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.78rem; color: var(--text-secondary); cursor: pointer;">
                    <input type="checkbox" name="update_all_tenants_pix" value="1">
                    <span>Sincronizar esta chave PIX em todas as barbearias já cadastradas</span>
                </label>
            </div>

            <!-- Dias de Carência de Corte -->
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px; padding: 18px;">
                <label style="display: block; font-size: 0.85rem; font-weight: 800; color: #fff; margin-bottom: 6px;">
                    ⏳ Tolerância de Corte (Dias de Carência)
                </label>
                <input type="number" name="grace_period_days" value="<?= $gracePeriodDays ?>" min="1" max="15" required class="form-input" style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; color: #fff; margin-bottom: 8px;">
                <p style="font-size: 0.75rem; color: var(--text-muted);">
                    Quantidade de dias de tolerância após o vencimento antes do sistema cortar o acesso automaticamente. (Padrão: 2 dias).
                </p>
            </div>

        </div>

        <!-- Credenciais Mercado Pago & Google -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 24px;">
            
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px; padding: 18px;">
                <strong style="color: var(--cyan); font-size: 0.88rem; display: block; margin-bottom: 12px;">
                    💳 Mercado Pago PIX (Token & Chaves)
                </strong>
                <div style="margin-bottom: 10px;">
                    <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">MP Access Token (Produção)</label>
                    <input type="password" name="mp_access_token" value="<?= htmlspecialchars($mpAccessToken) ?>" class="form-input" style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: #fff; font-family: var(--font-mono); font-size: 0.8rem;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">MP Public Key</label>
                    <input type="text" name="mp_public_key" value="<?= htmlspecialchars($mpPublicKey) ?>" class="form-input" style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: #fff; font-family: var(--font-mono); font-size: 0.8rem;">
                </div>
            </div>

            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px; padding: 18px;">
                <strong style="color: var(--primary); font-size: 0.88rem; display: block; margin-bottom: 12px;">
                    📅 Google Cloud OAuth (Calendar & GIS)
                </strong>
                <div style="margin-bottom: 10px;">
                    <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">Client ID</label>
                    <input type="text" name="google_client_id" value="<?= htmlspecialchars($googleClientId) ?>" class="form-input" style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: #fff; font-family: var(--font-mono); font-size: 0.8rem;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">Client Secret</label>
                    <input type="password" name="google_client_secret" value="<?= htmlspecialchars($googleClientSecret) ?>" class="form-input" style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: #fff; font-family: var(--font-mono); font-size: 0.8rem;">
                </div>
            </div>

        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn-primary" style="font-weight: 800; padding: 12px 28px; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4);">
                💾 Salvar Configurações do SaaS
            </button>
        </div>
    </form>
</section>

<!-- MODAL CADASTRAR NOVA BARBEARIA -->
<div id="modalNewTenant" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; width: 100%; max-width: 660px; max-height: 90vh; overflow-y: auto; padding: 28px; box-shadow: 0 25px 50px rgba(0,0,0,0.7);">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 16px;">
            <div>
                <h2 style="font-size: 1.3rem; color: #fff; margin-bottom: 4px;">Cadastrar Novo Assinante (Barbearia)</h2>
                <p style="font-size: 0.82rem; color: var(--text-muted);">Configure os dados cadastrais, plano e cobrança do novo cliente do seu SaaS.</p>
            </div>
            <button type="button" onclick="closeNewTenantModal()" style="background: none; border: none; color: var(--text-muted); font-size: 24px; cursor: pointer;">✕</button>
        </div>

        <form method="POST" action="/app/agendou/admin/super.php">
            <input type="hidden" name="action" value="create_tenant">

            <div style="font-size: 0.82rem; font-weight: 800; color: var(--primary); text-transform: uppercase; margin-bottom: 10px;">
                1. Dados do Estabelecimento
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Nome da Barbearia / Empresa *</label>
                    <input type="text" name="name" required class="form-input" placeholder="Ex: Barbearia Navalha de Ouro" oninput="autoSlug(this.value)" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Slug / Link Personalizado *</label>
                    <input type="text" name="slug" id="inputNewSlug" required class="form-input" placeholder="Ex: navalha" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">WhatsApp do Dono (para cobrança) *</label>
                    <input type="text" name="whatsapp" required class="form-input" placeholder="DDD + Número" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Cidade</label>
                    <input type="text" name="city" class="form-input" placeholder="Ex: Uberlândia" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Estado (UF)</label>
                    <input type="text" name="state" maxlength="2" class="form-input" placeholder="MG" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff; text-transform: uppercase;">
                </div>
            </div>

            <div style="font-size: 0.82rem; font-weight: 800; color: #facc15; text-transform: uppercase; margin: 16px 0 10px; border-top: 1px solid var(--border-color); padding-top: 14px;">
                2. Plano SaaS & Cobrança de Mensalidade
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div class="form-group">
                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Plano</label>
                    <select name="plan" id="selectNewPlan" class="form-input" onchange="autoPlanPrice(this.value)" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                        <option value="free">FREE — R$ 0,00 (Gratuito)</option>
                        <option value="starter" selected>🚀 STARTER — R$ 19,90/mês</option>
                        <option value="plus">⭐ PLUS — R$ 39,90/mês</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Valor Mensalidade (R$)</label>
                    <input type="text" name="monthly_price" id="inputNewPrice" value="19,90" class="form-input" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                    <div id="newPriceHint" style="margin-top: 4px; font-size: 0.72rem;"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">1º Vencimento</label>
                    <input type="date" name="next_due_date" id="inputNewDueDate" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" class="form-input" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
            </div>

            <div style="font-size: 0.82rem; font-weight: 800; color: var(--cyan); text-transform: uppercase; margin: 16px 0 10px; border-top: 1px solid var(--border-color); padding-top: 14px;">
                3. Acesso do Dono da Barbearia
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div class="form-group">
                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">E-mail de Login do Dono *</label>
                    <input type="email" name="admin_email" required class="form-input" placeholder="dono@barbearia.com" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
                <div class="form-group">
                    <label class="form-label" style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Senha Provisória</label>
                    <input type="text" name="admin_password" value="123456" class="form-input" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; color: #fff;">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" onclick="closeNewTenantModal()" class="btn-secondary" style="padding: 10px 18px;">Cancelar</button>
                <button type="submit" class="btn-primary" style="padding: 10px 24px; font-weight: 800;">✓ Salvar e Ativar Assinante</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDITAR ASSINATURA & VENCIMENTO -->
<div id="modalEditSub" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; width: 100%; max-width: 520px; padding: 24px; box-shadow: 0 25px 50px rgba(0,0,0,0.7);">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
            <h2 style="font-size: 1.15rem; color: #fff;">Editar Assinatura & Mensalidade</h2>
            <button type="button" onclick="closeEditModal()" style="background: none; border: none; color: var(--text-muted); font-size: 20px; cursor: pointer;">✕</button>
        </div>

        <form method="POST" action="/app/agendou/admin/super.php">
            <input type="hidden" name="action" value="update_subscription">
            <input type="hidden" name="tenant_id" id="editTenantId">

            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Barbearia</label>
                <input type="text" id="editTenantName" readonly style="width: 100%; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: var(--text-muted);">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Plano</label>
                    <select name="plan" id="editPlan" onchange="autoPlanPriceEdit(this.value)" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: #fff;">
                        <option value="free">FREE (Gratuito - R$ 0,00)</option>
                        <option value="starter">STARTER (R$ 19,90/mês)</option>
                        <option value="plus">PLUS (R$ 39,90/mês)</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Valor Mensal (R$)</label>
                    <input type="text" name="monthly_price" id="editMonthlyPrice" required style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: #fff;">
                    <div id="editPriceHint" style="margin-top: 4px; font-size: 0.72rem;"></div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Status da Assinatura</label>
                    <select name="subscription_status" id="editSubStatus" style="width: 100%; background: #18181b; border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: #fff;">
                        <option value="active">🟢 Em Dia (Ativo)</option>
                        <option value="past_due">🟡 Em Atraso</option>
                        <option value="suspended">🚫 Cortado / Suspenso</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Próximo Vencimento</label>
                    <input type="date" name="next_due_date" id="editNextDueDate" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: #fff;">
                    <div id="editDueDateHint" style="margin-top: 4px; font-size: 0.72rem; color: var(--text-muted);"></div>
                </div>
            </div>

            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 4px;">Chave PIX para Cobrança</label>
                <input type="text" name="pix_key" id="editPixKey" style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 12px; color: #fff;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeEditModal()" class="btn-secondary" style="padding: 8px 16px;">Cancelar</button>
                <button type="submit" class="btn-primary" style="padding: 8px 20px;">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
function openNewTenantModal() {
    document.getElementById('modalNewTenant').style.display = 'flex';
}
function closeNewTenantModal() {
    document.getElementById('modalNewTenant').style.display = 'none';
}

function autoSlug(val) {
    const clean = val.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
    document.getElementById('inputNewSlug').value = clean;
}

function autoPlanPrice(p) {
    const input = document.getElementById('inputNewPrice');
    const hint = document.getElementById('newPriceHint');
    const due = document.getElementById('inputNewDueDate');
    if (p === 'free') {
        input.value = '0,00';
        if (hint) hint.innerHTML = '<span style="color: #10b981; font-weight: 700;">✓ Gratuito — Sem cobrança</span>';
        if (due) due.value = '';
    } else if (p === 'starter') {
        input.value = '19,90';
        if (hint) hint.innerHTML = '';
        if (due && !due.value) due.value = '<?= date('Y-m-d', strtotime('+30 days')) ?>';
    } else if (p === 'plus') {
        input.value = '39,90';
        if (hint) hint.innerHTML = '';
        if (due && !due.value) due.value = '<?= date('Y-m-d', strtotime('+30 days')) ?>';
    }
}

function autoPlanPriceEdit(p) {
    const input = document.getElementById('editMonthlyPrice');
    if (p === 'free') {
        input.value = '0,00';
    } else if (p === 'starter') {
        input.value = '19,90';
    } else if (p === 'plus') {
        input.value = '39,90';
    }
    handleEditPlanUI(p);
}

function handleEditPlanUI(p) {
    const priceInput = document.getElementById('editMonthlyPrice');
    const priceHint = document.getElementById('editPriceHint');
    const dueDateInput = document.getElementById('editNextDueDate');
    const dueHint = document.getElementById('editDueDateHint');
    if (p === 'free') {
        priceInput.value = '0,00';
        if (priceHint) priceHint.innerHTML = '<span style="color: #10b981; font-weight: 700;">✓ Gratuito (Isento de mensalidade)</span>';
        if (dueDateInput) {
            dueDateInput.removeAttribute('required');
        }
        if (dueHint) dueHint.innerHTML = '<span style="color: #10b981;">Isento para plano FREE</span>';
    } else {
        if (priceHint) priceHint.innerHTML = '';
        if (dueDateInput) {
            dueDateInput.setAttribute('required', 'required');
            if (!dueDateInput.value) {
                const d = new Date();
                d.setDate(d.getDate() + 30);
                dueDateInput.value = d.toISOString().split('T')[0];
            }
        }
        if (dueHint) dueHint.innerHTML = '';
    }
}

function openEditModal(tenant) {
    document.getElementById('editTenantId').value = tenant.id;
    document.getElementById('editTenantName').value = '#' + tenant.id + ' - ' + tenant.name;
    const plan = tenant.plan || 'starter';
    document.getElementById('editPlan').value = plan;
    
    let price = 0;
    if (plan === 'free') {
        price = 0;
    } else if (tenant.monthly_price !== null && tenant.monthly_price !== undefined && tenant.monthly_price !== '') {
        price = parseFloat(tenant.monthly_price);
        if (price <= 0) price = (plan === 'plus' ? 39.90 : 19.90);
    } else {
        price = (plan === 'plus' ? 39.90 : 19.90);
    }

    document.getElementById('editMonthlyPrice').value = price.toFixed(2).replace('.', ',');
    document.getElementById('editNextDueDate').value = (plan === 'free' ? '' : (tenant.next_due_date || ''));
    document.getElementById('editSubStatus').value = tenant.subscription_status || 'active';
    document.getElementById('editPixKey').value = tenant.pix_key || '<?= addslashes($defaultPixKey) ?>';
    
    handleEditPlanUI(plan);
    document.getElementById('modalEditSub').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('modalEditSub').style.display = 'none';
}

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeNewTenantModal();
        closeEditModal();
    }
});

// Auto focus/highlight hash anchor on page load
window.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash;
    if (hash) {
        const el = document.querySelector(hash);
        if (el) {
            setTimeout(() => {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                el.style.boxShadow = '0 0 25px rgba(250, 204, 21, 0.4)';
                setTimeout(() => { el.style.boxShadow = ''; }, 2500);
            }, 100);
        }
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
