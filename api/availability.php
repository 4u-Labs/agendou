<?php
// ========================================================
// AGENDOU - API: Get Real-time Available Slots
// ========================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Services/AvailabilityService.php';

try {
    $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
    $slug = $_GET['slug'] ?? '';
    $date = $_GET['date'] ?? '';
    $serviceId = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
    $professionalId = !empty($_GET['professional_id']) ? (int)$_GET['professional_id'] : null;

    $pdo = Database::getConnection();

    if (!$tenantId && $slug) {
        $stmt = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND status = 'active'");
        $stmt->execute([$slug]);
        $tenantId = (int)$stmt->fetchColumn();
    }

    if (!$tenantId || !$date || !$serviceId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Parâmetros incompletos (tenant, date, service_id).']);
        exit;
    }

    $slots = AvailabilityService::getAvailableSlots($tenantId, $date, $serviceId, $professionalId);

    echo json_encode([
        'success' => true,
        'date' => $date,
        'total_slots' => count($slots),
        'slots' => $slots
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno ao calcular disponibilidade: ' . $e->getMessage()
    ]);
}
