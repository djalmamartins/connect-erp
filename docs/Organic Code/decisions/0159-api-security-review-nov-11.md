# ADR 0159 — Revisão de segurança da API e das exportações (11/11/2026)

- Situação: aceita (11/11/2026)
- Contexto: pedido do Djalma de 11/11 ("tem mais alguma coisa para fazer ou melhorar?" → revisão de segurança).
  Continua as revisões dos ADRs 0087 e 0172.

## O que foi conferido

- **Isolamento por condomínio:** varredura das consultas por `id` nos serviços do ERP. Toda leitura ou escrita por
  id passa antes por `CondominiumScope` (canAccess, assertAccess ou allowedIds) ou por uma busca que já filtrou o
  escopo. Os dois achados da varredura (`Invoices::issue` e `Imports::systemBalance`) são seguros: o primeiro chama
  `invoiceRow` antes; o segundo é privado e só roda depois da conferência da conta. CONFIRMADO no código.
- **SQL montado com variáveis:** todo trecho interpolado vem de lista fechada (tabela da definição do Registry,
  coluna de voto `yes|no`, status, período) ou de placeholders; nenhum valor do usuário entra sem bind.
- **CSRF nos POST de formulário** (ERP, site, Central de Ajuda, Studio, Help Desk): todos conferem o token no
  próprio método ou no ajudante (`guideAllowed`, `handle`, `decide`, `validWidgetRequest`). Os dois sem token
  (`/buscar` e `/studio/blog/home`) só leem e redirecionam. A API inteira já era coberta pelo ApiGuardCoverageTest.
- **Dados pessoais (LGPD):** relatórios, inadimplência, visão da imobiliária e busca de pessoas mascaram CPF/CNPJ,
  e-mail e telefone. O cadastro de pessoas e o painel da unidade mostram o contato completo a quem tem
  `erp.residents.view`, porque é a tela de trabalho de quem liga ou escreve para o morador (mantido).

## Decisão (o que mudou)

- **Exportação de usuários do Studio** (`/studio/users?export=csv`): era o único `fputcsv` que não saneava as
  células. Nome e telefone vêm do cadastro público, então um nome `=HYPERLINK(...)` virava fórmula no Excel de quem
  baixava. Agora toda célula passa por `CollectionReports::csvCell` (ADR 0081), e a exportação entra na auditoria
  (`export`/`users_csv`) só com a contagem e se havia filtro, sem dado pessoal. A entidade não começa com `erp_`,
  então não conta no limite de exportações do ERP nem aparece em Relatórios Enviados (AP-100).
- **Voto em artigo do site:** o log de falha usava uma variável inexistente (`$id`); passa a gravar `$articleId`.
- **Teste novo** `CsvExportSafetyTest`: qualquer `fputcsv` novo em `source/` precisa usar `csvCell` (ou ser
  cabeçalho fixo, ou o escritor central do ERP), senão o CI falha.

## Consequências

- Planilhas baixadas do Studio não executam fórmula vinda de cadastro de terceiros.
- PENDENTE (fora desta revisão): teste de carga dos limites (`request_limit`) e revisão do HTML dos templates
  quanto a escape, que segue coberta pelo render-smoke e pelo uso de `$this->e()`.
