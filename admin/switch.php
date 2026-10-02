<?php
// ========================================================
// AGENDOU - Central Master de Acesso & Troca de Nível
// Exclusivo para testes rápidos independentes (fbr4g4@gmail.com)
// Permite alternar entre Super Admin, Barbearia e Cliente sem trocar contas Google
// ========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
$pdo = Database::getConnection();

// 1. Verificação de Autorização do Fundador
$isFounderAuthorized = false;

// Já autenticado na sessão ativa como fundador / superadmin
if (!empty($_SESSION['master_authorized']) || ($_SESSION['agendou_user_role'] ?? '') === 'superadmin' || ($_SESSION['google_email'] ?? '') === 'fbr4g4@gmail.com') {
    $isFounderAuthorized = true;
    $_SESSION['master_authorized'] = true;
}

// Se enviou senha master pelo formulário de desbloqueio
$authError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['master_password'])) {
    $inputPass = trim($_POST['master_password'] ?? '');
    
    // Validar com senha padrão do fundador ou com o hash do superadmin no banco
    $validMaster = ($inputPass === 'admin123');
    if (!$validMaster) {
        $stmtSuper = $pdo->prepare("SELECT password FROM users WHERE role = 'superadmin' LIMIT 1");
        $stmtSuper->execute();
        $superHash = $stmtSuper->fetchColumn();
        if ($superHash && password_verify($inputPass, $superHash)) {
            $validMaster = true;
        }
    }
    
    if ($validMaster) {
        $_SESSION['master_authorized'] = true;
        $_SESSION['agendou_user_id'] = 1;
        $_SESSION['agendou_user_role'] = 'superadmin';
        unset($_SESSION['admin_tenant_id']);
        $isFounderAuthorized = true;
    } else {
        $authError = 'Chave Master incorreta. Acesso restrito ao fundador.';
    }
}

// Se NÃO estiver autorizado, renderizar tela de bloqueio e impedir qualquer troca
if (!$isFounderAuthorized) {
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8"/>
        <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
        <title>Central Master • Acesso Restrito (fbr4g4@gmail.com)</title>
        <link rel="icon" type="image/png" sizes="32x32" href="/app/agendou/public/icons/favicon-32x32.png"/>
        <link rel="icon" type="image/png" sizes="16x16" href="/app/agendou/public/icons/favicon-16x16.png"/>
        <link rel="shortcut icon" href="/app/agendou/public/icons/favicon.ico"/>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
        <style>
            :root {
                --bg-base: #07090e;
                --bg-card: rgba(15, 20, 31, 0.85);
                --border-glass: rgba(255, 255, 255, 0.08);
                --primary: #10b981;
                --accent: #38bdf8;
            }
            body {
                background-color: var(--bg-base);
                background-image: 
                    radial-gradient(at 0% 0%, rgba(56, 189, 248, 0.1) 0px, transparent 50%),
                    radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.1) 0px, transparent 50%);
                color: #f8fafc;
                font-family: 'Plus Jakarta Sans', sans-serif;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                margin: 0;
            }
            .lock-box {
                background: var(--bg-card);
                border: 1px solid var(--border-glass);
                border-radius: 24px;
                padding: 40px 32px;
                max-width: 440px;
                width: 100%;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
                text-align: center;
                backdrop-filter: blur(20px);
            }
            .lock-icon-badge {
                width: 64px;
                height: 64px;
                border-radius: 20px;
                background: rgba(56, 189, 248, 0.12);
                border: 1px solid rgba(56, 189, 248, 0.25);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.8rem;
                margin: 0 auto 20px;
                box-shadow: 0 8px 24px rgba(56, 189, 248, 0.2);
            }
            .form-input-pass {
                background: rgba(255, 255, 255, 0.04);
                border: 1px solid var(--border-glass);
                border-radius: 12px;
                color: #fff;
                padding: 14px 16px;
                font-size: 1rem;
                width: 100%;
                margin-bottom: 16px;
                outline: none;
                transition: border-color 0.2s;
            }
            .form-input-pass:focus {
                border-color: var(--accent);
                background: rgba(255, 255, 255, 0.07);
            }
            .btn-unlock {
                background: linear-gradient(135deg, #0284c7, #0284c7 50%, #0369a1);
                color: #fff;
                font-weight: 800;
                font-size: 0.95rem;
                border: none;
                border-radius: 12px;
                padding: 14px;
                width: 100%;
                cursor: pointer;
                box-shadow: 0 4px 15px rgba(2, 132, 199, 0.4);
                transition: all 0.2s ease;
            }
            .btn-unlock:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(2, 132, 199, 0.6);
            }
            .err-msg {
                background: rgba(239, 68, 68, 0.15);
                border: 1px solid rgba(239, 68, 68, 0.3);
                color: #f87171;
                padding: 10px 14px;
                border-radius: 10px;
                font-size: 0.85rem;
                margin-bottom: 18px;
            }
        </style>
    </head>
    <body>
        <div class="lock-box">
            <div class="lock-icon-badge">🔐</div>
            <div style="font-size: 0.72rem; text-transform: uppercase; font-weight: 800; color: var(--accent); letter-spacing: 0.08em; margin-bottom: 6px;">
                Acesso Restrito ao Fundador
            </div>
            <h2 style="font-size: 1.45rem; font-weight: 800; color: #fff; margin-bottom: 8px;">
                Central Master
            </h2>
            <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 24px; line-height: 1.5;">
                Exclusivo para <strong>fbr4g4@gmail.com</strong>. Digite sua Chave Master para alternar entre os níveis do sistema.
            </p>

            <?php if ($authError): ?>
                <div class="err-msg"><?= htmlspecialchars($authError) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="password" name="master_password" class="form-input-pass" placeholder="Digite a Chave Master..." autofocus required>
                <button type="submit" class="btn-unlock">⚡ Desbloquear Central</button>
            </form>

            <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border-glass);">
                <a href="/app/agendou/admin/login.php" style="color: #64748b; font-size: 0.8rem; text-decoration: none; font-weight: 600;">
                    ← Voltar ao Login do Estabelecimento
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 2. Processar Desconexão
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: /app/agendou/admin/switch.php");
    exit;
}

