# ADR 0150 — Telas de administração, relatórios enviados, usuários e permissões ligadas à API

- Situação: aceita (10/11/2026)
- Contexto: serviços e API do ADR 0168 (`AdminReports`, `ErpAccess`, `Controllers/Api/V1/ErpAdmin`)

## Contexto

Conferido na main em 10/11: das páginas do catálogo, só seis ainda caíam na demonstração. AP-031 e AP-037
dependem do banco real. As outras quatro já tinham serviço e API desde o ADR 0168, mas a tela seguia com dados
fixos: AP-091 Administração, AP-100 Relatórios Enviados, AP-149 Usuários do Sistema e AP-150 Permissões de Usuários.
O Balanço Patrimonial (AP-081) já estava ligado pelo ADR 0148.

## Decisão

- `AdminScreens` lê pelos mesmos serviços e entrega o template único `pages/settings/admin`, com quatro modos:
  - **AP-091** (`erp.settings.view`): administradora vinda dos parâmetros AP-147 e o resumo do escopo, com o
    fechamento do mês anterior (o "em aberto" fica em destaque quando é maior que zero). Só leitura.
  - **AP-100** (`erp.audit.view`): filtro De/Até/Relatório por GET, as exportações de `AdminReports::sent` com
    nome e IP mascarados e o aviso de lista cortada. Só leitura.
  - **AP-149** (`erp.users.view`; mudar com `erp.users.manage`): lista de `ErpAccess::users` no condomínio da barra
    superior ou em todo o escopo; "Dar acesso" por e-mail e condomínios; tirar um condomínio ou todos. O botão só
    aparece quando o serviço marca a conta como `manageable`.
  - **AP-150** (`erp.permissions.view`; mudar com `erp.permissions.manage`): matriz papel × permissão `erp.*` de
    `ErpAccess::matrix`, com a caixa travada quando quem consulta não acessa todos os condomínios ou o papel não é
    editável; e a exceção por usuário (Segue o papel, Liberar, Bloquear), escolhendo o usuário por `?usuario=`.
    Um id fora da lista do escopo é ignorado antes de consultar.
- Gravações pelo erp.js na própria API, com o CSRF no cabeçalho: os formulários e botões reaproveitam
  `data-access-form`/`data-access-post` (ADR 0147); a caixa do papel (`data-api-toggle`) e a exceção
  (`data-api-select`) gravam na hora e voltam ao valor anterior se a API recusar.
- A tela não cria conta, não troca papel e não mexe em senha, login ou 2FA: isso continua em Studio › Usuários,
  como decidido no ADR 0168.

## Consequências

- Os artigos da Central de Ajuda das quatro páginas passam a `situacao: funciona`; os prints ainda são da
  demonstração até rodar `bash tools/help/prints.sh` no Mac.
- Testes: `ErpAdminScreensTest` (páginas, permissões, mudanças só pela API, regras puras) e o smoke das quatro
  telas, com escape de nome, retirada só de conta gerenciável e modo só leitura sem a permissão de mudar.
- Com isto, do que tem API, nenhuma página do catálogo fica na demonstração; sobram AP-031 e AP-037 (banco real).

## Revisão de cobertura de testes e do OpenAPI (10/11/2026)

- OpenAPI × rotas: o `OpenApiRoutesTest` já cobre os dois sentidos (rota sem documentação e documentação sem
  rota). Na leitura do contrato, nenhum `operationId` repetido e nenhum parâmetro de caminho sem declaração; três
  operações da vistoria (`PUT /inspections/{id}/items/{item}`, `POST …/photos` e `POST …/complete`) não
  documentavam o 401 e passaram a documentar. Os POST sem corpo documentado (emitir fatura, convocar e abrir
  assembleia, etc.) conferem com os controllers, que não leem corpo.
- Testes: todo serviço do ERP já é citado por algum teste. Os controllers da API sem teste próprio (`Me`,
  `Notifications`, `Panels`, `RecurringEntriesGenerate`, `Tickets`) ficavam fora da matriz do ADR 0094, que só
  vale para os controllers listados nela. O `ApiGuardCoverageTest` estende a varredura a todos os controllers de
  `/api/v1`, inclusive os futuros: toda rota passa pelo `ApiAuthenticated` (menos `/health` e a confirmação do
  2FA, que segue com CSRF, desafio pendente e limite de tentativas), e toda ação pública chega a `Auth::user()` ou
  `Access::can`.
