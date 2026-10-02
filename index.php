<?php
// ========================================================
// AGENDOU - Main Router & Landing Page
// Resolves tenant slugs (e.g. /app/agendou/pedromendes) or serves landing
// ========================================================

require_once __DIR__ . '/config/database.php';

$pdo = Database::getConnection();

// 1. Detect requested slug
$slug = '';

// Check query param first
if (!empty($_GET['slug'])) {
    $slug = trim($_GET['slug']);
} else {
    // Parse URI path
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $uri = trim($uri, '/');
    $parts = explode('/', $uri);
    
    // Check if the last part is a slug (e.g. agendou/pedromendes)
    if (count($parts) >= 3 && $parts[0] === 'app' && $parts[1] === 'agendou' && !in_array($parts[2], ['api', 'admin', 'public', 'views', 'config', 'database', 'cancelar.php', 'cadastro.php', 'cadastro'])) {
        $slug = $parts[2];
    } elseif (count($parts) >= 2 && $parts[0] === 'agendou' && !in_array($parts[1], ['api', 'admin', 'public', 'views', 'config', 'database', 'cancelar.php', 'cadastro.php', 'cadastro'])) {
        $slug = $parts[1];
    }
}

// 2. If slug detected, attempt to render public booking page
if ($slug) {
    $stmtT = $pdo->prepare("SELECT * FROM tenants WHERE slug = ?");
    $stmtT->execute([$slug]);
    $tenant = $stmtT->fetch();

    if ($tenant) {
        // Check if tenant is suspended/cut off due to unpaid subscription
        if (($tenant['subscription_status'] ?? '') === 'suspended' || ($tenant['status'] ?? '') === 'suspended') {
            require __DIR__ . '/views/suspended.php';
            exit;
        }

        $tenantId = (int)$tenant['id'];

        // Fetch services
        $stmtS = $pdo->prepare("SELECT * FROM services WHERE tenant_id = ? AND status = 'active' ORDER BY price ASC");
        $stmtS->execute([$tenantId]);
        $services = $stmtS->fetchAll();

        // Fetch professionals
        $stmtP = $pdo->prepare("SELECT * FROM professionals WHERE tenant_id = ? AND status = 'active' ORDER BY name ASC");
        $stmtP->execute([$tenantId]);
        $professionals = $stmtP->fetchAll();

        require __DIR__ . '/views/booking.php';
        exit;
    }
}

// 3. If no tenant slug or tenant not found, render modern SaaS Landing Page
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>AGENDOU • Plataforma SaaS de Agendamentos Online & Google Calendar</title>
    <meta name="description" content="O sistema definitivo de agendamento online para barbearias, salões, clínicas e profissionais. Integrado nativamente ao Google Calendar e WhatsApp."/>
    <link rel="icon" type="image/png" href="/loja/favicon-32x32.png"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@600;800&display=swap" rel="stylesheet">
    <link href="/app/agendou/public/css/landing.css?v=1.0" rel="stylesheet"/>
