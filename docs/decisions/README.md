# Decisões (ADRs) — índice único

O projeto tem duas séries de ADR por motivo histórico. Elas **não serão renumeradas**:
os nomes atuais são citados em código, migrations, specs e PRs, e renomear quebraria essas
referências. Este arquivo é o índice único das duas (issue #9).

## Regra para novas decisões

- Toda decisão nova vai em `docs/Organic Code/decisions/` com o próximo número de 4 dígitos
  (`NNNN-titulo-em-kebab-case.md`). **Próximo número livre: 0071.**
  Faixas reservadas por frente: Avançar 0055–0059, Preparar 0060–0069, layout 0070 em diante.
- Antes de criar, confira este índice e o diretório: duas threads já usaram o mesmo número
  em paralelo (0010). Ao criar, acrescente a linha na tabela abaixo no mesmo commit.
- A série `ADR-00N` de `docs/decisions/` fica congelada: são as decisões de fundação do
  produto (núcleo, administradora única, design system). Não criar ADR-004.

## Fundação (`docs/decisions/`)

| ADR | Decisão |
|---|---|
| [ADR-001](ADR-001-EXISTING-CORE.md) | Reutilizar núcleo de identidade e auditoria |
| [ADR-002](ADR-002-ADMINISTRADORA-UNICA.md) | Administradora única, sem SaaS |
| [ADR-003](ADR-003-STUDIO-DESIGN-SYSTEM.md) | Design System do Studio em camada única |

## Implementação (`docs/Organic Code/decisions/`)

| ADR | Decisão |
|---|---|
| [0001](../Organic%20Code/decisions/0001-local-environment.md) | Ambiente local na stack XAMPP compartilhada |
| [0002](../Organic%20Code/decisions/0002-helpdesk-support-themes.md) | Help Desk e Central de Suporte como temas próprios |
| 0003 | Não existe: o ADR de assets foi renumerado para 0004 (`5e8ddbd`) |
| [0004](../Organic%20Code/decisions/0004-organic-shared-assets.md) | Distribuição Organic em `container/shared` e fim de `organic/` e `src/` |
| [0005](../Organic%20Code/decisions/0005-legacy-images-storage.md) | Imagens do legado no storage, fora do Git |
| [0006](../Organic%20Code/decisions/0006-fail-closed-access-and-offline-unit-tests.md) | Autorização fail-closed e suíte unit sem MariaDB |
| [0007](../Organic%20Code/decisions/0007-erp-theme-and-condominiums-slice.md) | Tema ERP e primeira fatia de Condomínios |
| [0008](../Organic%20Code/decisions/0008-send-mail-templates.md) | Templates de e-mail no ambiente `send` |
| [0009](../Organic%20Code/decisions/0009-password-reset-tokens.md) | Recuperação de senha com token aleatório, hash e uso único |
| [0010](../Organic%20Code/decisions/0010-erp-frontend-shell-and-catalog.md) | Moldura do ERP, catálogo de rotas e login compartilhado |
| [0011](../Organic%20Code/decisions/0011-condominium-registry.md) | Cadastro e edição de condomínios (AP-004) |
| [0012](../Organic%20Code/decisions/0012-single-database.md) | Banco único (C5) |
| [0013](../Organic%20Code/decisions/0013-condominium-onboarding-checklist.md) | Checklist de chegada do condomínio |
| [0014](../Organic%20Code/decisions/0014-registry-engine.md) | Motor único dos cadastros do ERP |
| [0015](../Organic%20Code/decisions/0015-inspections-gps-photos.md) | Vistoria presencial: check-in por GPS, fotos privadas e relatório |
| [0016](../Organic%20Code/decisions/0016-code-map-and-watchdog.md) | Mapa do código (`docs/map/`) e vigia de 30 minutos com issues como fila |
| [0017](../Organic%20Code/decisions/0017-units-and-unit-links.md) | Unidades (AP-006) e troca de `unit_label` por `unit_id` |
| [0018](../Organic%20Code/decisions/0018-condominium-areas-assets.md) | Áreas comuns, áreas vinculáveis e patrimônio pelo motor de cadastros |
| [0019](../Organic%20Code/decisions/0019-chart-of-accounts.md) | Plano de contas (AP-160): padrão da Connect e contas por condomínio |
| [0020](../Organic%20Code/decisions/0020-invoice-types-and-references.md) | Tipos de faturas (AP-168) e campo de referência entre cadastros |
| [0021](../Organic%20Code/decisions/0021-cost-centers-and-bank-accounts.md) | Centros de custo (CN-002) e contas bancárias (CN-003) |
| [0022](../Organic%20Code/decisions/0022-financial-entries.md) | Lançamentos (AP-020): receitas e despesas por condomínio |
| [0023](../Organic%20Code/decisions/0023-login-throttle-account-ip.md) | Limite de tentativas de login e 2FA por conta e por IP |
| [0024](../Organic%20Code/decisions/0024-payables-receivables-bank-balances.md) | Contas a pagar e a receber (AP-022/023) como visões dos lançamentos e saldo por conta bancária |
| [0025](../Organic%20Code/decisions/0025-admin-two-factor-reset.md) | Reset do 2FA de outro usuário por administrador |
| [0026](../Organic%20Code/decisions/0026-studio-helpdesk-erp-shell.md) | Studio (CMS) e Help Desk com a moldura do ERP, e Studio em host próprio |
| [0027](../Organic%20Code/decisions/0027-recurring-entries.md) | Lançamentos fixos (AP-021) e geração do mês sem duplicar |
| [0028](../Organic%20Code/decisions/0028-two-factor-change-notice.md) | Aviso por e-mail quando o 2FA da conta muda |
| [0029](../Organic%20Code/decisions/0029-two-factor-qr-code.md) | QR code na configuração do 2FA, gerado no servidor |
| [0030](../Organic%20Code/decisions/0030-condominium-context-in-url.md) | Condomínio da barra superior como contexto global do ERP, com slug na URL |
| [0031](../Organic%20Code/decisions/0031-month-closing.md) | Fechamento mensal (AP-030): resumo da competência e trava dos lançamentos |
| [0032](../Organic%20Code/decisions/0032-finance-condominium-context.md) | Financeiro no contexto do condomínio da barra superior (padrão da Connect nas listas e tela do fechamento) |
| [0033](../Organic%20Code/decisions/0033-invoices.md) | Faturas (AP-032): cobrança por unidade, emitida como contas a receber, sem duplicar |
| [0034](../Organic%20Code/decisions/0034-tickets-helpdesk-and-environment-switcher.md) | Todo chamado na fila do Help Desk (condomínio, unidade, solicitante) e alternador ERP / Studio / Help Desk |
| [0035](../Organic%20Code/decisions/0035-bank-charges-sandbox.md) | Boletos, retorno e conciliação pelo adaptador bancário (só sandbox, sem credencial nem banco real) |
| [0036](../Organic%20Code/decisions/0036-logout-all-hosts.md) | Sair em um host encerra as sessões do usuário nos outros |
| [0037](../Organic%20Code/decisions/0037-financial-reports.md) | Relatórios financeiros: balancete de verificação e prestação de contas com conciliação bancária |
| [0038](../Organic%20Code/decisions/0038-banking-screens.md) | Telas da cobrança bancária: boletos, importar retorno e fila de divergências (sandbox) |
| [0039](../Organic%20Code/decisions/0039-delinquency-and-agreements.md) | Inadimplência por unidade e acordos (AP-033/034): contas vencidas viram parcelas, sem dívida duplicada |
| [0040](../Organic%20Code/decisions/0040-late-charges-and-agreement-breach.md) | Multa, juros, correção pelo IPCA e quebra de acordo, com padrões ajustáveis por condomínio (issue #46) |
| [0041](../Organic%20Code/decisions/0041-agreements-screens.md) | Telas de inadimplência e acordos (AP-033/034) e lista de arquivos de retorno em AP-038 |
| [0042](../Organic%20Code/decisions/0042-gatehouse.md) | Portaria (AP-131 a AP-136): portarias, previsão de visita, registro de acessos, achados e perdidos e entregas |
| [0043](../Organic%20Code/decisions/0043-late-charges-screens.md) | Telas de encargos: regra de cobrança e IPCA (AP-165), encargos na inadimplência, sugestão no acordo e quebra explícita |
| [0044](../Organic%20Code/decisions/0044-unit-documents.md) | Documentos da unidade: certidão de débitos, declaração de inadimplência, quitação e anuência, com código de verificação |
| [0045](../Organic%20Code/decisions/0045-meter-readings.md) | Leituras e consumo (AP-041 a AP-043): relógios, tarifas em faixas progressivas, leituras e cobrança individual |
| [0046](../Organic%20Code/decisions/0046-unit-documents-screens.md) | Telas dos documentos da unidade (AP-123/124/125/130): emitir, conferir pelo código, imprimir e cancelar |
| [0047](../Organic%20Code/decisions/0047-virtual-disk.md) | Disco Virtual (AP-122): arquivos fora da pasta pública, tipo conferido pelo conteúdo, download só com permissão e escopo |
| [0048](../Organic%20Code/decisions/0048-meter-readings-screen.md) | Tela de leituras do mês (AP-042): lançamento em lote, consumo e valor por unidade e faturar |
| [0049](../Organic%20Code/decisions/0049-virtual-disk-screen.md) | Tela do Disco Virtual (AP-122): pastas, envio multipart com progresso, baixar e excluir |
| [0050](../Organic%20Code/decisions/0050-document-registries.md) | Avisos de recebimento, cópias de cheques, empenhos e malotes (AP-126 a AP-129), com anexo do Disco Virtual |
| [0051](../Organic%20Code/decisions/0051-operational-registries.md) | Operacional (AP-110 a AP-121): manutenções, ocorrências com chamado no Help Desk, reservas pelas regras da área comum, mudanças, seguros e contratos |
| [0052](../Organic%20Code/decisions/0052-registry-attachment-picker.md) | Seletor do Disco Virtual no anexo dos cadastros AP-126 a 129: escolher, enviar e anexar no formulário |
| [0053](../Organic%20Code/decisions/0053-operational-dashboard.md) | Dashboard com alertas reais, situações pela data, chamada da lista de espera e próxima manutenção |
| [0054](../Organic%20Code/decisions/0054-condominium-unit-panels.md) | Painéis do condomínio (AP-002) e da unidade (AP-003) e outbox de notificações (sem envio ainda) |
| [0055](../Organic%20Code/decisions/0055-notification-dispatch.md) | Fila de envio dos avisos do ERP: modelo do send, log ou caixa de teste fora de produção, retentativas e trilha |
| [0070](../Organic%20Code/decisions/0070-operational-screens.md) | Telas do Operacional: Agendamentos Pendentes, chamado do Help Desk na ocorrência, lista de espera, próxima manutenção e dashboard com dados reais |
