// =========================================================================
// AGENDOU!! - Instalador PWA Universal (Desktop, Android e iOS)
// Registra Service Worker, gerencia prompt nativo e modal educativo
// =========================================================================

(function() {
    'use strict';

    // 1. Injetar estilos do PWA dinamicamente
    const pwaStyles = `
    /* AGENDOU!! PWA Styles */
    @keyframes pwaSlideUp {
        from { transform: translate3d(0, 40px, 0); opacity: 0; }
        to { transform: translate3d(0, 0, 0); opacity: 1; }
    }
    @keyframes pwaPulseGlow {
        0%, 100% { box-shadow: 0 8px 25px rgba(56, 189, 248, 0.35); }
        50% { box-shadow: 0 8px 35px rgba(56, 189, 248, 0.6); }
    }

    #agendouPwaDockBanner {
        position: fixed !important;
        bottom: 16px !important;
        left: 12px !important;
        right: 12px !important;
        width: auto !important;
        max-width: 460px !important;
        margin-left: auto !important;
        margin-right: auto !important;
        box-sizing: border-box !important;
        background: rgba(11, 19, 41, 0.96) !important;
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(56, 189, 248, 0.45) !important;
        border-radius: 20px !important;
        padding: 12px 16px !important;
        display: none;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 10px !important;
        z-index: 999999 !important;
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.85), 0 0 25px rgba(56, 189, 248, 0.25) !important;
        animation: pwaSlideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
    }

    .agendou-pwa-dock-left {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        flex: 1;
    }

    .agendou-pwa-dock-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        object-fit: cover;
        box-shadow: 0 4px 15px rgba(56, 189, 248, 0.4);
        flex-shrink: 0;
    }

    .agendou-pwa-dock-text {
        min-width: 0;
        flex: 1;
    }

    .agendou-pwa-dock-text strong {
        display: block;
        color: #fff;
        font-size: 0.9rem;
        font-weight: 800;
        letter-spacing: -0.01em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .agendou-pwa-dock-text span {
        display: block;
        color: #94a3b8;
        font-size: 0.72rem;
        line-height: 1.25;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .agendou-pwa-dock-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }

    .btn-pwa-install-dock {
        background: linear-gradient(135deg, #38bdf8, #0284c7);
        color: #070d18 !important;
        font-weight: 800;
        font-size: 0.82rem;
        padding: 8px 14px;
        border-radius: 12px;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
        transition: transform 0.2s, box-shadow 0.2s;
        box-shadow: 0 4px 15px rgba(56, 189, 248, 0.4);
    }
    .btn-pwa-install-dock:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(56, 189, 248, 0.6);
    }

    .btn-pwa-close-dock {
        background: rgba(255, 255, 255, 0.08);
        border: none;
        color: #94a3b8;
        width: 32px;
        height: 32px;
        border-radius: 10px;
        font-size: 16px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    .btn-pwa-close-dock:hover {
        color: #fff;
        background: rgba(239, 68, 68, 0.2);
    }

    @media (max-width: 440px) {
        #agendouPwaDockBanner {
            bottom: 12px !important;
            left: 8px !important;
            right: 8px !important;
            padding: 10px 12px !important;
            gap: 8px !important;
            border-radius: 16px !important;
        }
        .agendou-pwa-dock-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
        }
        .agendou-pwa-dock-text strong {
            font-size: 0.82rem;
        }
        .agendou-pwa-dock-text span {
            font-size: 0.68rem;
        }
        .agendou-pwa-dock-actions {
            gap: 6px;
        }
        .btn-pwa-install-dock {
            padding: 7px 11px;
            font-size: 0.75rem;
            border-radius: 10px;
        }
        .btn-pwa-close-dock {
            width: 28px;
            height: 28px;
            font-size: 14px;
            border-radius: 8px;
        }
    }

    /* Modal Guia iOS */
    #modalPwaIosGuide {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.88);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        z-index: 99999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .pwa-ios-card {
        background: #0f172a;
        border: 1px solid rgba(56, 189, 248, 0.4);
        border-radius: 24px;
        width: 100%;
        max-width: 440px;
        padding: 26px;
        box-shadow: 0 25px 50px rgba(0,0,0,0.9);
        color: #e2e8f0;
        position: relative;
    }
    .pwa-step-box {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 14px;
        padding: 12px 14px;
        margin-bottom: 10px;
    }
    .pwa-step-num {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        background: rgba(56, 189, 248, 0.15);
        color: #38bdf8;
        font-weight: 800;
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    `;

    const styleEl = document.createElement('style');
    styleEl.innerHTML = pwaStyles;
    document.head.appendChild(styleEl);

    // 2. Registrar Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/app/agendou/service-worker.js?v=3.0', { scope: '/app/agendou/' })
                .then(reg => {
                    reg.update();
                    // Update check
                    reg.onupdatefound = () => {
                        const installingWorker = reg.installing;
                        if (installingWorker) {
                            installingWorker.onstatechange = () => {
                                if (installingWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                    console.log('Nova versão do AGENDOU!! disponível.');
                                }
                            };
                        }
                    };
                })
                .catch(err => console.log('PWA ServiceWorker Notice:', err));
        });
    }

    // 3. Detecção de standalone (se já está instalado e rodando em modo app)
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches 
        || window.navigator.standalone === true 
        || document.referrer.includes('android-app://');

    // 4. Detecção de iOS
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;

    let deferredPrompt = null;
    window.pwaDeferredPrompt = null;

    // 5. Interceptar prompt nativo do Android / Chrome / Edge
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        window.pwaDeferredPrompt = e;

        // Torna todos os botões de instalação visíveis
        document.querySelectorAll('.btn-pwa-install, .pwa-install-trigger').forEach(btn => {
            btn.style.display = 'inline-flex';
        });

        // Mostrar o Dock Banner se o usuário não dispensou recentemente
        checkAndShowDockBanner();
    });

    // 6. Verificar se deve exibir o Dock Banner
    function checkAndShowDockBanner() {
        if (isStandalone) return;

        const dismissedTime = localStorage.getItem('agendou_pwa_dock_dismissed');
        const now = Date.now();
        // Não mostrar se foi fechado nas últimas 48 horas
        if (dismissedTime && (now - parseInt(dismissedTime, 10)) < (48 * 60 * 60 * 1000)) {
            return;
        }

        const banner = document.getElementById('agendouPwaDockBanner');
        if (banner) {
            banner.style.display = 'flex';
        }
    }

    // 7. Função Universal de Instalação (Chamada por qualquer botão no app)
    window.triggerPWAInstall = function() {
        if (isStandalone) {
            alert('O AGENDOU!! já está instalado e aberto em modo aplicativo!');
            return;
        }

        if (deferredPrompt) {
            // Chrome / Edge / Android nativo
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then((choiceResult) => {
                if (choiceResult.outcome === 'accepted') {
                    console.log('Usuário aceitou instalar o AGENDOU!!');
                    const banner = document.getElementById('agendouPwaDockBanner');
                    if (banner) banner.style.display = 'none';
                }
                deferredPrompt = null;
                window.pwaDeferredPrompt = null;
            });
        } else if (isIos) {
            // Safari iOS
            openIosGuideModal();
        } else {
            // Navegadores desktop sem prompt disparado ou já aberto
            openDesktopHelpModal();
        }
    };

    // 8. Evento quando a instalação é concluída
    window.addEventListener('appinstalled', () => {
        console.log('AGENDOU!! instalado com sucesso!');
        deferredPrompt = null;
        window.pwaDeferredPrompt = null;
        const banner = document.getElementById('agendouPwaDockBanner');
        if (banner) banner.style.display = 'none';
        document.querySelectorAll('.btn-pwa-install, .pwa-install-trigger').forEach(btn => {
            btn.style.display = 'none';
        });
    });

    // 9. Modal iOS
    function openIosGuideModal() {
        let modal = document.getElementById('modalPwaIosGuide');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'modalPwaIosGuide';
            modal.innerHTML = `
                <div class="pwa-ios-card">
                    <button type="button" onclick="closePwaIosGuide()" style="position: absolute; top: 16px; right: 16px; background: rgba(255,255,255,0.06); border: none; color: #94a3b8; width: 32px; height: 32px; border-radius: 8px; font-size: 16px; cursor: pointer;">✕</button>
                    
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 18px;">
                        <img src="/app/agendou/public/icons/icon-192.png" alt="AGENDOU!!" style="width: 48px; height: 48px; border-radius: 12px; box-shadow: 0 4px 15px rgba(56, 189, 248, 0.4);">
                        <div>
                            <h3 style="margin: 0; color: #fff; font-size: 1.15rem; font-weight: 800;">Instalar o AGENDOU!! no iPhone</h3>
                            <span style="font-size: 0.78rem; color: #38bdf8;">Adicionar à tela de início sem App Store</span>
                        </div>
                    </div>

                    <p style="font-size: 0.84rem; color: #cbd5e1; margin-bottom: 16px; line-height: 1.4;">
                        Para instalar o aplicativo no seu iPhone ou iPad usando o Safari, siga estes 3 passos rápidos:
                    </p>

                    <div class="pwa-step-box">
                        <div class="pwa-step-num">1</div>
                        <div style="font-size: 0.85rem; color: #fff;">
                            Toque no botão <strong>Compartilhar</strong> <span style="font-size: 1.1rem;">⎋</span> (ícone de quadrado com seta para cima na barra inferior do Safari).
                        </div>
                    </div>

                    <div class="pwa-step-box">
                        <div class="pwa-step-num">2</div>
                        <div style="font-size: 0.85rem; color: #fff;">
                            Role para baixo na lista e toque em <strong>"Adicionar à Tela de Início"</strong> <span style="font-size: 1rem;">➕</span>.
                        </div>
                    </div>

                    <div class="pwa-step-box">
                        <div class="pwa-step-num">3</div>
                        <div style="font-size: 0.85rem; color: #fff;">
                            Toque em <strong>"Adicionar"</strong> no canto superior direito. Pronto! O ícone do AGENDOU!! aparecerá na sua tela inicial como um app nativo.
                        </div>
                    </div>

                    <button type="button" onclick="closePwaIosGuide()" style="width: 100%; margin-top: 14px; background: linear-gradient(135deg, #38bdf8, #0284c7); border: none; color: #070d18; font-weight: 800; padding: 12px; border-radius: 12px; font-size: 0.9rem; cursor: pointer;">
                        ✓ Entendi, vou adicionar
                    </button>
                </div>
            `;
            document.body.appendChild(modal);
        }
        modal.style.display = 'flex';
    }

    window.closePwaIosGuide = function() {
        const modal = document.getElementById('modalPwaIosGuide');
        if (modal) modal.style.display = 'none';
    };

    // 10. Modal Desktop Help
    function openDesktopHelpModal() {
        alert("Para instalar o AGENDOU!! no seu computador:\n\n1. Clique no ícone de instalação (computador com seta para baixo) na barra de endereços do Chrome/Edge;\nOU\n2. Clique no menu de 3 pontinhos do navegador > 'Salvar e Compartilhar' > 'Instalar AGENDOU!!'.");
    }

    // 11. Montar o Dock Banner automaticamente no DOM
    function injectDockBanner() {
        if (isStandalone) return;
        if (document.getElementById('agendouPwaDockBanner')) return;

        const banner = document.createElement('div');
        banner.id = 'agendouPwaDockBanner';
        banner.style.cssText = 'position: fixed !important; bottom: 16px !important; left: 12px !important; right: 12px !important; margin-left: auto !important; margin-right: auto !important; max-width: 460px !important; width: auto !important; box-sizing: border-box !important; z-index: 999999 !important;';
        banner.innerHTML = `
            <div class="agendou-pwa-dock-left">
                <img src="/app/agendou/public/icons/icon-192.png" alt="AGENDOU!!" class="agendou-pwa-dock-icon">
                <div class="agendou-pwa-dock-text">
                    <strong>Instale o AGENDOU!!</strong>
                    <span>Acesso ultra rápido na sua tela inicial</span>
                </div>
            </div>
            <div class="agendou-pwa-dock-actions">
                <button type="button" class="btn-pwa-install-dock" onclick="triggerPWAInstall()">
                    <span>📲</span> Instalar
                </button>
                <button type="button" class="btn-pwa-close-dock" onclick="dismissPwaDock()" title="Fechar">✕</button>
            </div>
        `;
        document.body.appendChild(banner);

        // Avalia exibição do Dock Banner
        checkAndShowDockBanner();
    }

    window.dismissPwaDock = function() {
        const banner = document.getElementById('agendouPwaDockBanner');
        if (banner) banner.style.display = 'none';
        localStorage.setItem('agendou_pwa_dock_dismissed', Date.now().toString());
    };

    // Inicializar quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            injectDockBanner();
        });
    } else {
        injectDockBanner();
    }

})();
