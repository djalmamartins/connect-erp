# Mapeamento WEB

Tema: `container/web/default/v1/`. O HTML, classes, layouts, pages, CSS, JS,
fontes e imagens vieram do código ativo do pacote. `default.php` documenta a
composição e `routes.php` contém o registro no MovesCode Router.

Rotas confirmadas: `/`, `/solicite-sua-proposta` (GET/POST),
`/politica-de-privacidade`, `/termos-de-uso`, `/artigos/`, paginação, post,
busca e categoria, além de `/ops/{errcode}`. Foram identificados 13 registros
GET/POST. Home, proposta, legais e artigos retornaram HTTP 200.

O conteúdo dinâmico permanece nas tabelas `settings`, `pages`, `posts`,
`categories`, `slides`, `categories_slide`, `brief`, `proposals` e
`proposal_responses`. SEO continua usando MovesCode SEO e os valores do banco.

O domínio externo indicado pelo legado não estava acessível pela ferramenta de
verificação. Assim, a fidelidade foi comprovada contra os templates/assets
fornecidos, não por comparação pixel a pixel com produção.
