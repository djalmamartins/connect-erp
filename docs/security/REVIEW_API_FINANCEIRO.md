# Revisão de segurança: API financeira, chamados e Disco Virtual (09/11/2026)

Escopo: os controllers de `source/Controllers/Api/V1/` para faturas (`Invoices`), boletos e retornos
(`Banking`), inadimplência e acordos (`Agreements`), regras de cobrança e índices (`BillingRules`),
documentos da unidade (`UnitDocuments`), leituras (`MeterReadings`), chamados (`Tickets`) e Disco
Virtual (`VirtualDisk`), com os serviços de `source/Services/Erp/` e `Support/TicketService` que
eles chamam. Revisão por leitura de código, no `claude-dev` de 09/11. Nada foi testado contra um
servidor rodando.

## O que foi conferido

| Ponto | Como está | Situação |
|---|---|---|
| Sessão, 2FA e CSRF | Toda rota de `/api/v1` (exceto `/health` e `/auth/2fa/verify`) passa pelo `ApiAuthenticated`, que exige sessão elevada e `X-CSRF-Token` em método que não é GET, HEAD ou OPTIONS | CONFIRMADO |
| Permissão | Cada controller exige `erp.access` + `<módulo>.view`, e `<módulo>.manage` em toda escrita (a escrita passa por `write()`/`authorize('manage')`) | CONFIRMADO |
| Escopo por condomínio (IDOR) | Busca por id carrega o registro e confere `CondominiumScope::canAccess`. Fora do escopo responde 404, sem dizer que o registro existe. As listagens filtram por `allowedIds` | CONFIRMADO |
| Referências cruzadas | Conta bancária do boleto do mesmo condomínio da conta a receber. Retorno só casa títulos da conta bancária enviada. Item da fatura da mesma fatura. Tipo de fatura do condomínio. Unidade do chamado do condomínio escolhido. Campos `ref` dos cadastros (plano de contas etc.) validados por `RegistryService::refRules` | CONFIRMADO |
| Entrada | Ids por `FILTER_VALIDATE_INT`, datas com `createFromFormat` estrito, competência AAAA-MM, texto com limite de tamanho, SQL parametrizado (os `IN (...)` usam só inteiros já convertidos) | CONFIRMADO |
| Disco Virtual | Tipo pelo conteúdo (finfo) numa lista fechada e batendo com a extensão, limite de 20 MB, `is_uploaded_file`, nome gerado pelo sistema fora da pasta pública, caminho do download validado por regex (sem `..`), download como anexo com `nosniff` e CSP `sandbox`, exclusão lógica auditada | CONFIRMADO no serviço. As rotas `/documents` ainda não estão no `index.php` (o Avançar está ligando) |

## Achado corrigido

**Acordos: id de conta de outro condomínio era distinguível de id inexistente, e a criação travava
linhas de outro condomínio.** `Agreements::suggestion` e `Agreements::create` buscavam as contas
por `WHERE id IN (...)` sem filtrar o condomínio.

O resultado para um usuário com `erp.agreements.view` era este:
- um id de outro condomínio respondia "Conta N não é desta unidade";
- um id inexistente respondia "Alguma conta escolhida não existe".

Isso permite enumerar quais ids de lançamento existem fora do escopo. Os dados em si não vazavam.
Além disso, o `FOR UPDATE` da criação travava essas linhas alheias durante a transação, e a lista
de ids não tinha limite.

Correção:
- as duas consultas agora filtram `condominium_id` da unidade, então id de outro condomínio responde igual a id inexistente;
- os ids passam por `Agreements::entryIds()`: inteiros positivos, sem repetição, no máximo `MAX_ENTRIES` = 120.

Testes: unitário `AgreementsTest::testEntryIdsAreNormalizedAndCapped`. Integração
`AgreementsIntegrationTest::testRefusalsAndScope`, que precisa rodar no lab.

## Riscos e lacunas que ficam

- **Verificação de documento da unidade** (`GET /unit-documents/verify/{code}`): exige sessão, permissão e escopo. Não é verificação pública. Se um dia virar pública, vai precisar de limite de tentativas.
- **Chamados**: `GET /tickets/{id}` também mostra o chamado a quem o abriu ou é o solicitante, mesmo sem escopo no condomínio. É o comportamento atual (`TicketService::findFor`) e fica registrado aqui: confirmar se é o desejado.
- **Mensagens de validação de outros serviços** podem ter oráculos parecidos com o dos acordos. A revisão olhou os pontos com lista de ids. Uma varredura por teste automático (escopo × id alheio para cada rota) seria mais segura e fica para os testes de integração dos ciclos 8 a 16.
- **Limite de requisições na API**: não existe no `ApiAuthenticated`. Upload e importação de retorno têm limite de tamanho, mas não de frequência.