// 3. Processar troca direta via URL (?role=...) apenas para o Fundador Autenticado
$roleParam = $_GET['role'] ?? '';
$tenantParam = (int)($_GET['tenant'] ?? 0);

if ($roleParam === 'super' || $roleParam === 'superadmin') {
    $_SESSION['agendou_user_id'] = 1;
    $_SESSION['agendou_user_role'] = 'superadmin';
    $_SESSION['master_authorized'] = true;
    unset($_SESSION['admin_tenant_id']);
    $_SESSION['master_logged_as'] = 'Super Administrador (fbr4g4@gmail.com)';
    header("Location: /app/agendou/admin/super.php");
    exit;
}

if ($roleParam === 'barber' || $roleParam === 'barbearia1' || $roleParam === 'pedromendes') {
    $targetTenantId = ($tenantParam > 0) ? $tenantParam : 1;
    
    $stmtU = $pdo->prepare("SELECT id FROM users WHERE tenant_id = ? AND role = 'tenant_admin' LIMIT 1");
    $stmtU->execute([$targetTenantId]);
    $userId = (int)$stmtU->fetchColumn() ?: 2;

    $_SESSION['agendou_user_id'] = $userId;
    $_SESSION['agendou_user_role'] = 'tenant_admin';
    $_SESSION['admin_tenant_id'] = $targetTenantId;
    $_SESSION['master_authorized'] = true;
    $_SESSION['master_logged_as'] = 'Dono do Estabelecimento (Tenant #' . $targetTenantId . ')';
    header("Location: /app/agendou/admin/index.php");
    exit;
}

if ($roleParam === 'viking') {
    $stmtU = $pdo->prepare("SELECT id FROM users WHERE tenant_id = 2 AND role = 'tenant_admin' LIMIT 1");
    $stmtU->execute();
    $userId = (int)$stmtU->fetchColumn() ?: 3;

    $_SESSION['agendou_user_id'] = $userId;
    $_SESSION['agendou_user_role'] = 'tenant_admin';
    $_SESSION['admin_tenant_id'] = 2;
    $_SESSION['master_authorized'] = true;
    $_SESSION['master_logged_as'] = 'Barbearia Viking Club (Tenant #2)';
    header("Location: /app/agendou/admin/index.php");
    exit;
}

if ($roleParam === 'client') {
    header("Location: /app/agendou/?slug=pedromendes&test_client=1");
    exit;
}

