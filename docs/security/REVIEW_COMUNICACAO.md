# Revisão de segurança — Comunicação pela outbox (ciclo 28, ADR 0059)

Data: 2026-11-09 · Código revisado: `claude-dev` em 03d6ae6 · Correções: ADR 0065

Escopo: `source/Services/Erp/Announcements.php`, `source/Controllers/Api/V1/Announcements.php`,
as mudanças em `NotificationDispatcher` e `NotificationOutbox`, os modelos `announcement` e
`collection-reminder` do container send e a migration 20261108_0537.

## Opt-in e recusa

| Ponto | Situação |
|---|---|
| Comunicado geral vai só para pessoas ativas com e-mail que não recusaram (`notices_opt_out = 0`), filtrado na consulta dos destinatários | CONFIRMADO |
| Aviso de cobrança vai só para quem autorizou cobrança por e-mail (`billing_by_email = 1`, ADR 0056) | CONFIRMADO |
| Até 5 pessoas por unidade; entrega registrada por hash do e-mail, sem repetir a mesma pessoa no mesmo aviso | CONFIRMADO |
| Endereços (AP-139) mostram o e-mail mascarado e pedem também `erp.residents.view` | CONFIRMADO |
| Comunicado geral é por recusa (recebe por padrão), não por aceite. É comunicação do condomínio com o morador, não marketing; a recusa fica no cadastro | INFERIDO (base legal a confirmar com o Djalma, issue #46) |

## Sandbox

| Ponto | Situação |
|---|---|
| Nada é enviado no serviço: cada unidade vira um evento na outbox e só a fila (ADR 0055) entrega | CONFIRMADO |
| Fora de produção, mesmo com `production` configurado, o modo cai para sandbox (só a caixa de teste, com `[TESTE]` no assunto) ou log. O teste de integração usa essa configuração e confere que nenhum e-mail de morador recebe | CONFIRMADO |
| Comunicado cancelado não sai: o despachante relê o status na origem antes de enviar, e o cancelamento marca os eventos pendentes | CONFIRMADO |

## Frequência

| Ponto | Situação |
|---|---|
| Aviso de cobrança: unidade avisada nos últimos 7 dias fica de fora | CONFIRMADO, com a trava do achado 3 |
| Agendamento até 60 dias à frente; data no passado recusada | CONFIRMADO |
| Fila: até 500 eventos por rodada, com tentativas e espera crescente | CONFIRMADO |
| Teto de comunicados por condomínio | Faltava; achado 4 |

## Injeção no modelo

| Ponto | Situação |
|---|---|
| Assunto e texto escapados com `$this->e()` no corpo; quebras de linha viram `<br>` depois do escape (`nl2br(e(...))`) | CONFIRMADO |
| O `<title>` do layout também escapa o assunto | CONFIRMADO |
| O nome do modelo vem da tabela fixa `NOTICES`, nunca da requisição. O payload da outbox é montado pelo serviço, não pelo cliente | CONFIRMADO |
| Caracteres de controle removidos do assunto e do texto | CONFIRMADO; quebra de linha no assunto, achado 1 |
| SQL: valores por parâmetro; as listas de ids interpoladas são inteiros já convertidos | CONFIRMADO |

## Achados corrigidos (ADR 0065)

1. **Quebra de linha no assunto.** O limpador de texto mantinha `\n`, que é certo no corpo, mas o
   assunto vai para o cabeçalho do e-mail. O PHPMailer limpa, mas agora o assunto e o motivo são
   uma linha só, e o despachante também limpa o que já estiver gravado.
2. **Unidades do aviso de cobrança sem validação.** `unit_ids` aceitava qualquer lista, sem teto.
   Agora as duas rotas usam `unitIds()`: inteiros positivos, sem repetir, de 1 a 2.000.
3. **Dois avisos de cobrança ao mesmo tempo.** A checagem dos 7 dias era lida fora da transação, e
   dois pedidos simultâneos avisavam a mesma unidade duas vezes. Agora a criação trava o condomínio
   e lê os recentes depois da trava.
4. **Sem limite de comunicados.** Um script ou cliques repetidos enchiam a fila. Agora são até 20
   comunicados e avisos por condomínio em 24 horas, e passar disso responde 409.

## Lacunas

- **Descadastro pelo próprio morador.** O e-mail diz para falar com a administração; não há link de
  descadastro. Para comunicação do condomínio isso basta hoje, mas um link assinado por pessoa
  facilitaria o direito de oposição (LGPD, art. 18).
- **Limite de requisições por usuário.** Continua sem limite na API como um todo.
- **Status 409 no limite diário.** O certo seria 429 com `Retry-After`; ficou 409 para não mudar o
  contrato do controlador nesta rodada.
- **Envio real.** Segue pendente (ADR 0055 e 0060). Antes de ligar produção, rever SPF, DKIM e o
  remetente.

## Testes

- `AnnouncementsTest::testSubjectIsSingleLineAndUnitIdsAreCapped` (unidade).
- `AnnouncementsIntegrationTest::testHardeningSubjectLineUnitsAndDailyCap` (integração; não roda
  neste ambiente, roda com `composer test:integration` no banco `*_test`).
- Suíte de unidade: 382 testes, só as 2 falhas locais conhecidas do `QrCodeTest` (biblioteca de QR
  ausente aqui; passa na CI).
