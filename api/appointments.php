<?php
// ========================================================
// AGENDOU - API: Book Appointment & Real-time Atomic Lock
// ========================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Services/AvailabilityService.php';
require_once __DIR__ . '/../app/Services/GoogleCalendarService.php';
require_once __DIR__ . '/../app/Services/WhatsAppService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Support both JSON payload and POST form data
$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;

$slug = trim($input['slug'] ?? '');
$tenantId = (int)($input['tenant_id'] ?? 0);
$serviceId = (int)($input['service_id'] ?? 0);
$professionalId = (int)($input['professional_id'] ?? 0);
$date = trim($input['date'] ?? '');
$time = trim($input['time'] ?? '');
$customerName = trim($input['customer_name'] ?? '');
$customerWhatsapp = preg_replace('/[^0-9]/', '', $input['customer_whatsapp'] ?? '');
$customerEmail = trim($input['customer_email'] ?? '');
$googleId = trim($input['google_id'] ?? '');

$pdo = Database::getConnection();

// 1. Resolve Tenant
if (!$tenantId && $slug) {
    $stmtT = $pdo->prepare("SELECT * FROM tenants WHERE slug = ? AND status = 'active'");
    $stmtT->execute([$slug]);
    $tenant = $stmtT->fetch();
} elseif ($tenantId) {
    $stmtT = $pdo->prepare("SELECT * FROM tenants WHERE id = ? AND status = 'active'");
    $stmtT->execute([$tenantId]);
    $tenant = $stmtT->fetch();
} else {
    $tenant = null;
}

if (!$tenant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Estabelecimento não encontrado ou inativo.']);
    exit;
}
$tenantId = (int)$tenant['id'];

// 2. Validate Fields
if (!$serviceId || !$date || !$time || !$customerName || !$customerWhatsapp) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Por favor, preencha todos os campos obrigatórios (Serviço, Data, Horário, Nome e WhatsApp).']);
    exit;
}

// 3. Resolve Service
$stmtS = $pdo->prepare("SELECT * FROM services WHERE id = ? AND tenant_id = ? AND status = 'active'");
$stmtS->execute([$serviceId, $tenantId]);
$service = $stmtS->fetch();
if (!$service) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Serviço selecionado não existe.']);
    exit;
}

$durationMinutes = (int)$service['duration_minutes'];
$startTime = date('H:i:s', strtotime("$date $time"));
$endTime = date('H:i:s', strtotime("$date $time + {$durationMinutes} minutes"));

// 4. Resolve Professional (if not chosen, pick first available for this slot)
if (!$professionalId) {
    $availableSlots = AvailabilityService::getAvailableSlots($tenantId, $date, $serviceId, null);
    $matched = null;
    foreach ($availableSlots as $slot) {
        if ($slot['time'] === substr($time, 0, 5) && !empty($slot['available_professionals'])) {
            $matched = $slot['available_professionals'][0]['professional_id'];
            break;
        }
    }
    if (!$matched) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Nenhum profissional disponível para o horário selecionado. Escolha outro horário.']);
        exit;
    }
    $professionalId = $matched;
}

$stmtP = $pdo->prepare("SELECT * FROM professionals WHERE id = ? AND tenant_id = ? AND status = 'active'");
$stmtP->execute([$professionalId, $tenantId]);
$professional = $stmtP->fetch();
if (!$professional) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Profissional inválido.']);
    exit;
}