</head>
<body>
    <div class="landing-ambient"></div>

    <nav class="landing-nav">
        <div class="landing-brand">
            <span class="brand-badge">⚡</span>
            <div class="brand-text">
                <strong>AGENDOU</strong>
                <span>4U.IA.BR SAAS</span>
            </div>
        </div>
        <div class="nav-links">
            <a href="/app/agendou/?slug=pedromendes" class="nav-link">Demonstração</a>
            <a href="/app/agendou/cadastro.php" class="nav-link" style="color: var(--primary); font-weight: 700;">Criar Conta Grátis</a>
            <a href="/app/agendou/admin/" class="btn-login-header">Entrar no Painel →</a>
        </div>
    </nav>

    <header class="landing-hero">
        <div class="badge-hero">🚀 SAAS DE AGENDAMENTOS ONLINE & GOOGLE CALENDAR</div>
        <h1>Agendamentos que lotam sua agenda <span>no piloto automático</span>.</h1>
        <p>
            O cliente agenda em menos de 1 minuto pelo celular sem instalar nada. Sincronização em tempo real com seu Google Calendar e notificações no WhatsApp.
        </p>

        <div class="hero-cta-group">
            <a href="/app/agendou/cadastro.php" class="btn-cta-primary">
                <span>CADASTRAR MINHA BARBEARIA / EMPRESA</span>
                <span class="cta-arrow">→</span>
            </a>
            <a href="/app/agendou/?slug=pedromendes" class="btn-cta-secondary">
                <span>VER DEMONSTRAÇÃO AO VIVO</span>
            </a>
        </div>

        <div class="hero-stats-strip">
            <div class="stat-item">
                <strong>&lt; 60s</strong>
                <span>Tempo médio de agendamento</span>
            </div>
            <div class="stat-sep"></div>
            <div class="stat-item">
                <strong>100%</strong>
                <span>Integrado ao Google Calendar</span>
            </div>
            <div class="stat-sep"></div>
            <div class="stat-item">
                <strong>0</strong>
                <span>Aplicativos para instalar</span>
            </div>
        </div>
    </header>

    <section class="landing-features">
        <div class="features-grid">
            <div class="feature-card">
                <div class="feat-icon">⚡</div>
                <h3>Página Própria do Estabelecimento</h3>
                <p>Link exclusivo para colocar na bio do Instagram (ex: <code>4u.ia.br/app/agendou/pedromendes</code>) com QR Code para balcão.</p>
            </div>
            <div class="feature-card">
                <div class="feat-icon">📅</div>
                <h3>Sincronização com Google Calendar</h3>
                <p>Agendamentos entram direto na sua agenda do Google. Seus compromissos pessoais bloqueiam horários automaticamente.</p>
            </div>
            <div class="feature-card">
                <div class="feat-icon">💬</div>
                <h3>Confirmação via WhatsApp</h3>
                <p>Comprovante automático enviado em 1 clique com mensagem pré-formatada para reduzir faltas (no-show).</p>
            </div>
            <div class="feature-card">
                <div class="feat-icon">🛡️</div>
                <h3>Multi-Tenant Isolado & Seguro</h3>
                <p>Arquitetura moderna com banco relacional, total isolamento de dados entre empresas e controle de múltiplos profissionais.</p>
            </div>
        </div>
    </section>

    <!-- PRICING TIERS -->
    <section class="landing-pricing" id="planos" style="max-width: 1100px; margin: 40px auto 80px; padding: 0 20px; position: relative; z-index: 10;">
        <div style="text-align: center; margin-bottom: 40px;">
            <div class="badge-hero" style="margin-bottom: 12px;">PLANOS TRANSPARENTES</div>
            <h2 style="font-size: 2.2rem; font-weight: 800; color: #fff; margin-bottom: 8px;">Escolha o plano ideal para o seu negócio</h2>
            <p style="color: var(--text-secondary); font-size: 0.95rem;">Agendamentos pelo estabelecimento e pelo cliente. Comece grátis e escale quando precisar.</p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; align-items: stretch;">
            
            <!-- FREE -->
            <div class="pricing-card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; padding: 32px; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <span style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.05em;">Para quem está começando</span>
                    <h3 style="font-size: 1.5rem; font-weight: 800; color: #fff; margin: 6px 0 12px;">FREE</h3>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-bottom: 16px;">
                        <span style="font-size: 2.4rem; font-weight: 900; color: #fff;">R$ 0</span>
                        <span style="color: var(--text-muted); font-size: 0.85rem;">/mês</span>
                    </div>
                    <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);">
                        Ideal para autônomos que querem organizar a agenda sem custos. Sem cartão de crédito.
                    </p>

                    <ul style="list-style: none; display: flex; flex-direction: column; gap: 12px; font-size: 0.88rem; color: #e2e8f0; margin-bottom: 30px;">
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> <strong>30 agendamentos / mês</strong></li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> 1 profissional</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> 5 serviços</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Página pública de agendamento</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Link personalizado & QR Code</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Botão WhatsApp em cada agendamento</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Agenda interna (balcão/telefone)</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Cadastro de clientes & Lembretes</li>
                    </ul>
                </div>
                <a href="/app/agendou/cadastro.php?plan=free" class="btn-secondary" style="width: 100%; text-align: center; justify-content: center; padding: 14px; font-weight: 700; text-decoration: none; border-radius: 12px;">
                    Começar Gratuitamente
                </a>
            </div>

            <!-- STARTER -->
            <div class="pricing-card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; padding: 32px; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <span style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: var(--primary); letter-spacing: 0.05em;">Pequenos Negócios</span>
                    <h3 style="font-size: 1.5rem; font-weight: 800; color: #fff; margin: 6px 0 12px;">🚀 STARTER</h3>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-bottom: 16px;">
                        <span style="font-size: 2.4rem; font-weight: 900; color: #fff;">R$ 19,90</span>
                        <span style="color: var(--text-muted); font-size: 0.85rem;">/mês</span>
                    </div>
                    <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);">
                        Para profissionais que buscam automação com Google Calendar e mais clientes.
                    </p>

                    <ul style="list-style: none; display: flex; flex-direction: column; gap: 12px; font-size: 0.88rem; color: #e2e8f0; margin-bottom: 30px;">
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> <strong>150 agendamentos / mês</strong></li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> <strong>Até 3 profissionais</strong></li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> <strong>Serviços ilimitados</strong></li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> <strong>Google Calendar nativo</strong></li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Lembretes 2h e 15m antes</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Botão WhatsApp em cada atendimento</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Reagendamento e cancelamento</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Dashboard com faturamento e relatórios</li>
                    </ul>
                </div>
                <a href="/app/agendou/cadastro.php?plan=starter" class="btn-primary" style="width: 100%; text-align: center; justify-content: center; padding: 14px; font-weight: 800; text-decoration: none; border-radius: 12px; background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.5); color: #fff;">
                    Assinar Starter →
                </a>
            </div>

            <!-- PLUS (POPULAR) -->
            <div class="pricing-card" style="background: rgba(16, 185, 129, 0.06); border: 2px solid var(--primary); border-radius: 20px; padding: 32px; display: flex; flex-direction: column; justify-content: space-between; position: relative; box-shadow: 0 0 35px rgba(16, 185, 129, 0.2);">
                <div style="position: absolute; top: -14px; left: 50%; transform: translateX(-50%); background: var(--primary); color: #000; font-size: 0.72rem; font-weight: 900; padding: 4px 14px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.05em;">
                    MAIS POPULAR
                </div>
                <div>
                    <span style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: #facc15; letter-spacing: 0.05em;">Alta Produtividade</span>
                    <h3 style="font-size: 1.5rem; font-weight: 800; color: #fff; margin: 6px 0 12px;">⭐ PLUS</h3>
                    <div style="display: flex; align-items: baseline; gap: 4px; margin-bottom: 16px;">
                        <span style="font-size: 2.4rem; font-weight: 900; color: #fff;">R$ 39,90</span>
                        <span style="color: var(--text-muted); font-size: 0.85rem;">/mês</span>
                    </div>
                    <p style="font-size: 0.82rem; color: var(--text-secondary); margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);">
                        Para barbearias e clínicas completas que exigem recursos ilimitados e máxima gestão.
                    </p>

                    <ul style="list-style: none; display: flex; flex-direction: column; gap: 12px; font-size: 0.88rem; color: #e2e8f0; margin-bottom: 30px;">
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> <strong>500 agendamentos / mês</strong></li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> <strong>Profissionais ilimitados</strong></li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> <strong>Serviços & Clientes ilimitados</strong></li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> <strong>Google Calendar nativo duplo</strong></li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Lembretes automáticos 2h e 15m antes</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Bloqueio de horários & Folgas</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Múltiplos calendários e unidades</li>
                        <li style="display: flex; align-items: center; gap: 8px;"><span style="color: var(--primary);">✓</span> Gestão financeira & Faturamento</li>
                    </ul>
                </div>
                <a href="/app/agendou/cadastro.php?plan=plus" class="btn-cta-primary" style="width: 100%; text-align: center; justify-content: center; padding: 14px; font-weight: 900; text-decoration: none; border-radius: 12px;">
                    Assinar Plano Plus ⭐
                </a>
            </div>

        </div>

        <div style="text-align: center; margin-top: 30px; font-size: 0.82rem; color: var(--text-muted);">
            💳 Pagamentos mensais 100% seguros via PIX do Mercado Pago • Liberação instantânea sem burocracia
        </div>
    </section>

    <footer class="landing-footer">
        <p>© 2026 AGENDOU • Plataforma SaaS de Agendamentos Online • 4U.IA.BR</p>
    </footer>
</body>
</html>
