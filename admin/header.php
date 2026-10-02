<?php
// ========================================================
// AGENDOU - Admin Header Layout
// ========================================================

require_once __DIR__ . '/auth_check.php';

$activeNav = $activeNav ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title><?= $pageTitle ?? 'Painel Administrativo' ?> • AGENDOU</title>
    <link rel="icon" type="image/png" href="/loja/favicon-32x32.png"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link href="/app/agendou/public/css/admin.css?v=1.0" rel="stylesheet"/>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <aside class="admin-sidebar" id="adminSidebar">
            <?php if (($currentUser['role'] ?? '') === 'superadmin'): ?>
                <!-- SUPER ADMIN SAAS SIDEBAR -->
                <div class="sidebar-header">
                    <div class="brand-badge" style="background: rgba(250, 204, 21, 0.15); border-color: rgba(250, 204, 21, 0.4); color: #facc15;">👑</div>
                    <div class="brand-info">
                        <h2>AGENDOU SAAS</h2>
                        <span style="color: #facc15; font-size: 0.72rem; font-weight: 700;">Fundador • 4U.IA.BR</span>
                    </div>
                </div>

                <?php $curTab = $_GET['tab'] ?? ''; ?>
                <nav class="sidebar-nav">
                    <a href="/app/agendou/admin/super.php" class="nav-item <?= ($activeNav === 'super' && empty($curTab)) ? 'active' : '' ?>" style="color: #facc15;">
                        <span class="nav-icon">📊</span>
                        <span>Painel SaaS & MRR</span>
                    </a>
                    <a href="/app/agendou/admin/super.php?tab=assinantes#assinantes" class="nav-item <?= ($activeNav === 'super' && $curTab === 'assinantes') ? 'active' : '' ?>">
                        <span class="nav-icon">🏢</span>
                        <span>Barbearias & Assinantes</span>
                    </a>
                    <a href="/app/agendou/admin/super.php?tab=inadimplentes#inadimplentes" class="nav-item <?= ($activeNav === 'super' && $curTab === 'inadimplentes') ? 'active' : '' ?>">
                        <span class="nav-icon">🚫</span>
                        <span>Inadimplentes & Cortes</span>
                    </a>
                    <a href="/app/agendou/admin/super.php?tab=faturas#faturas" class="nav-item <?= ($activeNav === 'super' && $curTab === 'faturas') ? 'active' : '' ?>">
                        <span class="nav-icon">💳</span>
                        <span>Histórico de Pagamentos</span>
                    </a>
                    <a href="/app/agendou/admin/super.php?tab=configuracoes#configuracoes" class="nav-item <?= ($activeNav === 'super' && $curTab === 'configuracoes') ? 'active' : '' ?>">
                        <span class="nav-icon">⚙️</span>
                        <span>Configurações & Chave PIX</span>
                    </a>
                </nav>
            <?php else: ?>
                <!-- BARBEARIA / ESTABELECIMENTO SIDEBAR -->
                <div class="sidebar-header">
                    <div class="brand-badge">⚡</div>
                    <div class="brand-info">
                        <h2>AGENDOU</h2>
                        <span><?= htmlspecialchars($currentTenant['name'] ?? 'Minha Barbearia') ?></span>
                    </div>
                </div>

                <nav class="sidebar-nav">
                    <a href="/app/agendou/admin/index.php" class="nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
                        <span class="nav-icon">📊</span>
                        <span>Dashboard</span>
                    </a>
                    <a href="/app/agendou/admin/agenda.php" class="nav-item <?= $activeNav === 'agenda' ? 'active' : '' ?>">
                        <span class="nav-icon">📅</span>
                        <span>Agenda Visual</span>
                    </a>
                    <a href="/app/agendou/admin/services.php" class="nav-item <?= $activeNav === 'services' ? 'active' : '' ?>">
                        <span class="nav-icon">✂️</span>
                        <span>Serviços</span>
                    </a>
                    <a href="/app/agendou/admin/professionals.php" class="nav-item <?= $activeNav === 'professionals' ? 'active' : '' ?>">
                        <span class="nav-icon">👤</span>
                        <span>Profissionais</span>
                    </a>
                    <a href="/app/agendou/admin/customers.php" class="nav-item <?= $activeNav === 'customers' ? 'active' : '' ?>">
                        <span class="nav-icon">👥</span>
                        <span>Clientes</span>
                    </a>
                    <a href="/app/agendou/admin/hours.php" class="nav-item <?= $activeNav === 'hours' ? 'active' : '' ?>">
                        <span class="nav-icon">🕒</span>
                        <span>Horários & Bloqueios</span>
                    </a>
                    <a href="/app/agendou/admin/google.php" class="nav-item <?= $activeNav === 'google' ? 'active' : '' ?>">
                        <span class="nav-icon">🗓️</span>
                        <span>Google Calendar</span>
                    </a>
                    <a href="/app/agendou/admin/settings.php" class="nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>">
                        <span class="nav-icon">⚙️</span>
                        <span>Configurações & QR</span>
                    </a>
                    <a href="/app/agendou/admin/subscription.php" class="nav-item <?= $activeNav === 'subscription' ? 'active' : '' ?>">
                        <span class="nav-icon">💳</span>
                        <span>Minha Assinatura</span>
                    </a>
                </nav>
            <?php endif; ?>

            <div class="sidebar-footer">
                <div class="user-mini-card">
                    <div class="user-avatar-mini">
                        <?= strtoupper(substr($currentUser['name'] ?? 'U', 0, 1)) ?>
                    </div>
                    <div class="user-info-mini">
                        <strong><?= htmlspecialchars($currentUser['name'] ?? 'Admin') ?></strong>
                        <span><?= htmlspecialchars($currentUser['email'] ?? '') ?></span>
                    </div>
                    <a href="/app/agendou/admin/logout.php" class="btn-logout-mini" title="Sair do Sistema">🚪</a>
                </div>
            </div>
        </aside>

        <!-- Main Body -->
        <div class="admin-main">
            <!-- Top Nav -->
            <header class="admin-topbar">
                <div class="topbar-left">
                    <button class="btn-sidebar-toggle" onclick="toggleAdminSidebar()">☰</button>
                    <span class="topbar-breadcrumb">Painel Administrativo / <?= $pageTitle ?? 'Visão Geral' ?></span>
                    <?php if (($currentUser['role'] ?? '') === 'superadmin'): ?>
                        <?php if (!empty($currentTenant['name'])): ?>
                            <span style="margin-left: 12px; font-size: 0.75rem; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.4); color: #60a5fa; padding: 4px 10px; border-radius: 20px; display: inline-flex; align-items: center; gap: 6px;">
                                <span>👁️ Inspecionando:</span>
                                <strong style="color: #fff;"><?= htmlspecialchars($currentTenant['name']) ?></strong>
                                <a href="/app/agendou/admin/super.php" style="color: #60a5fa; text-decoration: underline; font-weight: 700;">[Voltar ao Painel SaaS]</a>
                            </span>
                        <?php else: ?>
                            <span style="margin-left: 12px; font-size: 0.75rem; background: rgba(250, 204, 21, 0.15); border: 1px solid rgba(250, 204, 21, 0.4); color: #facc15; padding: 4px 12px; border-radius: 20px; display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
                                <span>👑 Modo Fundador • Gestão Global da Plataforma</span>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="topbar-right">
                    <?php if (($currentUser['role'] ?? '') === 'superadmin'): ?>
                        <a href="/app/agendou/" target="_blank" class="btn-public-link" style="border-color: rgba(250, 204, 21, 0.4); color: #facc15;">
                            <span>🌐 Ver Landing Page do SaaS</span>
                        </a>
                    <?php elseif (!empty($currentTenant['slug'])): ?>
                        <a href="/app/agendou/?slug=<?= urlencode($currentTenant['slug']) ?>" target="_blank" class="btn-public-link">
                            <span>🔗 Ver Minha Página de Agendamento</span>
                        </a>
                    <?php endif; ?>
                </div>
            </header>

            <!-- Content Area -->
            <main class="admin-content">
