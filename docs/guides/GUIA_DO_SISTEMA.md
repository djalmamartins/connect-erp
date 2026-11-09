# Guia do sistema Connect ERP

Situação em 09/11/2026, com a main no commit do PR que trouxe este guia. Este documento diz o que
existe, o que funciona de verdade, o que ainda é tela de exemplo e onde está o arquivo de cada parte.

## 1. Resumo honesto

- **O ERP tem 171 páginas no menu.** 107 funcionam com dados reais do banco (cadastros, financeiro,
  cobrança, operacional, documentos, importações, comunicação e parte dos relatórios). 53 mostram uma
  tela de exemplo, mas o backend delas já existe na API REST (`/api/v1/...`): falta ligar a tela. 11
  são só tela de exemplo, sem backend (dependem do banco real ou de uma regra que ainda não foi definida).
- **"Tela de exemplo"** quer dizer: a página abre, tem filtros, colunas e botões, mas as linhas vêm
  de `database/fixtures/erp-demo` (dados inventados). Nada é gravado. Isso é ligado por
  `ERP_DEMO_DATA`; fora de produção ele fica ligado.
- **Nenhum boleto, Pix ou CNAB real.** O boleto e o retorno usam o adaptador sandbox (simulado).
- **Nenhum e-mail sai para morador.** A fila de avisos fica em modo `log` (só registra) ou `sandbox`
  (vai só para a caixa de teste `ERP_MAIL_SANDBOX_TO`).
- **Antivírus desligado por padrão** (`ERP_AV_MODE=off`): os arquivos entram como "não verificado".
- **O que foi testado:** 501 testes unitários e o smoke das telas (3.542 verificações, todas as 171
  páginas renderizadas sem erro), rodados na nuvem em 09/11. Os 72 arquivos de testes de integração
  (fluxos de ponta a ponta no banco `movesos_test`) só rodam no Mac, e a última rodada completa no lab
  não cobriu os ciclos 47 a 50 (migration 0546, verificação pública e reverificação do antivírus).
- **O que nunca foi testado de ponta a ponta por uma pessoa:** login real com 2FA no
  erp.connect.lab clicando em cada tela, o console do navegador, o celular, e qualquer integração
  externa (banco, SMTP, clamd). O script da seção 2 faz a parte automática; a parte de clicar é a
  seção 3.

## 2. Como atualizar e testar no Mac

No Terminal:

```bash
cd "/Volumes/MAC HD/Docker/xampp/www/connect"
git checkout main
bash tools/testar-main.sh
```

O script `tools/testar-main.sh` faz, nesta ordem, e para no primeiro problema grave:

| Passo | O que faz | Se falhar |
|---|---|---|
| 0 | Confere que a pasta está na `main`, sem arquivo versionado alterado, com `.env` e Docker ligado | Para e mostra o que está alterado (não mexe em nada) |
| 1 | `git pull --ff-only origin main` | Para (a main local divergiu) |
| 2 | Sobe `legacy-db` e `app` pelo compose e espera ficarem saudáveis | Para |
| 3 | Backup do banco (`.local/backups/connect-erp-*.sql.gz`) e de `storage/private` | Para, sem tocar no banco |
| 4 | `composer install` no container | Para |
| 5 | Testes unitários, de integração e smoke das telas, todos no banco `movesos_test` | Continua, mas não aplica migrations |
| 6 | Migrations no banco do lab (todas as pendentes) | Mostra o backup para restaurar |
| 6b | Cria `CONNECT_2FA_KEY` (se faltar) e liga `CONNECT_2FA_ENFORCE_FROM` no `.env` local, só com a tabela do 2FA aplicada | Marca ATENÇÃO e não liga o 2FA |
| 7 | Reconstrói o scheduler e confere 9 endereços por HTTP sem login | Marca no resumo |
| 8 | Mostra o resumo com OK/FALHOU por etapa | |

Os registros de cada passo ficam em `.local/logs/testar-main-AAAAMMDD-HHMMSS/`. Opções:
`SEM_PULL=1`, `SEM_HTTP=1`, `MIGRATE_UNTIL=20261108_0546`, `LAB_SEM_2FA=1` (não mexe no 2FA) e
`FORCAR_MIGRATIONS=1` (aplica as migrations mesmo com a integração falhando; o backup já foi feito).

> **Migrations pendentes escondem páginas.** Uma permissão só existe depois que a migration que a
> cria roda; até lá a página some do menu, até para o papel `developer`. Se algo não aparece no
> menu, confira primeiro a linha "Migrations pendentes agora" do resumo.

> **Nunca rode `vendor/bin/phpunit` direto.** Em 07/11 isso esvaziou o banco real
> `connect_legacy`. Os testes só rodam por `composer test:unit` e `composer test:integration`, que
> exigem um banco terminado em `_test`. O script segue essa regra.

O `tools/lab-atualizar.sh` continua existindo: ele faz backup, integração e migrations, mas não faz
o pull, não roda os unitários nem o smoke, e não mostra resumo.

Para restaurar o backup, se uma migration quebrar o banco:

```bash
gunzip -c .local/backups/connect-erp-AAAAMMDD-HHMMSS.sql.gz | docker compose exec -T legacy-db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

## 3. Roteiro para testar clicando (15 a 30 minutos)

Os nomes abaixo são os do menu lateral. Em Financeiro, Operacional, Relatórios e Configurações o menu
é dividido em seções (por exemplo "Rotina"): clique na seção para abrir a lista de páginas. A lupa no
topo (Ctrl + K) acha qualquer página pelo nome.

1. Entre em `https://erp.connect.lab/login` com um usuário de papel `developer` ou `super_admin`.
   **2FA:** depois de rodar o `tools/testar-main.sh` desta versão, o login pede para configurar o
   aplicativo autenticador (Google Authenticator, Microsoft Authenticator, 1Password etc.) e mostra
   os códigos de recuperação. Guarde os códigos. Antes desta versão o lab entrava sem 2FA porque as
   chaves `CONNECT_2FA_KEY` e `CONNECT_2FA_ENFORCE_FROM` não chegavam ao PHP; o script agora cria as
   duas no seu `.env` (ver passo 6b da seção 2). Usuário sem papel não entra no ERP (é de propósito).
