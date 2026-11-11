# 0179 — Publicação congelada da prestação de contas mensal

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0031 (fechamento mensal), 0166 (prestação de contas, AP-101), 0177 (fundos), 0178 (CSV)

## Contexto

A prestação de contas é calculada na hora, a partir dos lançamentos. Mas se o mês é reaberto e algum lançamento muda,
o pacote que foi entregue ao síndico e aos moradores não pode mais ser mostrado igual. Não há como provar o que foi
entregue nem comparar com a versão corrigida.

## Decisão

A tabela nova `erp_accountability_publications` (migration 20261111_0712) guarda o pacote como foi entregue:

- o JSON canônico de `Accountability::build`, com as chaves ordenadas e as listas na ordem;
- o sha256 desse JSON;
- a versão.

O service é `AccountabilityPublications`.

### Publicar

- Só com o mês fechado (AP-030). Mês aberto ou reaberto dá 409.
- Publicar de novo o mesmo conteúdo devolve a versão atual e não cria outra (`created = false`).
- Conteúdo diferente (o mês foi reaberto, corrigido e fechado de novo) vira a versão seguinte. A anterior passa a
  `superseded` e continua lá, igual.
- Duas publicações ao mesmo tempo esbarram na chave única (condomínio, mês, versão); a segunda tenta de novo uma vez.

### Ver

- A lista traz as versões, sem o conteúdo.
- O detalhe traz o pacote e `intact`: o sha256 recalculado confere com o gravado. Assim, uma alteração direta no banco
  é percebida.
- `?format=csv` baixa o CSV da versão publicada (ADR 0178), não o do cálculo atual. Segue o limite de 30 por hora e a
  auditoria.

### Retirar

- Só a versão atual, com motivo de 3 a 255 caracteres.
- O status passa a `withdrawn`. Nada é apagado.

### Permissões e escopo

- Ver: `erp.closing.view` + `erp.entries.view` + `erp.chart.view`, como a listagem de prestações (AP-094).
- Publicar e retirar: também `erp.closing.manage`, como fechar o mês.
- CSRF pelo `ApiAuthenticated`.
- Fora do escopo dá 404, sem dizer se a publicação existe.
- Toda escrita e todo CSV são auditados.

### O que o pacote guarda de dados pessoais

O pacote não tem nome de morador: a inadimplência vai por unidade. Só os valores e os rótulos que o pacote já mostra.

## Consequências

- **Testes.** `AccountabilityPublicationsTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
  - que mês aberto recusa;
  - que a primeira versão sai como atual e que publicar o mesmo conteúdo não cria outra;
  - que reabrir, corrigir e fechar cria a versão 2 e a versão 1 continua com os números entregues;
  - que a adulteração é detectada;
  - a retirada (motivo, uma vez só, nova versão depois dela);
  - o escopo (404 em tudo) e as entradas inválidas;
  - o JSON canônico;
  - as permissões e a auditoria no controller;
  - a migration.
- **Rotas novas:** quatro, no guia `docs/handoff-0179-shared.md`.
- **Sem tela nova**, então sem artigo novo na Central de Ajuda. Quando a frente de layout fizer a tela, entra a página
  no catálogo e o artigo, com `php tools/help.php scaffold`.
