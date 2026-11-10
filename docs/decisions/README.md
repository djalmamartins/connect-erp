# Decisões (ADRs) — índice único

O projeto tem duas séries de ADR por motivo histórico. Elas **não serão renumeradas**:
os nomes atuais são citados em código, migrations, specs e PRs, e renomear quebraria essas
referências. Este arquivo é o índice único das duas (issue #9).

## Regra para novas decisões

- Toda decisão nova vai em `docs/Organic Code/decisions/` com o próximo número de 4 dígitos
  (`NNNN-titulo-em-kebab-case.md`). **Próximo número livre: 0167.**
  Faixas reservadas por frente: Preparar 0060–0069, layout 0070–0079 e depois 0120–0139, Avançar 0080–0099 (0055–0059 já usados pelo Avançar). Dentro da faixa, use o próximo número livre da sua frente (layout: 0120; Avançar: 0167, na faixa nova 0160–0199, porque 0080–0099 acabou; Preparar: 0116, porque 0060–0069 já foram usados). Quando as faixas atuais acabarem: Preparar 0100–0119, layout 0120–0139. Threads avulsas (fora dessas frentes): 0140–0159. Avançar, depois de 0099: 0160–0199.
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
| [0056](../Organic%20Code/decisions/0056-notification-events.md) | Avisos de fatura, boleto, acordo, quebra e reserva aprovada pela outbox, com opt-in de cobrança por e-mail |
| [0057](../Organic%20Code/decisions/0057-entry-shortcuts-and-receipts.md) | Lançamentos avulsos (AP-024 a AP-029): desconto com trilha, multa avulsa e cobrança extra travadas pela origem, transferência entre contas, e recibo com código (AP-040) |
| [0058](../Organic%20Code/decisions/0058-imports.md) | Importações com prévia, validação do arquivo inteiro e idempotência por hash: extrato OFX conciliado sem baixa automática (AP-140) e planilhas CSV/XLSX (AP-142 a AP-146) |
| [0059](../Organic%20Code/decisions/0059-communication-outbox.md) | Comunicação pela outbox: comunicados gerais (AP-111), aviso de cobrança (AP-137), agendados (AP-138) e endereços de comunicação (AP-139), só em log ou sandbox fora de produção |
| [0060](../Organic%20Code/decisions/0060-smtp-credentials-from-environment.md) | Credenciais SMTP pelo ambiente (`MOVESOS_SMTP_*`), valendo sobre a tabela settings |
| [0061](../Organic%20Code/decisions/0061-panels-require-module-permissions.md) | Painéis e dashboard exigem a permissão de cada módulo que mostram; disparo da fila de avisos só com acesso a todos os condomínios |
| [0062](../Organic%20Code/decisions/0062-shortcuts-and-receipts-hardening.md) | Recibo sem oráculo de id de outro condomínio, teto de valor em `Invoices::cents` e no máximo 1000 unidades por cobrança extra |
| [0063](../Organic%20Code/decisions/0063-imports-hardening.md) | Importações: valores do OFX e da planilha com teto e sem estouro, XLSX esparso recusado e leitura do ZIP limitada |
| [0064](../Organic%20Code/decisions/0064-performance-indexes.md) | Índices de `erp_entries` por data de pagamento (condomínio e conta bancária) e EXPLAIN das consultas quentes na suíte de integração |
| [0065](../Organic%20Code/decisions/0065-communications-hardening.md) | Comunicação: assunto em uma linha, unidades escolhidas com teto, trava por condomínio no aviso de cobrança e limite de 20 comunicados por dia |
| [0066](../Organic%20Code/decisions/0066-collections-hardening.md) | Cobrança e multas: contas do caso judicial buscadas só na unidade e no condomínio, trava no andamento do caso e nome do contato em uma linha |
| [0067](../Organic%20Code/decisions/0067-collection-reports-hardening.md) | Relatórios de cobrança: célula do CSV neutralizada também depois de espaço ou controle e em largura total, e teto de 20.000 recebimentos por consulta |
| [0068](../Organic%20Code/decisions/0068-management-reports-hardening.md) | Relatórios gerenciais: conta bancária inexistente e de fora respondem igual, teto da matriz do rateio e data do extrato por parâmetro |
| [0069](../Organic%20Code/decisions/0069-chart-data-hardening.md) | Dados dos gráficos: saldo por conta bancária filtrado por condomínio dentro da subconsulta e com as 8 contas de maior movimento |
| [0070](../Organic%20Code/decisions/0070-operational-screens.md) | Telas do Operacional: Agendamentos Pendentes, chamado do Help Desk na ocorrência, lista de espera, próxima manutenção e dashboard com dados reais |
| [0071](../Organic%20Code/decisions/0071-condominium-unit-panel-screens.md) | Telas dos painéis do condomínio (AP-002) e da unidade (AP-003) sobre o PanelSummaries |
| [0072](../Organic%20Code/decisions/0072-notification-queue-screen.md) | Tela da fila de avisos (CN-005): situação, trilha com endereços mascarados e reenvio manual |
| [0073](../Organic%20Code/decisions/0073-entry-shortcut-screens.md) | Telas dos lançamentos avulsos (AP-024 a AP-029) e do recibo (AP-040): desconto, multa, cobrança extra, transferência e recibo de contas pagas |
| [0074](../Organic%20Code/decisions/0074-import-screens.md) | Telas de importação (AP-140, AP-142 a AP-146 e AP-044): envio, prévia por linha, aplicar e fila de linhas pendentes do extrato |
| [0075](../Organic%20Code/decisions/0075-communication-screens.md) | Telas de comunicação (AP-111, AP-137, AP-138 e AP-139): comunicado, aviso de cobrança, agendados e endereços |
| [0076](../Organic%20Code/decisions/0076-collection-screens.md) | Telas de cobrança (AP-116, AP-117 e AP-039): multas pendentes com origem, atendimento com histórico e cobrança judicial só como acompanhamento |
| [0077](../Organic%20Code/decisions/0077-collection-report-screens.md) | Telas dos relatórios de cobrança (AP-047, AP-048, AP-057 a AP-061, AP-071 e AP-072): filtros, rodapé com somas e CSV pela API |
| [0078](../Organic%20Code/decisions/0078-chart-screens.md) | Telas dos gráficos (AP-102 a AP-109): um eixo por painel, barras empilhadas nas composições e a tabela equivalente |
| [0079](../Organic%20Code/decisions/0079-nfe-import-screen.md) | Tela da importação de NF-e em XML (AP-141): conta de despesa obrigatória, vencimento para nota sem duplicata e limite de 1 MB |
| [0080](../Organic%20Code/decisions/0080-collections-and-fines.md) | Cobrança e multas: multas pendentes com a origem (AP-116), atendimento de cobrança com promessa quebrada (AP-117) e cobrança judicial só como acompanhamento (AP-039) |
| [0081](../Organic%20Code/decisions/0081-collection-reports.md) | Relatórios de cobrança: acordos e taxas (AP-047/048), inadimplentes em 5 visões (AP-057 a AP-061) e recebimentos (AP-071/072), com CSV protegido contra injeção de fórmula |
| [0082](../Organic%20Code/decisions/0082-management-reports.md) | Relatórios gerenciais: consumo (AP-045/046), centros de custo e comparativo (AP-049/050), demonstrativos e extrato (AP-052 a AP-056), rateio pela fração ideal (AP-062 a AP-067), lançamentos e boletos (AP-068 a AP-070) |
| [0083](../Organic%20Code/decisions/0083-chart-data.md) | Dados dos gráficos (AP-102 a AP-109): séries mensais de receitas e despesas, contas, consumo, caixa, inadimplência, contas bancárias e áreas comuns, só consulta |
| [0084](../Organic%20Code/decisions/0084-registry-admin-reports.md) | Relatórios cadastrais e administrativos (AP-085 a AP-099): reservas, unidades, pessoas com dados mascarados, condomínios, ocorrências, pagamentos pendentes, prestações, mudanças, endereços, logs, acessos e boletos enviados |
| [0085](../Organic%20Code/decisions/0085-erp-settings.md) | Configurações do ERP (AP-147 a AP-167): parâmetros por condomínio sobre o padrão, sem credenciais e auditados, saldo inicial por centro de custo, previsão orçamentária, frações em lote, banners e impostos |
| [0086](../Organic%20Code/decisions/0086-nfe-import.md) | Importação de NF-e XML (AP-141): leitura segura sem DOCTYPE nem entidades, contas a pagar por duplicata depois da prévia, chave de acesso única por condomínio, sem SEFAZ |
| [0087](../Organic%20Code/decisions/0087-erp-hardening.md) | Endurecimento do ERP: dados pessoais mascarados na auditoria e nos logs, tetos nos relatórios, índices de período, sem consulta por linha nas importações e limite de 30 CSV por hora |
| [0088](../Organic%20Code/decisions/0088-assemblies-voting.md) | Assembleia e votação: edital pela outbox, presença com procuração, inadimplente sem voto, apuração por fração ou unidade e ata imutável com sha256 |
| [0089](../Organic%20Code/decisions/0089-payment-calculation-transfer-reports.md) | Relatórios de pagamentos, memória de cálculo dos encargos (mesmo LateCharges da cobrança) e transferências entre contas, com CSV seguro |
| [0090](../Organic%20Code/decisions/0090-partner-resident-access.md) | Acesso de imobiliárias parceiras (papel fixo erp_partner, só leitura mascarada), convite de morador de uso único e matriz do portal do morador desligada por padrão |
| [0091](../Organic%20Code/decisions/0091-integration-status.md) | Integrações: estado do banco, do e-mail, dos índices e da fila, só leitura e sem credenciais |
| [0092](../Organic%20Code/decisions/0092-resident-invitation-acceptance.md) | Aceite do convite do morador em /convite: token só na sessão, mensagem genérica, CPF do cadastro, limite por IP e token, conta comum sem login automático |
| [0093](../Organic%20Code/decisions/0093-invite-hardening.md) | Convite do morador: limite de tentativas que falha fechado (abrir e aceitar) e CPF/CNPJ válido obrigatório para convidar e para aceitar |
| [0094](../Organic%20Code/decisions/0094-quality-pass-37-42.md) | Passada de qualidade dos ciclos 37 a 42: listagem de imobiliárias com teto e escopo, desvínculo com escopo, valor vencido da presença só com erp.agreements.view e matriz de permissões das rotas novas |
| [0095](../Organic%20Code/decisions/0095-checkin-debt-gate.md) | Check-in da assembleia sem o valor vencido: overdue_cents só com erp.agreements.view; o direito a voto e o motivo sempre visíveis |
| [0096](../Organic%20Code/decisions/0096-review-settings-reports-shortcuts-agreements.md) | Revisão de configurações, relatórios cadastrais, atalhos e acordos: banners só com texto simples, lote de frações em transação com FOR UPDATE, IP e e-mail mascarados, teto nos ajustes e 404 na lista de acordos fora do escopo |
| [0097](../Organic%20Code/decisions/0097-plaintext-precision-settings-audit.md) | Texto simples dos banners sem falso positivo (domínio solto só com TLD comum ou .com.br) e auditoria das configurações com CPF/CNPJ mascarado pelo AuditMask::text |
| [0098](../Organic%20Code/decisions/0098-upload-antivirus-public-document-verification.md) | Antivírus opcional no upload do Disco Virtual (falha fechado em enforce) e verificação pública de documentos em /verificar (só autêntico, tipo, data e condomínio; limite por IP) |
| [0099](../Organic%20Code/decisions/0099-printed-verification-code-and-link.md) | Código e link de verificação no rodapé dos documentos da unidade (código no fragmento do link; rotas /verificar no index.php, em qualquer host) |
| [0100](../Organic%20Code/decisions/0100-month-closing-concurrency.md) | Fechamento mensal: competência conferida com leitura travada na transação da gravação e fechamento que grava a situação antes de somar |
| [0101](../Organic%20Code/decisions/0101-occurrence-ticket-and-balances.md) | Ocorrência: vínculo do chamado gravado na transação do chamado, sem chamado órfão; saldo bancário só com lançamentos e transferências do condomínio da conta |
| [0102](../Organic%20Code/decisions/0102-statement-bank-filter.md) | Prestação de contas: conciliação por conta bancária só com lançamentos do condomínio da conta e até o fim do período |
| [0103](../Organic%20Code/decisions/0103-unit-panel-and-waitlist-call.md) | Painel da unidade com a inadimplência só da unidade e chamada da lista de espera com a reserva travada, uma pessoa chamada por vez |
| [0104](../Organic%20Code/decisions/0104-meter-reading-closed-competence.md) | Leitura manual e gravação da importação recusam competência fechada; consumo de energia em kWh |
| [0105](../Organic%20Code/decisions/0105-unit-documents-reference-date.md) | Documentos da unidade apurados na data de referência, só com contas do condomínio da unidade; cancelamento sem auditoria dupla |
| [0106](../Organic%20Code/decisions/0106-recurring-generation-errors.md) | Geração dos lançamentos fixos desfaz a transação do modelo em qualquer erro; dado inválido vai para failed |
| [0107](../Organic%20Code/decisions/0107-assembly-vote-tally-lock.md) | Assembleia: voto com o item travado em modo compartilhado e apuração com o item travado antes de somar, sem voto fora do resultado |
| [0108](../Organic%20Code/decisions/0108-notification-processing-lease.md) | Fila de avisos: posse com prazo em processing, aviso preso é retomado e reenvio manual só a partir de failed ou cancelled |
| [0109](../Organic%20Code/decisions/0109-announcement-cancel-lock.md) | Cancelamento de comunicado com o comunicado travado e conferido de novo, sem auditoria dupla |
| [0110](../Organic%20Code/decisions/0110-price-index-import-limits.md) | Índices de correção: mês futuro recusado e no máximo 600 meses por arquivo |
| [0111](../Organic%20Code/decisions/0111-partner-access-review.md) | Revisão do ADR 0090: vínculo e concessão de parceiro com travas, painel operacional só com erp.units.view e entrega do convite com token novo só para quem ainda não recebeu |
| [0112](../Organic%20Code/decisions/0112-invitation-token-fragment.md) | Convite do morador: token depois do "#" no link do e-mail, levado por POST com CSRF; caminho antigo fora do log de acesso |
| [0113](../Organic%20Code/decisions/0113-calculation-report-limits.md) | Memória de cálculo: data de referência até 60 dias à frente e no máximo 120 mil meses de índice por consulta |
| [0114](../Organic%20Code/decisions/0114-plain-text-uncommon-tlds.md) | Texto simples: domínio com caminho ou código de país recusado mesmo com TLD fora da lista |
| [0115](../Organic%20Code/decisions/0115-fixed-verification-address-and-signatures.md) | Verificação pública no endereço fixo /verificar-certidao-debitos (formulário sem AJAX; /verificar redireciona) e assinaturas do síndico e da administradora nos documentos |
| [0140](../Organic%20Code/decisions/0140-unit-batch-and-measures-grid.md) | Cadastro rápido de unidades (sequência por andares, pré-visualização, tudo ou nada) e fração ideal/área em grade no AP-167 |
| [0141](../Organic%20Code/decisions/0141-help-center-knowledge-base.md) | Base de conhecimento em arquivos com prints marcados gerados pelo Playwright, cobertura obrigatória das páginas do menu e Central de Ajuda pública com mensagem que vira chamado |
| [0142](../Organic%20Code/decisions/0142-erp-helpdesk-conversation-and-shared-bell.md) | Integração ERP, Help Desk e Studio: conversa do chamado no ERP com resposta, avisos nos dois sentidos, sino compartilhado e deep link entre ambientes (/ambiente/{env}?ir=) |
| [0160](../Organic%20Code/decisions/0160-review-verification-antivirus.md) | Revisão de segurança da verificação pública e do antivírus: limite global de falhas, CSRF em tempo constante, cabeçalhos anti-frame, prazo total e teto de 25 MB no clamd, download bloqueado para infectado/erro |
| [0161](../Organic%20Code/decisions/0161-document-rescan.md) | Reverificação em lotes dos arquivos do Disco Virtual quando o antivírus é ligado depois (tools/erp-rescan.php, com trava) e o bloco do antivírus na tela de Integrações |
| [0162](../Organic%20Code/decisions/0162-optional-cnpj-people-search-links-lab-data.md) | CNPJ do condomínio opcional, busca e vínculo de proprietário/inquilino em várias unidades (API /people) e dados de exemplo do lab com trava |
| [0163](../Organic%20Code/decisions/0163-api-optional-scope-includes-standard.md) | API de tipos de fatura com condominium_id inclui os tipos padrão (o do condomínio prevalece) e recusa condomínio fora do escopo |
| [0164](../Organic%20Code/decisions/0164-demo-pages-inventory-annual-balance.md) | Inventário das páginas em demonstração (docs/process/PAGINAS-EXEMPLO.md) e API do Balancete Anual GET /api/v1/reports/annual-balance (competência ou caixa, escopo, LIMIT, CSV com limite de 30/h) |
| [0165](../Organic%20Code/decisions/0165-accounting-reports.md) | Relatórios contábeis: Livro Razão (/reports/ledger), Saldos de Contas (/reports/account-balances) e DRE (/reports/income-statement), com competência ou caixa, escopo, LIMIT e CSV; Balanço Patrimonial pendente (plano de contas sem natureza patrimonial) |
| [0166](../Organic%20Code/decisions/0166-accountability-deliveries-chart-openings.md) | Prestação de contas mensal (AP-101) montada dos relatórios existentes, registros de entregas com baixa da retirada (AP-136) e saldo inicial do plano de contas só em conta analítica (AP-161) |
