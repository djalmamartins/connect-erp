# 0623 — Token de integração com escopos, criado e revogado na tela Integrações

- Status: Aceito
- Data: 20/11/2026
- Relacionados: ADR 0308 (integração com o Connect Talk e token `ctk_`), 0386 (2ª via do boleto para o Talk), 0359 (Talk
  de volta ao Raspberry Pi do escritório), 0091/0158 (tela Integrações), 0328 (relatório do titular).
- Numeração: faixa avulsa 0600–0799. Nasceu como 0611, renumerada para 0623 porque outra thread mesclou o 0611 (desempenho) antes.

## Contexto
Pedido do Djalma (20/11/2026): "a API REST completa, pronta, para o ConnectTalk pegar todas as informações que forem
necessárias, com um token de acesso", trabalhando "com calma". A proposta (Claude Doc "API do ERP para o Connect Talk —
proposta") divide em 4 etapas e não abre a API inteira do ERP para o token: as rotas da equipe devolvem CPF e o
financeiro completo, mais do que o atendimento por WhatsApp precisa (LGPD). A API `/api/v1/talk` cresce.

Até aqui o token só nascia no terminal (`tools/talk-token.php`), não vencia e valia para todas as rotas `/talk`.
Esta é a etapa 1: o token.

## Decisão
1. **Escopos.** `erp_integration_tokens.scopes` (migration `20261120_2100_integration_token_scopes`, com `.down.sql`).
   `TalkTokens::SCOPES`: `condominiums.read`, `people.read`, `residents.read`, `charges.read` (leitura) e
   `tickets.write` (escrita). Cada rota pede o seu (`Talk::agent($scope)`): pessoas = `people.read`, 2ª via =
   `charges.read`, abrir/transferir/encerrar = `tickets.write`. Sem o escopo = **403 `insufficient_scope`** com o escopo
   que faltou. Fora do middleware (sem token na requisição), nenhum escopo vale.
2. **O token que já existe não para.** O `DEFAULT` da coluna é `people.read,charges.read,tickets.write`
   (`LEGACY_SCOPES`), o que o Talk do Pi já usa. O terminal continua criando com esses três, ou com `--escopos=`.
3. **Validade e IPs por token.** `expires_at` opcional (30, 90, 180 ou 365 dias na tela; vencido = 401) e `allowed_ips`
   opcional, conferido com `FILTER_VALIDATE_IP` (fora da lista ou sem IP = 401). `TALK_ALLOWED_IPS` do `.env` continua
   valendo para todos.
4. **Limite por token.** `TalkTokens::RATE` = 120 pedidos por minuto por token (`IpRateLimit` com a chave do token),
   429 com `Retry-After: 60`. O limite de falhas por IP (20 em 15 minutos) e o de 60 consultas de boleto por minuto
   continuam.
5. **Registro.** `last_ip` ao lado de `last_used_at` (gravados no máximo a cada 5 minutos, ou quando o IP muda). Criar e
   revogar vão para a auditoria com o final do token, escopos, validade e IPs; o token em si nunca.
6. **`GET /api/v1/talk/me`** (sem escopo): nome, escopos, validade, limite e condomínios do usuário de integração, para o
   Talk conferir o token ao ligar.
7. **Tela Configurações › Integrações (AP-171), quadro "Tokens de acesso (Connect Talk)".** Lista para quem tem
   `erp.settings.view` (nome, final, usuário, escopos, validade, último uso e IP, situação). Quem tem
   `erp.settings.manage` cria (nome, usuário de integração, validade, escopos com leitura marcada e escrita desmarcada,
   IPs) e revoga, com a modal do sistema. Escrita marcada pede confirmação. O token aparece **uma vez**, na resposta, com
   botão de copiar. API da tela: `GET/POST /api/v1/integration-tokens` e `POST /api/v1/integration-tokens/{id}/revoke`
   (sessão + CSRF).
8. **Usuário de integração** continua sendo um usuário comum, ativo e com `support.manage`; a tela só oferece esses. O
   escopo de condomínios dele continua valendo para tudo.

## Fora desta decisão (próximas etapas da proposta)
- Etapa 2: `/talk/condominiums`, unidades, áreas comuns e comunicados (`condominiums.read`).
- Etapa 3: `/talk/residents/{id}/...` com o telefone conferido no cabeçalho `X-Resident-Phone`, reaproveitando as regras
  do app do morador (`residents.read`).
- Etapa 4: mensagem do morador dentro do chamado e `GET /talk/tickets?updated_since=`.
- PENDENTE [Djalma]: o Talk vai poder fazer coisas pelo morador (reserva, visita, ocorrência)? Inquilino vê boleto?
  Qual código do Talk recebe a integração (`djalmamartins/talk` do Pi ou `connecttalk-v2` do Mac)?

## Consequências
- Quem cuida da Connect cria e revoga o token do Talk sem terminal, e um token vazado pode ser só de leitura, vencer e
  ficar preso a um IP.
- Com o Talk atrás do Cloudflare Tunnel, o IP visto pelo ERP é o da Cloudflare: a lista de IPs por token só serve se o
  Talk sair por um IP fixo. A tela avisa.
- Testes: `tests/legacy/Unit/IntegrationTokenScopesTest.php` (escopos, IPs, validade, limite, rotas, OpenAPI, tela,
  migration) e `tests/legacy/Integration/IntegrationTokensTest.php` (token antigo com os 3 escopos, 403 sem escopo,
  `/talk/me`, vencido, IP fora, tela sem hash, revogar, permissão).
- Central de Ajuda: artigo da tela Integrações com a seção "Tokens de acesso".
