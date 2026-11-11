# 0198 — Conferência do faturamento

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0033 (faturas), 0181 (conferência antes de fechar), 0184 (extrato da unidade), 0185 (qualidade do
  cadastro), 0189 (simulação da taxa)

## Contexto

Todo mês, antes de mandar as cobranças, a administradora confere se cada unidade foi cobrada certo. Esta conferência
pega erros comuns:

- a unidade nova que ficou fora da fatura;
- a taxa lançada duas vezes (a fatura e mais um lançamento avulso);
- o valor que dobrou por engano de digitação;
- a cobrança de uma unidade já inativa.

Hoje isso é feito comparando planilhas. A conferência antes de fechar (ADR 0181) olha o caixa e o Balanço, não a
cobrança por unidade.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/billing-check?condominium_id=&competence=AAAA-MM&tolerance=`. O service
é `BillingCheck`. Conta só as contas a receber válidas (ativas e não excluídas) do condomínio na competência.

### O que aponta

- **`missing`:** unidade ativa sem nenhuma conta a receber na competência, com o valor da competência anterior.
- **`duplicates`:** unidade com duas ou mais contas a receber na mesma conta do plano. Taxa e fundo de reserva da mesma
  unidade não são duplicidade.
- **`variations`:** unidade cujo total mudou mais que a tolerância em relação à competência anterior (padrão 20%, de 1%
  a 500%). Vem da maior variação para a menor.
- **`inactive_units`:** conta a receber de unidade inativa ou excluída.
- **`without_unit`:** receita da competência sem unidade. Pode estar certo (rendimento, multa avulsa), mas vale olhar.

### Resumo e histórico curto

- **Resumo:** unidades ativas, unidades cobradas, totais da competência e da anterior, receita sem unidade e as
  contagens de cada lista.
- **Histórico curto:** se a competência anterior não tem nada cobrado das unidades (condomínio novo, ou o laboratório
  em janeiro), não há variação e vem o aviso `no_previous`, em vez de apontar todas as unidades.

### Dados, permissões e escopo

- **Dados expostos:** das unidades sai só o rótulo (101, Bloco A 12). Nenhum dado de morador.
- **Tamanho:** cada lista vem limitada a 200 itens, com a marca `truncated`.
- **Permissão:** exige `erp.access` e `erp.entries.view`.
- **Escopo:** um condomínio, obrigatório; fora do escopo dá 404 antes de consultar.
- **Entradas inválidas:** competência ou tolerância inválidas dão 422.
- **Consultas:** parametrizadas.

## Consequências

- **Testes.** `BillingCheckTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
  - as cinco listas e o resumo;
  - que cancelada, excluída, despesa e outro condomínio não entram;
  - que taxa e fundo de reserva da mesma unidade não são duplicidade;
  - a tolerância e a ordem das variações;
  - a unidade inativa e a excluída;
  - o condomínio sem competência anterior, com o aviso `no_previous`;
  - a regra pura da variação, o escopo, as entradas inválidas, o controller e a rota.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration nem tabela nova, sem gravação, sem e-mail e sem banco.**
- **Sem tela nova neste patch.** A tela de faturas pode mostrar a conferência antes de "Emitir" quando a frente de
  layout quiser; quando a tela existir, ela ganha o artigo da Central de Ajuda.
