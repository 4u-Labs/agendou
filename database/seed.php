<?php
// ========================================================
// AGENDOU - Database Seeder
// Seeds default SuperAdmin, Demo Tenant (Pedro Mendes Barbearia)
// ========================================================

require_once __DIR__ . '/../config/database.php';

$pdo = Database::getConnection();
Database::runMigrations($pdo);

// Check if tenant 'pedromendes' already exists
$stmt = $pdo->prepare("SELECT id FROM tenants WHERE slug = ?");
$stmt->execute(['pedromendes']);
$tenant = $stmt->fetch();

if (!$tenant) {
    echo "Populando banco de dados inicial do AGENDOU...\n";

    // 1. Create Tenant
    $stmtTenant = $pdo->prepare("
        INSERT INTO tenants (
            name, slug, email, phone, whatsapp, category, address, city, state, description, primary_color, plan, status
        ) VALUES (
            'Pedro Mendes Barbearia', 'pedromendes', 'contato@pedromendes.com', '38999999999', '38999999999', 
            'Barbearia & Estética Masculina', 'Av. Rondon Pacheco, 1250 - Tibery', 'Uberlândia', 'MG',
            'Referência em cortes clássicos, barba com toalha quente e atendimento premium no Triângulo Mineiro.',
            '#10b981', 'pro', 'active'
        )
    ");
    $stmtTenant->execute();
    $tenantId = (int)$pdo->lastInsertId();

    // 2. Users
    // SuperAdmin
    $stmtSuper = $pdo->prepare("
        INSERT INTO users (tenant_id, name, email, password, role)
        VALUES (NULL, 'Super Administrador', 'admin@agendou.com.br', ?, 'superadmin')
    ");
    $stmtSuper->execute([password_hash('admin123', PASSWORD_BCRYPT)]);

    // Tenant Admin
    $stmtAdmin = $pdo->prepare("
        INSERT INTO users (tenant_id, name, email, password, role)
        VALUES (?, 'Pedro Mendes', 'pedro@barbearia.com', ?, 'tenant_admin')
    ");
    $stmtAdmin->execute([$tenantId, password_hash('admin123', PASSWORD_BCRYPT)]);

    // 3. Professionals
    $professionals = [
        [
            'name' => 'Pedro Mendes',
            'specialty' => 'Master Barber & Visagismo',
            'bio' => 'Mais de 12 anos de experiência, especialista em fade, barba terapia e tesoura.',
            'avatar_url' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=200&h=200&fit=crop&crop=faces',
            'email' => 'pedro@barbearia.com',
            'phone' => '38999999991'
        ],
        [
            'name' => 'João Santos',
            'specialty' => 'Barbeiro & Estilista',
            'bio' => 'Especialista em freestyle, cortes modernos e alinhamento facial.',
            'avatar_url' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=200&h=200&fit=crop&crop=faces',
            'email' => 'joao@barbearia.com',
            'phone' => '38999999992'
        ],
        [
            'name' => 'Carlos Andrade',
            'specialty' => 'Cortes Clássicos & Pigmentação',
            'bio' => 'Precisão cirúrgica na navalha, barba rústica e pigmentação capilar.',
            'avatar_url' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=200&h=200&fit=crop&crop=faces',
            'email' => 'carlos@barbearia.com',
            'phone' => '38999999993'
        ]
    ];

    $profIds = [];
    $stmtProf = $pdo->prepare("
        INSERT INTO professionals (tenant_id, name, specialty, bio, avatar_url, email, phone)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($professionals as $p) {
        $stmtProf->execute([$tenantId, $p['name'], $p['specialty'], $p['bio'], $p['avatar_url'], $p['email'], $p['phone']]);
        $profIds[] = (int)$pdo->lastInsertId();
    }

    // 4. Services
    $services = [
        ['name' => 'Corte Tradicional', 'price' => 40.00, 'duration_minutes' => 30, 'category' => 'Cabelo', 'description' => 'Corte completo com lavagem especial, tesoura, máquina e finalização com pomada modeladora.'],
        ['name' => 'Corte + Barba Terapia', 'price' => 70.00, 'duration_minutes' => 60, 'category' => 'Combo VIP', 'description' => 'Nosso serviço mais pedido! Corte completo + barba com toalha quente e massagem facial.'],
        ['name' => 'Barba com Toalha Quente', 'price' => 35.00, 'duration_minutes' => 30, 'category' => 'Barba', 'description' => 'Alinhamento na navalha, vapor de ozônio, toalha quente aromatizada e óleo hidratante.'],
        ['name' => 'Acabamento / Pezinho', 'price' => 20.00, 'duration_minutes' => 15, 'category' => 'Manutenção', 'description' => 'Alinhamento dos contornos da nuca, costeletas e acabamento na navalha.'],
        ['name' => 'Pigmentação de Barba', 'price' => 45.00, 'duration_minutes' => 40, 'category' => 'Barba', 'description' => 'Disfarce de falhas e fios brancos com acabamento natural de alta durabilidade.']
    ];

    $servIds = [];
    $stmtServ = $pdo->prepare("
        INSERT INTO services (tenant_id, name, price, duration_minutes, category, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($services as $s) {
        $stmtServ->execute([$tenantId, $s['name'], $s['price'], $s['duration_minutes'], $s['category'], $s['description']]);
        $servIds[] = (int)$pdo->lastInsertId();
    }

    // Link all professionals to all services
    $stmtLink = $pdo->prepare("INSERT INTO professional_services (tenant_id, professional_id, service_id) VALUES (?, ?, ?)");
    foreach ($profIds as $pid) {
        foreach ($servIds as $sid) {
            $stmtLink->execute([$tenantId, $pid, $sid]);
        }
    }

    // 5. Business Hours (Segunda a Sábado 08:00 - 19:00, Domingo Fechado)
    $stmtHours = $pdo->prepare("
        INSERT INTO business_hours (tenant_id, day_of_week, open_time, close_time, break_start, break_end, is_closed)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    for ($d = 0; $d <= 6; $d++) {
        $isClosed = ($d === 0) ? 1 : 0; // Domingo fechado
        $stmtHours->execute([$tenantId, $d, '08:00', '19:00', '12:00', '13:00', $isClosed]);
    }

    // 6. Customers & Appointments Demo
    $customers = [
        ['name' => 'Lucas Ferreira', 'whatsapp' => '38988776655', 'email' => 'lucas@gmail.com'],
        ['name' => 'Matheus Oliveira', 'whatsapp' => '38977665544', 'email' => 'matheus@hotmail.com'],
        ['name' => 'Rafael Costa', 'whatsapp' => '38966554433', 'email' => 'rafael@outlook.com']
    ];

    $stmtCust = $pdo->prepare("INSERT INTO customers (tenant_id, name, whatsapp, email) VALUES (?, ?, ?, ?)");
    $custIds = [];
    foreach ($customers as $c) {
        $stmtCust->execute([$tenantId, $c['name'], $c['whatsapp'], $c['email']]);
        $custIds[] = (int)$pdo->lastInsertId();
    }

    // Today's Date
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    $demoAppointments = [
        [
            'customer_id' => $custIds[0],
            'professional_id' => $profIds[0],
            'service_id' => $servIds[1], // Corte + Barba
            'date' => $today,
            'start' => '09:00',
            'end' => '10:00',
            'price' => 70.00,
            'status' => 'confirmed'
        ],
        [
            'customer_id' => $custIds[1],
            'professional_id' => $profIds[1],
            'service_id' => $servIds[0], // Corte Tradicional
            'date' => $today,
            'start' => '14:30',
            'end' => '15:00',
            'price' => 40.00,
            'status' => 'confirmed'
        ],
        [
            'customer_id' => $custIds[2],
            'professional_id' => $profIds[0],
            'service_id' => $servIds[2], // Barba
            'date' => $tomorrow,
            'start' => '10:00',
            'end' => '10:30',
            'price' => 35.00,
            'status' => 'confirmed'
        ]
    ];

    $stmtAppt = $pdo->prepare("
        INSERT INTO appointments (
            tenant_id, customer_id, professional_id, service_id, appointment_date, start_time, end_time, price, status, cancellation_token
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($demoAppointments as $app) {
        $token = bin2hex(random_bytes(16));
        $stmtAppt->execute([
            $tenantId, $app['customer_id'], $app['professional_id'], $app['service_id'],
            $app['date'], $app['start'], $app['end'], $app['price'], $app['status'], $token
        ]);
    }

    echo "✅ Seed executado com sucesso!\n";
    echo "Tenant criado: Pedro Mendes Barbearia (slug: pedromendes)\n";
    echo "SuperAdmin: admin@agendou.com.br (senha: admin123)\n";
    echo "Admin do Estabelecimento: pedro@barbearia.com (senha: admin123)\n";
} else {
    echo "Banco de dados já contém o tenant 'pedromendes'.\n";
}