2. No topo, escolha um condomínio na barra superior. A maioria das telas trabalha no condomínio
   escolhido; "Todos" soma o que a sua conta pode ver.
3. **Base:** Condomínios → Condomínios → Novo condomínio. O CNPJ é opcional; ao digitar o CEP o
   endereço é preenchido sozinho; telefone, CEP, CPF/CNPJ e e-mail ganham máscara enquanto você
   digita. Ao salvar, o sistema abre o **Checklist de chegada** do condomínio (clique em "Iniciar
   checklist de chegada"). Depois: Condomínios → Unidades (ou o cadastro rápido de unidades) e
   Cadastros → Proprietários e inquilinos → Novo. No card **"Pessoa já cadastrada?"**, ao lado do
   formulário, busque pelo nome ou CPF/CNPJ, escolha a pessoa, marque várias unidades (de um ou mais
   condomínios), escolha proprietário ou inquilino e clique em "Vincular às unidades marcadas". Pessoas
   de condomínios que você não administra só aparecem pelo CPF/CNPJ completo. Num cadastro já aberto o
   mesmo card aparece como "Vincular a mais unidades".
4. **Financeiro:** Configurações → seção "Financeiro e parâmetros" → **Plano de Contas** e Tipos de
   faturas; Financeiro → seção "Cadastros financeiros" → Contas bancárias; Financeiro → seção
   "Contas e pagamentos" → Faturas (preparar, emitir); Cobrança → Boletos (registrar no sandbox).
5. **Retorno e conciliação:** Cobrança → **Arquivo de Retorno** (importar o arquivo do banco) e
   Cobrança → **Retornos** (lista dos retornos importados e das divergências de cada um).
6. **Acordo:** Cobrança → Acordos (simular encargos e criar). Para ter inadimplência para testar,
   crie os dados de exemplo do lab: ponha no `.env` as linhas `ERP_DEMO_DATA=true` e
   `ERP_DEMO_DB=connect_legacy` e rode
   `docker compose exec -T app php tools/lab-dados-exemplo.php`. Ele cria 2 condomínios, 8 unidades,
   10 pessoas e lançamentos vencidos, todos com nome começando por "EXEMPLO". Rodar de novo não duplica.
   Para apagar tudo depois: `docker compose exec -T app php tools/lab-dados-exemplo.php --remover`.
   O comando recusa rodar fora do lab e em banco que não seja o de `ERP_DEMO_DB`.
7. **Documentos:** Documentos → Certidão de débitos. Imprima: o documento tem as linhas de assinatura
   do síndico e da administradora. Confira o código em
   `https://erp.connect.lab/verificar-certidao-debitos` (sem login): digite o código e a página diz se
   o documento é autêntico. O endereço antigo `/verificar` redireciona para ele.
8. **Fechamento:** Financeiro → seção "Fechamento" → Fechamento mensal; depois Prestação de contas →
   **Balancete Verificação** e **Demonstrativo** (há também Demonstrativo Contas, Analítico e
   Situação).
9. **Operacional:** Operacional → seção **Rotina** → Ocorrências, Reservas Áreas Comuns, Manutenções,
   Vistorias e Chamados (o chamado aparece no Help Desk).
10. **Comunicação:** Comunicação → Comunicado geral. No lab **nenhum e-mail sai**: a tela mostra o modo
    de entrega ("só registro" ou "caixa de teste") e o que teria sido enviado fica em Configurações →
    Fila de avisos. Para receber de verdade numa caixa de teste, ponha no `.env`
    `ERP_MAIL_DELIVERY=sandbox` e `ERP_MAIL_SANDBOX_TO=<sua caixa de teste>`.

Se uma tela mostrar o aviso de dados de exemplo, confira na tabela da seção 5 se ela é 🟡 ou ⚪.

### O que mudou depois do teste de 09/11 (23:18)

| Item do teste | O que foi feito |
|---|---|
| 1. Entrou sem 2FA | As chaves do 2FA agora são lidas do `.env` e o `testar-main.sh` cria e liga o 2FA (passo 6b) |
| 3. Cadastro de condomínio | CNPJ opcional, CEP preenche o endereço, máscaras de telefone/CEP/CPF/CNPJ/e-mail, checklist de chegada redesenhado no padrão do ERP |
| 3. Pessoa com várias unidades | Card "Pessoa já cadastrada?" em Proprietários e inquilinos: busca por nome ou CPF/CNPJ e vínculo com várias unidades de uma vez (ADR 0162) |
| 4, 5, 8, 9. Páginas "sumidas" | Existiam, mas o menu escondia as páginas dos grupos grandes e o guia usava outros nomes. O menu agora mostra todas as páginas por seção e este roteiro usa os nomes do menu |
| 6. Sem inadimplência | Comando `tools/lab-dados-exemplo.php` cria dados de exemplo com lançamentos vencidos (e `--remover` apaga) |
| 7. Impressão e verificação | Assinaturas do síndico e da administradora, endereço fixo `/verificar-certidao-debitos` e a página não fica mais carregando |
| 9. Chamados não abriram | Em correção junto com a integração ERP / Help Desk |
| 10. Comunicado "não enviou" | A tela agora explica que no lab nada sai e mostra o modo de entrega |

## 4. Ambientes e endereços

| Ambiente | Endereço no lab | O que é | Estado | Arquivo de referência |
|---|---|---|---|---|
| ERP | `https://erp.connect.lab` (também `https://www.connect.lab/erp`) | Sistema da administradora: condomínios, financeiro, cobrança, operacional | Ver seção 5 | `container/erp/connect/v1/routes.php`, `source/Controllers/Erp/Connect/` |
| Studio (CMS) | `https://www.connect.lab/studio` e `https://studio.connect.lab` | Painel herdado do Organic Code: usuários, papéis e permissões, logs, blog, páginas, mídia, slides, FAQ, depoimentos, propostas, notificações, configurações | Funciona (é a base que já existia; não foi recriado). Usuários, permissões e logs do ERP são estes | `container/cms/studio/v1/routes.php`, `source/Controllers/Studio/` |
| Help Desk | `https://www.connect.lab/helpdesk` e `studio.connect.lab/helpdesk` | Chamados, agenda e central de ajuda (artigos) | Funciona. Todo chamado do ERP (ocorrência ou Chamados) cai aqui (ADR 0034) | `container/cms/helpdesk/v1/routes.php`, `source/Services/Support/TicketService.php` |
| Troca de ambiente | `/ambiente/{erp\|studio\|helpdesk}` | Alternador da barra lateral, com código de uso único entre hosts | Funciona (ADR 0034) | `source/Controllers/Environments/Handoff.php` |
| Site público | `https://www.connect.lab` | Página inicial, "Solicite sua proposta", política e termos, recuperar senha | Funciona quando o site está aberto (`CONF_ACCESS_SITE`); senão mostra "indisponível" | `container/web/default/v1/routes.php`, `source/Controllers/Web/Connect/Web.php` |
| Central de ajuda pública | `https://www.connect.lab/suporte` (tema `support`) | Artigos do Help Desk para o público | Funciona quando `CONF_ACCESS_SUPPORT` está ligado | `container/web/support/v1/routes.php` |
| Verificação de documentos | `/verificar-certidao-debitos` em qualquer host (o antigo `/verificar` redireciona) | Qualquer pessoa digita o código impresso numa certidão, declaração ou recibo e vê se é autêntico | Funciona; tem CSRF e limite por IP (ADR 0098, 0099, 0115, 0160) | `source/Controllers/Web/Connect/DocumentVerify.php` |
| Convite de morador | `/convite/{token}` (ERP e Studio) | O morador recebe o link, confirma o CPF e cria a senha | Página pronta e testada (ADR 0092, 0093, 0112). O envio do convite está **desligado**: nenhum e-mail sai fora de produção | `source/Controllers/Web/Connect/ResidentInvite.php` |
| Recuperar senha | `/forget` | Link de troca de senha por e-mail | Funciona (ADR 0009). O e-mail depende do SMTP configurado | `source/Controllers/Web/Connect/Web.php` |
| API REST | `http://127.0.0.1:8088/api/v1` | Tudo que o ERP faz, em JSON, com sessão + 2FA e CSRF | Funciona; contrato em `docs/api/openapi.yaml` e `docs/api/registries.openapi.yaml` | `index.php` (grupo `/api/v1`), `source/Controllers/Api/V1/` |
| Scheduler | container `scheduler` | Avisos do checklist às 07:00 e a fila de avisos a cada minuto, em modo sandbox | Funciona; nunca envia para morador fora de produção | `service/jobs/scheduler.sh` |

## 5. ERP, página por página

Legenda:

- ✅ **Funciona:** lê e grava no banco, com permissão, escopo por condomínio e auditoria. Sem banco
  ou sem a permissão do módulo, a tela volta para o exemplo.
- 🟡 **Tela de exemplo, API pronta:** o backend existe e está testado; a tela ainda mostra dados
  inventados. Dá para conferir pelo endereço da API indicado (com login no navegador).
- ⚪ **Tela de exemplo, sem backend:** só a interface.

Os endereços são relativos a `https://erp.connect.lab`. "Arquivo de referência" é o arquivo que
monta a tela (ou a ficha `docs/specs/pages/` quando a tela ainda é de exemplo). Os cadastros (✅
cadastro) usam todos o mesmo motor: lista, novo, editar e excluir, com a definição de campos em
`source/Services/Erp/Registry/Definitions.php` e a tela em `source/Controllers/Erp/Connect/Registry.php`.

### Como usar cada módulo

- **Dashboard:** os cartões de manutenção, seguros, contratos, reservas, ocorrências e entregas são
  reais (ADR 0053/0070). Os gráficos de receitas × despesas e a tabela de atividades ainda são de
  exemplo.
- **Condomínios:** cadastro e edição (ADR 0011), checklist de chegada do condomínio (ADR 0013, em
  `/condominios/{id}/checklist`), unidades com cadastro rápido em lote e grade de fração ideal e área
  (ADR 0140), áreas comuns, vinculáveis e patrimônio. Os painéis do condomínio e da unidade juntam
  tudo de um lugar só (ADR 0054/0071).
- **Cadastros:** pessoas (proprietários, inquilinos, moradores), síndico e conselho, funcionários,
  fornecedores, advogados, veículos, visitantes, animais e contatos úteis. Todos por condomínio.
- **Financeiro e cobrança:** o passo a passo completo (lançamentos fixos → fatura → boleto → retorno
  → acordo → documentos → fechamento → relatórios) está em `docs/guides/FINANCEIRO_E_OPERACIONAL.md`.
  Regras padrão decididas por nós e ajustáveis em Cobrança → Regras de cobrança: multa 2%, juros 1%
  ao mês pro rata, IPCA (índice carregado à mão), quebra de acordo com parcela vencida há mais de 30
  dias ou 2 em aberto (a quebra é sempre manual, com motivo).
- **Prestação de contas:** balancete (AP-079) e demonstrativo por caixa (AP-051) são reais. Os demais
  têm API pronta.
- **Operacional:** portaria (previsão de visita, acessos, achados e perdidos, entregas), reservas com
  aprovação e lista de espera, manutenções com a próxima gerada pela periodicidade, ocorrências que
  viram chamado no Help Desk, seguros, contratos, mudanças, relógios, tarifas e leituras, e a vistoria
  presencial com fotos, GPS e relatório em PDF (ADR 0015).
- **Comunicação:** comunicado geral, aviso de cobrança, agendados e endereços; tudo pela fila de
  avisos, com limite de 20 por condomínio em 24 h. Assembleia e votação (ADR 0088) existem só pela
  API.
- **Documentos:** certidão de débitos, declaração de inadimplência, quitação, carta de anuência e
  recibo, com código de verificação; Disco Virtual para atas, contratos e notas (até 20 MB, tipo
  conferido pelo conteúdo); avisos de recebimento, cópias de cheques, empenhos e malotes.
- **Importações:** planilha (CSV com `;` ou XLSX) ou OFX em duas etapas: prévia linha a linha e
  aplicar tudo ou nada. Também importa NF-e em XML como conta a pagar.
- **Configurações:** plano de contas, tipos de fatura, banners, impostos, frações em lote, regras de
  cobrança, fila de avisos e integrações (estado do banco, e-mail, índices e fila, sem mostrar
  credencial) são reais. Parâmetros, saldos iniciais e previsão orçamentária têm API pronta. Acesso de
  imobiliária e de morador (ADR 0090) tem API pronta, com a regra que você aprovou em 09/11 (imobiliária
  só leitura com contato mascarado, morador por convite, portal desligado no início).
- **Segurança da conta:** `/seguranca` ativa, renova os códigos e desativa o 2FA.

### Tabela das 171 páginas

#### Dashboard

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| AP-001 | Dashboard | `/dashboard` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/dashboard/index.php` |

#### Condomínios

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| AP-004 | Condomínios | `/condominios` | ✅ Funciona (tela própria) | `source/Controllers/Erp/Connect/Condominiums.php` |
| AP-002 | Painel do condomínio | `/condominios/painel-do-condominio` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/condominiums/panel.php` |
| AP-003 | Painel da unidade | `/condominios/painel-da-unidade` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/condominiums/unit-panel.php` |
| AP-006 | Unidades | `/condominios/unidades` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`units`) |
| AP-008 | Áreas Comuns | `/condominios/areas-comuns` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`common_areas`) |
| AP-007 | Áreas Vinculáveis | `/condominios/areas-vinculaveis` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`linkable_areas`) |
| AP-019 | Patrimônio | `/condominios/patrimonio` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`assets`) |

#### Cadastros

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| AP-005 | Proprietários e inquilinos | `/cadastros/proprietarios-e-inquilinos` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`residents`) |
| AP-014 | Síndico e conselho | `/cadastros/sindico-e-conselho` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`board`) |
| AP-009 | Funcionários | `/cadastros/funcionarios` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`employees`) |
| AP-010 | Fornecedores | `/cadastros/fornecedores` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`suppliers`) |
| AP-016 | Advogados | `/cadastros/advogados` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`lawyers`) |
| AP-011 | Veículos | `/cadastros/veiculos` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`vehicles`) |
| AP-012 | Visitantes | `/cadastros/visitantes` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`visitors`) |
| AP-013 | Animais de Estimação | `/cadastros/animais-de-estimacao` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`pets`) |
| AP-015 | Contatos Úteis | `/cadastros/contatos-uteis` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`contacts`) |

#### Financeiro

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| AP-020 | Lançamentos | `/financeiro/lancamentos` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`entries`) |
| AP-021 | Lançamentos fixos | `/financeiro/lancamentos-fixos` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`recurring_entries`) |
| AP-022 | Contas a Pagar | `/financeiro/contas-a-pagar` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`payables`) |
| AP-023 | Contas a Receber | `/financeiro/contas-a-receber` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`receivables`) |
| AP-024 | Lançar Despesa | `/financeiro/lancar-despesa` | ✅ Funciona (abre o "novo" de /financeiro/contas-a-pagar/novo) | `source/Controllers/Erp/Connect/EntryShortcutScreens.php` |
| AP-025 | Lançar Receita | `/financeiro/lancar-receita` | ✅ Funciona (abre o "novo" de /financeiro/contas-a-receber/novo) | `source/Controllers/Erp/Connect/EntryShortcutScreens.php` |
| AP-026 | Lançar Desconto | `/financeiro/lancar-desconto` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/finance/discount.php` |
| AP-027 | Lançar Multa (Avulsa) | `/financeiro/lancar-multa-avulsa` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/finance/extra-charges.php` |
| AP-028 | Cobranças Extras | `/financeiro/cobrancas-extras` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/finance/extra-charges.php` |
| AP-029 | Outros Lançamentos | `/financeiro/outros-lancamentos` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/finance/bank-transfers.php` |
| AP-030 | Fechamento Mensal | `/financeiro/fechamento-mensal` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/modules/closing.php` |
| AP-031 | Pagamentos (Bancos) | `/financeiro/pagamentos-bancos` | ⚪ Tela de exemplo, sem backend (depende do banco real (homologação)) | `docs/specs/pages/ap-031-pagamentos-bancos.md` |
| AP-032 | Faturas | `/financeiro/faturas` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/finance/invoices.php` |
| AP-040 | Recibos | `/financeiro/recibos` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/documents/unit-document.php` |
| CN-002 | Centros de custo | `/financeiro/centros-de-custo` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`cost_centers`) |
| CN-003 | Contas bancárias | `/financeiro/contas-bancarias` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`bank_accounts`) |

