# 0172 — Revisão de segurança e coerência contábil dos ADRs 0166–0171

- **Status:** aceito
- **Data:** 2026-11-10
- **Escopo:** Accountability, DeliveryLog, ChartOpenings (0166), BalanceSheet (0167), AdminReports e ErpAccess (0168),
  PatrimonialEntries (0169), Investments e razão bancário (0170), PatrimonialImports (0171).

## Contexto

Seis ciclos seguidos criaram o balanço, os lançamentos patrimoniais, as aplicações e a importação patrimonial.
Antes de seguir, revisamos a segurança e conferimos num cenário completo se os relatórios fecham entre si.

## O que foi conferido

1. **Escopo.** Todo GET e POST passa por `CondominiumScope::assertAccess` (fora do escopo = 404). Os ids que vêm no
   corpo são lidos com `condominium_id` na consulta:
   - conta bancária, lançamento de origem e conta do plano de outro condomínio dão 422;
   - a conta-mãe de uma aplicação também é conferida assim.
2. **Permissões.** `Access::can` nega o que não está concedido (fail-closed) em todas as rotas `erp.*` revisadas.
3. **CSRF.** Todo POST novo fica atrás do `ApiAuthenticated`, que confere o `X-CSRF-Token`.
4. **Auditoria.** Toda gravação chama `Audit::record`.
5. **CSV.** As exportações neutralizam fórmulas (`=`, `+`, `-`, `@`, tab e CR) e têm limite de linhas com `truncated`.
6. **SQL.** Só consultas parametrizadas; não há valor do usuário concatenado em SQL.
7. **Corridas.**
   - O estorno duplo é barrado pelo `UNIQUE (reversal_of_id)`.
   - A aplicação dupla da importação é barrada pela troca de status condicional (`WHERE status = 'previewed'`)
     dentro da transação.
8. **Erros.** As mensagens de 404 e 422 não trazem dados de outro condomínio.

## Achados e correções

| # | Gravidade | Onde | Achado | O que foi feito |
|---|---|---|---|---|
| A1 | Alta | `AdminReports` (exportação da auditoria) | `LIKE 'erp\_%' ESCAPE '\'`: no MySQL a barra escapa a aspa, e a consulta falha (erro 500 na exportação). No SQLite passava, por isso o teste não pegou. | Passou a usar `LIKE 'erp!_%' ESCAPE '!'`, que vale igual nos dois bancos. |
| A2 | Baixa | `ChartOpenings::set` | Duas gravações simultâneas do primeiro saldo da mesma conta: a segunda batia na chave única e dava erro 500. | Se o INSERT der chave repetida (1062/19), vira UPDATE. |
| A3 | Baixa | `ErpAccess::invite`, `setRolePermission`, `setUserPermission` | O mesmo caso de corrida na concessão de acesso e de permissão dava erro 500. | Novo `insertOnce`: chave repetida conta como "já tinha". O `setUserPermission` (DELETE + INSERT) agora roda numa transação com rollback. |
| A4 | Média | `PatrimonialImports::preview` | Ao repetir a prévia depois de chave repetida, qualquer outra violação de restrição do SQLite (também código 19) fazia a função se chamar sem parar. | A nova tentativa acontece uma vez só; se falhar de novo, o erro sobe. |
| A5 | Média | `PatrimonialEntries::movements` (balanço) | Trazia todos os lançamentos patrimoniais do condomínio até a data, um por um, para a memória. | Soma no banco (`GROUP BY a.code, a.nature, m.entry_date`). O resultado do balanço é o mesmo. |

Cada correção tem teste em `tests/legacy/Unit/AccountingCoherenceTest.php::testReviewFixes`:

- A1 e A5 conferem o código-fonte.
- A2 e A3 simulam a outra requisição: um `PDOStatement` de teste grava a linha concorrente logo antes do INSERT. Um
  gatilho do SQLite não serviria, porque a falha do comando desfaz também o que o gatilho gravou.
- A4 usa uma restrição `CHECK` que sempre falha e espera o erro, não o laço.
- A transação do `setUserPermission` não tem teste próprio de falha no meio; o `ErpAccessAdminTest` continua cobrindo
  o caminho normal.

## Coerência contábil

O teste `testEndToEndYearKeepsEveryReportCoherent` monta um ano completo num condomínio:

- saldo inicial do banco e do PL;
- receitas e despesas pagas e em aberto;
- taxa de fundo de reserva com apropriação e uso do fundo;
- aplicação, rendimento e IR, resgate e uma aplicação cancelada;
- um lançamento errado estornado;
- uma importação patrimonial;
- a virada do ano.

Ele confere:

- **Balanço:** fecha com diferença zero.
- **DRE:** o resultado do ano entra na 9.2. Vale `9.2 = DRE do exercício + movimentos patrimoniais na 9.2`; sem
  apropriação, a 9.2 é igual à DRE.
- **Razão:** o fechamento das contas patrimoniais (8.2, 8.3, 9.1) é igual ao saldo do balanço.
- **Razão bancário:** o fechamento total é igual ao Disponível (7.1), e cada conta bate com a conciliação da tela
  de aplicações.
- **Aplicação e resgate:** não mudam o total; as transferências somam zero.
- **Cancelamento:** a aplicação cancelada some.
- **Estorno:** anula o lançamento.
- **Virada do ano:** a 9.2 zera e a 9.1 recebe o resultado.

Não há banco `*_test` acessível sem Docker, então o teste é unit com SQLite. Quando houver, vale repetir o mesmo
cenário como teste de integração no MySQL, o que pegaria casos como o A1.

## Riscos aceitos

- **Mês fechado sem lock.** `PatrimonialEntries::create` e o importador conferem o mês fechado sem lock. Na main, o
  Preparar troca essa conferência por `isClosed`, que trava no MySQL; não repeti a troca aqui para não conflitar.
- **Convite.** O convite revela se o e-mail já tem conta. Já tinha sido aceito no ADR 0168: só o administrador
  da administradora vê.
- **CSRF depende das rotas.** O CSRF depende de as rotas serem registradas com `ApiAuthenticated`; as linhas dos
  guias já trazem o middleware.
- **Razão e balanço contam saldos iniciais de forma diferente.** O razão ignora saldo inicial com data dentro do
  período (aparece em `openings_ignored`); o balanço conta. Está documentado no ADR 0167.

## Consequências

- **Sem migration nem rota nova.** A 20261109_0709 não foi necessária.
- **Contrato da API inalterado.**
