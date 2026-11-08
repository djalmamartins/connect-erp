# 0016 — Mapa do código e vigia de 30 minutos

Data: 2026-11-08 · Status: aceito (pedido do Djalma: "seguir sozinho" e "achar tudo fácil")

## Contexto

Várias threads do Claude trabalham ao mesmo tempo no Connect ERP (claude-dev, claude-layout,
cadastros, unidades, vistorias). Cada uma precisava redescobrir onde ficam rotas, controllers,
services e tabelas, e o trabalho parava quando uma sessão terminava sem ninguém para retomar.

## Decisão

1. **Mapa do código** em `docs/map/`, gerado por `python3 tools/code-map.py` (também
   `composer map`). Análise estática, sem PHP nem banco: rotas fixas de `index.php` e de cada
   `container/*/*/v1/routes.php`, classes de `source/` e suas dependências, tabelas das
   migrations (novas e legadas) e quem as usa, testes que citam cada classe, ADRs e páginas do
   catálogo do ERP. Saídas: `README.md` (Mermaid + índices, abre no GitHub) e `code-map.json`.
   `--find <texto>` responde no terminal; `--check` diz se o mapa está desatualizado.
   Rotas registradas em laço (cadastros, catálogo) aparecem só como contagem de linhas dinâmicas.
   Em conflito de merge em `docs/map/`, regenere: os arquivos são gerados.
2. **Fila de tarefas = issues do GitHub** de `djalmamartins/connect-erp`. Rótulos:
   `precisa-djalma` (depende dele; o vigia pula), `em-andamento` (o vigia está nela),
   `prioridade` (vai primeiro).
3. **Vigia** em `tools/claude/vigia/`: um LaunchAgent (`br.com.connect.erp.vigia`, a cada
   1800 s) roda `vigia.sh`, que só age quando nada no repositório, nos worktrees nem nos
   transcripts do Claude Code mudou em 30 minutos. Aí pega a primeira issue da fila e roda o
   Claude Code headless (`claude -p`, modo `auto`, teto de custo e de tempo) num worktree
   próprio (`worktrees/connect-claude-vigia`), branch `vigia/issue-N`, abrindo PR em rascunho
   para o `claude-dev`. Instalação: `bash tools/claude/vigia/instalar.sh`.
4. `database-migrate.php` aceita `--until=AAAAMMDD_NNNN` para aplicar só até uma fatia, e
   `tools/lab-atualizar.sh` faz backup → migrations → rebuild do scheduler → test:integration.

## Consequências

- O vigia nunca faz merge, nunca mexe na main, não roda migrations nem `vendor/bin/phpunit`
  (prompt + guarda `tools/claude/connect-erp-guard.sh`). Merge para a main continua com o Djalma
  ou com uma sessão que ele autorizou.
- O vigia gasta créditos da conta do Claude do Djalma; o teto por rodada é `VIGIA_BUDGET_USD`
  (padrão US$ 15) e `VIGIA_MAX_MIN` (180 min). `instalar.sh --remover` desliga.
- O mapa pode ficar atrás do código entre regenerações; a fonte de verdade é o código.