// 5. ATOMIC CHECK & INSERT (TRANSACTION)
try {
    $pdo->beginTransaction();

    // Verify slot collision right before writing
    $isFree = AvailabilityService::isSlotFree($tenantId, $professionalId, $date, $startTime, $endTime);
    if (!$isFree) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Este horário acabou de ser reservado por outro cliente. Por favor, selecione outro horário.'
        ]);
        exit;
    }

    // Upsert Customer
    $stmtC = $pdo->prepare("SELECT id FROM customers WHERE tenant_id = ? AND whatsapp = ?");
    $stmtC->execute([$tenantId, $customerWhatsapp]);
    $customerId = $stmtC->fetchColumn();

    if ($customerId) {
        $stmtUpC = $pdo->prepare("
            UPDATE customers 
            SET name = ?, email = COALESCE(NULLIF(?, ''), email), google_id = COALESCE(NULLIF(?, ''), google_id), updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmtUpC->execute([$customerName, $customerEmail, $googleId, $customerId]);
    } else {
        $stmtInC = $pdo->prepare("
            INSERT INTO customers (tenant_id, name, whatsapp, email, google_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmtInC->execute([$tenantId, $customerName, $customerWhatsapp, $customerEmail, $googleId]);
        $customerId = (int)$pdo->lastInsertId();
    }

    // Generate secure cancellation token
    $token = bin2hex(random_bytes(16));

    // Insert Appointment
    $stmtA = $pdo->prepare("
        INSERT INTO appointments (
            tenant_id, customer_id, professional_id, service_id, appointment_date, 
            start_time, end_time, price, status, cancellation_token
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?
        )
    ");
    $stmtA->execute([
        $tenantId, $customerId, $professionalId, $serviceId, $date,
        $startTime, $endTime, $service['price'], $token
    ]);
    $appointmentId = (int)$pdo->lastInsertId();

    $pdo->commit();

    // 6. External Integrations (Outside Transaction)
    // A. Google Calendar API Sync
    $googleEventId = null;
    try {
        $googleEventId = GoogleCalendarService::createEvent($tenantId, [
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'service_name' => $service['name'],
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_whatsapp' => $customerWhatsapp,
            'professional_name' => $professional['name'],
            'price' => $service['price'],
            'notes' => ''
        ]);

        if ($googleEventId) {
            $stmtGE = $pdo->prepare("UPDATE appointments SET google_event_id = ? WHERE id = ?");
            $stmtGE->execute([$googleEventId, $appointmentId]);
        }
    } catch (Throwable $ge) {
        error_log("Google Calendar error: " . $ge->getMessage());
    }

    // B. Build WhatsApp confirmation link
    $customerData = ['name' => $customerName, 'whatsapp' => $customerWhatsapp];
    $appointmentData = ['appointment_date' => $date, 'start_time' => $startTime, 'price' => $service['price']];
    $whatsappLink = WhatsAppService::buildConfirmationLink($tenant, $appointmentData, $customerData, $service, $professional);

    // C. Build direct Google Calendar URL for client to add to their personal calendar (with 2h and 15m reminders)
    $calTitle = urlencode("{$service['name']} - {$tenant['name']}");
    $calDates = date('Ymd\THis', strtotime("$date $startTime")) . '/' . date('Ymd\THis', strtotime("$date $endTime"));
    $calDetails = urlencode("Agendamento em {$tenant['name']}\n✂️ Serviço: {$service['name']}\n👤 Profissional: {$professional['name']}\n🔔 Lembretes: 2 horas antes e 15 minutos antes\n📍 Cancelamento/Reagendamento: {$config['app_url']}/cancelar.php?token={$token}");
    $calLocation = urlencode("{$tenant['address']}, {$tenant['city']} - {$tenant['state']}");
    $clientGoogleCalendarUrl = "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$calTitle}&dates={$calDates}&details={$calDetails}&location={$calLocation}";

    echo json_encode([
        'success' => true,
        'message' => 'Agendamento confirmado com sucesso!',
        'appointment' => [
            'id' => $appointmentId,
            'token' => $token,
            'date' => $date,
            'date_formatted' => date('d/m/Y', strtotime($date)),
            'start_time' => substr($startTime, 0, 5),
            'end_time' => substr($endTime, 0, 5),
            'service' => $service['name'],
            'professional' => $professional['name'],
            'price' => number_format($service['price'], 2, ',', '.'),
            'tenant_name' => $tenant['name'],
            'tenant_address' => $tenant['address'],
            'google_synced' => !empty($googleEventId)
        ],
        'whatsapp_url' => $whatsappLink,
        'client_calendar_url' => $clientGoogleCalendarUrl,
        'cancel_url' => "/app/agendou/cancelar.php?token=" . $token
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao processar agendamento: ' . $e->getMessage()
    ]);
}
