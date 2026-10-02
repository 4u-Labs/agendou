<?php
// ========================================================
// AGENDOU - Self-Service Onboarding / Cadastro de Barbearia
// ========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
$pdo = Database::getConnection();

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $businessName = trim($_POST['business_name'] ?? '');
    $category = trim($_POST['category'] ?? 'Barbearia');
    $whatsapp = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = strtoupper(trim($_POST['state'] ?? ''));
    $ownerName = trim($_POST['owner_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$businessName || !$whatsapp || !$email || !$password) {
        $error = 'Por favor, preencha todos os campos obrigatórios.';
    } else {
        // Generate clean slug
        $baseSlug = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace([' ', '.'], '-', $businessName)));
        if (!$baseSlug) $baseSlug = 'barbearia-' . time();

        $stmtSlug = $pdo->prepare("SELECT id FROM tenants WHERE slug = ?");
        $stmtSlug->execute([$baseSlug]);
        if ($stmtSlug->fetch()) {
            $baseSlug .= '-' . rand(100, 999);
        }

        // Check if user already exists
        $stmtCheckU = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheckU->execute([$email]);
        if ($stmtCheckU->fetch()) {
            $error = 'Este e-mail já possui uma conta no AGENDOU. Faça login ou use outro e-mail.';
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Create Tenant
                $selectedPlan = in_array($_POST['plan'] ?? '', ['free', 'starter', 'plus']) ? $_POST['plan'] : 'free';
                $monthlyPrice = ($selectedPlan === 'plus') ? 39.90 : (($selectedPlan === 'starter') ? 19.90 : 0.00);

                $stmtInT = $pdo->prepare("
                    INSERT INTO tenants (name, slug, category, whatsapp, email, city, state, plan, monthly_price, next_due_date, subscription_status, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, date('now', '+30 days'), 'active', 'active')
                ");
                $stmtInT->execute([$businessName, $baseSlug, $category, $whatsapp, $email, $city, $state, $selectedPlan, $monthlyPrice]);
                $newTenantId = (int)$pdo->lastInsertId();

                // 2. Create User
                $passHash = password_hash($password, PASSWORD_DEFAULT);
                $stmtInU = $pdo->prepare("
                    INSERT INTO users (tenant_id, name, email, password, role)
                    VALUES (?, ?, ?, ?, 'tenant_admin')
                ");
                $stmtInU->execute([$newTenantId, $ownerName ?: $businessName, $email, $passHash]);
                $newUserId = (int)$pdo->lastInsertId();

                // 3. Create Business Hours (Seg a Sáb 08:00 - 19:00, Dom fechado)
                $stmtH = $pdo->prepare("
                    INSERT INTO business_hours (tenant_id, day_of_week, open_time, close_time, break_start, break_end, is_closed)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtH->execute([$newTenantId, 0, '08:00', '19:00', '12:00', '13:00', 1]);
                for ($d = 1; $d <= 6; $d++) {
                    $stmtH->execute([$newTenantId, $d, '08:00', '19:00', '12:00', '13:00', 0]);
                }

                // 4. Create First Professional
                $stmtPro = $pdo->prepare("
                    INSERT INTO professionals (tenant_id, name, specialty, email, phone, status)
                    VALUES (?, ?, 'Profissional Especialista', ?, ?, 'active')
                ");
                $stmtPro->execute([$newTenantId, $ownerName ?: 'Profissional 1', $email, $whatsapp]);
                $proId = (int)$pdo->lastInsertId();

                // 5. Create Popular Services
                $defaultServices = [
                    ['name' => 'Corte Masculino Degradê / Social', 'price' => 35.00, 'duration' => 35, 'cat' => 'Cabelo'],
                    ['name' => 'Barba Terapia com Toalha Quente', 'price' => 30.00, 'duration' => 30, 'cat' => 'Barba'],
                    ['name' => 'Combo Corte + Barba VIP', 'price' => 60.00, 'duration' => 50, 'cat' => 'Combos'],
                    ['name' => 'Acabamento / Pezinho', 'price' => 15.00, 'duration' => 15, 'cat' => 'Acabamento']
                ];

                $stmtS = $pdo->prepare("
                    INSERT INTO services (tenant_id, name, category, price, duration_minutes, status)
                    VALUES (?, ?, ?, ?, ?, 'active')
                ");
                $stmtPS = $pdo->prepare("
                    INSERT INTO professional_services (tenant_id, professional_id, service_id)
                    VALUES (?, ?, ?)
                ");

                foreach ($defaultServices as $ds) {
                    $stmtS->execute([$newTenantId, $ds['name'], $ds['cat'], $ds['price'], $ds['duration']]);
                    $svcId = (int)$pdo->lastInsertId();
                    $stmtPS->execute([$newTenantId, $proId, $svcId]);
                }

                $pdo->commit();

                // Gerar Link Curto Oficial (ex: 4u.ia.br/{slug})
                require_once __DIR__ . '/app/Services/UrlShortenerService.php';
                UrlShortenerService::ensureTenantShortLink(['name' => $businessName, 'slug' => $baseSlug]);

                // Auto login
                $_SESSION['agendou_user_id'] = $newUserId;
                $_SESSION['agendou_user_role'] = 'tenant_admin';
                $_SESSION['admin_tenant_id'] = $newTenantId;

                header("Location: /app/agendou/admin/index.php?new=1");
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Erro ao cadastrar estabelecimento: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Cadastre seu Estabelecimento • AGENDOU!!</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/app/agendou/public/icons/favicon-32x32.png"/>
    <link rel="icon" type="image/png" sizes="16x16" href="/app/agendou/public/icons/favicon-16x16.png"/>
    <link rel="apple-touch-icon" href="/app/agendou/public/icons/apple-touch-icon.png"/>
    <link rel="shortcut icon" href="/app/agendou/public/icons/favicon.ico"/>
    <link rel="manifest" href="/app/agendou/manifest.json"/>
    <meta name="theme-color" content="#0284c7"/>
    <meta name="apple-mobile-web-app-capable" content="yes"/>
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
    <meta name="apple-mobile-web-app-title" content="AGENDOU!!"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@600;800&display=swap" rel="stylesheet">
    <link href="/app/agendou/public/css/landing.css?v=1.0" rel="stylesheet"/>
    <style>
        .signup-container {
            max-width: 580px;
            margin: 40px auto 60px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 36px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.6);
        }
        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-size: 0.82rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 6px; }
        .form-input {
            width: 100%;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 14px;
            color: #fff;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-input:focus { border-color: var(--primary); }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .btn-submit-signup {
            width: 100%;
            background: linear-gradient(135deg, var(--primary), #059669);
            border: none;
            color: #fff;
            padding: 16px;
            border-radius: 14px;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            margin-top: 10px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-submit-signup:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
        }
        @media (max-width: 600px) {
            .signup-container { margin: 20px 14px; padding: 24px 18px; }
            .form-grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="landing-ambient"></div>

    <nav class="landing-nav">
        <a href="/app/agendou/" class="landing-brand" style="text-decoration: none; display: flex; align-items: center; gap: 12px;">
            <img src="/app/agendou/public/icons/icon-192.png" alt="AGENDOU!!" style="width: 38px; height: 38px; border-radius: 10px; box-shadow: 0 4px 15px rgba(56, 189, 248, 0.4);">
            <div class="brand-text">
                <strong style="font-size: 1.15rem; color: #fff; letter-spacing: -0.02em;">AGENDOU!!</strong>
                <span style="color: #38bdf8; font-size: 0.72rem; font-weight: 700;">4U.IA.BR SAAS</span>
            </div>
        </a>
        <div class="nav-links">
            <button type="button" onclick="triggerPWAInstall()" class="btn-pwa-install" style="background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.4); color: #38bdf8; padding: 7px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;">
                <span>📲</span> Instalar App
            </button>
            <a href="/app/agendou/admin/" class="btn-login-header">Já sou cadastrado →</a>
        </div>
    </nav>

    <div class="signup-container">
        <div style="text-align: center; margin-bottom: 28px;">
            <div class="badge-hero" style="margin-bottom: 12px;">🚀 COMECE EM MENOS DE 2 MINUTOS</div>
            <h1 style="font-size: 1.6rem; font-weight: 800; color: #fff; margin-bottom: 8px;">Cadastre seu Estabelecimento</h1>
            <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">
                Tenha sua página de agendamentos com Google Calendar e WhatsApp pronta para seus clientes.
            </p>
        </div>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 12px 16px; border-radius: 12px; font-size: 0.85rem; margin-bottom: 20px;">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">Nome da Barbearia / Salão / Empresa *</label>
                <input type="text" name="business_name" required class="form-input" placeholder="Ex: Barbearia Navalha de Ouro" value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>">
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Categoria</label>
                    <select name="category" class="form-input" style="background: #18181b;">
                        <option value="Barbearia" selected>💈 Barbearia</option>
                        <option value="Salão de Beleza">✂️ Salão de Beleza</option>
                        <option value="Manicure / Nails">💅 Manicure & Pedicure</option>
                        <option value="Clínica / Estética">💆 Estética & Spa</option>
                        <option value="Tatuagem">🖋️ Tatuagem</option>
                        <option value="Autônomo">👤 Profissional Autônomo</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">WhatsApp de Notificações *</label>
                    <input type="text" name="whatsapp" required class="form-input" placeholder="DDD + Número" value="<?= htmlspecialchars($_POST['whatsapp'] ?? '') ?>">
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Cidade</label>
                    <input type="text" name="city" class="form-input" placeholder="Ex: Uberlândia" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Estado (UF)</label>
                    <input type="text" name="state" maxlength="2" class="form-input" placeholder="MG" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST['state'] ?? '') ?>">
                </div>
            </div>

            <div style="border-top: 1px solid var(--border-color); margin: 20px 0 16px; padding-top: 16px;">
                <div style="font-size: 0.85rem; font-weight: 800; color: var(--primary); text-transform: uppercase; margin-bottom: 14px;">
                    Dados de Acesso ao seu Painel
                </div>

                <div class="form-group">
                    <label class="form-label">Seu Nome Completo</label>
                    <input type="text" name="owner_name" class="form-input" placeholder="Ex: Pedro Mendes" value="<?= htmlspecialchars($_POST['owner_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">E-mail (Seu Login de Acesso) *</label>
                    <input type="email" name="email" required class="form-input" placeholder="seuemail@exemplo.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Crie uma Senha *</label>
                    <input type="password" name="password" required class="form-input" placeholder="Mínimo 6 caracteres">
                </div>
            </div>

            <button type="submit" class="btn-submit-signup">
                CRIAR MEU SISTEMA DE AGENDAMENTO GRÁTIS →
            </button>

            <div style="text-align: center; margin-top: 18px; font-size: 0.8rem; color: var(--text-muted);">
                Ao criar sua conta você concorda com os Termos de Uso e Política de Privacidade.
            </div>
        </form>
    </div>
    <script src="/app/agendou/public/js/pwa-installer.js"></script>
</body>
</html>
