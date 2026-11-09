# 0075 — Telas de comunicação: comunicado, aviso de cobrança, agendados e endereços

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: ADR 0059 e 0065

## Contexto

O ADR 0059 entregou a comunicação pela outbox (comunicado geral, aviso de cobrança, agendamento e
endereços), e o ADR 0065 endureceu o serviço. As páginas AP-111, AP-137, AP-138 e AP-139 ainda
mostravam a demonstração.

## Decisão

- **Dados no servidor pelo mesmo serviço da API** (`CommunicationScreens`): com banco e com
  `erp.announcements.view` (mais `erp.agreements.view` na AP-137 e `erp.residents.view` na AP-139,
  como na API), a página troca a demonstração pela tela própria. Gravar e cancelar são pela API REST,
  com CSRF no cabeçalho (erp.js, `data-erp-communication`).
- **AP-111 Comunicado geral:** assunto e texto com contador, todas as unidades ativas ou as
  escolhidas, enviar agora ou agendar (`datetime-local` limitado a 60 dias), confirmação antes de
  enviar. A lista mostra envio, unidades, quantos avisos saíram e a situação (Agendado, Enviando,
  Com falha, Enviado ou Cancelado). `?comunicado=ID` abre o detalhe com a entrega, o texto escapado e
  o motivo do cancelamento.
- **AP-137 Aviso de cobrança:** a tela lista as unidades com conta vencida na data de referência
  (`?referencia=`, padrão hoje; futura ou inválida volta para hoje com aviso), com contas, valor sem
  encargos, vencimento mais antigo e atraso. Unidade avisada nos últimos 7 dias aparece desmarcada e
  travada; o atraso mínimo trava as que ficam abaixo. Com o cadastro de pessoas visível, cada unidade
  mostra quantas pessoas recebem a cobrança por e-mail, e "ninguém" aparece em destaque com link para
  Endereços. Com todas as unidades elegíveis marcadas, o pedido vai sem `unit_ids` (o serviço aplica
  o critério inteiro); senão, só as marcadas.
  - Para marcar as avisadas, o serviço ganhou `Announcements::remindedUnits()`, que confere o escopo
    do condomínio e reaproveita a mesma consulta do envio.
- **AP-138 Agendada:** os comunicados e avisos que ainda vão sair, dos dois tipos, com cancelar.
- **Cancelar** (as três páginas): só aparece com aviso ainda pendente e permissão de gravar; pede o
  motivo num diálogo e informa quantos avisos pararam na fila.
- **AP-139 Endereços:** resumo (unidades, sem ninguém para comunicado, sem ninguém para cobrança),
  filtro `?sem=comunicado|cobranca` e, por unidade, as pessoas com vínculo, e-mail mascarado e o que
  recebem ("Recusou" comunicado, "Não autorizou" cobrança, "Sem e-mail válido"). O nome leva ao
  cadastro da pessoa, onde a correção é feita.

## Fora deste ADR

- Pré-visualizar o e-mail como o morador recebe.
- Editar um comunicado agendado: hoje é cancelar e criar de novo.
- Aviso de cobrança com encargos (multa e juros) no texto.

## Consequências

- `ErpCommunicationScreensTest` e o smoke de renderização cobrem as quatro telas, as regras puras
  (`statusOf`, `cancellable`, `referenceDate`, `filterAddresses`) e o escape do texto.
- Nada foi testado contra MariaDB real nesta rodada.
