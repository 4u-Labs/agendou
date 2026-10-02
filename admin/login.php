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
    <title>Entrar • AGENDOU Admin</title>
    <link rel="icon" type="image/png" href="/loja/favicon-32x32.png"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/app/agendou/public/css/landing.css?v=1.0" rel="stylesheet"/>
    <style>
        .login-box {
            max-width: 420px;
            margin: 60px auto;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
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
        .demo-credentials {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px;
            margin-top: 24px;
            font-size: 0.78rem;
            color: var(--text-muted);
        }
        .demo-btn-fill {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--primary);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="landing-ambient"></div>

    <div class="login-box">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="brand-badge" style="margin: 0 auto 12px;">⚡</div>
            <h2 style="font-size: 1.45rem; font-weight: 800; color: #fff;">Painel do Estabelecimento</h2>
            <p style="font-size: 0.85rem; color: var(--text-muted);">Acesse para gerenciar sua agenda e clientes</p>
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

        <div class="demo-credentials">
            <strong>Credenciais Rápidas de Teste:</strong><br>
            <span>Barbearia: <code>pedro@barbearia.com</code> (senha: <code>admin123</code>)</span><br>
            <span class="demo-btn-fill" onclick="fillDemo('pedro@barbearia.com', 'admin123')">⚡ Preencher Barbearia</span>
            <span class="demo-btn-fill" onclick="fillDemo('admin@agendou.com.br', 'admin123')">👑 Preencher SuperAdmin</span>
        </div>
    </div>

    <script>
        function fillDemo(email, pass) {
            document.getElementById('loginEmail').value = email;
            document.getElementById('loginPassword').value = pass;
        }
    </script>
</body>
</html>
