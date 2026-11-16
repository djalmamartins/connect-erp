# 0330 — Anonimização de quem saiu (LGPD)

- Status: Aceito (API; a tela em Configurações e o artigo da Central de Ajuda vêm na próxima fatia)
- Data: 16/11/2026
- Pedido: `docs/ROADMAP_V1.md` etapa 6 (LGPD); Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0328 (relatório dos dados do titular), ADR-002 (escopo)

## Contexto

Depois do relatório (ADR 0328), falta atender o pedido de eliminação (LGPD art. 18, IV e VI). O condomínio precisa guardar a
contabilidade (contas, boletos, acordos, prestação de contas) e pode cobrar quem deve, então apagar a pessoa inteira não é
possível nem desejável.

## Decisão

1. `POST /api/v1/privacy/anonymize` com a mesma busca do relatório (`document`/`email`). Sem `confirm` = prévia com o que muda
   (`plan`) e o que impede (`blockers`). Com `confirm: true` e `reason` (10 a 500 caracteres), tudo ou nada numa transação.
2. O que muda, só nos condomínios do escopo: cadastro de morador vira "Titular anonimizado #id", sem documento, e-mail, telefone,
   anotações e vínculo com a conta, e fica inativo; convites pendentes são revogados; visitantes com o mesmo documento, dono de
   veículo e tutor de animal ligados pelo nome e quem abriu os chamados ficam como "Titular anonimizado".
3. O que fica: unidade, vínculo, datas, contas a receber, boletos, acordos, prestação de contas, placa/modelo, espécie e o texto
   dos chamados. A conta de acesso (`users`) também fica; desativar login é tarefa de Usuários do Sistema.
4. Recusa (409) se algum cadastro ainda está ativo sem data de saída no passado (a pessoa ainda mora) ou se há conta a receber
   ativa e não paga com ela como pagadora (interesse legítimo de cobrança). Nada é alterado nesse caso.
5. Permissões `erp.access`, `erp.privacy.export` e `erp.users.manage`; limite de 10 por 10 minutos; auditoria `anonymize` com o
   hash da busca, o motivo e as quantidades por grupo.

## Consequências

- Não tem volta: os dados apagados só voltam restaurando o backup (ADR 0325), que guarda 30 dias diários e 400 dias mensais.
  Quem pede a anonimização precisa saber disso (a tela vai avisar na modal do sistema, ADR 0316).
- O texto livre dos chamados e das mensagens pode citar o nome da pessoa; isso não é apagado automaticamente.
