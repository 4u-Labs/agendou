<?php
// ========================================================
// AGENDOU - Real-time Availability Engine
// Calculates exact available booking slots
// ========================================================

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/GoogleCalendarService.php';

class AvailabilityService {

    /**
     * Get available time slots for a given tenant, date, service and optional professional
     * 
     * @param int $tenantId
     * @param string $date YYYY-MM-DD
     * @param int $serviceId
     * @param int|null $professionalId (null for 'any professional')
     * @return array
     */
    public static function getAvailableSlots(int $tenantId, string $date, int $serviceId, ?int $professionalId = null): array {
        $pdo = Database::getConnection();

        // 1. Validate date
        $timestamp = strtotime($date);
        if (!$timestamp || $date < date('Y-m-d')) {
            return []; // Past date or invalid
        }

        $dayOfWeek = (int)date('w', $timestamp); // 0=Sunday, 1=Monday, etc.

        // 2. Fetch Service details
        $stmtService = $pdo->prepare("SELECT duration_minutes, price, name FROM services WHERE id = ? AND tenant_id = ? AND status = 'active'");
        $stmtService->execute([$serviceId, $tenantId]);
        $service = $stmtService->fetch();
        if (!$service) return [];

        $durationMinutes = (int)$service['duration_minutes'];

        // 3. Fetch Business Hours for this day
        $stmtHours = $pdo->prepare("SELECT * FROM business_hours WHERE tenant_id = ? AND day_of_week = ?");
        $stmtHours->execute([$tenantId, $dayOfWeek]);
        $hours = $stmtHours->fetch();

        if (!$hours || $hours['is_closed']) {
            return []; // Closed on this day
        }

        $openTime = $hours['open_time'];
        $closeTime = $hours['close_time'];
        $breakStart = $hours['break_start'];
        $breakEnd = $hours['break_end'];

        // 4. Determine list of professionals that offer this service
        if ($professionalId) {
            $stmtProf = $pdo->prepare("
                SELECT p.id, p.name 
                FROM professionals p
                JOIN professional_services ps ON ps.professional_id = p.id
                WHERE p.id = ? AND p.tenant_id = ? AND ps.service_id = ? AND p.status = 'active'
            ");
            $stmtProf->execute([$professionalId, $tenantId, $serviceId]);
            $professionals = $stmtProf->fetchAll();
        } else {
            $stmtProf = $pdo->prepare("
                SELECT p.id, p.name 
                FROM professionals p
                JOIN professional_services ps ON ps.professional_id = p.id
                WHERE p.tenant_id = ? AND ps.service_id = ? AND p.status = 'active'
            ");
            $stmtProf->execute([$tenantId, $serviceId]);
            $professionals = $stmtProf->fetchAll();
        }

        if (empty($professionals)) {
            return [];
        }

        // 5. Fetch all existing appointments for this date & tenant
        $stmtAppts = $pdo->prepare("
            SELECT professional_id, start_time, end_time 
            FROM appointments 
            WHERE tenant_id = ? AND appointment_date = ? AND status = 'confirmed'
        ");
        $stmtAppts->execute([$tenantId, $date]);
        $existingAppointments = $stmtAppts->fetchAll();

        // 6. Fetch all blocked times for this date & tenant
        $dayStart = $date . ' 00:00:00';
        $dayEnd = $date . ' 23:59:59';
        $stmtBlocked = $pdo->prepare("
            SELECT professional_id, start_datetime, end_datetime 
            FROM blocked_times 
            WHERE tenant_id = ? AND start_datetime <= ? AND end_datetime >= ?
        ");
        $stmtBlocked->execute([$tenantId, $dayEnd, $dayStart]);
        $blockedTimes = $stmtBlocked->fetchAll();

        // 7. Fetch Google Calendar busy slots if connected
        $googleBusySlots = GoogleCalendarService::getBusySlots($tenantId, $date);

        // 8. Generate possible slots (every 15 or 30 minutes, aligned to service step)
        $stepMinutes = 30; // standard slot increment
        $currentSlot = strtotime("$date $openTime");
        $closeTimestamp = strtotime("$date $closeTime");
        $nowTimestamp = time();

        $availableSlotsMap = []; // slotTime => array of available professionalIds

        while (($currentSlot + ($durationMinutes * 60)) <= $closeTimestamp) {
            $slotStartStr = date('H:i', $currentSlot);
            $slotEndTimestamp = $currentSlot + ($durationMinutes * 60);
            $slotEndStr = date('H:i', $slotEndTimestamp);

            // Don't show slots in the past for today
            if ($date === date('Y-m-d') && $currentSlot <= ($nowTimestamp + 900)) { // 15 min buffer
                $currentSlot += ($stepMinutes * 60);
                continue;
            }

            // Check lunch break collision
            if ($breakStart && $breakEnd) {
                $bStart = strtotime("$date $breakStart");
                $bEnd = strtotime("$date $breakEnd");
                if ($currentSlot < $bEnd && $slotEndTimestamp > $bStart) {
                    $currentSlot += ($stepMinutes * 60);
                    continue; // Overlaps with lunch break
                }
            }

            // Check which professionals are free for this slot
            foreach ($professionals as $p) {
                $pId = (int)$p['id'];
                $isBusy = false;

                // A. Check existing appointments
                foreach ($existingAppointments as $app) {
                    if ((int)$app['professional_id'] === $pId) {
                        $appStart = strtotime("$date " . $app['start_time']);
                        $appEnd = strtotime("$date " . $app['end_time']);
                        if ($currentSlot < $appEnd && $slotEndTimestamp > $appStart) {
                            $isBusy = true;
                            break;
                        }
                    }
                }

                // B. Check blocked times
                if (!$isBusy) {
                    foreach ($blockedTimes as $block) {
                        if ($block['professional_id'] === null || (int)$block['professional_id'] === $pId) {
                            $bStart = strtotime($block['start_datetime']);
                            $bEnd = strtotime($block['end_datetime']);
                            if ($currentSlot < $bEnd && $slotEndTimestamp > $bStart) {
                                $isBusy = true;
                                break;
                            }
                        }
                    }
                }

                // C. Check Google Calendar events
                if (!$isBusy && !empty($googleBusySlots)) {
                    foreach ($googleBusySlots as $gSlot) {
                        $gStart = strtotime($gSlot['start']);
                        $gEnd = strtotime($gSlot['end']);
                        if ($currentSlot < $gEnd && $slotEndTimestamp > $gStart) {
                            $isBusy = true;
                            break;
                        }
                    }
                }

                if (!$isBusy) {
                    if (!isset($availableSlotsMap[$slotStartStr])) {
                        $availableSlotsMap[$slotStartStr] = [];
                    }
                    $availableSlotsMap[$slotStartStr][] = [
                        'professional_id' => $pId,
                        'professional_name' => $p['name']
                    ];
                }
            }

            $currentSlot += ($stepMinutes * 60);
        }

        $result = [];
        foreach ($availableSlotsMap as $timeStr => $profs) {
            $result[] = [
                'time' => $timeStr,
                'available_professionals' => $profs
            ];
        }

        return $result;
    }

    /**
     * Atomically check and reserve a slot right before confirming
     */
    public static function isSlotFree(int $tenantId, int $professionalId, string $date, string $startTime, string $endTime): bool {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt 
            FROM appointments 
            WHERE tenant_id = ? AND professional_id = ? AND appointment_date = ? 
              AND status = 'confirmed'
              AND (
                  (start_time < ? AND end_time > ?) OR
                  (start_time < ? AND end_time > ?) OR
                  (start_time >= ? AND end_time <= ?)
              )
        ");
        $stmt->execute([
            $tenantId, $professionalId, $date,
            $endTime, $startTime,
            $startTime, $startTime,
            $startTime, $endTime
        ]);

        $row = $stmt->fetch();
        return (int)$row['cnt'] === 0;
    }
}
