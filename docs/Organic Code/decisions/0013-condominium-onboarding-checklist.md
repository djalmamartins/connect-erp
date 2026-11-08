# 0013 — Checklist de chegada do condomínio

Data: 2026-11-08 · Status: proposto (aguarda revisão do Djalma)

## Contexto

O Djalma pediu que a chegada de um condomínio vire um checklist do que existe e do que
falta (CNPJ, AVCB, extintores…), gerando protocolos para regularizar e manter em dia.
Spec: `docs/specs/modules/checklist-de-chegada.md`.

## Decisão

- Catálogo configurável em `onboarding_item_templates` (código, categoria, prazo padrão,
  periodicidade de renovação, ordem, ativo) e cópia por condomínio em
  `condominium_onboarding_items` com `condominium_id` (ADR-002). Unicidade
  `(condominium_id, template_id)` torna `start()` idempotente; itens avulsos têm
  `template_id` nulo.
- O protocolo é uma coluna do item (`CHK-AAAA-NNNNNN`, ano + id), gerado uma vez quando o
  item passa a "falta providenciar" ou "em andamento". Não reutiliza
  `studio_support_tickets` (Help Desk do Studio, com SLA e mensagens próprios); se for
  preciso conversa por protocolo, a integração fica para depois.
- Situações derivadas (`expiring`, `expired`, `overdue`) são calculadas na leitura a partir
  de `expires_at`/`due_date`, sem job e sem estado duplicado.
- Serviço único `Source\Services\OnboardingChecklist` usado pela tela
  (`Erp\Connect\CondominiumChecklist`) e pela API (`Api\V1\CondominiumOnboarding`).
- Reaproveita `erp.condominiums.view/manage`; nova permissão `erp.onboarding.catalog`
  reservada para a futura tela do catálogo. Histórico pelo `Audit` existente.
- Estilos em `assets/css/checklist.css`, ligado pela própria página, para não mexer em
  `erp.css`/`default.php` (donos: frente de layout).

## Consequências

- Migration `20261108_0010_condominium_onboarding` (com `.down.sql`) semeia 21 itens.
  Valores de prazo/renovação são INFERIDOS e devem ser revisados pela Connect.
- A fatia de cadastro (AP-004) deve chamar `start()` após criar o condomínio.
- Migration `20261108_0011_onboarding_alerts` adiciona `alerted_situation`/`alerted_at` para o
  job diário `service/jobs/onboarding-alerts.php` não repetir avisos. O job só enfileira
  (notifications + mail_queue); o envio continua no worker de e-mail.
- Catálogo editável em `/erp/checklist/catalogo` (e `/api/v1/onboarding/templates`) com
  `erp.onboarding.catalog`.
