<?php
// ========================================================
// AGENDOU - Configuration & Environment
// ========================================================

// Load .env if exists
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (strpos($line, '=') !== false) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            $_ENV[$key] = $val;
            putenv("$key=$val");
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $val = getenv($key);
        if ($val === false) {
            return $_ENV[$key] ?? $default;
        }
        return $val;
    }
}

// Global App Configuration
return [
    'app_name' => 'AGENDOU',
    'app_url' => env('APP_URL', 'https://4u.ia.br/app/agendou'),
    'app_env' => env('APP_ENV', 'production'),
    
    // Google Cloud OAuth 2.0 Credentials (for Google Calendar & Client Login)
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('APP_URL', 'https://4u.ia.br/app/agendou') . '/api/google_callback.php',
        'scopes' => [
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/calendar.readonly',
            'https://www.googleapis.com/auth/userinfo.profile',
            'https://www.googleapis.com/auth/userinfo.email',
            'openid'
        ]
    ],

    // Database config
    'database' => [
        'driver' => env('DB_DRIVER', 'sqlite'),
        'sqlite_path' => __DIR__ . '/../database/agendou.sqlite',
        // MySQL fallback options
        'host' => env('DB_HOST', 'localhost'),
        'database' => env('DB_DATABASE', 'agendou'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4'
    ],

    // WhatsApp configuration
    'whatsapp' => [
        'default_country_code' => '55',
        'default_message' => "Olá! Meu nome é *{customer_name}*.\nAcabei de realizar um agendamento:\n\n📅 *Data:* {date}\n🕐 *Horário:* {time}\n✂️ *Serviço:* {service_name}\n👤 *Profissional:* {professional_name}\n💰 *Valor:* {price}\n\nGostaria de confirmar meu atendimento no *{tenant_name}*!"
    ],

    // Mercado Pago PIX credentials (from 4u.ia.br ecosystem)
    'mercadopago' => [
        'access_token' => env('MP_ACCESS_TOKEN', 'APP_USR-4404962015981699-111621-7eb905e9749a5abcac15f4e322da4b03-124159657'),
        'public_key' => env('MP_PUBLIC_KEY', 'APP_USR-cbe6db67-0f0a-4149-82f9-abfc0a57f55f')
    ],

    // Plans
    'plans' => [
        'free' => [
            'name' => 'FREE',
            'max_professionals' => 1,
            'max_services' => 5,
            'max_monthly_appointments' => 30,
            'google_calendar' => false,
            'price' => 0.00
        ],
        'starter' => [
            'name' => 'STARTER',
            'max_professionals' => 3,
            'max_services' => 99999,
            'max_monthly_appointments' => 150,
            'google_calendar' => true,
            'price' => 19.90
        ],
        'plus' => [
            'name' => 'PLUS',
            'max_professionals' => 99999,
            'max_services' => 99999,
            'max_monthly_appointments' => 500,
            'google_calendar' => true,
            'price' => 39.90
        ]
    ]
];
