<?php
// ========================================================
// AGENDOU - WhatsApp Service
// Generates official wa.me direct links & message templates
// ========================================================

class WhatsAppService {

    /**
     * Build direct wa.me link with encoded pre-filled message
     */
    public static function buildConfirmationLink(array $tenant, array $appointment, array $customer, array $service, array $professional): string {
        $phone = preg_replace('/[^0-9]/', '', $tenant['whatsapp'] ?? $tenant['phone']);
        if (strlen($phone) === 10 || strlen($phone) === 11) {
            $phone = '55' . $phone; // default Brazil country code
        }

        $dateFormatted = date('d/m/Y', strtotime($appointment['appointment_date']));
        $timeFormatted = substr($appointment['start_time'], 0, 5);
        $priceFormatted = 'R$ ' . number_format($appointment['price'], 2, ',', '.');

        $message = "Olá! Meu nome é *{$customer['name']}*.\n\n" .
                   "Acabei de realizar um agendamento:\n\n" .
                   "✂️ *Serviço:* {$service['name']}\n" .
                   "📅 *Data:* {$dateFormatted}\n" .
                   "🕐 *Horário:* {$timeFormatted}\n" .
                   "👤 *Profissional:* {$professional['name']}\n" .
                   "💰 *Valor:* {$priceFormatted}\n\n" .
                   "Gostaria de confirmar meu atendimento no *{$tenant['name']}*!";

        return "https://wa.me/{$phone}?text=" . urlencode($message);
    }

    /**
     * Build 24h Reminder message template for future automated cron/webhooks
     */
    public static function buildReminderMessage(array $tenant, array $appointment, array $customer, array $service): string {
        $dateFormatted = date('d/m/Y', strtotime($appointment['appointment_date']));
        $timeFormatted = substr($appointment['start_time'], 0, 5);

        return "Olá *{$customer['name']}*! 👋\n\n" .
               "Lembrete do seu agendamento no *{$tenant['name']}*:\n\n" .
               "✂️ *Serviço:* {$service['name']}\n" .
               "📅 *Data:* Amanhã ({$dateFormatted})\n" .
               "🕐 *Horário:* {$timeFormatted}\n" .
               "📍 *Endereço:* {$tenant['address']}, {$tenant['city']}\n\n" .
               "Contamos com a sua presença! Se precisar reagendar ou cancelar, use seu link seguro de agendamento.";
    }
}
