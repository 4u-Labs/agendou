<?php
// ========================================================
// AGENDOU - Admin Login
// ========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        // Acesso Master para o Fundador (fbr4g4@gmail.com)
        if (strtolower($email) === 'fbr4g4@gmail.com' && $password === 'admin123') {
            $_SESSION['agendou_user_id'] = 1;
            $_SESSION['agendou_user_role'] = 'superadmin';
            $_SESSION['master_authorized'] = true;
            unset($_SESSION['admin_tenant_id']);
            header("Location: /app/agendou/admin/switch.php");
            exit;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['agendou_user_id'] = $user['id'];
            $_SESSION['agendou_user_role'] = $user['role'];
            $_SESSION['admin_tenant_id'] = $user['tenant_id'];

            if ($user['role'] === 'superadmin') {
                header("Location: /app/agendou/admin/super.php");
            } else {
                header("Location: /app/agendou/admin/index.php");
            }
            exit;
        } else {
            $error = 'E-mail ou senha incorretos.';
        }
    } else {
        $error = 'Preencha todos os campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Entrar • AGENDOU!! Admin</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/app/agendou/public/icons/favicon-32x32.png"/>
    <link rel="icon" type="image/png" sizes="16x16" href="/app/agendou/public/icons/favicon-16x16.png"/>
    <link rel="apple-touch-icon" href="/app/agendou/public/icons/apple-touch-icon.png"/>
    <link rel="shortcut icon" href="/app/agendou/public/icons/favicon.ico"/>
    <link rel="manifest" href="/app/agendou/manifest.json"/>
    <meta name="theme-color" content="#0284c7"/>
    <meta name="apple-mobile-web-app-capable" content="yes"/>
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
    <meta name="apple-mobile-web-app-title" content="AGENDOU!!"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/app/agendou/public/css/landing.css?v=3.0" rel="stylesheet"/>
    <style>
        html, body {
            max-width: 100vw;
            overflow-x: hidden;
        }
        body {
            box-sizing: border-box;
            padding: 20px 16px 110px;
        }
        .login-box {
            width: 100%;
            max-width: 420px;
            margin: 20px auto;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 32px 22px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
            box-sizing: border-box;
        }
        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); margin-bottom: 6px; }
        .form-input {
            width: 100%;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 14px;
            color: #fff;
            font-size: 0.95rem;
            outline: none;
        }
        .form-input:focus { border-color: var(--primary); }
        .btn-submit-login {
            width: 100%;
            background: linear-gradient(135deg, var(--primary), #059669);
            border: none;
            color: #fff;
            padding: 14px;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 800;
            cursor: pointer;
            margin-top: 8px;
            transition: transform 0.2s;
        }
        .btn-submit-login:hover { transform: translateY(-1px); }
    </style>
</head>
<body>
    <div class="landing-ambient"></div>

    <div class="login-box">
        <div style="text-align: center; margin-bottom: 24px;">
            <img src="/app/agendou/public/icons/icon-192.png" alt="AGENDOU!!" style="width: 56px; height: 56px; border-radius: 14px; margin: 0 auto 12px; display: block; box-shadow: 0 4px 20px rgba(56, 189, 248, 0.4);">
            <h2 style="font-size: 1.45rem; font-weight: 800; color: #fff; margin-bottom: 4px;">AGENDOU!!</h2>
            <p style="font-size: 0.85rem; color: var(--text-muted);">Painel do Estabelecimento • Gestão & Agenda</p>
        </div>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 10px 14px; border-radius: 10px; font-size: 0.82rem; margin-bottom: 18px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">E-mail</label>
                <input type="email" name="email" id="loginEmail" class="form-input" placeholder="seuemail@exemplo.com" required>
            </div>

            <div class="form-group">
                <label class="form-label">Senha</label>
                <input type="password" name="password" id="loginPassword" class="form-input" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn-submit-login">ENTRAR NO SISTEMA</button>
        </form>

        <div style="margin-top: 24px; text-align: center; font-size: 0.85rem; color: var(--text-muted);">
            Não possui uma conta? <a href="/app/agendou/cadastro.php" style="color: var(--primary); text-decoration: none; font-weight: 700;">Cadastre seu estabelecimento →</a>
        </div>
    </div>
    <script src="/app/agendou/public/js/pwa-installer.js?v=3.0"></script>
</body>
</html>