#### Cobrança

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| AP-117 | Atendimento Cobrança | `/cobranca/atendimento-cobranca` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/collections/desk.php` |
| AP-033 | Acordos | `/cobranca/acordos` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/finance/agreements.php` |
| AP-034 | Parcelas de Acordo | `/cobranca/parcelas-de-acordo` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/finance/agreement-parcels.php` |
| AP-035 | Boletos | `/cobranca/boletos` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/finance/bank-charges.php` |
| AP-037 | Remessas | `/cobranca/remessas` | ⚪ Tela de exemplo, sem backend (remessa CNAB depende do banco real) | `docs/specs/pages/ap-037-remessas.md` |
| AP-036 | Arquivo de Retorno | `/cobranca/arquivo-de-retorno` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/finance/bank-return-import.php` |
| AP-038 | Retornos | `/cobranca/retornos` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/finance/bank-divergences.php` |
| AP-039 | Cobrança Judicial | `/cobranca/cobranca-judicial` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/collections/legal-cases.php` |
| AP-116 | Multas Pendentes | `/cobranca/multas-pendentes` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/collections/pending-fines.php` |

#### Prestação de contas

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| AP-101 | Prestação de contas | `/prestacao-de-contas/prestacao-de-contas` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/statement` (é o mesmo do AP-051) | `docs/specs/pages/ap-101-prestacao-de-contas.md` |
| AP-094 | Listagem de prestações de contas | `/prestacao-de-contas/listagem-de-prestacoes-de-contas` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/closings` | `docs/specs/pages/ap-094-listagem-de-prestacao-de.md` |
| AP-051 | Demonstrativo | `/prestacao-de-contas/demonstrativo` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/statement.php` |
| AP-052 | Demonstrativo Contas | `/prestacao-de-contas/demonstrativo-contas` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/financial-statement?view=accounts` | `docs/specs/pages/ap-052-demonstrativo-contas.md` |
| AP-053 | Demonstrativo Analítico | `/prestacao-de-contas/demonstrativo-analitico` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/financial-statement?view=analytic` | `docs/specs/pages/ap-053-demonstrativo-analitico.md` |
| AP-054 | Demonstrativo Situação | `/prestacao-de-contas/demonstrativo-situacao` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/financial-statement?view=situation` | `docs/specs/pages/ap-054-demonstrativo-situacao.md` |
| AP-056 | Extrato Conta | `/prestacao-de-contas/extrato-conta` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/account-statement` | `docs/specs/pages/ap-056-extrato-conta.md` |
| AP-079 | Balancete Verificação | `/prestacao-de-contas/balancete-verificacao` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/trial-balance.php` |
| AP-080 | Balancete Anual | `/prestacao-de-contas/balancete-anual` | ⚪ Tela de exemplo, sem backend | `docs/specs/pages/ap-080-balancete-anual.md` |
| AP-081 | Balanço Patrimonial | `/prestacao-de-contas/balanco-patrimonial` | ⚪ Tela de exemplo, sem backend | `docs/specs/pages/ap-081-balanco-patrimonial.md` |
| AP-082 | Demonstração do resultado | `/prestacao-de-contas/demonstracao-do-resultado` | ⚪ Tela de exemplo, sem backend | `docs/specs/pages/ap-082-resultado-exercicio.md` |
| AP-083 | Livro Razão | `/prestacao-de-contas/livro-razao` | ⚪ Tela de exemplo, sem backend | `docs/specs/pages/ap-083-livro-razao.md` |

