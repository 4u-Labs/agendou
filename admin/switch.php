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

// Endpoint de autenticação via Google OAuth
if (isset($_GET['action']) && $_GET['action'] === 'google_login') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;

    $email = null;
    $name = null;
    $picture = null;

    if (!empty($input['id_token'])) {
        $idToken = trim($input['id_token']);
        $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && $resp) {
            $tData = json_decode($resp, true);
            if (!empty($tData['email'])) {
                $email = strtolower($tData['email']);
                $name = $tData['name'] ?? explode('@', $email)[0];
                $picture = $tData['picture'] ?? null;
            }
        }
    } elseif (!empty($input['access_token'])) {
        $token = trim($input['access_token']);
        $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && $resp) {
            $uData = json_decode($resp, true);
            if (!empty($uData['email'])) {
                $email = strtolower($uData['email']);
                $name = $uData['name'] ?? explode('@', $email)[0];
                $picture = $uData['picture'] ?? null;
            }
        }
    }

    if ($email) {
        if ($email === 'fbr4g4@gmail.com') {
            $_SESSION['master_authorized'] = true;
            $_SESSION['google_email'] = $email;
            $_SESSION['google_name'] = $name;
            $_SESSION['agendou_user_id'] = 1;
            $_SESSION['agendou_user_role'] = 'superadmin';
            unset($_SESSION['admin_tenant_id']);
            echo json_encode(['success' => true, 'redirect' => '/app/agendou/admin/switch.php']);
            exit;
        } else {
            http_response_code(403);
            echo json_encode([
                'success' => false, 
                'error' => "Acesso restrito ao fundador. A conta Google conectada ({$email}) não tem permissão de acesso à Central Master."
            ]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Não foi possível validar o login com Google.']);
    exit;
}

// 1. Verificação de Autorização do Fundador
$isFounderAuthorized = false;

// Já autenticado na sessão ativa como fundador / superadmin / google fbr4g4@gmail.com
if (!empty($_SESSION['master_authorized']) || 
    ($_SESSION['agendou_user_role'] ?? '') === 'superadmin' || 
    ($_SESSION['google_email'] ?? '') === 'fbr4g4@gmail.com' ||
    (($_SESSION['shortener_user']['email'] ?? '') === 'fbr4g4@gmail.com')
) {
    $isFounderAuthorized = true;
    $_SESSION['master_authorized'] = true;
    $_SESSION['google_email'] = 'fbr4g4@gmail.com';
}

// Se NÃO estiver autorizado, renderizar tela de bloqueio exclusiva com Google
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
        <script src="https://accounts.google.com/gsi/client" async defer></script>
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
            .btn-google-login {
                width: 100%;
                background: #ffffff;
                color: #1f2937;
                border: 1px solid rgba(255, 255, 255, 0.2);
                border-radius: 12px;
                padding: 13px 16px;
                font-size: 0.92rem;
                font-weight: 700;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                cursor: pointer;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
                transition: all 0.2s ease;
                text-decoration: none;
            }
            .btn-google-login:hover {
                background: #f8fafc;
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(255, 255, 255, 0.15);
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
                Exclusivo para o fundador (<strong>fbr4g4@gmail.com</strong>). Conecte sua conta Google autorizada para desbloquear a Central Master.
            </p>

            <div id="lockErrorBox" class="err-msg" style="display: none;"></div>

            <!-- Botão Google Login -->
            <button type="button" class="btn-google-login" onclick="loginWithGoogle()" id="btnGoogleAuth">
                <svg width="20" height="20" viewBox="0 0 24 24" style="flex-shrink:0;">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                </svg>
                <span>Entrar com Google (fbr4g4@gmail.com)</span>
            </button>

            <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border-glass);">
                <a href="/app/agendou/admin/login.php" style="color: #64748b; font-size: 0.8rem; text-decoration: none; font-weight: 600;">
                    ← Voltar ao Login do Estabelecimento
                </a>
            </div>
        </div>

        <script>
            const GOOGLE_CLIENT_ID = "YOUR_GOOGLE_CLIENT_ID";

            function showAuthError(msg) {
                const box = document.getElementById('lockErrorBox');
                if (box) {
                    box.textContent = msg;
                    box.style.display = 'block';
                } else {
                    alert(msg);
                }
            }

            function loginWithGoogle() {
                const btn = document.getElementById('btnGoogleAuth');
                if (btn) btn.innerHTML = '<span>⏳ Conectando ao Google...</span>';

                if (typeof google === 'undefined' || !google.accounts || !google.accounts.oauth2) {
                    alert("A autenticação do Google ainda está carregando. Tente novamente em 2 segundos.");
                    if (btn) btn.innerHTML = '<span>Entrar com Google (fbr4g4@gmail.com)</span>';
                    return;
                }

                const tokenClient = google.accounts.oauth2.initTokenClient({
                    client_id: GOOGLE_CLIENT_ID,
                    scope: 'https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email openid',
                    callback: async (tokenResponse) => {
                        if (tokenResponse && tokenResponse.access_token) {
                            try {
                                const res = await fetch('/app/agendou/admin/switch.php?action=google_login', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ access_token: tokenResponse.access_token })
                                });
                                const data = await res.json();
                                if (data.success) {
                                    window.location.reload();
                                } else {
                                    showAuthError(data.error || 'Acesso negado para este e-mail Google.');
                                    if (btn) btn.innerHTML = '<span>Entrar com Google (fbr4g4@gmail.com)</span>';
                                }
                            } catch(e) {
                                showAuthError('Erro de comunicação com o servidor.');
                                if (btn) btn.innerHTML = '<span>Entrar com Google (fbr4g4@gmail.com)</span>';
                            }
                        } else {
                            if (btn) btn.innerHTML = '<span>Entrar com Google (fbr4g4@gmail.com)</span>';
                        }
                    }
                });

                tokenClient.requestAccessToken({ prompt: 'select_account' });
            }

            // Ativar Google One-Tap se disponível
            window.addEventListener('load', () => {
                if (typeof google !== 'undefined' && google.accounts && google.accounts.id) {
                    google.accounts.id.initialize({
                        client_id: GOOGLE_CLIENT_ID,
                        callback: async (response) => {
                            if (response && response.credential) {
                                try {
                                    const res = await fetch('/app/agendou/admin/switch.php?action=google_login', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ id_token: response.credential })
                                    });
                                    const data = await res.json();
                                    if (data.success) {
                                        window.location.reload();
                                    } else {
                                        showAuthError(data.error || 'Acesso negado.');
                                    }
                                } catch(e) {}
                            }
                        }
                    });
                    google.accounts.id.prompt();
                }
            });
        </script>
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
    $targetTenantId = ($tenantParam > 0) ? $tenantParam : 1;
    $stmtSlug = $pdo->prepare("SELECT slug FROM tenants WHERE id = ?");
    $stmtSlug->execute([$targetTenantId]);
    $clientSlug = $stmtSlug->fetchColumn() ?: 'barbearia1';
    header("Location: /app/agendou/?slug=" . urlencode($clientSlug) . "&test_client=1");
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
                            Visão do Cliente agendando na <strong>Barbearia 1</strong>: fluxo público de 4 passos com login Google, lembretes automáticos e botão direto de WhatsApp.
                        </p>
                    </div>

                    <div>
                        <a href="/app/agendou/admin/switch.php?role=client&tenant=1" target="_blank" class="btn-access btn-client">
                            <span>⚡ Testar como Cliente</span>
                        </a>

                        <div class="url-chip">
                            <span>4u.ia.br/barbearia1</span>
                            <button class="btn-copy-chip" onclick="copyLink('https://4u.ia.br/barbearia1')" title="Copiar"><i class="far fa-copy"></i></button>
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
                                    <div style="display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap;">
                                        <a href="/app/agendou/admin/switch.php?role=client&tenant=<?= $t['id'] ?>" target="_blank" class="btn btn-sm btn-outline-info rounded-pill px-3 py-1" style="font-weight: 700; font-size: 0.75rem;">
                                            ✂️ Testar Agendamento
                                        </a>
                                        <a href="/app/agendou/admin/switch.php?role=barber&tenant=<?= $t['id'] ?>" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1" style="font-weight: 700; font-size: 0.75rem;">
                                            👁️ Painel Admin
                                        </a>
                                    </div>
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
