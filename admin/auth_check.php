<?php
// ========================================================
// AGENDOU - Admin Session & Auth Guard
// ========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['agendou_user_id'])) {
    header("Location: /app/agendou/admin/login.php");
    exit;
}

$pdo = Database::getConnection();

// Fetch current user
$stmtU = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtU->execute([(int)$_SESSION['agendou_user_id']]);
$currentUser = $stmtU->fetch();

if (!$currentUser) {
    session_destroy();
    header("Location: /app/agendou/admin/login.php");
    exit;
}

// Allow Super Admin to switch active tenant via session
if (($currentUser['role'] ?? '') === 'superadmin') {
    if (!empty($_SESSION['admin_tenant_id'])) {
        $tenantId = (int)$_SESSION['admin_tenant_id'];
        $stmtT = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
        $stmtT->execute([$tenantId]);
        $currentTenant = $stmtT->fetch() ?: null;
    } else {
        $tenantId = null;
        $currentTenant = null;
    }
} else {
    $tenantId = (int)($currentUser['tenant_id'] ?? 0);
    $stmtT = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmtT->execute([$tenantId]);
    $currentTenant = $stmtT->fetch();

    if (!$currentTenant) {
        die("Estabelecimento associado não encontrado.");
    }
}

// Subscription Enforcement: Cut off access if suspended or overdue
if (($currentUser['role'] ?? '') !== 'superadmin' && $currentTenant) {
    $subStatus = $currentTenant['subscription_status'] ?? 'active';
    $tenStatus = $currentTenant['status'] ?? 'active';
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

    // Auto-check expiration (grace period: 2 days after due date)
    if (!empty($currentTenant['next_due_date'])) {
        $today = date('Y-m-d');
        $dueDate = $currentTenant['next_due_date'];
        $cutoffDate = date('Y-m-d', strtotime($dueDate . ' +2 days'));

        if ($today > $cutoffDate && $subStatus !== 'suspended') {
            // Cut off access automatically after 2 days
            $stmtCut = $pdo->prepare("UPDATE tenants SET subscription_status = 'suspended', status = 'suspended', blocked_reason = 'Assinatura suspensa por falta de pagamento (tolerância de 2 dias esgotada)' WHERE id = ?");
            $stmtCut->execute([$tenantId]);
            $subStatus = 'suspended';
        } elseif ($today > $dueDate && $subStatus === 'active') {
            // Mark as past due
            $stmtPast = $pdo->prepare("UPDATE tenants SET subscription_status = 'past_due' WHERE id = ?");
            $stmtPast->execute([$tenantId]);
            $subStatus = 'past_due';
        }
    }

    if (($subStatus === 'suspended' || $tenStatus === 'suspended') && !in_array($currentScript, ['blocked.php', 'logout.php'])) {
        header("Location: /app/agendou/admin/blocked.php");
        exit;
    }
}
