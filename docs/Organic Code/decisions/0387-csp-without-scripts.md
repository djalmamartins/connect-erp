# 0387 — Content-Security-Policy sem restringir scripts

- Status: Aceito
- Data: 18/11/2026
- Contexto: ROADMAP_V1 §3.7 ("Pendente: Content-Security-Policy que limita scripts, depois do piloto") e ADR 0355.

## Contexto
Limitar scripts (`script-src`) exige tirar os scripts e eventos inline das telas, um trabalho grande que ficou para
depois do piloto. Outras três regras da CSP não mexem em script nenhum e fecham ataques comuns quando algum texto
escapa da limpeza (os testes de XSS dos ADRs 0344 a 0347 são a primeira barreira):

- `object-src 'none'`: nenhuma página embute plugin (`<object>`, `<embed>`); o sistema não usa nenhum;
- `base-uri 'self'`: um `<base href>` injetado não muda o destino dos links e formulários relativos;
- `form-action`: um formulário injetado não manda senha, CPF ou dados para outro site.

## Decisão
- `SecurityHeaders::csp(host)` monta `frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'
  <domínio> *.<domínio>` em toda resposta.
- O domínio vem do host do pedido sem o primeiro nome quando ele é de um sistema (`www`, `erp`, `app`, `studio`,
  `talk`): o site, o ERP, o app e o Studio postam uns para os outros (por exemplo, `url()` aponta para o site e
  `erp_url()` para o ERP). Host inválido ou sem ponto (`localhost`) fica sem `form-action`.
- O widget do Help Desk continua trocando a CSP inteira (`frame-ancestors *`) para abrir dentro de outros sites.

## Consequências
- Nenhuma tela muda: nenhum formulário do sistema posta para fora do domínio (conferido nos `action=` dos temas).
- Se um dia algum formulário precisar postar para um banco ou outro serviço, o endereço entra no `form-action`.
- Origem sem porta só vale na porta padrão (conferido no Chromium): o laboratório numa porta própria (ex.: `erp.connect.lab:8443`) leva a mesma porta no `form-action`.
- `script-src` continua pendente para depois do piloto.
- Testes: `SecurityHeadersTest` (cabeçalho completo, domínio base, host estranho fora do cabeçalho, sem `script-src`).
