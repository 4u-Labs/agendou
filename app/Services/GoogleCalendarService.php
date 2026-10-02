<?php
// ========================================================
// AGENDOU - Google Calendar API v3 Service
// Official OAuth 2.0 Two-way synchronization
// ========================================================

require_once __DIR__ . '/../../config/database.php';

class GoogleCalendarService {

    /**
     * Get valid access token for a tenant, automatically refreshing if expired
     */
    public static function getValidAccessToken(int $tenantId): ?string {
        $pdo = Database::getConnection();

        // Plano FREE ou suspenso não possui sincronização com Google Calendar
        $stmtT = $pdo->prepare("SELECT plan, subscription_status FROM tenants WHERE id = ?");
        $stmtT->execute([$tenantId]);
        $tenantData = $stmtT->fetch();
        $plan = strtolower($tenantData['plan'] ?? 'free');
        $subStatus = $tenantData['subscription_status'] ?? 'active';

        if ($plan === 'free' || $subStatus === 'suspended') {
            return null;
        }

        $stmt = $pdo->prepare("SELECT * FROM google_integrations WHERE tenant_id = ? AND sync_enabled = 1");
        $stmt->execute([$tenantId]);
        $integration = $stmt->fetch();

        if (!$integration || empty($integration['access_token'])) {
            return null;
        }

        // Check if token is still valid (with 2 min buffer)
        if ($integration['expires_at'] && $integration['expires_at'] > (time() + 120)) {
            return $integration['access_token'];
        }

        // Token expired, refresh it using refresh_token
        if (empty($integration['refresh_token'])) {
            return null;
        }

        $config = require __DIR__ . '/../../config/config.php';
        $googleCfg = $config['google'];

        $postData = [
            'client_id' => $googleCfg['client_id'],
            'client_secret' => $googleCfg['client_secret'],
            'refresh_token' => $integration['refresh_token'],
            'grant_type' => 'refresh_token'
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
            $data = json_decode($resp, true);
            if (!empty($data['access_token'])) {
                $newAccessToken = $data['access_token'];
                $expiresIn = (int)($data['expires_in'] ?? 3600);
                $newExpiresAt = time() + $expiresIn;

                $stmtUpdate = $pdo->prepare("
                    UPDATE google_integrations 
                    SET access_token = ?, expires_at = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE tenant_id = ?
                ");
                $stmtUpdate->execute([$newAccessToken, $newExpiresAt, $tenantId]);

                return $newAccessToken;
            }
        }

        return null;
    }

    /**
     * Create event in Google Calendar
     */
    public static function createEvent(int $tenantId, array $appt): ?string {
        $token = self::getValidAccessToken($tenantId);
        if (!$token) return null;

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT calendar_id FROM google_integrations WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $calendarId = $stmt->fetchColumn() ?: 'primary';

        $timeZone = 'America/Sao_Paulo';
        $startIso = date('Y-m-d\TH:i:s', strtotime("{$appt['date']} {$appt['start_time']}")) . '-03:00';
        $endIso = date('Y-m-d\TH:i:s', strtotime("{$appt['date']} {$appt['end_time']}")) . '-03:00';

        $summary = "{$appt['service_name']} - {$appt['customer_name']}";
        $description = "Agendamento confirmado via AGENDOU\n\n" .
                       "👤 Cliente: {$appt['customer_name']}\n" .
                       "📱 WhatsApp: {$appt['customer_whatsapp']}\n" .
                       "✂️ Serviço: {$appt['service_name']}\n" .
                       "💈 Profissional: {$appt['professional_name']}\n" .
                       "💰 Valor: R$ " . number_format($appt['price'], 2, ',', '.') . "\n";

        if (!empty($appt['notes'])) {
            $description .= "📝 Obs: {$appt['notes']}\n";
        }

        $eventPayload = [
            'summary' => $summary,
            'description' => $description,
            'start' => ['dateTime' => $startIso, 'timeZone' => $timeZone],
            'end' => ['dateTime' => $endIso, 'timeZone' => $timeZone],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'popup', 'minutes' => 120], // 2 horas antes
                    ['method' => 'popup', 'minutes' => 15]   // 15 minutos antes
                ]
            ]
        ];

        // Se o cliente informou e-mail (Login Google), adiciona como convidado para salvar no Google Agenda dele também
        if (!empty($appt['customer_email'])) {
            $eventPayload['attendees'] = [
                ['email' => $appt['customer_email'], 'displayName' => $appt['customer_name'] ?? 'Cliente']
            ];
        }

        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendarId) . '/events?sendUpdates=all';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($eventPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (($httpCode === 200 || $httpCode === 201) && $resp) {
            $resData = json_decode($resp, true);
            return $resData['id'] ?? null;
        }

        return null;
    }

    /**
     * Delete / Cancel Google Calendar event
     */
    public static function deleteEvent(int $tenantId, string $eventId): bool {
        $token = self::getValidAccessToken($tenantId);
        if (!$token || !$eventId) return false;

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT calendar_id FROM google_integrations WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $calendarId = $stmt->fetchColumn() ?: 'primary';

        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendarId) . '/events/' . urlencode($eventId);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 204 || $httpCode === 200;
    }

    /**
     * Fetch busy intervals from Google Calendar for availability calculation
     */
    public static function getBusySlots(int $tenantId, string $date): array {
        $token = self::getValidAccessToken($tenantId);
        if (!$token) return [];

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT calendar_id FROM google_integrations WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $calendarId = $stmt->fetchColumn() ?: 'primary';

        $timeMin = date('Y-m-d\T00:00:00-03:00', strtotime($date));
        $timeMax = date('Y-m-d\T23:59:59-03:00', strtotime($date));

        $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendarId) . "/events?" . http_build_query([
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'singleEvents' => 'true',
            'orderBy' => 'startTime'
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$resp) {
            return [];
        }

        $data = json_decode($resp, true);
        $busy = [];
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                if (empty($item['start']['dateTime']) || empty($item['end']['dateTime'])) continue;
                $busy[] = [
                    'start' => $item['start']['dateTime'],
                    'end' => $item['end']['dateTime'],
                    'summary' => $item['summary'] ?? 'Ocupado'
                ];
            }
        }

        return $busy;
    }
}
