# 0339 — Anexos dos chamados só com login

- Status: Aceito
- Data: 17/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.7 (revisão final antes de abrir ao público); Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0142 (conversa do chamado no ERP), ADR 0305 (ocorrência como chamado), ADR 0338 (`.htaccess`)

## Contexto

Os anexos do Help Desk (respostas da equipe, respostas pelo ERP e pelo app, e o formulário público de suporte) são gravados
em `storage/files/AAAA/MM/ticket-...` e `storage/images/AAAA/MM/public-ticket-...`. Essas pastas são públicas: quem tivesse
o endereço baixava o arquivo sem login, inclusive o anexo de nota interna. O nome tem 8 caracteres aleatórios, mas o número
do chamado aparece nele e o endereço circula em e-mail e histórico do navegador. Além disso, o link que o sistema montava
(`url('/files/...')`, sem o `storage/`) nem abria.

## Decisão

1. Nova rota `GET /api/v1/ticket-attachments/{id}` (sessão ou Bearer do app do morador, pelo `ResidentApiAuthenticated`).
   `TicketAttachmentAccess` decide:
   - quem tem `support.manage` (equipe do Help Desk; é a mesma regra que mostra as notas internas no ERP) baixa qualquer anexo;
   - os demais só baixam anexo de mensagem pública de chamado que veem (`TicketService::findFor`: o próprio chamado ou o
     do condomínio no escopo);
   - o arquivo precisa estar dentro de `storage/files` ou `storage/images` (sem `../` nem link para fora).
   Qualquer recusa responde 404, sem dizer se o anexo existe. O arquivo sai como download, com `nosniff` e `no-store`.
2. A conversa do chamado (API `/tickets/{id}/messages`, ERP, app do morador) e o Help Desk passam a usar essa rota como
   `attachments[].url`.
3. O `.htaccess` bloqueia `storage/files/.../ticket-*` e `storage/images/.../public-ticket-*`. Os outros arquivos dessas
   pastas continuam públicos (fotos do site, imagens dos temas); levar o resto para fora da pasta pública fica para quando
   os documentos forem para o OneDrive (§3.4 do roadmap).

## Verificação

`TicketAttachmentAccessTest` (regra, caminho e consulta com SQLite) e `HtaccessExposureTest`; integração dos chamados
(30 testes) sem mudança.

## Riscos

Anexos de mensagens antigas continuam com o mesmo caminho no banco e passam a abrir pela rota nova; nada é movido.
