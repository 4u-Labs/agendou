# ⚡ AGENDOU — Plataforma SaaS de Agendamentos Online & Google Calendar

O **AGENDOU** é um SaaS completo, moderno, multi-tenant e pronto para produção de agendamentos online para barbearias, salões de beleza, clínicas, estúdios e prestadores de serviços.

---

## 🌟 Principais Recursos

1. **Página Pública de Agendamento do Estabelecimento:**
   * URL amigável e curta: `https://4u.ia.br/app/agendou/pedromendes`
   * Fluxo em 4 passos rápidos no celular (&lt; 60 segundos).
   * **Login com Conta Google (1-Clique):** Auto-preenchimento instantâneo de nome e e-mail via Google Identity Services (GIS).
   * Sem necessidade de criar conta com senha ou baixar aplicativos.

2. **Sincronização Bidirecional com Google Calendar:**
   * Integração oficial via Google Calendar API v3 e OAuth 2.0.
   * Criação automática do evento na agenda do estabelecimento.
   * Bloqueio automático de horários para compromissos pessoais criados no Google Agenda.
   * Botão para o cliente adicionar o evento em sua própria agenda.

3. **Confirmação e Lembretes via WhatsApp:**
   * Geração automática de link `wa.me` com mensagem pré-formatada do agendamento.
   * Estrutura preparada para automação e lembretes com 24h e 2h de antecedência.

4. **Multi-Tenant Seguro & Isolado:**
   * Cada estabelecimento possui seus próprios profissionais, serviços, horários, clientes e agendamentos isolados.
   * Banco de dados relacional com `tenant_id` e chaves estrangeiras com integridade referencial.

5. **Disponibilidade em Tempo Real & Bloqueio Atômico:**
   * O motor calcula os horários livres cruzando: expediente semanal, intervalos de almoço, duração do serviço, compromissos existentes e eventos do Google Calendar.
   * Validação atômica antes da gravação para impedir agendamentos simultâneos duplicados.

6. **Painel Administrativo Completo:**
   * **Dashboard:** Métricas diárias de faturamento, agendamentos confirmados, cancelados e clientes.
   * **Agenda Visual:** Grade diária e semanal por profissional.
   * **Serviços:** Catálogo de serviços com duração, preços e categorias.
   * **Profissionais:** Cadastro de barbeiros/atendentes com horários específicos.
   * **Clientes (CRM):** Histórico de visitas, valor acumulado e WhatsApp direto.
   * **Horários & Bloqueios:** Grade semanal e bloqueio de datas para feriados/férias.
   * **Google Calendar:** Central de conexão OAuth 2.0 e seleção de calendário.
   * **Configurações & QR Code:** Gerador de QR Code de alta resolução para balcão e mesas.
   * **Super Admin:** Gestão de todas as empresas cadastradas na plataforma SaaS.

---

## 📂 Estrutura de Diretórios

```
/home/fabiano/public_html/app/agendou/
├── api/                     # Endpoints da API REST
│   ├── appointments.php     # Criação de agendamentos com lock atômico
│   ├── availability.php     # Consulta de slots disponíveis em tempo real
│   ├── cancel.php           # Cancelamento seguro via token
│   └── google_callback.php  # Callback OAuth 2.0 do Google Calendar
├── app/
│   └── Services/            # Motores de negócio
│       ├── AvailabilityService.php  # Cálculo de disponibilidade
│       ├── GoogleCalendarService.php # Integração Google Calendar API
│       └── WhatsAppService.php      # Geração de mensagens e links wa.me
├── config/
│   ├── config.php           # Configurações globais e carregador .env
│   └── database.php         # Conexão PDO e migrações automáticas
├── database/
│   ├── schema.sql           # Schema relacional (SQLite / MySQL)
│   ├── seed.php             # Seeder do estabelecimento Pedro Mendes e SuperAdmin
│   └── agendou.sqlite       # Banco relacional local ativo
├── public/
│   └── css/                 # Folhas de estilo modernas (landing, booking, admin)
├── views/
│   └── booking.php          # Página pública do cliente (Mobile-first)
├── admin/                   # Painel administrativo multi-tenant
│   ├── index.php            # Dashboard operacional
│   ├── agenda.php           # Grade visual
│   ├── services.php         # Gestão de serviços
│   ├── professionals.php    # Gestão de equipe
│   ├── customers.php        # CRM de clientes
│   ├── hours.php            # Horários e bloqueios
│   ├── google.php           # Conexão Google Calendar
│   ├── settings.php         # Configurações e QR Code
│   ├── super.php            # Painel do dono da plataforma SaaS
│   ├── login.php            # Tela de login
│   └── logout.php           # Encerramento de sessão
├── index.php                # Router amigável (/pedromendes) e Landing Page
├── cancelar.php             # Portal seguro de cancelamento pelo cliente
├── manifest.json            # PWA manifest
├── service-worker.js        # Cache offline PWA
└── .env.example             # Modelo de variáveis de ambiente
```

---

## 🚀 Como Acessar e Testar

### 1. Página Pública do Cliente (Demonstração Pedro Mendes Barbearia):
👉 **https://4u.ia.br/app/agendou/?slug=pedromendes**

### 2. Painel Administrativo do Estabelecimento:
👉 **https://4u.ia.br/app/agendou/admin/**  
* **Usuário:** `pedro@barbearia.com`  
* **Senha:** `admin123`

### 3. Painel do Super Administrador (Dono do SaaS):
👉 **https://4u.ia.br/app/agendou/admin/login.php**  
* **Usuário:** `admin@agendou.com.br`  
* **Senha:** `admin123`

---

## ⚙️ Configuração do Google Cloud OAuth 2.0

Para habilitar a sincronização com o Google Calendar em produção:
1. Acesse o [Google Cloud Console](https://console.cloud.google.com/).
2. Crie ou selecione seu projeto.
3. Ative a **Google Calendar API**.
4. Em **Tela de permissão OAuth**, configure o nome do app e escopos (`calendar.events`, `calendar.readonly`).
5. Em **Credenciais**, crie um **ID do cliente OAuth 2.0 (Aplicativo Web)**:
   * **URIs de redirecionamento autorizados:**  
     `https://4u.ia.br/app/agendou/api/google_callback.php`
6. Cole o `CLIENT_ID` e o `CLIENT_SECRET` no seu arquivo `.env`.

---

© 2026 AGENDOU • Desenvolvido com excelência técnica para o ecossistema **4U.IA.BR**.
