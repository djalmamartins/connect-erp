# 0002 — Help Desk e Central de Suporte como temas próprios

Data: 2026-11-07 · Status: aceita

## Contexto

No legado, o atendimento tinha duas partes:

- **Help Desk** (`/helpdesk`): fila de chamados, agenda da equipe, base de
  conhecimento, usuários, configurações, versões e logs. O controller
  `Source\Controllers\Studio\Studio` atende Studio e Help Desk e escolhe a
  identidade pela URL; o tema legado `container/apps/helpdesk/default` era uma
  cópia dos componentes do Studio que o controller nunca usava.
- **Central de Suporte pública** (`/suporte`): artigos por categoria, busca,
  avaliação e widget embutível para abrir e acompanhar chamados.

Na primeira migração, as telas do suporte foram parar dentro do tema WEB
(`container/web/default/v1/support` e `_support.php`), enquanto o controller
`Web\Support\Support` apontava para `container/web/support/v1`, que não
existia. Resultado: `/suporte` e `/helpdesk` sem rotas no Connect.

## Decisão

1. `container/web/support/v1/` é o tema da Central de Suporte:
   `default.php` e `routes.php` na raiz; `layouts/theme.php`; telas em
   `pages/` (home, search, category, article, widget); `assets/support.css`
   (base do WEB + faixa institucional) e `assets/images/`.
2. `container/cms/helpdesk/v1/` é o ambiente do Help Desk no CMS, com apenas
   `default.php` e `routes.php`. Layouts e components continuam únicos em
   `container/cms/studio/v1/`, sem duplicação.
3. `moves_container_path/url('helpdesk', …)` resolvem para
   `container/cms/helpdesk/v1`. `index.php` carrega `routes.php` dos dois temas.
4. O controller do suporte renderiza `pages/*` e o widget compartilhado
   (`container/shared/assets/js/helpdesk-widget.js`) usa os assets em
   `container/web/support/v1/assets`.

## Consequências

- Manutenção do suporte isolada do site institucional.
- As tabelas (`studio_support_*`, `studio_calendar_events`, `support_*`) já
  têm migrations em `database/legacy-migrations/migrations`; o dump do legado
  não é importado.
- Os métodos `support*` de `Web\Connect\Web` não têm rotas e ficam como
  candidatos a remoção numa limpeza futura.
