# 0183 — Publicação congelada da prestação de contas anual

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0179 (publicação congelada do mensal), 0182 (prestação de contas anual)

## Contexto

O ADR 0182 criou o pacote do ano para a assembleia, mas sem o congelamento que o mensal ganhou no ADR 0179. A
prestação de contas aprovada na assembleia ordinária precisa poder ser mostrada de novo, igual, anos depois, mesmo que
algum mês seja reaberto e corrigido.

## Decisão

A mesma tabela e o mesmo service das publicações mensais passam a aceitar o tipo.

### A tabela

A migration 20261111_0713 faz três mudanças em `erp_accountability_publications`:

- acrescenta a coluna `kind` (`monthly` ou `annual`, com padrão `monthly`);
- troca a chave única para (condomínio, tipo, competência, versão);
- acrescenta um índice (condomínio, tipo, competência, situação).

As publicações que já existem ficam como `monthly`.

### Publicar o ano

`AccountabilityPublications::publishAnnual()` monta o pacote do `AnnualAccountability` e grava com `kind = annual` e
`competence = 'AAAA'`. A publicação exige todos os meses esperados fechados, de janeiro até o mês da data de corte. Se
algum estiver aberto, dá 409 e a mensagem diz quais.

As regras do mensal valem igual:

- publicar de novo o mesmo conteúdo devolve a versão atual;
- conteúdo diferente vira a próxima versão;
- a versão atual pode ser retirada com motivo;
- o detalhe confere o sha256;
- tudo é auditado.

Mensal e anual têm versões separadas: a publicação de dezembro e a do ano são independentes.

### A API (mesmas rotas do ADR 0179)

- **`POST /api/v1/accountability-publications`** aceita `{condominium_id, kind: "annual", year, notes?}`. Sem `kind`,
  continua publicando o mês. Tipo inválido dá 422.
- **`GET /api/v1/accountability-publications`** aceita `kind` (`monthly` ou `annual`) e `year` no anual. Um `month`
  sem `kind` continua sendo o mensal.
- **O detalhe** traz `kind`. O `?format=csv` usa o formato anual (ADR 0182) quando a versão é anual.

## Consequências

- **Testes.**
  - `AnnualAccountabilityPublicationTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
    - que dezembro aberto recusa, com o mês na mensagem;
    - a versão 1 do ano e a mesma publicação devolvida quando nada mudou;
    - que a publicação mensal de dezembro tem a própria versão 1;
    - os filtros por tipo e período;
    - o conteúdo congelado com `intact` e o formato do CSV;
    - que, depois de retirar, a versão 2 sai e o mensal não muda;
    - as entradas inválidas;
    - a migration e o controller.
  - `AccountabilityPublicationsTest` ganhou a coluna `kind` no fixture.
- **Rotas.** Sem rota nova.
- **OpenAPI.** Os caminhos de `/accountability-publications` estão na OpenAPI da main, que o meu worktree não tem. Por
  isso a atualização da descrição (o `kind` e o `year`) vai como texto no guia, para aplicar lá.
- **TRUNCATE e purge.** A tabela já está no TRUNCATE e no purge do lab, então nada muda.
- **Sem tela nova**, então sem artigo na Central de Ajuda.
