# 0350 — Registro das operações de tratamento (LGPD art. 37)

- Status: Aceito
- Data: 17/11/2026
- Contexto: plano v1.0, §3.8 ("registro das operações de tratamento e regra de retenção dos logs")

## Contexto

A LGPD (art. 37) pede que controlador e operador mantenham registro das operações de tratamento. O ERP guarda dado
pessoal em dezenas de tabelas, e não havia uma lista de quais são, para quê e por quanto tempo.

## Decisão

- Novo `docs/security/LGPD_REGISTRO_OPERACOES.md`, levantado do banco (information_schema) e do código. Contém:
  - 16 operações, cada uma com titulares, dados, tabelas, finalidade, base legal proposta e prazo de guarda;
  - os suboperadores (Hostinger, Microsoft, Inter, Cora, e-mail, WhatsApp);
  - as medidas de segurança, com o ADR de cada uma;
  - como o titular exerce seus direitos;
  - as decisões que ainda faltam.
- Bases legais, papéis e prazos marcados **INFERIDO** são propostas técnicas para o advogado revisar.
- Novo `PersonalDataRegisterIntegrationTest`. Toda tabela com coluna de dado pessoal precisa aparecer no registro:
  - a coluna conta pelo nome (documento, e-mail, telefone, nome de pessoa, placa, IP, foto);
  - o teste falha quando uma migration nova cria uma dessas tabelas sem atualizar o registro.

## Achados (CONFIRMADO no código)

- Sem prazo nem limpeza:
  - entradas e saídas da portaria (`erp_access_logs`);
  - fila de e-mails (`mail_queue`);
  - fila de avisos (`erp_notification_outbox`);
  - trilha de auditoria (`system_audit_logs`);
  - a maior parte do Talk.
- Com limpeza:
  - `app_log`: 90 a 180 dias;
  - arquivos de log: 6 meses (ADR 0336);
  - limitadores: 1 dia;
  - troca de ambiente: 1 dia.
- Lacuna no relatório e na anonimização do titular (ADRs 0328 e 0330). Ficam de fora:
  - portaria;
  - mudanças;
  - reservas e espera;
  - assembleias e conselho;
  - contatos de cobrança;
  - Talk.

  Nessas tabelas o nome fica solto, sem ligação com o cadastro.

## Pendente (Djalma e advogado)

- Confirmar os papéis: condomínio controlador, Connect operadora. Precisa entrar no contrato com cada condomínio.
- Confirmar as bases legais propostas.
- Definir os prazos marcados PENDENTE. Só depois disso entra a rotina de limpeza.

## Próximo passo sem o Djalma

Levar o relatório e a anonimização do titular às tabelas da lacuna acima, com busca por documento e por nome.
