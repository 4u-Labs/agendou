<?php
// ========================================================
// AGENDOU - Modal Interativo: Tutorial & Guia de Uso do App
// ========================================================

$tutorialSlug = htmlspecialchars($currentTenant['slug'] ?? 'barbearia');
$tutorialDomain = $_SERVER['HTTP_HOST'] ?? '4u.ia.br';
?>

<!-- MODAL TUTORIAL DO APP -->
<div id="modalAppTutorial" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.88); backdrop-filter: blur(10px); z-index: 99999; align-items: center; justify-content: center; padding: 16px;">
    <div style="background: var(--bg-card, #121826); border: 1px solid rgba(56, 189, 248, 0.3); border-radius: 20px; width: 100%; max-width: 860px; max-height: 92vh; display: flex; flex-direction: column; box-shadow: 0 25px 60px rgba(0,0,0,0.9), 0 0 35px rgba(56, 189, 248, 0.15); overflow: hidden; animation: tutModalFadeIn 0.25s ease-out;">
        
        <!-- Header do Modal -->
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-color, #1e293b); display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, rgba(56, 189, 248, 0.08), rgba(99, 102, 241, 0.04));">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(56, 189, 248, 0.15); border: 1px solid rgba(56, 189, 248, 0.3); display: flex; align-items: center; justify-content: center; font-size: 22px; color: #38bdf8;">
                    📖
                </div>
                <div>
                    <h2 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                        Tutorial & Guia do Agendou
                        <span style="font-size: 0.68rem; background: #38bdf8; color: #0f172a; font-weight: 800; padding: 2px 8px; border-radius: 12px; text-transform: uppercase;">Passo a Passo</span>
                    </h2>
                    <p style="font-size: 0.82rem; color: var(--text-muted, #94a3b8); margin: 3px 0 0 0;">
                        Aprenda a configurar, divulgar, gerenciar o caixa e criar clubes de assinatura na sua barbearia.
                    </p>
                </div>
            </div>
            <button type="button" onclick="closeAppTutorialModal()" style="background: rgba(255,255,255,0.06); border: 1px solid var(--border-color, #1e293b); color: #94a3b8; font-size: 18px; width: 36px; height: 36px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.color='#fff'; this.style.background='rgba(239, 68, 68, 0.2)'" onmouseout="this.style.color='#94a3b8'; this.style.background='rgba(255,255,255,0.06)'">
                ✕
            </button>
        </div>

        <!-- Barra de Navegação por Abas -->
        <div style="display: flex; gap: 6px; padding: 12px 20px; background: rgba(0, 0, 0, 0.3); border-bottom: 1px solid var(--border-color, #1e293b); overflow-x: auto; scrollbar-width: none;">
            <button type="button" class="tut-tab-btn active" onclick="switchTutTab('passos', this)">
                <span>⚡</span> Primeiros Passos
            </button>
            <button type="button" class="tut-tab-btn" onclick="switchTutTab('link', this)">
                <span>🔗</span> Link & QR Code
            </button>
            <button type="button" class="tut-tab-btn" onclick="switchTutTab('agenda', this)">
                <span>📅</span> Agenda & WhatsApp
            </button>
            <button type="button" class="tut-tab-btn" onclick="switchTutTab('caixa', this)">
                <span>💰</span> Caixa & Fechamento
            </button>
            <button type="button" class="tut-tab-btn" onclick="switchTutTab('clubes', this)">
                <span>📦</span> Clubes & Pacotes
            </button>
            <button type="button" class="tut-tab-btn" onclick="switchTutTab('dicas', this)">
                <span>💡</span> Dicas de Faturamento
            </button>
        </div>

        <!-- Conteúdo do Tutorial (Scrollável) -->
        <div class="tut-content-area" style="padding: 24px; overflow-y: auto; overflow-x: hidden; flex: 1; display: flex; flex-direction: column; gap: 16px; color: #e2e8f0;">

            <!-- ABA 1: PRIMEIROS PASSOS -->
            <div id="tutTab-passos" class="tut-tab-pane" style="display: block;">
                <div style="background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.2); border-radius: 14px; padding: 16px 20px; margin-bottom: 20px;">
                    <h3 style="color: #38bdf8; font-size: 1rem; margin: 0 0 6px 0; font-weight: 700;">
                        🚀 4 Passos Obrigatórios no seu Primeiro Dia:
                    </h3>
                    <p style="font-size: 0.85rem; color: #cbd5e1; margin: 0; line-height: 1.5;">
                        Para começar a receber agendamentos sem erros, basta cadastrar sua equipe, serviços e horários uma única vez.
                    </p>
                </div>

                <div class="tut-grid-cards">
                    <!-- Card 1 -->
                    <div class="tut-card">
                        <div class="tut-card-number">1</div>
                        <div>
                            <h4 style="font-size: 0.95rem; color: #fff; margin: 0 0 4px 0;">Cadastre os Profissionais</h4>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0 0 10px 0; line-height: 1.4;">
                                Adicione cada barbeiro da sua barbearia com nome, telefone e foto. O cliente poderá escolher o profissional de sua preferência.
                            </p>
                            <a href="/app/agendou/admin/professionals.php" class="tut-link-btn">
                                <span>Ir para Profissionais</span> →
                            </a>
                        </div>
                    </div>

                    <!-- Card 2 -->
                    <div class="tut-card">
                        <div class="tut-card-number">2</div>
                        <div>
                            <h4 style="font-size: 0.95rem; color: #fff; margin: 0 0 4px 0;">Cadastre os Serviços & Preços</h4>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0 0 10px 0; line-height: 1.4;">
                                Crie serviços como Corte Masculino, Barba Terapia, Sobrancelha, etc. Defina o tempo de atendimento (ex: 30 min) e o valor.
                            </p>
                            <a href="/app/agendou/admin/services.php" class="tut-link-btn">
                                <span>Ir para Serviços</span> →
                            </a>
                        </div>
                    </div>

                    <!-- Card 3 -->
                    <div class="tut-card">
                        <div class="tut-card-number">3</div>
                        <div>
                            <h4 style="font-size: 0.95rem; color: #fff; margin: 0 0 4px 0;">Defina Horários & Bloqueios</h4>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0 0 10px 0; line-height: 1.4;">
                                Ajuste a hora de início e encerramento (ex: 09:00 às 20:00), intervalo de almoço e dias de folga (como domingos ou segundas).
                            </p>
                            <a href="/app/agendou/admin/hours.php" class="tut-link-btn">
                                <span>Definir Horários</span> →
                            </a>
                        </div>
                    </div>

                    <!-- Card 4 -->
                    <div class="tut-card">
                        <div class="tut-card-number">4</div>
                        <div>
                            <h4 style="font-size: 0.95rem; color: #fff; margin: 0 0 4px 0;">Configurações & Chave PIX</h4>
                            <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0 0 10px 0; line-height: 1.4;">
                                Preencha o nome público da barbearia, endereço para o GPS do cliente e sua Chave PIX para pagamentos adiantados ou no balcão.
                            </p>
                            <a href="/app/agendou/admin/settings.php" class="tut-link-btn">
                                <span>Configurar Barbearia</span> →
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ABA 2: LINK & QR CODE -->
            <div id="tutTab-link" class="tut-tab-pane" style="display: none;">
                <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 14px; padding: 18px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <span style="font-size: 0.75rem; font-weight: 800; color: #34d399; text-transform: uppercase;">Seu Link Exclusivo de Agendamento:</span>
                            <div style="font-family: var(--font-mono); font-size: 1.15rem; color: #fff; font-weight: 700; margin-top: 4px;">
                                https://<?= $tutorialDomain ?>/<?= $tutorialSlug ?>
                            </div>
                        </div>
                        <button type="button" class="btn-primary" onclick="copyTutLink('https://<?= $tutorialDomain ?>/<?= $tutorialSlug ?>', this)" style="padding: 8px 16px; font-size: 0.85rem;">
                            📋 Copiar Link
                        </button>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div class="tut-instruction-row">
                        <div class="tut-icon-circle">📸</div>
                        <div>
                            <strong style="color: #fff; font-size: 0.95rem;">1. Bio do Instagram & Linktree</strong>
                            <p style="font-size: 0.85rem; color: #94a3b8; margin: 4px 0 0 0; line-height: 1.4;">
                                Coloque o seu link curto diretamente no campo <strong>Site/Link</strong> da bio do seu Instagram. Nos Stories, use a figurinha de link com a legenda: <em>"Clique aqui e agende seu horário sem fila!"</em>.
                            </p>
                        </div>
                    </div>

                    <div class="tut-instruction-row">
                        <div class="tut-icon-circle">💬</div>
                        <div>
                            <strong style="color: #fff; font-size: 0.95rem;">2. Resposta Automática no WhatsApp</strong>
                            <p style="font-size: 0.85rem; color: #94a3b8; margin: 4px 0 0 0; line-height: 1.4;">
                                Configure uma mensagem de saudação no WhatsApp Business: <em>"Olá! Para escolher seu barbeiro e ver os horários livres, clique no nosso link rápido: https://<?= $tutorialDomain ?>/<?= $tutorialSlug ?>"</em>.
                            </p>
                        </div>
                    </div>

                    <div class="tut-instruction-row">
                        <div class="tut-icon-circle">🖨️</div>
                        <div>
                            <strong style="color: #fff; font-size: 0.95rem;">3. Imprima o QR Code para o Balcão & Espelhos</strong>
                            <p style="font-size: 0.85rem; color: #94a3b8; margin: 4px 0 0 0; line-height: 1.4;">
                                Em <a href="/app/agendou/admin/settings.php" style="color: #38bdf8; text-decoration: underline;">Configurações & QR</a>, baixe o QR Code em alta resolução. Imprima em um display de acrílico ou adesivo no espelho. O cliente aponta a câmera do celular enquanto corta e já marca o próximo atendimento.
                            </p>
                        </div>
                    </div>

                    <div class="tut-instruction-row">
                        <div class="tut-icon-circle">⚡</div>
                        <div>
                            <strong style="color: #fff; font-size: 0.95rem;">4. Sem Instalação de App e Sem Senhas</strong>
                            <p style="font-size: 0.85rem; color: #94a3b8; margin: 4px 0 0 0; line-height: 1.4;">
                                Seus clientes <strong>não precisam baixar nada na Play Store</strong> nem lembrar senhas complicadas. O sistema abre instantaneamente no navegador do celular, carrega rápido e confirma na hora.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ABA 3: AGENDA & WHATSAPP -->
            <div id="tutTab-agenda" class="tut-tab-pane" style="display: none;">
                <div style="background: rgba(99, 102, 241, 0.08); border: 1px solid rgba(99, 102, 241, 0.25); border-radius: 14px; padding: 16px 20px; margin-bottom: 18px;">
                    <h3 style="color: #818cf8; font-size: 1rem; margin: 0 0 6px 0; font-weight: 700;">
                        📲 Controle Total no seu Celular ou Computador
                    </h3>
                    <p style="font-size: 0.85rem; color: #cbd5e1; margin: 0; line-height: 1.5;">
                        Acompanhe em tempo real quem agendou, envie lembretes automáticos pelo WhatsApp e sincronize tudo com o Google Agenda.
                    </p>
                </div>

                <div class="tut-grid-cards">
                    <div class="tut-card">
                        <div style="font-size: 26px; margin-bottom: 8px;">📊</div>
                        <h4 style="font-size: 0.95rem; color: #fff; margin: 0 0 6px 0;">Dashboard em Tempo Real</h4>
                        <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0; line-height: 1.4;">
                            Na tela inicial, você vê os próximos agendamentos do dia, o total de atendimentos concluídos, clientes atendidos e o faturamento acumulado.
                        </p>
                    </div>

                    <div class="tut-card">
                        <div style="font-size: 26px; margin-bottom: 8px;">🟢</div>
                        <h4 style="font-size: 0.95rem; color: #fff; margin: 0 0 6px 0;">WhatsApp com 1 Clique</h4>
                        <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0; line-height: 1.4;">
                            Em qualquer agendamento, clique no ícone do WhatsApp. O sistema abre diretamente a conversa com o cliente com uma mensagem pronta contendo horário, serviço e nome do barbeiro.
                        </p>
                    </div>

                    <div class="tut-card">
                        <div style="font-size: 26px; margin-bottom: 8px;">🗓️</div>
                        <h4 style="font-size: 0.95rem; color: #fff; margin: 0 0 6px 0;">Google Agenda Integrado</h4>
                        <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0 0 10px 0; line-height: 1.4;">
                            Conecte sua conta Google em <a href="/app/agendou/admin/google.php" style="color: #38bdf8;">Google Calendar</a>. Os agendamentos aparecem diretamente no calendário do seu telefone com notificações sonoras.
                        </p>
                    </div>

                    <div class="tut-card">
                        <div style="font-size: 26px; margin-bottom: 8px;">➕</div>
                        <h4 style="font-size: 0.95rem; color: #fff; margin: 0 0 6px 0;">Encaixe & Agendamento Manual</h4>
                        <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0; line-height: 1.4;">
                            O cliente ligou ou chegou de surpresa na barbearia? Clique em <strong>+ Novo Agendamento</strong> no topo do Dashboard e lance o horário manualmente em 10 segundos.
                        </p>
                    </div>
                </div>
            </div>

            <!-- ABA 4: CAIXA & FECHAMENTO -->
            <div id="tutTab-caixa" class="tut-tab-pane" style="display: none;">
                <div style="background: rgba(234, 179, 8, 0.08); border: 1px solid rgba(234, 179, 8, 0.25); border-radius: 14px; padding: 16px 20px; margin-bottom: 18px;">
                    <h3 style="color: #facc15; font-size: 1rem; margin: 0 0 6px 0; font-weight: 700;">
                        💵 Como Concluir Atendimentos & Registrar Vendas Extras
                    </h3>
                    <p style="font-size: 0.85rem; color: #cbd5e1; margin: 0; line-height: 1.5;">
                        Cada atendimento concluído alimenta o fluxo financeiro da sua barbearia. Saiba como somar produtos e dar descontos:
                    </p>
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div class="tut-instruction-row">
                        <div class="tut-icon-circle" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">✓</div>
                        <div>
                            <strong style="color: #fff; font-size: 0.95rem;">Passo 1: Clique em "✓ Concluir" no Agendamento</strong>
                            <p style="font-size: 0.85rem; color: #94a3b8; margin: 4px 0 0 0; line-height: 1.4;">
                                Quando o cliente sair da cadeira, localize o agendamento no Dashboard e clique no botão verde <strong>✓ Concluir</strong>. Isso abre a tela de acerto financeiro.
                            </p>
                        </div>
                    </div>

                    <div class="tut-instruction-row">
                        <div class="tut-icon-circle" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">➕</div>
                        <div>
                            <strong style="color: #fff; font-size: 0.95rem;">Passo 2: Adicione Vendas Extras (+ Pomadas, Cerveja, Barba)</strong>
                            <p style="font-size: 0.85rem; color: #94a3b8; margin: 4px 0 0 0; line-height: 1.4;">
                                O cliente comprou uma pomada modeladora de R$ 35,00 ou bebeu uma cerveja? No campo <strong>+ Acréscimos</strong>, digite o valor extra e selecione o motivo. O valor total é somado na hora automaticamente.
                            </p>
                        </div>
                    </div>

                    <div class="tut-instruction-row">
                        <div class="tut-icon-circle" style="background: rgba(239, 68, 68, 0.15); color: #ef4444;">➖</div>
                        <div>
                            <strong style="color: #fff; font-size: 0.95rem;">Passo 3: Aplique Descontos Promocionais</strong>
                            <p style="font-size: 0.85rem; color: #94a3b8; margin: 4px 0 0 0; line-height: 1.4;">
                                Se for aplicar um desconto de amigo ou cortesia, digite o valor no campo <strong>- Desconto</strong>. O sistema recalcula o valor líquido instantaneamente.
                            </p>
                        </div>
                    </div>

                    <div class="tut-instruction-row">
                        <div class="tut-icon-circle" style="background: rgba(168, 85, 247, 0.15); color: #c084fc;">💳</div>
                        <div>
                            <strong style="color: #fff; font-size: 0.95rem;">Passo 4: Escolha a Forma de Pagamento & Salve</strong>
                            <p style="font-size: 0.85rem; color: #94a3b8; margin: 4px 0 0 0; line-height: 1.4;">
                                Selecione se foi pago em <strong>PIX, Dinheiro, Cartão de Crédito, Débito</strong> ou se é coberto por um <strong>Pacote de Assinatura</strong>. Clique em <em>Salvar e Concluir</em>.
                            </p>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 10px; padding: 14px; background: rgba(0,0,0,0.25); border-radius: 12px; display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 0.85rem; color: #94a3b8;">Veja todo o histórico, comissões de barbeiros e relatórios completos:</span>
                    <a href="/app/agendou/admin/financeiro.php" class="btn-primary" style="padding: 8px 16px; font-size: 0.82rem;">
                        💰 Ver Caixa & Faturamento
                    </a>
                </div>
            </div>

            <!-- ABA 5: CLUBES & PACOTES -->
            <div id="tutTab-clubes" class="tut-tab-pane" style="display: none;">
                <div style="background: rgba(168, 85, 247, 0.08); border: 1px solid rgba(168, 85, 247, 0.25); border-radius: 14px; padding: 16px 20px; margin-bottom: 18px;">
                    <h3 style="color: #c084fc; font-size: 1rem; margin: 0 0 6px 0; font-weight: 700;">
                        👑 Clubes de Assinatura: Sua Barbearia com Salário Fixo Todo Mês!
                    </h3>
                    <p style="font-size: 0.85rem; color: #cbd5e1; margin: 0; line-height: 1.5;">
                        A maior tendência das barbearias modernas é a receita recorrente: o cliente paga uma mensalidade fixa no início do mês e corta o cabelo com frequência garantida.
                    </p>
                </div>

                <div class="tut-grid-cards" style="margin-bottom: 16px;">
                    <div class="tut-card">
                        <span style="font-size: 0.72rem; font-weight: 800; color: #38bdf8; text-transform: uppercase;">Modelo 1</span>
                        <h4 style="font-size: 0.95rem; color: #fff; margin: 4px 0 6px 0;">♾️ Cortes Ilimitados</h4>
                        <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0 0 8px 0; line-height: 1.4;">
                            Exemplo: <em>"Plano Cabelo VIP - R$ 99,90/mês"</em>.<br>
                            O cliente pode cortar quantas vezes quiser dentro do mês. Excelente para quem gosta de manter o corte sempre perfeito na régua!
                        </p>
                    </div>

                    <div class="tut-card">
                        <span style="font-size: 0.72rem; font-weight: 800; color: #facc15; text-transform: uppercase;">Modelo 2</span>
                        <h4 style="font-size: 0.95rem; color: #fff; margin: 4px 0 6px 0;">🎟️ Combos de Créditos</h4>
                        <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0 0 8px 0; line-height: 1.4;">
                            Exemplo: <em>"Pacote Combo: 4 Cortes + 4 Barbas - R$ 140,00/mês"</em>.<br>
                            O sistema desconta 1 crédito a cada visita e avisa quantos créditos ainda restam.
                        </p>
                    </div>
                </div>

                <div style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px;">
                    <h4 style="color: #fff; font-size: 0.9rem; margin: 0 0 8px 0;">Como o Agendou cuida dos Assinantes:</h4>
                    <ul style="margin: 0; padding-left: 20px; font-size: 0.82rem; color: #94a3b8; line-height: 1.6;">
                        <li>Ao agendar pelo link, o sistema reconhece o telefone do cliente e coloca uma tag roxa <strong>👑 Assinante do Clube</strong> no agendamento.</li>
                        <li>Ao clicar em <strong>✓ Concluir</strong>, o sistema sugere automaticamente a opção <em>"Cobrir pelo Pacote (R$ 0,00)"</em> para você não cobrar duas vezes.</li>
                        <li>Na aba <a href="/app/agendou/admin/pacotes.php?tab=assinantes" style="color: #c084fc; text-decoration: underline;">Assinantes Ativos</a>, você vê o status de cada um e pode renovar ou cobrar mensalidade pelo WhatsApp com 1 clique!</li>
                    </ul>
                </div>

                <div style="margin-top: 14px; text-align: right;">
                    <a href="/app/agendou/admin/pacotes.php" class="btn-primary" style="padding: 8px 18px; font-size: 0.85rem;">
                        📦 Criar Meu Primeiro Clube
                    </a>
                </div>
            </div>

            <!-- ABA 6: DICAS DE FATURAMENTO -->
            <div id="tutTab-dicas" class="tut-tab-pane" style="display: none;">
                <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 14px; padding: 16px 20px; margin-bottom: 18px;">
                    <h3 style="color: #34d399; font-size: 1rem; margin: 0 0 6px 0; font-weight: 700;">
                        💡 5 Dicas Práticas para Aumentar o Faturamento da sua Barbearia
                    </h3>
                    <p style="font-size: 0.85rem; color: #cbd5e1; margin: 0; line-height: 1.5;">
                        Táticas simples que barbearias de sucesso usam com o Agendou para dobrar o faturamento mensal:
                    </p>
                </div>

                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div class="tut-tip-item">
                        <strong style="color: #facc15;">1. O "Retorno Garantido" na Cadeira</strong>
                        <p style="font-size: 0.82rem; color: #cbd5e1; margin: 4px 0 0 0; line-height: 1.4;">
                            Nunca deixe o cliente sair sem o próximo corte marcado. Ao terminar o acabamento, pergunte: <em>"Vamos deixar reservado o seu mesmo horário para daqui a 15 dias?"</em>. Abra o Dashboard e lance o agendamento em segundos.
                        </p>
                    </div>

                    <div class="tut-tip-item">
                        <strong style="color: #38bdf8;">2. Venda Cruzada de Produtos (Pomadas & Barba)</strong>
                        <p style="font-size: 0.82rem; color: #cbd5e1; margin: 4px 0 0 0; line-height: 1.4;">
                            Aplique a pomada no cliente explicando o efeito e ofereça o pote para ele levar para casa. Cada pomada vendida acrescenta de R$ 30 a R$ 50 no caixa e o valor é somado direto no fechamento do atendimento.
                        </p>
                    </div>

                    <div class="tut-tip-item">
                        <strong style="color: #a855f7;">3. Converta Clientes Regulares em Assinantes</strong>
                        <p style="font-size: 0.82rem; color: #cbd5e1; margin: 4px 0 0 0; line-height: 1.4;">
                            Aquele cliente que corta a cada 10 dias é o candidato perfeito para o <em>Clube de Cortes Ilimitados</em>. Ele sente que está economizando e você garante renda fixa todo dia 1º.
                        </p>
                    </div>

                    <div class="tut-tip-item">
                        <strong style="color: #ef4444;">4. Acabe com o "No-Show" (Faltas sem Avisar)</strong>
                        <p style="font-size: 0.82rem; color: #cbd5e1; margin: 4px 0 0 0; line-height: 1.4;">
                            2 horas antes do atendimento, envie a mensagem de confirmação do WhatsApp pelo botão do Dashboard. Se o cliente desmarcar, a vaga é liberada na hora para outro agendar.
                        </p>
                    </div>

                    <div class="tut-tip-item">
                        <strong style="color: #34d399;">5. Postagem Diária no Instagram com Link na Bio</strong>
                        <p style="font-size: 0.82rem; color: #cbd5e1; margin: 4px 0 0 0; line-height: 1.4;">
                            Fotografe o corte finalizado, poste nos Stories e marque o cliente com o sticker de link direto para a sua página. Novos seguidores agendam na mesma hora!
                        </p>
                    </div>
                </div>
            </div>

        </div>

        <!-- Rodapé do Modal -->
        <div style="padding: 16px 24px; border-top: 1px solid var(--border-color, #1e293b); background: rgba(0, 0, 0, 0.4); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 8px;">
                <span>💡 Dúvidas adicionais? Suporte via WhatsApp no painel.</span>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn-cancel" onclick="prevTutTab()" id="btnTutPrev" style="padding: 8px 16px; font-size: 0.85rem; display: none;">
                    ← Anterior
                </button>
                <button type="button" class="btn-primary" onclick="nextTutTab()" id="btnTutNext" style="padding: 8px 18px; font-size: 0.85rem;">
                    Próximo Passo →
                </button>
                <button type="button" class="btn-primary" onclick="closeAppTutorialModal()" id="btnTutFinish" style="padding: 8px 18px; font-size: 0.85rem; display: none; background: #10b981; border-color: #10b981;">
                    ✓ Entendido, Começar!
                </button>
            </div>
        </div>

    </div>
