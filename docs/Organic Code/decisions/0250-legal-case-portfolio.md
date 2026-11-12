<!-- markdownlint-disable MD024 -->
# 0250 — Carteira da cobrança judicial do condomínio

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - cobrança (Collections): processos, andamentos, contas ligadas e a mesa de cobrança, só como serviço reusado;
  - 0238 e 0246 (mesmo desenho de relatório por fase ou faixa, somente leitura).

## Contexto

A cobrança (Collections) abre o processo de uma unidade com as contas em atraso, registra os andamentos e lista os
processos um a um; a mesa de cobrança mostra o processo de cada inadimplente. Ninguém vê, porém, a carteira:

- quantos processos há em cada fase;
- quanto foi cobrado, quanto das contas cobradas já foi pago e quanto segue em aberto;
- há quanto tempo os processos correm e quais estão parados sem andamento;
- quantos estão sem advogado.

A administradora precisa disso na prestação de contas e na conversa com o escritório.

Busquei `erp_legal_cases` em `source/`, no README das ADRs e nos handoffs 02xx: só a cobrança usa a tabela, para abrir,
registrar andamentos, listar e mostrar um processo; nenhum relatório resume a carteira.

## Decisão

Rota nova: `GET /api/v1/reports/legal-cases?condominium_id=&stale_days=`. O service é `LegalCasePortfolio`, em
`Source\Services\Erp`.

### Reuso

- **Fases:** as de `Collections::CASE_STATUSES`.
- **Valor cobrado:** o gravado na abertura do processo (`claimed_cents`).
- **Contas ligadas:** a mesma regra de "ainda em aberto" do detalhe do processo (`Collections::findCase`): conta ativa sem
  pagamento, pelo valor atual. As pagas contam pelo valor cobrado; as canceladas e excluídas ficam de fora.

### Linhas

A regra pura `summarize()` monta, na data de hoje, uma linha por fase, sempre as cinco, com:

- `cases` e `claimed_cents`;
- `recovered_cents`: as contas cobradas já pagas;
- `still_open_cents`: as contas ainda em aberto;
- `stale`: processos não encerrados sem andamento há mais de `stale_days` dias (padrão 90, de 30 a 365; sem andamento,
  conta a abertura);
- `without_lawyer`;
- `avg_age_days`: a média dos dias desde a abertura até hoje ou até o encerramento (`null` sem processos).

`totals` soma as linhas e recalcula a média. Fora das linhas, `opened_12m` e `closed_12m` contam os processos abertos e
encerrados nos últimos 12 meses.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.collections.view`, as mesmas da cobrança; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só as fases, as contagens, as somas e as médias. Não saem unidade, devedor, advogado, número do
  processo, vara ou andamento.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\LegalCasePortfolioTest`:**
  - o resumo: as fases da cobrança, a fase desconhecida como "Em preparação", o parado no limite de 90 e 91 dias, o
    processo sem andamento, o encerrado que nunca fica parado, as médias, os 12 meses e o total igual à soma das linhas;
  - a consulta com as contas ligadas: paga pelo valor cobrado, em aberto pelo valor atual, cancelada e excluída fora, sem
    outro condomínio e sem dado do processo;
  - validação, escopo (404) e contrato; controller e rota.
- **`Integration\LegalCasePortfolioTest`** (MariaDB, banco `*_test`):
  - processos com contas do cadastro (paga e em aberto), um parado, um sem advogado e um encerrado;
  - as linhas, os totais, as médias e os 12 meses;
  - somente leitura e sem dado do processo; o escopo.
- **Sem tela nova neste patch.** A tela de processos pode ganhar o quadro da carteira; quando ganhar, vem o artigo da
  Central de Ajuda.