// 2. Se acessado diretamente, exibir Interface Visual Moderna de Seleção
$currentUserId = $_SESSION['agendou_user_id'] ?? null;
$currentUserRole = $_SESSION['agendou_user_role'] ?? null;
$currentTenantId = $_SESSION['admin_tenant_id'] ?? null;

// Buscar dados dos tenants disponíveis
$tenants = $pdo->query("SELECT id, name, slug, plan FROM tenants ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$currentStatus = 'Desconectado';
if ($currentUserRole === 'superadmin') {
    $currentStatus = '👑 Conectado como: Super Administrador (Dono do SaaS)';
} elseif ($currentUserRole === 'tenant_admin') {
    $stmtT = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
    $stmtT->execute([(int)$currentTenantId]);
    $tName = $stmtT->fetchColumn() ?: 'Estabelecimento';
    $currentStatus = '💈 Conectado como: ' . htmlspecialchars($tName);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Central Master de Acesso • AGENDOU!! (fbr4g4@gmail.com)</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/app/agendou/public/icons/favicon-32x32.png"/>
    <link rel="icon" type="image/png" sizes="16x16" href="/app/agendou/public/icons/favicon-16x16.png"/>
    <link rel="apple-touch-icon" href="/app/agendou/public/icons/apple-touch-icon.png"/>
    <link rel="shortcut icon" href="/app/agendou/public/icons/favicon.ico"/>
    <link rel="manifest" href="/app/agendou/manifest.json"/>
    <meta name="theme-color" content="#0284c7"/>
    <meta name="apple-mobile-web-app-capable" content="yes"/>
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
    <meta name="apple-mobile-web-app-title" content="AGENDOU!!"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #07090e;
            --bg-card: rgba(15, 20, 31, 0.75);
            --border-glass: rgba(255, 255, 255, 0.08);
            --primary: #10b981;
            --accent: #38bdf8;
            --yellow: #facc15;
            --purple: #c084fc;
        }

        html, body {
            max-width: 100vw;
            overflow-x: hidden;
        }

        body {
            background-color: var(--bg-base);
            background-image: 
                radial-gradient(at 0% 0%, rgba(16, 185, 129, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(99, 102, 241, 0.08) 0px, transparent 50%),
                radial-gradient(at 50% 100%, rgba(16, 185, 129, 0.04) 0px, transparent 50%);
            background-attachment: fixed;
            color: #f8fafc;
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 40px 20px;
            box-sizing: border-box;
        }

        .master-container {
            max-width: 900px;
            margin: 0 auto;
            width: 100%;
        }

        .glass-box {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-glass);
            border-radius: 24px;
            padding: 36px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
            box-sizing: border-box;
        }

        .level-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-glass);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.25s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
        }
        .level-card:hover {
            transform: translateY(-4px);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.4);
        }

        .level-super { border-top: 4px solid var(--yellow); }
        .level-barber { border-top: 4px solid var(--primary); }
        .level-client { border-top: 4px solid var(--accent); }

        .level-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin-bottom: 16px;
        }

        .icon-super { background: rgba(250, 204, 21, 0.15); color: var(--yellow); border: 1px solid rgba(250, 204, 21, 0.3); }
        .icon-barber { background: rgba(16, 185, 129, 0.15); color: var(--primary); border: 1px solid rgba(16, 185, 129, 0.3); }
        .icon-client { background: rgba(56, 189, 248, 0.15); color: var(--accent); border: 1px solid rgba(56, 189, 248, 0.3); }

        .btn-access {
            width: 100%;
            border-radius: 12px;
            padding: 12px 14px;
            font-weight: 800;
            font-size: 0.88rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            margin-top: 18px;
            box-sizing: border-box;
            white-space: nowrap;
            min-height: 48px;
            height: 48px;
        }

        .btn-super { background: linear-gradient(135deg, #facc15, #eab308); color: #000; }
        .btn-super:hover { background: #fde047; color: #000; box-shadow: 0 0 20px rgba(250, 204, 21, 0.35); }

        .btn-barber { background: linear-gradient(135deg, #10b981, #059669); color: #022c22; }
        .btn-barber:hover { background: #34d399; color: #022c22; box-shadow: 0 0 20px rgba(16, 185, 129, 0.35); }

        .btn-client { background: linear-gradient(135deg, #38bdf8, #0284c7); color: #082f49; }
        .btn-client:hover { background: #7dd3fc; color: #082f49; box-shadow: 0 0 20px rgba(56, 189, 248, 0.35); }

        .url-chip {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid var(--border-glass);
            border-radius: 8px;
            padding: 6px 12px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.78rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 10px;
        }

        .btn-copy-chip {
            background: none;
            border: none;
            color: #38bdf8;
            cursor: pointer;
            padding: 0 4px;
        }
        .btn-copy-chip:hover { color: #fff; }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-glass);
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.82rem;
            margin-bottom: 24px;
            max-width: 100%;
            flex-wrap: wrap;
            line-height: 1.4;
        }

        @media (max-width: 768px) {
            body {
                padding: 20px 12px !important;
            }
            .glass-box {
                padding: 20px 16px !important;
                border-radius: 18px !important;
            }
            h1 {
                font-size: 1.6rem !important;
            }
            .level-card {
                padding: 18px !important;
                margin-bottom: 14px;
            }
            .table-responsive {
                overflow-x: visible !important;
            }
            .table-responsive table,
            .table-responsive tbody,
            .table-responsive tr,
            .table-responsive td {
                display: block !important;
                width: 100% !important;
            }
            .table-responsive tr {
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid rgba(255, 255, 255, 0.08) !important;
                border-radius: 14px;
                padding: 12px;
                margin-bottom: 12px;
            }
            .table-responsive td {
                padding: 4px 0 !important;
                text-align: left !important;
            }
            .table-responsive td:last-child {
                margin-top: 8px;
            }
            .table-responsive td:last-child .btn {
                width: 100%;
                display: block;
                padding: 8px 12px !important;
            }
        }
    </style>
</head>
<body>

    <div class="master-container">

        <!-- Header -->
        <div class="text-center mb-4">
            <a href="/links.php" style="text-decoration: none;">
                <img src="/logo.webp" alt="4U.IA.BR" height="42" class="mb-3">
            </a>
            <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; color: #38bdf8; letter-spacing: 0.08em; margin-bottom: 6px;">
                ⚡ Central Master Multi-Nível • 4U.IA.BR
            </div>
            <h1 style="font-size: 2.2rem; font-weight: 900; margin-bottom: 10px; color: #fff;">
                Alternador Rápido de Níveis
            </h1>
            <p style="color: #94a3b8; font-size: 0.95rem; max-width: 600px; margin: 0 auto;">
                Teste todos os níveis da plataforma instantaneamente com sua conta <strong>fbr4g4@gmail.com</strong>, sem precisar deslogar ou trocar de usuário no Google.
            </p>
        </div>

        <!-- Status Card -->
        <div class="glass-box mb-4 text-center">
            <div class="status-pill">
                <span><?= $currentStatus ?></span>
                <?php if ($currentUserId): ?>
                    <a href="/app/agendou/admin/switch.php?logout=1" style="color: #ef4444; text-decoration: underline; font-weight: 700; margin-left: 8px;">[Desconectar]</a>
                <?php endif; ?>
            </div>
            <div style="font-size: 0.8rem; color: #64748b;">
                💡 <strong>Dica:</strong> Salve o atalho oficial <strong>👉 4u.ia.br/acesso</strong> nos seus favoritos para alternar de qualquer lugar em 1 clique.
            </div>
        </div>

        <!-- Grid of Levels -->
        <div class="row g-4 mb-4">

            <!-- NÍVEL 1: SUPER ADMIN -->
            <div class="col-md-4">
                <div class="level-card level-super">
                    <div>
                        <div class="level-icon icon-super">👑</div>
                        <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: var(--yellow); letter-spacing: 0.05em;">Nível 1 • Global</span>
                        <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin: 6px 0 10px;">Super Admin</h3>
                        <p style="font-size: 0.82rem; color: #94a3b8; line-height: 1.5; margin: 0;">
                            Visão do Fundador do SaaS: gestão de todas as barbearias, planos, corte/reativação, receita MRR e configurações do sistema.
                        </p>
                    </div>

                    <div>
                        <a href="/app/agendou/admin/switch.php?role=super" class="btn-access btn-super">
                            <span>⚡ Acessar Super Admin</span>
                        </a>

                        <div class="url-chip">
                            <span>4u.ia.br/acesso?role=super</span>
                            <button class="btn-copy-chip" onclick="copyLink('https://4u.ia.br/acesso?role=super')" title="Copiar"><i class="far fa-copy"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- NÍVEL 2: BARBEARIA (BARBEARIA 1) -->
            <div class="col-md-4">
                <div class="level-card level-barber">
                    <div>
                        <div class="level-icon icon-barber">💈</div>
                        <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: var(--primary); letter-spacing: 0.05em;">Nível 2 • Estabelecimento</span>
                        <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin: 6px 0 10px;">Barbearia 1</h3>
                        <p style="font-size: 0.82rem; color: #94a3b8; line-height: 1.5; margin: 0;">
                            Visão do Barbeiro: dashboard diário, agenda visual, botão de WhatsApp em cada agendamento, serviços e integração com Google Calendar.
                        </p>
                    </div>

                    <div>
                        <a href="/app/agendou/admin/switch.php?role=barber&tenant=1" class="btn-access btn-barber">
                            <span>⚡ Entrar como Barbeiro</span>
                        </a>

                        <div class="url-chip">
                            <span>4u.ia.br/acesso?role=barber</span>
                            <button class="btn-copy-chip" onclick="copyLink('https://4u.ia.br/acesso?role=barber')" title="Copiar"><i class="far fa-copy"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- NÍVEL 3: CLIENTE FINAL -->
            <div class="col-md-4">
                <div class="level-card level-client">
                    <div>
                        <div class="level-icon icon-client">✂️</div>
                        <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: var(--accent); letter-spacing: 0.05em;">Nível 3 • Público</span>
                        <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin: 6px 0 10px;">Cliente Final</h3>
                        <p style="font-size: 0.82rem; color: #94a3b8; line-height: 1.5; margin: 0;">
                            Visão do Cliente agendando: fluxo público de 4 passos com login Google, lembretes automáticos e botão direto de WhatsApp.
                        </p>
                    </div>

                    <div>
                        <a href="/app/agendou/admin/switch.php?role=client" target="_blank" class="btn-access btn-client">
                            <span>⚡ Testar como Cliente</span>
                        </a>

                        <div class="url-chip">
                            <span>4u.ia.br/pedromendes</span>
                            <button class="btn-copy-chip" onclick="copyLink('https://4u.ia.br/pedromendes')" title="Copiar"><i class="far fa-copy"></i></button>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Estabelecimentos Extras (Multi-tenant check) -->
        <div class="glass-box p-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h4 style="font-size: 1rem; font-weight: 800; margin: 0; color: #fff;">
                    🏢 Estabelecimentos Cadastrados no Banco (Multi-Tenant)
                </h4>
                <span style="font-size: 0.75rem; color: #94a3b8;"><?= count($tenants) ?> barbearias ativas</span>
            </div>

            <div class="table-responsive">
                <table class="table table-dark table-borderless mb-0" style="font-size: 0.85rem;">
                    <tbody>
                        <?php foreach ($tenants as $t): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <td style="padding: 12px 8px;">
                                    <strong style="color: #fff;"><?= htmlspecialchars($t['name']) ?></strong><br>
                                    <small style="color: #64748b;">Slug: 4u.ia.br/<?= htmlspecialchars($t['slug']) ?></small>
                                </td>
                                <td style="padding: 12px 8px; vertical-align: middle;">
                                    <span class="badge bg-dark border border-secondary text-light" style="font-size: 0.7rem;">Plano <?= strtoupper($t['plan']) ?></span>
                                </td>
                                <td style="padding: 12px 8px; vertical-align: middle; text-align: right;">
                                    <a href="/app/agendou/admin/switch.php?role=barber&tenant=<?= $t['id'] ?>" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1" style="font-weight: 700; font-size: 0.75rem;">
                                        👁️ Acessar como <?= htmlspecialchars($t['name']) ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <footer class="text-center mt-4" style="color: #64748b; font-size: 0.78rem;">
            © 2026 AGENDOU!! • Central de Alternância de Permissões • 4U.IA.BR
        </footer>

    </div>

    <script>
        function copyLink(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('✓ Link copiado para a área de transferência: ' + text);
            });
        }
    </script>
    <script src="/app/agendou/public/js/pwa-installer.js?v=3.0"></script>
</body>
</html>