</div>

<style>
@keyframes tutModalFadeIn {
    from { opacity: 0; transform: scale(0.96); }
    to { opacity: 1; transform: scale(1); }
}

.btn-primary {
    background: #38bdf8 !important;
    color: #0f172a !important;
    font-weight: 700 !important;
    padding: 8px 16px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.btn-primary:hover {
    background: #0284c7 !important;
    color: #fff !important;
}

.btn-cancel {
    background: rgba(255, 255, 255, 0.08);
    color: #cbd5e1;
    font-weight: 600;
    padding: 8px 16px;
    border-radius: 10px;
    border: 1px solid var(--border-color, #1e293b);
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.btn-cancel:hover {
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
}

.tut-tab-btn {
    background: transparent;
    border: 1px solid transparent;
    color: var(--text-muted, #94a3b8);
    padding: 8px 14px;
    border-radius: 10px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    transition: all 0.2s ease;
}

.tut-tab-btn:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.04);
}

.tut-tab-btn.active {
    background: rgba(56, 189, 248, 0.15);
    border-color: rgba(56, 189, 248, 0.4);
    color: #38bdf8;
    font-weight: 700;
}

.tut-card {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border-color, #1e293b);
    border-radius: 14px;
    padding: 16px;
    display: flex;
    gap: 14px;
    align-items: flex-start;
    transition: transform 0.2s, border-color 0.2s;
}

.tut-card:hover {
    transform: translateY(-2px);
    border-color: rgba(56, 189, 248, 0.3);
}

.tut-card-number {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: rgba(56, 189, 248, 0.15);
    color: #38bdf8;
    font-weight: 800;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.tut-link-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #38bdf8;
    font-size: 0.78rem;
    font-weight: 700;
    text-decoration: none;
    transition: gap 0.2s;
}

.tut-link-btn:hover {
    gap: 8px;
    text-decoration: underline;
}

.tut-instruction-row {
    display: flex;
    gap: 16px;
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid var(--border-color, #1e293b);
    border-radius: 14px;
    padding: 14px 18px;
    align-items: flex-start;
}

.tut-icon-circle {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.05);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.tut-tip-item {
    background: rgba(255, 255, 255, 0.02);
    border-left: 3px solid #38bdf8;
    border-radius: 0 10px 10px 0;
    padding: 12px 16px;
}

.tut-grid-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 14px;
}

@media (max-width: 640px) {
    .tut-content-area {
        padding: 14px !important;
    }
    .tut-grid-cards {
        grid-template-columns: 1fr !important;
    }
    .tut-instruction-row {
        flex-direction: column !important;
        gap: 8px !important;
        padding: 12px 14px !important;
    }
}
</style>

<script>
const TUT_TABS = ['passos', 'link', 'agenda', 'caixa', 'clubes', 'dicas'];
let curTutIndex = 0;

function openAppTutorialModal(tabName) {
    const modal = document.getElementById('modalAppTutorial');
    if (!modal) return;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    if (tabName && TUT_TABS.includes(tabName)) {
        curTutIndex = TUT_TABS.indexOf(tabName);
    } else {
        curTutIndex = 0;
    }
    renderTutCurrentTab();
}

function closeAppTutorialModal() {
    const modal = document.getElementById('modalAppTutorial');
    if (!modal) return;
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

function switchTutTab(tabName, btnElem) {
    const idx = TUT_TABS.indexOf(tabName);
    if (idx !== -1) {
        curTutIndex = idx;
        renderTutCurrentTab();
    }
}

function renderTutCurrentTab() {
    const activeTab = TUT_TABS[curTutIndex];

    // Hide all tabs
    document.querySelectorAll('.tut-tab-pane').forEach(el => el.style.display = 'none');
    const targetPane = document.getElementById('tutTab-' + activeTab);
    if (targetPane) targetPane.style.display = 'block';

    // Update buttons
    const buttons = document.querySelectorAll('.tut-tab-btn');
    buttons.forEach((b, i) => {
        if (i === curTutIndex) {
            b.classList.add('active');
            b.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        } else {
            b.classList.remove('active');
        }
    });

    // Update bottom nav buttons
    const btnPrev = document.getElementById('btnTutPrev');
    const btnNext = document.getElementById('btnTutNext');
    const btnFinish = document.getElementById('btnTutFinish');

    if (btnPrev) btnPrev.style.display = curTutIndex > 0 ? 'inline-block' : 'none';
    if (btnNext) btnNext.style.display = curTutIndex < TUT_TABS.length - 1 ? 'inline-block' : 'none';
    if (btnFinish) btnFinish.style.display = curTutIndex === TUT_TABS.length - 1 ? 'inline-block' : 'none';
}

function nextTutTab() {
    if (curTutIndex < TUT_TABS.length - 1) {
        curTutIndex++;
        renderTutCurrentTab();
    }
}

function prevTutTab() {
    if (curTutIndex > 0) {
        curTutIndex--;
        renderTutCurrentTab();
    }
}

function copyTutLink(text, btn) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            const orig = btn.innerText;
            btn.innerText = '✓ Copiado!';
            btn.style.background = '#10b981';
            setTimeout(() => {
                btn.innerText = orig;
                btn.style.background = '';
            }, 2500);
        });
    } else {
        const temp = document.createElement('input');
        temp.value = text;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        btn.innerText = '✓ Copiado!';
        setTimeout(() => { btn.innerText = '📋 Copiar Link'; }, 2500);
    }
}

// Fechar com ESC ou clique fora
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('modalAppTutorial');
        if (modal && modal.style.display === 'flex') {
            closeAppTutorialModal();
        }
    }
});

document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalAppTutorial');
    if (modal && e.target === modal) {
        closeAppTutorialModal();
    }
});

// Auto-abrir se tiver parâmetro ?tutorial na URL
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const tutParam = urlParams.get('tutorial');
    if (tutParam) {
        openAppTutorialModal(tutParam === '1' ? 'passos' : tutParam);
    }
});
</script>
