<?php
// ========================================================
// AGENDOU - API: Google OAuth 2.0 Callback
// Handles token exchange for Google Calendar integration
// ========================================================

require_once __DIR__ . '/../config/database.php';
$config = require __DIR__ . '/../config/config.php';

session_start();

$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';
$state = $_GET['state'] ?? ''; // contains tenant_id

if ($error) {
    header("Location: /app/agendou/admin/google.php?error=" . urlencode($error));
    exit;
}

if (!$code) {
    die("Código de autorização não recebido.");
}

$tenantId = (int)($state ?: ($_SESSION['admin_tenant_id'] ?? 1));

// Verificar se o plano permite Google Calendar
$pdo = Database::getConnection();
$stmtT = $pdo->prepare("SELECT plan FROM tenants WHERE id = ?");
$stmtT->execute([$tenantId]);
$plan = strtolower($stmtT->fetchColumn() ?: 'free');
if ($plan === 'free') {
    header("Location: /app/agendou/admin/google.php?error=" . urlencode("A sincronização com o Google Calendar é um recurso exclusivo dos planos STARTER e PLUS. Faça upgrade para ativar!"));
    exit;
}

$googleCfg = $config['google'];

$postData = [
    'code' => $code,
    'client_id' => $googleCfg['client_id'],
    'client_secret' => $googleCfg['client_secret'],
    'redirect_uri' => $googleCfg['redirect_uri'],
    'grant_type' => 'authorization_code'
];

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && $resp) {
    $tokenData = json_decode($resp, true);
    $accessToken = $tokenData['access_token'] ?? null;
    $refreshToken = $tokenData['refresh_token'] ?? null;
    $expiresIn = (int)($tokenData['expires_in'] ?? 3600);
    $expiresAt = time() + $expiresIn;

    // Fetch user email
    $email = '';
    if ($accessToken) {
        $chU = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
        curl_setopt($chU, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chU, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
        $uResp = curl_exec($chU);
        curl_close($chU);
        if ($uResp) {
            $uData = json_decode($uResp, true);
            $email = $uData['email'] ?? '';
        }
    }

    $pdo = Database::getConnection();
    // Upsert into google_integrations
    $stmtCheck = $pdo->prepare("SELECT id FROM google_integrations WHERE tenant_id = ?");
    $stmtCheck->execute([$tenantId]);
    $existingId = $stmtCheck->fetchColumn();

    if ($existingId) {
        $stmtUp = $pdo->prepare("
            UPDATE google_integrations 
            SET email = ?, access_token = ?, 
                refresh_token = COALESCE(NULLIF(?, ''), refresh_token),
                expires_at = ?, sync_enabled = 1, updated_at = CURRENT_TIMESTAMP
            WHERE tenant_id = ?
        ");
        $stmtUp->execute([$email, $accessToken, $refreshToken, $expiresAt, $tenantId]);
    } else {
        $stmtIn = $pdo->prepare("
            INSERT INTO google_integrations (tenant_id, email, access_token, refresh_token, expires_at, sync_enabled)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmtIn->execute([$tenantId, $email, $accessToken, $refreshToken, $expiresAt]);
    }

    header("Location: /app/agendou/admin/google.php?success=1");
    exit;
} else {
    $errDesc = "Erro ao trocar autorização do Google: " . $resp;
    header("Location: /app/agendou/admin/google.php?error=" . urlencode($errDesc));
    exit;
}