#### Operacional

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| CN-001 | Vistorias | `/operacional/vistorias` | ✅ Funciona (tela própria) | `source/Controllers/Erp/Connect/Inspections.php` |
| CN-004 | Chamados | `/operacional/chamados` | ✅ Funciona (tela própria) | `source/Controllers/Erp/Connect/Tickets.php` |
| AP-110 | Manutenções | `/operacional/manutencoes` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`maintenances`) |
| AP-112 | Ocorrências | `/operacional/ocorrencias` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`occurrences`) |
| AP-113 | Reservas Áreas Comuns | `/operacional/reservas-areas-comuns` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`reservations`) |
| AP-114 | Agendamentos Pendentes | `/operacional/agendamentos-pendentes` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/operational/pending-reservations.php` |
| AP-115 | Lista de espera | `/operacional/lista-de-espera` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`waitlist`) |
| AP-118 | Seguros | `/operacional/seguros` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`insurances`) |
| AP-119 | Contratos | `/operacional/contratos` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`contracts`) |
| AP-120 | Agendamentos | `/operacional/agendamentos` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`moves`) |
| AP-121 | Configurações | `/operacional/configuracoes` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`move_settings`) |
| AP-041 | Relógios | `/operacional/relogios` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`meters`) |
| AP-042 | Leituras | `/operacional/leituras` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/operational/meter-readings.php` |
| AP-043 | Tarifas | `/operacional/tarifas` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`tariffs`) |
| AP-131 | Gerenciamento | `/operacional/gerenciamento` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`gatehouses`) |
| AP-132 | Previsão de Visita | `/operacional/previsao-de-visita` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`visit_forecasts`) |
| AP-133 | Registro de Acessos | `/operacional/registro-de-acessos` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`access_logs`) |
| AP-134 | Achados e Perdidos | `/operacional/achados-e-perdidos` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`lost_found`) |
| AP-135 | Entregas | `/operacional/entregas` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`deliveries`) |
| AP-136 | Registros de Entregas | `/operacional/registros-de-entregas` | 🟡 Tela de exemplo · API pronta: `/api/v1/deliveries` (filtro "Retirada") | `docs/specs/pages/ap-136-registros-de-entregas.md` |

