# 0141 — Base de conhecimento com prints marcados e Central de Ajuda pública

Data: 2026-11-09 · Status: aceito (pedido do Djalma; decisões tomadas sem ele, por delegação) · Central de Ajuda (/suporte)

## Contexto

O Djalma pediu que cada página ou funcionalidade nova vire um artigo da base de conhecimento do ERP, e que a página
de suporte pública (separada do site) tenha tutoriais com print real da tela e marcações mostrando onde clicar, e
não só texto. As referências enviadas (páginas de contato e de artigos de outra empresa) serviram só de inspiração
de organização: busca no topo, canais de atendimento, formulário de mensagem, artigo com trilha, caminho do menu
destacado, dicas, índice lateral, artigos relacionados e "Foi útil?". Nada foi copiado.

Já existiam o tema público `container/web/support/v1` (tabelas `support_categories`, `support_articles`,
`support_article_votes`) e o Help Desk (`TicketService`), com o widget de chamados.

## Decisão

1. **Artigos em arquivos versionados no Git**: `container/web/support/v1/kb/artigos/<categoria>/<slug>.md`, com
   cabeçalho `chave: valor` (código das páginas do catálogo, título, resumo, categoria, rota, menu, ícone, situação,
   relacionados, palavras, rascunho) e um subconjunto de Markdown seguro (`ArticleRenderer`): tudo é escapado antes;
   `[[Menu > Tela]]` vira o caminho do menu, `{{Botão}}` o nome do botão, `>` dica, `>!` atenção, `>>` "Tudo sobre",
   e links só para `artigo:`, `/caminho` ou `https://`.
2. **Prints declarados no artigo** em blocos ```` ```tela <id> ````: rota, condomínio do contexto (ADR 0030), ações
   antes do print (clicar, preencher, escolher, rolar, esperar), recorte e marcações numeradas (`n: seletor | texto`).
   `tools/help/shots.mjs` (Playwright) entra no ERP, desenha anel dourado e número em cada marcação, salva o JPEG em
   `assets/kb/` só quando muda e avisa marcações que não encontrou. Assim os prints acompanham as telas: basta rodar
   de novo. Só aceita host local (ou `HELP_ALLOW_REMOTE`), e os prints são tirados de um banco `*_test` com dados
   fictícios criado por `tools/help/demo-seed.php` (que recusa outro banco). Banco do laboratório ou de produção
   nunca aparece em print público.
3. **Cobertura obrigatória**: `php tools/help.php check` falha quando uma página do catálogo do menu
   (`catalog.php`) não tem artigo, ou quando um artigo tem formato inválido, link quebrado ou print faltando. O teste
   unitário `HelpCenterKnowledgeBaseTest` roda a mesma checagem (sem exigir os JPGs) no CI. `scaffold` cria o rascunho
   do artigo a partir do inventário das telas (títulos, botões, filtros, colunas e campos do motor de cadastros), para
   o autor completar.
4. **Publicação por sincronização**: `php tools/help.php sync` grava categorias e artigos nas tabelas existentes
   (migração `20261109_0901`: `kb_key`, `kb_hash`, `kb_meta`), em uma transação, sem tocar artigos escritos à mão no
   painel (recusa se a URL já for de um artigo de fora da base). Artigo removido da base vira rascunho, não é apagado.
5. **Página pública** no tema do suporte, com a identidade da Connect (logo dourada, verde-escuro, Inter) e ícones SVG
   embutidos (sem CDN de terceiros): busca por várias palavras, canais (chat do Help Desk, WhatsApp quando
   configurado, central, chamado), "Deixe a sua mensagem" que abre um chamado no Help Desk
   (`TicketService::open(..., 'site')`, token assinado do widget ou CSRF, honeypot e limite de 5 por hora por IP),
   artigo com índice, relacionados, bloco "Precisa de ajuda?" e "Não encontrou o que procurava?".
6. **"Ajuda desta página" no ERP**: ícone na barra superior leva a `/suporte/tela/{código}`, que redireciona para o
   artigo publicado da página (ou para a Central, quando não há).

## Consequências

- Toda página nova no menu precisa de artigo no mesmo PR, ou o CI falha. O processo está em
  `docs/process/HELP_CENTER.md` e no Definition of Done.
- Os rascunhos gerados descrevem a tela, mas os fluxos principais precisam de texto escrito à mão; a fila está no
  processo. Os JPGs entram no Git (cerca de 100 KB cada); se crescer demais, mover para o Disco Virtual.
- Telas que ainda são proposta ou sem backend mostram aviso no artigo (`situacao: exemplo|sem-backend`).
