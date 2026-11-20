# 0600 — Desempenho para muitos moradores ao mesmo tempo e teste de carga

- Status: Aceito
- Data: 20/11/2026
- Relacionados: ADR 0302/0303 (app do morador), 0326 (limite por IP), 0348 (teste de carga do login), 0358 (fila de
  e-mail no cron), 0391 (Cache-Control private nos downloads)

## Contexto
O Djalma pediu para avaliar uma lista de 30 dicas de desempenho e testar o sistema com 3 mil e depois 10 mil pessoas
ao mesmo tempo (meta: 6 mil). O teste rodou numa máquina na nuvem de 4 núcleos, como a Cloud Startup, com PHP-FPM de
100 processos, Apache lendo o `.htaccess` e MariaDB 10.11, num banco só de teste (`connect_lab_carga`) com dados
fictícios: 628 condomínios, 10.024 unidades, 10 mil moradores com conta, 237 mil lançamentos e 173 mil boletos.

Achados (CONFIRMADO no teste):
1. As três consultas do portal do morador que ligam a conta ao boleto (`ResidentPortal`: Início, Boletos e detalhe)
   usavam `b.active_key = 1 AND (b.entry_id = e.id OR b.id = (subconsulta))`. O `OR` impede o índice e o banco lia
   todos os boletos para cada linha. Com 3 mil moradores, 60% dos pedidos deram erro; um morador sozinho esperava
   0,9 s com 173 mil boletos.
2. Toda página web, ao terminar, processava até 5 e-mails da `mail_queue` por SMTP, prendendo o processo PHP.
3. `ResidentPortalPermissions::enabled()` fazia uma consulta por função; uma tela do app fazia ~20.
4. A lista de posts do site lia a mesma categoria uma vez por post (N+1).
5. O `.htaccess` não ligava compressão nem cache dos arquivos estáticos.
6. O site carregava o Google Analytics antigo (Universal Analytics, `UA-`), desligado pelo Google desde 2023, antes do
   consentimento de cookies; e a fonte de ícones Material Symbols com todos os eixos variáveis.
7. O `fetch` do app nativo não tinha prazo; `AppBankInter` (cliente antigo do Inter) não tinha timeout nem uso;
   `monolog/monolog` estava no `composer.json` sem uso.

## Decisão
1. As três consultas passam a `b.id = COALESCE((boleto ativo do lançamento), (boleto único da fatura))`: duas
   subconsultas por índice. Mesmo resultado linha a linha no teste (quando há os dois, vale o boleto do próprio lançamento).
2. A fila de e-mail no fim da página só roda fora da produção (`AppLogger::sendsMailInline()`); com
   `ERP_MAIL_DELIVERY=production` o `tools/hosting/cron.sh` já envia a cada minuto.
3. `enabled()` lê as funções do condomínio de uma vez e guarda durante a requisição web; `set()` limpa. No terminal e
   nos testes continua lendo do banco. `Post::category()` faz o mesmo com a categoria.
4. `.htaccess`: compressão (`mod_deflate`) de texto, JSON e SVG; estáticos com `?v=` por 1 ano (`immutable`), sem `?v=`
   por 1 dia. Páginas e API continuam com o `Cache-Control` que o PHP manda.
5. Sai o `gtag` antigo do site; Material Symbols só no estilo usado (24, 400, sem preenchimento).
6. App nativo: prazo de 20 s por pedido (60 s no envio de foto), com a mensagem "O servidor demorou para responder".
7. Miniaturas (`Thumb`) em WebP quando o GD sabe gravar.
8. Saem `AppBankInter` e o pacote `monolog/monolog` (e o `psr/log`, que vinha com ele).
9. O roteiro do teste fica em `tools/hosting/carga/` (cópia dos dados fictícios e k6 misto por degraus).

## Resultado do teste (k6, degraus de 2 em 2 mil pessoas, 3 min em cada)
Ritmo do teste: cada pessoa abre uma tela a cada 5 a 15 s, sem parar (bem mais intenso que o uso comum). O gerador de
carga dividiu os 4 núcleos com o servidor.

| Pessoas ao mesmo tempo | Pedidos por segundo | Erros | Mediana | 95% em até |
| --- | --- | --- | --- | --- |
| 3.000, antes da correção dos boletos | 42 | 60% | 60 s | 60 s |
| 2.000 | 295 | 0% | 9 ms | 22 ms |
| 4.000 | 456 | 3,1% | 42 ms | 0,6 s |
| 6.000 | 519 | 14,4% | 28 ms | 0,2 s |
| 8.000 | 555 | 20,6% | 55 ms | 0,7 s |
| 10.000 | 587 | 25,2% | 55 ms | 0,6 s |

Com as consultas corrigidas o banco deixou de ser o gargalo; o limite é o processador. Os erros são quase todos
logins (`POST /api/v1/resident/app-sessions` e `/login`) que passam do prazo de 60 s: cada login confere a senha com
bcrypt e, quando falha, a pessoa tenta de novo. Num ritmo de uma tela a cada 30 s, 6 mil pessoas dão ~200 pedidos por
segundo, dentro do que passou sem erro. Com o ritmo intenso do teste, 6 mil pessoas precisam de mais processador.

## Fora desta mudança (PENDENTE)
- Ionicons do Studio vindos do unpkg.
- `loading="lazy"` nas imagens do site e "medir a velocidade dos usuários reais".
- Conferir na Hostinger o limite real de processos PHP e de conexões do banco com um teste curto antes de abrir aos
  moradores.
