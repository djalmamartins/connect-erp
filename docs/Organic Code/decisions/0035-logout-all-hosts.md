# 0035 — Sair em um host encerra as sessões do usuário nos outros

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma; aguarda migration no container)

## Contexto

O ERP (`erp.<domínio>`), o Studio (`studio.<domínio>`) e o Help Desk têm cookies de sessão
separados (isolamento por hostname). Com a troca de ambiente do ADR 0034, o mesmo usuário fica
logado em vários hosts, e o botão "Sair" de um deles não encerrava os outros (pendência do PR #42).
Compartilhar o cookie no domínio pai juntaria as sessões, mas quebraria o isolamento entre hosts.

## Decisão

- **Tabela `user_session_revocations`** (migration `20261109_0101`, reversível): uma linha por
  usuário com o instante (ms) do último "Sair".
- **`Auth::logout(true)`** grava esse instante (`SessionRevocation::revokeAll`). Usado pelos botões
  de sair do ERP (`POST /sair`) e do Studio/Help Desk (`/studio/sair`, `/helpdesk/sair`).
- **`Auth::user()`** compara o instante do login guardado na sessão (`authAt`) com o último "Sair":
  sessão iniciada antes dele é encerrada no próximo pedido, em qualquer host.
- Sessão sem `authAt` (aberta antes desta regra ou criada pela troca de ambiente) recebe o instante
  atual no primeiro pedido e passa a ser revogável dali em diante.
- Logouts forçados (sem permissão para o ambiente, 2FA pendente) continuam só no host atual, para
  não derrubar o usuário de um ambiente em que ele tem acesso.
- **Falha aberta**: sem a tabela, registra `SESSION_REVOCATION_UNAVAILABLE` e o "Sair" vale só no
  host atual, como antes.

## Consequências

- "Sair" encerra as sessões do usuário em todos os hosts e também em outros navegadores e
  aparelhos. Aceito: é o comportamento mais seguro e o esperado para "Sair".
- Uma consulta por chave primária a mais por pedido autenticado (com cache no pedido).
- `/studio/sair` e `/helpdesk/sair` são GET sem CSRF: um site externo pode forçar o logout. Já era
  assim; agora o efeito vale para todos os hosts. Trocar por POST com CSRF fica para a frente de layout.
- Testes: `SessionRevocationTest` (SQLite em memória). Integração com MariaDB na issue #2.
