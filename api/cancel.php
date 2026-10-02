<?php
// ========================================================
// AGENDOU - API: Cancel Appointment via Secure Token
// ========================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Services/GoogleCalendarService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;

$token = trim($input['token'] ?? '');
$reason = trim($input['reason'] ?? 'Cancelado pelo cliente');

if (!$token) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Token de cancelamento não informado.']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        SELECT a.*, t.name as tenant_name, s.name as service_name, p.name as professional_name
        FROM appointments a
        JOIN tenants t ON t.id = a.tenant_id
        JOIN services s ON s.id = a.service_id
        JOIN professionals p ON p.id = a.professional_id
        WHERE a.cancellation_token = ?
    ");
    $stmt->execute([$token]);
    $appt = $stmt->fetch();

    if (!$appt) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Agendamento não encontrado ou token inválido.']);
        exit;
    }

    if ($appt['status'] === 'cancelled') {
        echo json_encode(['success' => true, 'message' => 'Este agendamento já estava cancelado.']);
        exit;
    }

    // Cancel in Database
    $stmtUpdate = $pdo->prepare("
        UPDATE appointments 
        SET status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP, cancellation_reason = ? 
        WHERE id = ?
    ");
    $stmtUpdate->execute([$reason, $appt['id']]);

    // Cancel in Google Calendar if synced
    if (!empty($appt['google_event_id'])) {
        try {
            GoogleCalendarService::deleteEvent((int)$appt['tenant_id'], $appt['google_event_id']);
        } catch (Throwable $e) {
            error_log("Erro ao excluir do Google Calendar: " . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Agendamento cancelado com sucesso. O horário foi liberado na agenda.'
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao cancelar: ' . $e->getMessage()]);
}