#### Comunicação

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| AP-111 | Comunicados Gerais | `/comunicacao/comunicados-gerais` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/communication/announcements.php` |
| AP-018 | Assembleia Virtual | `/comunicacao/assembleia-virtual` | 🟡 Tela de exemplo · API pronta: `/api/v1/assemblies` (edital, presença, apuração) | `docs/specs/pages/ap-018-assembleia-virtual.md` |
| AP-017 | Votação | `/comunicacao/votacao` | 🟡 Tela de exemplo · API pronta: `/api/v1/assemblies` (votos) | `docs/specs/pages/ap-017-votacao.md` |
| AP-137 | Cobrança | `/comunicacao/cobranca` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/communication/announcements.php` |
| AP-138 | Agendada | `/comunicacao/agendada` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/communication/announcements.php` |
| AP-139 | Endereços | `/comunicacao/enderecos` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/communication/addresses.php` |

#### Documentos

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| AP-122 | Disco Virtual | `/documentos/disco-virtual` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/documents/virtual-disk.php` |
| AP-123 | Certidão de Débitos | `/documentos/certidao-de-debitos` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/documents/unit-document.php` |
| AP-124 | Declaração de Inadimplência | `/documentos/declaracao-de-inadimplencia` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/documents/unit-document.php` |
| AP-125 | Comprovante de Quitação | `/documentos/comprovante-de-quitacao` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/documents/unit-document.php` |
| AP-126 | Avisos de Recebimento | `/documentos/avisos-de-recebimento` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`delivery_notices`) |
| AP-127 | Cópias de Cheques | `/documentos/copias-de-cheques` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`check_copies`) |
| AP-128 | Empenhos | `/documentos/empenhos` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`commitments`) |
| AP-129 | Malotes | `/documentos/malotes` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`pouches`) |
| AP-130 | Carta de anuência | `/documentos/carta-de-anuencia` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/documents/unit-document.php` |

#### Relatórios

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| AP-102 | Gráficos gerais | `/relatorios/graficos-gerais` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/chart.php` |
| AP-103 | Gráfico de contas | `/relatorios/grafico-de-contas` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/chart.php` |
| AP-104 | Gráfico de consumo (unidade) | `/relatorios/grafico-de-consumo-unidade` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/chart.php` |
| AP-105 | Gráfico de consumo (geral) | `/relatorios/grafico-de-consumo-geral` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/chart.php` |
| AP-106 | Gráfico financeiro | `/relatorios/grafico-financeiro` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/chart.php` |
| AP-107 | Gráfico de inadimplência | `/relatorios/grafico-de-inadimplencia` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/chart.php` |
| AP-108 | Gráfico financeiro por conta | `/relatorios/grafico-financeiro-por-conta` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/chart.php` |
| AP-109 | Gráfico de áreas comuns | `/relatorios/grafico-de-areas-comuns` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/chart.php` |
| AP-045 | Consumo (Geral) | `/relatorios/consumo-geral` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/consumption` | `docs/specs/pages/ap-045-consumo-geral.md` |
| AP-046 | Consumo (Unidade) | `/relatorios/consumo-unidade` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/consumption?by=unit` | `docs/specs/pages/ap-046-consumo-unidade.md` |
| AP-047 | Relatório de acordos | `/relatorios/relatorio-de-acordos` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/collection-report.php` |
| AP-048 | Taxas de Acordos | `/relatorios/taxas-de-acordos` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/collection-report.php` |
| AP-049 | Relatório de centros de custo | `/relatorios/relatorio-de-centros-de-custo` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/cost-centers` | `docs/specs/pages/ap-049-centros-de-custo.md` |
| AP-050 | Comparativo | `/relatorios/comparativo` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/comparison` | `docs/specs/pages/ap-050-comparativo.md` |
| AP-057 | Inadimplentes (Geral) | `/relatorios/inadimplentes-geral` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/collection-report.php` |
| AP-058 | Inadimplentes (Unidade) | `/relatorios/inadimplentes-unidade` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/collection-report.php` |
| AP-059 | Inadimplentes (Detalhado) | `/relatorios/inadimplentes-detalhado` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/collection-report.php` |
| AP-060 | Inadimplentes (Taxa) | `/relatorios/inadimplentes-taxa` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/collection-report.php` |
| AP-061 | Inadimplentes (Morador) | `/relatorios/inadimplentes-morador` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/collection-report.php` |
| AP-062 | Rateio | `/relatorios/rateio` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/apportionment?view=unit` | `docs/specs/pages/ap-062-rateio.md` |
| AP-063 | Rateio Comparativo | `/relatorios/rateio-comparativo` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/apportionment?view=compare` | `docs/specs/pages/ap-063-rateio-comparativo.md` |
| AP-064 | Rateio Detalhado | `/relatorios/rateio-detalhado` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/apportionment?view=unit_account` | `docs/specs/pages/ap-064-rateio-detalhado.md` |
| AP-065 | Rateio Analítico | `/relatorios/rateio-analitico` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/apportionment?view=unit_entries` | `docs/specs/pages/ap-065-rateio-analitico.md` |
| AP-066 | Rateio Analítico (Detalhado) | `/relatorios/rateio-analitico-detalhado` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/apportionment?view=entries` | `docs/specs/pages/ap-066-rateio-analitico-detalhado.md` |
| AP-067 | Rateio por Conta | `/relatorios/rateio-por-conta` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/apportionment?view=account` | `docs/specs/pages/ap-067-rateio-por-conta.md` |
| AP-068 | Relatório de lançamentos | `/relatorios/relatorio-de-lancamentos` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/entries` | `docs/specs/pages/ap-068-lancamentos.md` |
| AP-069 | Relatório de lançamentos (detalhado) | `/relatorios/relatorio-de-lancamentos-detalhado` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/entries?detailed=1` | `docs/specs/pages/ap-069-lancamentos-detalhado.md` |
| AP-070 | Relatório de boletos | `/relatorios/relatorio-de-boletos` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/bank-charges` | `docs/specs/pages/ap-070-boletos.md` |
| AP-071 | Recebimentos | `/relatorios/recebimentos` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/collection-report.php` |
| AP-072 | Recebimentos por Conta | `/relatorios/recebimentos-por-conta` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/reports/collection-report.php` |
| AP-073 | Saldos de Contas | `/relatorios/saldos-de-contas` | 🟡 Tela de exemplo · API pronta: `/api/v1/bank-accounts/balances` | `docs/specs/pages/ap-073-saldos-de-contas.md` |
| AP-074 | Registro de Pagamentos | `/relatorios/registro-de-pagamentos` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/payments` | `docs/specs/pages/ap-074-registro-de-pagamentos.md` |
| AP-075 | Relatório Memória de Cálculo | `/relatorios/relatorio-memoria-de-calculo` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/calculation` | `docs/specs/pages/ap-075-relatorio-memoria-de-calculo.md` |
| AP-076 | Relatório de transferências | `/relatorios/relatorio-de-transferencias` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/transfers` | `docs/specs/pages/ap-076-relatorio-de-transferencias.md` |
| AP-084 | Relatório de previsão orçamentária | `/relatorios/relatorio-de-previsao-orcamentaria` | 🟡 Tela de exemplo · API pronta: `/api/v1/budgets` | `docs/specs/pages/ap-084-previsao-orcamentaria.md` |
| AP-085 | Relatório de reservas | `/relatorios/relatorio-de-reservas` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/reservations` | `docs/specs/pages/ap-085-reservas-areas-comuns.md` |
| AP-086 | Relatório de unidades | `/relatorios/relatorio-de-unidades` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/units` | `docs/specs/pages/ap-086-unidades.md` |
| AP-087 | Listagem de Pessoas | `/relatorios/listagem-de-pessoas` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/people` | `docs/specs/pages/ap-087-listagem-de-pessoas.md` |
| AP-088 | Listagem de presenças | `/relatorios/listagem-de-presencas` | 🟡 Tela de exemplo · API pronta: `/api/v1/assemblies/{id}/attendance-report` | `docs/specs/pages/ap-088-listagem-de-presencas-em.md` |
| AP-089 | Relatório de condomínios | `/relatorios/relatorio-de-condominios` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/condominiums` | `docs/specs/pages/ap-089-condominios.md` |
| AP-090 | Relatório de ocorrências | `/relatorios/relatorio-de-ocorrencias` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/occurrences` | `docs/specs/pages/ap-090-ocorrencias.md` |
| AP-091 | Administração | `/relatorios/administracao` | ⚪ Tela de exemplo, sem backend (regra não definida na ficha) | `docs/specs/pages/ap-091-administracao.md` |
| AP-092 | Parecer | `/relatorios/parecer` | ⚪ Tela de exemplo, sem backend (parecer do conselho não definido) | `docs/specs/pages/ap-092-parecer.md` |
| AP-093 | Pagamentos Pendentes | `/relatorios/pagamentos-pendentes` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/pending-payments` | `docs/specs/pages/ap-093-pagamentos-pendentes.md` |
| AP-095 | Mudanças | `/relatorios/mudancas` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/moves` | `docs/specs/pages/ap-095-mudancas.md` |
| AP-096 | Relatório de Endereços | `/relatorios/relatorio-de-enderecos` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/addresses` | `docs/specs/pages/ap-096-relatorio-de-enderecos.md` |
| AP-097 | Logs | `/relatorios/logs` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/logs` | `docs/specs/pages/ap-097-logs.md` |
| AP-098 | Pessoas com Acesso | `/relatorios/pessoas-com-acesso` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/access-people` | `docs/specs/pages/ap-098-pessoas-com-acesso.md` |
| AP-099 | Boletos Enviados | `/relatorios/boletos-enviados` | 🟡 Tela de exemplo · API pronta: `/api/v1/reports/sent-boletos` | `docs/specs/pages/ap-099-boletos-enviados.md` |
| AP-100 | Relatórios Enviados | `/relatorios/relatorios-enviados` | ⚪ Tela de exemplo, sem backend (regra não definida na ficha) | `docs/specs/pages/ap-100-relatorios-enviados.md` |

