<?php
// ========================================================
// AGENDOU - Public Booking Page
// Mobile-first, High Conversion, Google Sign-in enabled
// ========================================================

/** @var array $tenant */
/** @var array $services */
/** @var array $professionals */

$primaryColor = $tenant['primary_color'] ?? '#10b981';
$config = require __DIR__ . '/../config/config.php';
$googleClientId = $config['google']['client_id'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
    <title><?= htmlspecialchars($tenant['name']) ?> • Agendamento Online</title>
    <meta name="description" content="Agende seu horário online em <?= htmlspecialchars($tenant['name']) ?> de forma rápida e prática."/>
    
    <!-- Open Graph SEO -->
    <meta property="og:title" content="<?= htmlspecialchars($tenant['name']) ?> • Agendamento Online"/>
    <meta property="og:description" content="Escolha seu serviço, profissional e horário em menos de 1 minuto."/>
    <meta property="og:type" content="business.business"/>
    
    <link rel="icon" type="image/png" href="/loja/favicon-32x32.png"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link href="/app/agendou/public/css/booking.css?v=1.0" rel="stylesheet"/>

    <!-- Google Identity Services (GIS) for 1-click client sign in -->
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body>
    <div class="booking-wrapper">
        <!-- Business Header -->
        <header class="business-hero">
            <div class="business-hero-content">
                <div class="business-avatar">
                    <?php if (!empty($tenant['logo_url'])): ?>
                        <img src="<?= htmlspecialchars($tenant['logo_url']) ?>" alt="Logo">
                    <?php else: ?>
                        <span>💈</span>
                    <?php endif; ?>
                </div>
                <h1 class="business-title"><?= htmlspecialchars($tenant['name']) ?></h1>
                <p class="business-category"><?= htmlspecialchars($tenant['category'] ?? 'Atendimento Especializado') ?></p>
                
                <div class="business-meta">
                    <span class="meta-rating">⭐ 4.9 (180+ avaliações)</span>
                    <span class="meta-sep">•</span>
                    <span class="meta-location">📍 <?= htmlspecialchars($tenant['city']) ?> - <?= htmlspecialchars($tenant['state']) ?></span>
                </div>
            </div>
        </header>

        <!-- Booking Wizard Container -->
        <main class="booking-card">
            <!-- Step Indicators -->
            <div class="step-progress">
                <div class="step-indicator active" id="indicator-1"><span>1</span> Serviço</div>
                <div class="step-indicator" id="indicator-2"><span>2</span> Barbeiro</div>
                <div class="step-indicator" id="indicator-3"><span>3</span> Data & Hora</div>
                <div class="step-indicator" id="indicator-4"><span>4</span> Confirmar</div>
            </div>

            <!-- STEP 1: Escolha o Serviço -->
            <div class="wizard-step active" id="step-service">
                <div class="step-heading">
                    <h2>Escolha o Serviço</h2>
                    <p>Selecione o procedimento que deseja agendar</p>
                </div>

                <div class="services-list">
                    <?php foreach ($services as $s): ?>
                        <div class="service-item" onclick="selectService(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($s['name'])) ?>', <?= (float)$s['price'] ?>, <?= (int)$s['duration_minutes'] ?>)" id="srv-<?= $s['id'] ?>">
                            <div class="service-info">
                                <h3><?= htmlspecialchars($s['name']) ?></h3>
                                <p><?= htmlspecialchars($s['description'] ?? '') ?></p>
                                <div class="service-tags">
                                    <span class="tag-duration">⏱️ <?= (int)$s['duration_minutes'] ?> min</span>
                                    <span class="tag-category"><?= htmlspecialchars($s['category'] ?? 'Geral') ?></span>
                                </div>
                            </div>
                            <div class="service-price-box">
                                <span class="service-price">R$ <?= number_format($s['price'], 2, ',', '.') ?></span>
                                <span class="btn-select-pill">Selecionar</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- STEP 2: Escolha o Profissional -->
            <div class="wizard-step" id="step-professional" style="display: none;">
                <div class="step-heading">
                    <button class="btn-step-back" onclick="goToStep(1)">← Voltar</button>
                    <h2>Escolha o Profissional</h2>
                    <p>Quem você prefere que realize seu atendimento?</p>
                </div>

                <div class="professionals-grid">
                    <!-- Any available option -->
                    <div class="professional-card active-any" onclick="selectProfessional(0, 'Qualquer profissional')" id="prof-0">
                        <div class="prof-avatar">✨</div>
                        <div class="prof-info">
                            <h3>Qualquer Profissional</h3>
                            <p>Primeiro horário disponível</p>
                        </div>
                    </div>

                    <?php foreach ($professionals as $p): ?>
                        <div class="professional-card" onclick="selectProfessional(<?= (int)$p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')" id="prof-<?= $p['id'] ?>">
                            <div class="prof-avatar">
                                <?php if (!empty($p['avatar_url'])): ?>
                                    <img src="<?= htmlspecialchars($p['avatar_url']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
                                <?php else: ?>
                                    <span><?= strtoupper(substr($p['name'], 0, 1)) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="prof-info">
                                <h3><?= htmlspecialchars($p['name']) ?></h3>
                                <p><?= htmlspecialchars($p['specialty'] ?? 'Profissional') ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- STEP 3: Escolha a Data e Horário -->
            <div class="wizard-step" id="step-datetime" style="display: none;">
                <div class="step-heading">
                    <button class="btn-step-back" onclick="goToStep(2)">← Voltar</button>
                    <h2>Escolha a Data & Horário</h2>
                    <p>Horários livres calculados em tempo real</p>
                </div>

                <!-- Date Picker Strip -->
                <div class="date-carousel" id="dateCarousel">
                    <!-- Generated via JS -->
                </div>

                <!-- Time Slots Section -->
                <div class="time-slots-section">
                    <div class="slots-header">
                        <h4>Horários Disponíveis</h4>
                        <span id="selectedDateLabel" class="slots-date-label">Carregando...</span>
                    </div>

                    <div class="slots-grid" id="slotsGrid">
                        <div class="slots-loading">
                            <div class="spinner"></div>
                            <span>Consultando disponibilidade em tempo real...</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STEP 4: Dados do Cliente & Confirmação -->
            <div class="wizard-step" id="step-confirm" style="display: none;">
                <div class="step-heading">
                    <button class="btn-step-back" onclick="goToStep(3)">← Voltar</button>
                    <h2>Seus Dados para Contato</h2>
                    <p>Sem necessidade de criar senha ou aplicativo</p>
                </div>

                <!-- Appointment Summary Box -->
                <div class="booking-summary-card">
                    <div class="summary-row">
                        <span>Serviço:</span>
                        <strong id="summaryService">--</strong>
                    </div>
                    <div class="summary-row">
                        <span>Profissional:</span>
                        <strong id="summaryProf">--</strong>
                    </div>
                    <div class="summary-row">
                        <span>Data e Hora:</span>
                        <strong id="summaryDateTime" style="color: var(--primary);">--</strong>
                    </div>
                    <div class="summary-row summary-total">
                        <span>Valor Total:</span>
                        <strong id="summaryPrice" style="color: var(--primary); font-size: 1.2rem;">--</strong>
                    </div>
                </div>

                <!-- 1-Click Google Sign-in Prompt -->
                <div class="google-autofill-box" style="border: 1px solid rgba(66, 133, 244, 0.4); background: rgba(66, 133, 244, 0.08); border-radius: 14px; padding: 16px; margin-bottom: 20px;">
                    <div class="google-box-header" style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                        <svg width="22" height="22" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                        <strong style="color: #fff; font-size: 0.95rem;">Agendar com Conta Google (Recomendado)</strong>
                    </div>
                    <p style="font-size: 0.8rem; color: #cbd5e1; margin-bottom: 12px; line-height: 1.4;">
                        Preenche seus dados e <strong>adiciona na sua Google Agenda</strong> com lembretes automáticos <strong>2 horas antes</strong> e <strong>15 minutos antes</strong>.
                    </p>
                    <button type="button" class="btn-google-autofill" onclick="loginClientGoogle()" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; background: #ffffff; color: #1f2937; border: none; border-radius: 10px; padding: 12px; font-weight: 700; font-size: 0.9rem; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.25);">
                        <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                        <span>Conectar Conta Google</span>
                    </button>
                </div>

                <form id="bookingForm" onsubmit="submitBooking(event)">
                    <div class="form-group">
                        <label class="form-label">Seu Nome Completo *</label>
                        <input type="text" id="custName" class="form-input" placeholder="Ex: João Silva" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">WhatsApp (com DDD) *</label>
                        <input type="tel" id="custWhatsapp" class="form-input" placeholder="(38) 99999-9999" required maxlength="15">
                        <small style="color: var(--text-muted); font-size: 0.75rem;">Você receberá o comprovante de agendamento por aqui.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">E-mail (opcional)</label>
                        <input type="email" id="custEmail" class="form-input" placeholder="seuemail@gmail.com">
                    </div>

                    <button type="submit" class="btn-confirm-booking" id="btnSubmitBooking">
                        <span>CONFIRMAR AGENDAMENTO</span>
                        <span class="btn-arrow">→</span>
                    </button>
                </form>
            </div>

            <!-- STEP 5: SUCCESS / COMPROVANTE -->
            <div class="wizard-step" id="step-success" style="display: none;">
                <div class="success-box">
                    <div class="success-badge">✓</div>
                    <h2>AGENDAMENTO CONFIRMADO!</h2>
                    <p class="success-subtitle">Seu horário está garantido e registrado com sucesso.</p>

                    <div class="success-details-card">
                        <div class="success-row">
                            <span>📅 Data & Horário:</span>
                            <strong id="succDateTime">--</strong>
                        </div>
                        <div class="success-row">
                            <span>✂️ Serviço:</span>
                            <strong id="succService">--</strong>
                        </div>
                        <div class="success-row">
                            <span>👤 Profissional:</span>
                            <strong id="succProf">--</strong>
                        </div>
                        <div class="success-row">
                            <span>📍 Local:</span>
                            <strong id="succLocation"><?= htmlspecialchars($tenant['address']) ?>, <?= htmlspecialchars($tenant['city']) ?></strong>
                        </div>
                    </div>

                    <!-- Direct WhatsApp Confirmation Button -->
                    <a href="#" id="succWhatsappBtn" target="_blank" class="btn-whatsapp-action">
                        <span>💬 FALAR NO WHATSAPP COM O ESTABELECIMENTO</span>
                    </a>

                    <!-- Add to Personal Calendar -->
                    <a href="#" id="succCalBtn" target="_blank" class="btn-calendar-action">
                        <span>📅 Adicionar à Minha Google Agenda</span>
                    </a>

                    <div class="cancel-link-box">
                        <p>Precisa alterar ou desmarcar?</p>
                        <a href="#" id="succCancelLink" class="cancel-link">Acessar link seguro de cancelamento</a>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="booking-footer">
            <p>Agendamento seguro proporcionado por <strong>AGENDOU • 4U.IA.BR</strong></p>
        </footer>
    </div>

    <!-- Booking State Engine -->
    <script>
        const TENANT_SLUG = "<?= htmlspecialchars($tenant['slug']) ?>";
        const TENANT_ID = <?= (int)$tenant['id'] ?>;
        const GOOGLE_CLIENT_ID = "<?= htmlspecialchars($googleClientId) ?>";

        let bookingState = {
            serviceId: null,
            serviceName: '',
            price: 0,
            duration: 30,
            professionalId: 0,
            professionalName: 'Qualquer Profissional',
            selectedDate: '',
            selectedTime: '',
            customerName: '',
            customerWhatsapp: '',
            customerEmail: '',
            googleId: ''
        };

        // 1. Select Service
        function selectService(id, name, price, duration) {
            bookingState.serviceId = id;
            bookingState.serviceName = name;
            bookingState.price = price;
            bookingState.duration = duration;

            document.querySelectorAll('.service-item').forEach(el => el.classList.remove('selected'));
            document.getElementById('srv-' + id)?.classList.add('selected');

            goToStep(2);
        }

        // 2. Select Professional
        function selectProfessional(id, name) {
            bookingState.professionalId = id;
            bookingState.professionalName = name;

            document.querySelectorAll('.professional-card').forEach(el => el.classList.remove('selected'));
            document.getElementById('prof-' + id)?.classList.add('selected');

            goToStep(3);
            initDateCarousel();
        }

        // 3. Date Carousel
        function initDateCarousel() {
            const container = document.getElementById('dateCarousel');
            container.innerHTML = '';

            const today = new Date();
            const daysNames = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
            const monthsNames = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

            let firstValidDate = '';

            // Generate next 21 days
            for (let i = 0; i < 21; i++) {
                const d = new Date();
                d.setDate(today.getDate() + i);

                const year = d.getFullYear();
                const month = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                const dateStr = `${year}-${month}-${day}`;
                const dayWeek = d.getDay();

                if (!firstValidDate && dayWeek !== 0) {
                    firstValidDate = dateStr;
                }

                const card = document.createElement('div');
                card.className = 'date-pill' + (dateStr === firstValidDate ? ' selected' : '');
                card.id = 'date-' + dateStr;
                card.innerHTML = `
                    <span class="date-weekday">${daysNames[dayWeek]}</span>
                    <span class="date-num">${day}</span>
                    <span class="date-month">${monthsNames[d.getMonth()]}</span>
                `;
                card.onclick = () => selectDate(dateStr, `${day} de ${monthsNames[d.getMonth()]}`);
                container.appendChild(card);
            }

            if (firstValidDate) {
                const dObj = new Date(firstValidDate + 'T12:00:00');
                selectDate(firstValidDate, `${String(dObj.getDate()).padStart(2, '0')} de ${monthsNames[dObj.getMonth()]}`);
            }
        }

        // Select Date & Fetch Slots
        async function selectDate(dateStr, label) {
            bookingState.selectedDate = dateStr;
            document.getElementById('selectedDateLabel').textContent = label;

            document.querySelectorAll('.date-pill').forEach(el => el.classList.remove('selected'));
            document.getElementById('date-' + dateStr)?.classList.add('selected');

            // Fetch real-time available slots
            const grid = document.getElementById('slotsGrid');
            grid.innerHTML = `<div class="slots-loading"><div class="spinner"></div><span>Calculando horários livres...</span></div>`;

            try {
                let url = `/app/agendou/api/availability.php?slug=${TENANT_SLUG}&date=${dateStr}&service_id=${bookingState.serviceId}`;
                if (bookingState.professionalId > 0) {
                    url += `&professional_id=${bookingState.professionalId}`;
                }

                const res = await fetch(url);
                const data = await res.json();

                if (data.success && data.slots && data.slots.length > 0) {
                    grid.innerHTML = data.slots.map(s => `
                        <button type="button" class="slot-pill" onclick="selectSlot('${s.time}')">
                            ${s.time}
                        </button>
                    `).join('');
                } else {
                    grid.innerHTML = `<p class="no-slots-msg">Nenhum horário livre disponível para esta data. Por favor, escolha outro dia.</p>`;
                }
            } catch (err) {
                grid.innerHTML = `<p class="no-slots-msg">Erro ao carregar horários. Tente novamente.</p>`;
            }
        }

        // Select Slot and proceed to confirmation
        function selectSlot(timeStr) {
            bookingState.selectedTime = timeStr;

            document.getElementById('summaryService').textContent = `${bookingState.serviceName} (${bookingState.duration} min)`;
            document.getElementById('summaryProf').textContent = bookingState.professionalName;
            
            const [y, m, d] = bookingState.selectedDate.split('-');
            document.getElementById('summaryDateTime').textContent = `${d}/${m}/${y} às ${timeStr}`;
            document.getElementById('summaryPrice').textContent = 'R$ ' + bookingState.price.toFixed(2).replace('.', ',');

            goToStep(4);
        }

        // Submit Booking
        async function submitBooking(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitBooking');
            btn.disabled = true;
            btn.innerHTML = `<span>Agendando...</span>`;

            bookingState.customerName = document.getElementById('custName').value.trim();
            bookingState.customerWhatsapp = document.getElementById('custWhatsapp').value.trim();
            bookingState.customerEmail = document.getElementById('custEmail').value.trim();

            const payload = {
                slug: TENANT_SLUG,
                service_id: bookingState.serviceId,
                professional_id: bookingState.professionalId,
                date: bookingState.selectedDate,
                time: bookingState.selectedTime,
                customer_name: bookingState.customerName,
                customer_whatsapp: bookingState.customerWhatsapp,
                customer_email: bookingState.customerEmail,
                google_id: bookingState.googleId
            };

            try {
                const res = await fetch('/app/agendou/api/appointments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (data.success) {
                    // Populate success screen
                    document.getElementById('succDateTime').textContent = `${data.appointment.date_formatted} às ${data.appointment.start_time}`;
                    document.getElementById('succService').textContent = data.appointment.service;
                    document.getElementById('succProf').textContent = data.appointment.professional;
                    
                    document.getElementById('succWhatsappBtn').href = data.whatsapp_url;
                    document.getElementById('succCalBtn').href = data.client_calendar_url;
                    document.getElementById('succCancelLink').href = data.cancel_url;

                    goToStep(5);
                } else {
                    alert(data.error || 'Não foi possível confirmar o agendamento.');
                    btn.disabled = false;
                    btn.innerHTML = `<span>CONFIRMAR AGENDAMENTO</span><span class="btn-arrow">→</span>`;
                }
            } catch (err) {
                alert('Erro de conexão com o servidor. Tente novamente.');
                btn.disabled = false;
                btn.innerHTML = `<span>CONFIRMAR AGENDAMENTO</span><span class="btn-arrow">→</span>`;
            }
        }

        // Step Navigation helper
        function goToStep(stepNum) {
            document.querySelectorAll('.wizard-step').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.step-indicator').forEach(el => el.classList.remove('active'));

            if (stepNum === 1) {
                document.getElementById('step-service').style.display = 'block';
                document.getElementById('indicator-1').classList.add('active');
            } else if (stepNum === 2) {
                document.getElementById('step-professional').style.display = 'block';
                document.getElementById('indicator-2').classList.add('active');
            } else if (stepNum === 3) {
                document.getElementById('step-datetime').style.display = 'block';
                document.getElementById('indicator-3').classList.add('active');
            } else if (stepNum === 4) {
                document.getElementById('step-confirm').style.display = 'block';
                document.getElementById('indicator-4').classList.add('active');
            } else if (stepNum === 5) {
                document.getElementById('step-success').style.display = 'block';
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // 1-Click Client Google Sign-In
        function loginClientGoogle() {
            if (typeof google === 'undefined' || !google.accounts || !google.accounts.oauth2) {
                alert('Google Identity Services ainda não carregou. Aguarde alguns instantes.');
                return;
            }

            const tokenClient = google.accounts.oauth2.initTokenClient({
                client_id: GOOGLE_CLIENT_ID,
                scope: 'https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email openid',
                callback: async (resp) => {
                    if (resp && resp.access_token) {
                        try {
                            const uRes = await fetch('https://www.googleapis.com/oauth2/v3/userinfo', {
                                headers: { Authorization: `Bearer ${resp.access_token}` }
                            });
                            if (uRes.ok) {
                                const uData = await uRes.json();
                                if (uData.name) document.getElementById('custName').value = uData.name;
                                if (uData.email) document.getElementById('custEmail').value = uData.email;
                                if (uData.sub) bookingState.googleId = uData.sub;
                                
                                const box = document.querySelector('.google-autofill-box');
                                if (box) {
                                    box.innerHTML = `<span style="color: var(--primary); font-weight: 700;">✓ Conectado como ${uData.name} (${uData.email})</span>`;
                                }
                            }
                        } catch (_) {}
                    }
                }
            });

            tokenClient.requestAccessToken({ prompt: 'select_account' });
        }

        // WhatsApp auto-formatting mask
        const waInput = document.getElementById('custWhatsapp');
        if (waInput) {
            waInput.addEventListener('input', (e) => {
                let v = e.target.value.replace(/\D/g, '');
                if (v.length > 11) v = v.substring(0, 11);
                if (v.length > 6) {
                    e.target.value = `(${v.substring(0,2)}) ${v.substring(2,7)}-${v.substring(7)}`;
                } else if (v.length > 2) {
                    e.target.value = `(${v.substring(0,2)}) ${v.substring(2)}`;
                } else if (v.length > 0) {
                    e.target.value = `(${v}`;
                }
            });
        }
    </script>
</body>
</html>
