# 0087 — Endurecimento do ERP: dados pessoais na auditoria, limites, índices e consultas por linha

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: handoff/claude-units-hardening-findings.md

## Contexto

A revisão de segurança e desempenho dos serviços e controllers do ERP (ADRs 0019 a 0086) encontrou um
item de gravidade alta, quatro médios e cinco baixos. CSRF, bind de SQL, escopo nos serviços novos, CSV
seguro, leitura de XML e upload estavam em ordem.

## Decisão

- **A1, dados pessoais na auditoria:**
  - o motor de cadastros passa a gravar na auditoria os campos pessoais (tipos document, cpf, cnpj,
    id_document, email e phone) mascarados (`Support\AuditMask::fields`). A trilha diz que mudou, sem
    o valor;
  - o relatório de logs (AP-097) mascara no contexto qualquer CPF, CNPJ, e-mail ou telefone que tenha
    ficado de registros antigos (`AuditMask::text`). Datas, horas e valores não são tocados.
- **M1, sem limite de linhas:** o relatório de acordos (AP-047) e a base da inadimplência passam a ter
  teto de 20.000 linhas (o mesmo dos recebimentos, ADR 0067), com a mensagem para reduzir o período ou
  escolher um condomínio.
- **M2, contato sem máscara:** a visão por morador dos inadimplentes (AP-061) mascara também o e-mail e
  o telefone do responsável, como a listagem de pessoas (AP-087).
- **M3 e B3, índices** (migration 20261108_0542):
  - outbox: (condominium_id, event_type, created_at);
  - reservas: (condominium_id, reserved_on);
  - ocorrências: (condominium_id, occurred_on).
- **M4, consultas por linha nas importações:**
  - o fechamento mensal é consultado uma vez por competência em cada validação (`closed()`);
  - nas leituras, o histórico dos relógios do arquivo vem numa consulta só, e
    `ImportRules::readingContext` decide, por linha, se já há leitura, se foi cobrada, se há leitura
    posterior e qual é a anterior.
- **B1:** a data do saldo inicial no extrato da conta vai por bind.
- **B2:** o extrato responde 404 tanto para conta inexistente quanto para conta fora do escopo.
- **B5, limite de exportação:** até 30 exportações em CSV por usuário por hora (`ReportExportLimit`),
  contadas na própria auditoria, no mesmo mecanismo do ADR 0065. Acima disso, a resposta é 429.
- **B4** (subconsultas do relatório de unidades) fica como está: usa índice. Só muda se o EXPLAIN
  mostrar custo.

## Consequências

- A auditoria antiga continua no banco como está; o relatório de logs só a mostra mascarada.
