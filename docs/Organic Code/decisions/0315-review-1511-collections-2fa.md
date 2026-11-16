# 0315 — Revisão de 15/11, etapa 6: atendimento de cobrança sem recarregar, lembrete da próxima ação e 2FA "Connect Condomínios"

- Status: Aceito
- Data: 16/11/2026
- Issue: #545 (revisão de 15/11, etapa 6)
- Relacionadas: ADR 0080 (cobrança e atendimento), 0023/0028 (2FA), 0142 (sino do ERP), 0301 (avisos à equipe pelo sino)

## Contexto

Itens 22 a 24 da revisão de 15/11 do Djalma:

- 22: no Atendimento de cobrança (AP-117), salvar um contato recarregava a página inteira, e o histórico ficava
  abaixo do formulário.
- 23: a próxima ação marcada no contato não lembrava ninguém no dia.
- 24: o aplicativo autenticador mostrava "Connect ERP"; o pedido é "Connect Condomínios", com o ícone se o aplicativo
  aceitar.

## Decisão

1. **Salvar sem recarregar** (item 22): o painel da unidade mostra primeiro o **Histórico de atendimento**, em
   destaque (borda e fundo da marca), e depois o formulário. Ao registrar, o contato devolvido pela API
   (`POST /api/v1/collection-contacts`, sem mudança de contrato) entra no topo do histórico (montado com
   `textContent`, a partir de um `<template>`), a linha da unidade na fila mostra o novo último contato e a próxima
   ação, e o formulário fica limpo. Nada recarrega.
2. **Lembrete da próxima ação** (item 23): `Source\Services\Erp\CollectionActionReminders`, uma vez por dia
   (`tools/collection-reminders.php`, chamado pelo `service/jobs/scheduler.sh` às 7h junto com os avisos do
   checklist de chegada).
   - Vale só o contato mais novo da unidade: um atendimento depois substitui a data marcada antes.
   - O aviso vai para o sino (`notifications`) de quem registrou o contato. Se essa pessoa não tem mais a conta
     confirmada, a permissão `erp.collections.manage` ou o acesso ao condomínio, vai para a equipe que tem a
     permissão e alcança o condomínio.
   - Cada contato é lembrado uma vez (`erp_collection_contacts.reminded_at`, migration
     `20261116_0410_collection_action_reminder`, reversível). A marca é gravada antes do aviso, para não duplicar.
   - Ações de até 7 dias atrás ainda não lembradas (sistema parado) saem como "atrasada"; as mais antigas não.
   - Nada vai para o morador.
3. **Nome no autenticador** (item 24): `TwoFactorService::ISSUER = 'Connect Condomínios'` no `otpauth://` (rótulo e
   `issuer`) do ERP, do Studio e do aplicativo do morador. O ícone vai no parâmetro `image` (o ícone da Connect do
   aplicativo, só quando o endereço é https). Os aplicativos que aceitam (FreeOTP, 2FAS e outros) mostram; Google e
   Microsoft Authenticator ignoram (INFERIDO pela documentação pública dos aplicativos, não testado em aparelho).

## Consequências

- Quem já cadastrou o 2FA continua vendo o nome antigo no aplicativo até renomear ou cadastrar de novo. O código não
  muda e continua valendo.
- O lembrete depende do serviço `scheduler` do compose (ou do cron do servidor em produção) rodar
  `php tools/collection-reminders.php` uma vez por dia.

## Testes

- Integração: `CollectionActionRemindersTest` (dia certo e uma vez só, contato mais novo substitui, atraso de até 7
  dias, equipe do próprio condomínio quando quem atendeu saiu).
- Unidade: `TotpTest` (nome Connect Condomínios codificado, ícone só https).
- Smoke: AP-117 com o histórico antes do formulário e os ganchos do salvar sem recarregar. Conferido no navegador
  (Playwright) com a API simulada: sem navegação, contato no topo, linha da fila atualizada, formulário limpo.