#### Importações

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| AP-044 | Importação de Leituras | `/importacoes/importacao-de-leituras` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/imports/import.php` |
| AP-140 | Extrato Bancário (OFX) | `/importacoes/extrato-bancario-ofx` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/imports/import.php` |
| AP-141 | Importação NF-e (xml) | `/importacoes/importacao-nf-e-xml` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/imports/import.php` |
| AP-142 | Inadimplentes (Planilha) | `/importacoes/inadimplentes-planilha` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/imports/import.php` |
| AP-143 | Frações Ideais (Planilha) | `/importacoes/fracoes-ideais-planilha` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/imports/import.php` |
| AP-144 | Lançamentos (Planilha) | `/importacoes/lancamentos-planilha` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/imports/import.php` |
| AP-145 | Lançamentos por Unidade | `/importacoes/lancamentos-por-unidade` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/imports/import.php` |
| AP-146 | Contas a pagar (Planilha) | `/importacoes/contas-a-pagar-planilha` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/imports/import.php` |

#### Configurações

| Código | Página | Endereço | Estado | Arquivo de referência |
|---|---|---|---|---|
| AP-147 | Administrativo | `/configuracoes/administrativo` | 🟡 Tela de exemplo · API pronta: `/api/v1/settings` | `docs/specs/pages/ap-147-administrativo.md` |
| AP-149 | Usuários do Sistema | `/configuracoes/usuarios-do-sistema` | ⚪ Tela de exemplo, sem backend (usuários ficam no Studio (/studio/users)) | `docs/specs/pages/ap-149-usuarios-do-sistema.md` |
| AP-150 | Permissões de Usuários | `/configuracoes/permissoes-de-usuarios` | ⚪ Tela de exemplo, sem backend (permissões ficam no Studio (papéis e permissões existentes)) | `docs/specs/pages/ap-150-permissoes-de-usuarios.md` |
| AP-153 | Permissões de Moradores | `/configuracoes/permissoes-de-moradores` | 🟡 Tela de exemplo · API pronta: `/api/v1/resident-portal-permissions` | `docs/specs/pages/ap-153-permissoes-de-moradores.md` |
| AP-148 | Usuários de imobiliárias | `/configuracoes/usuarios-de-imobiliarias` | 🟡 Tela de exemplo · API pronta: `/api/v1/partner-agencies` | `docs/specs/pages/ap-148-usuarios-de-imobiliarias.md` |
| AP-152 | Criação de usuários para moradores | `/configuracoes/criacao-de-usuarios-para-moradores` | 🟡 Tela de exemplo · API pronta: `/api/v1/resident-invitations` | `docs/specs/pages/ap-152-criacao-de-usuarios-p.md` |
| AP-151 | Notificações da prestação de contas | `/configuracoes/notificacoes-da-prestacao-de-contas` | 🟡 Tela de exemplo · API pronta: `/api/v1/settings` | `docs/specs/pages/ap-151-notificacoes-da-prestacao-de.md` |
| AP-154 | Layout da Aplicação | `/configuracoes/layout-da-aplicacao` | 🟡 Tela de exemplo · API pronta: `/api/v1/settings` | `docs/specs/pages/ap-154-layout-da-aplicacao.md` |
| AP-155 | Notificações aplicativo | `/configuracoes/notificacoes-aplicativo` | 🟡 Tela de exemplo · API pronta: `/api/v1/settings` | `docs/specs/pages/ap-155-notificacoes-aplicativo.md` |
| AP-156 | Banners | `/configuracoes/banners` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`banners`) |
| AP-159 | Alertas | `/configuracoes/alertas` | 🟡 Tela de exemplo · API pronta: `/api/v1/settings` | `docs/specs/pages/ap-159-alertas.md` |
| AP-157 | Financeiro | `/configuracoes/financeiro` | 🟡 Tela de exemplo · API pronta: `/api/v1/settings` | `docs/specs/pages/ap-157-financeiro.md` |
| AP-158 | Demonstrativo | `/configuracoes/demonstrativo` | 🟡 Tela de exemplo · API pronta: `/api/v1/settings` | `docs/specs/pages/ap-158-demonstrativo.md` |
| AP-160 | Plano de Contas | `/configuracoes/plano-de-contas` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`chart`) |
| AP-161 | Saldo inicial (plano de contas) | `/configuracoes/saldo-inicial-plano-de-contas` | 🟡 Tela de exemplo · API pronta: `/api/v1/settings` (saldo inicial) | `docs/specs/pages/ap-161-saldo-inicial-plano-de.md` |
| AP-162 | Saldo inicial (centro de custo) | `/configuracoes/saldo-inicial-centro-de-custo` | 🟡 Tela de exemplo · API pronta: `/api/v1/cost-center-openings` | `docs/specs/pages/ap-162-saldo-inicial-centro-de.md` |
| AP-163 | Previsão Orçamentária | `/configuracoes/previsao-orcamentaria` | 🟡 Tela de exemplo · API pronta: `/api/v1/budgets` | `docs/specs/pages/ap-163-previsao-orcamentaria.md` |
| AP-164 | Imposto | `/configuracoes/imposto` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`taxes`) |
| AP-165 | Índices | `/configuracoes/indices` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/finance/billing-rules.php` |
| AP-166 | Parâmetros | `/configuracoes/parametros` | 🟡 Tela de exemplo · API pronta: `/api/v1/settings` | `docs/specs/pages/ap-166-parametros.md` |
| AP-167 | Fração Ideal | `/configuracoes/fracao-ideal` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/condominiums/unit-measures.php` |
| AP-168 | Tipos de Faturas | `/configuracoes/tipos-de-faturas` | ✅ Funciona (cadastro) | `source/Services/Erp/Registry/Definitions.php` (`invoice_types`) |
| AP-171 | Integrações | `/configuracoes/integracoes` | ✅ Funciona (tela própria) | `container/erp/connect/v1/pages/settings/integrations.php` |
| CN-005 | Fila de avisos | `/configuracoes/fila-de-avisos` | ✅ Funciona (tela própria) | `source/Controllers/Erp/Connect/NotificationQueue.php` |

## 6. O que depende de configuração externa

| Item | Como está | O que falta para ligar | Onde |
|---|---|---|---|
| Banco real (boleto, Pix, CNAB remessa/retorno) | Só o adaptador sandbox. As telas Pagamentos (Bancos) e Remessas são de exemplo | Você escolher o banco piloto, credenciais no cofre (fora do Git), homologação e aprovação para produção (issue #46) | `docs/banking/BANKING_INTEGRATIONS.md`, ADR 0035 |
| E-mail para moradores | Modo `log` no app e `sandbox` no scheduler | `ERP_MAIL_SANDBOX_TO` para testar numa caixa sua; `APP_ENV=production` + `ERP_MAIL_DELIVERY=production` + `MOVESOS_SMTP_*` só em produção, com aprovação | ADR 0055, 0060; `compose.yaml` |
| Convite de morador | Página `/convite` pronta; envio desligado | O mesmo do e-mail acima | ADR 0090, 0092 |
| Antivírus (clamd) | `ERP_AV_MODE=off`: arquivos entram "não verificados" | Subir um clamd, `ERP_AV_ENDPOINT` e `ERP_AV_MODE=enforce`. Depois, `php tools/erp-rescan.php` reverifica os arquivos antigos (PR #139) | ADR 0098, 0160, 0161 |
| Índice de correção (IPCA) | Tabela manual | Carregar os índices do mês (a tela de Integrações avisa quando estão atrasados) | ADR 0040, 0110 |
| Dados de exemplo | `ERP_DEMO_DATA` ligado fora de produção | `ERP_DEMO_DATA=false` esconde as telas de exemplo com o estado vazio | `source/Services/Erp/DemoData.php` |
| Hosts do lab | `erp.connect.lab`, `www.connect.lab`, `studio.connect.lab` no Apache do xampp | `bash tools/lab-studio-host.sh` liga o host do Studio | `docs/SETUP_CONNECT_ERP.md` |

## 7. Estado do Git em 09/11

- O `claude-dev` está inteiro dentro da `main` (nada ficou de fora).
- O PR #139 (reverificação dos arquivos com o antivírus, ADR 0161) ficou parado porque o GitHub
  Actions falha sem iniciar os jobs (provável limite de minutos ou cobrança da conta). Ele foi testado
  na nuvem (501 unitários e smoke) e entrou na main junto com este guia.
- Os outros branches antigos (`claude-layout`, `claude-condominios`, threads antigas) já estão na
  main. Ficaram de fora só dois que foram substituídos: `claude/erp-novo-condominio` (primeira versão
  do cadastro de condomínios, refeita no PR #18) e `setup/organiccode-foundation` (arquivos iniciais
  que já existem na main com outro conteúdo). Podem ser apagados quando você quiser.
- O CI do GitHub só roda os testes unitários offline. Os de integração precisam do Mac
  (`tools/testar-main.sh`).

## 8. Onde procurar mais

- Mapa do código (rotas, tabelas, classes, testes e decisões): `docs/map/README.md`
  (`python3 tools/code-map.py --find <palavra>`).
- Decisões (ADRs): `docs/decisions/README.md` e `docs/Organic Code/decisions/`.
- Fichas das páginas: `docs/specs/pages/` e a matriz `docs/ERP_V1_ROUTE_MATRIX.md`.
- Revisões de segurança: `docs/security/`.

## 9. Próximo passo sugerido

1. Rodar `tools/testar-main.sh` e mandar o resumo final (ou o log da etapa que falhou).
2. Fazer o roteiro da seção 3 e anotar o que estiver errado ou estranho.
3. Com isso, escolher a próxima frente: ligar as telas 🟡 (o backend já existe, é o trabalho mais
   rápido e mais visível) ou começar o banco real com um banco piloto.
