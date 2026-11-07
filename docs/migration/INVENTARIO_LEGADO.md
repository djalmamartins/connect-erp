# Inventário do legado WEB/Studio

Auditoria em 07/11/2026. Origem: `public_html (1).zip` (304 MB) e
`u807812373_db.sql` (2 MB). Os arquivos de origem permanecem fora do Git.

## Classificação

- 11.472 entradas no ZIP; 9.662 arquivos e nenhum caminho inseguro.
- 6.044 entradas `__MACOSX`: metadados, descartados.
- 3.016 entradas `vendor`: dependências reproduzidas por `composer.lock`.
- `storage/backups`: histórico, não usado como código ativo.
- Código ativo: 86 arquivos em `source`, 127 em WEB, 138 em Studio, 53
  artefatos de banco, 15 testes e 11 commands/jobs/workers.
- Assets WEB/Studio e Organic V2 identificados e migrados sem redesign.
- Dados gerados de `storage/images`, `files` e `uploads` foram copiados apenas
  para o ambiente local e são ignorados pelo Git.

## Núcleo identificado

PHP 8.2+, Composer 2, PDO MySQL, MovesCode Router/View/Storage/Compress/SEO,
Monolog, PHPMailer e Dompdf. O grafo estrutural do corpus ativo encontrou 984
nós, 2.370 relações e 162 comunidades. Pontos centrais: `url()`, `User`,
`Model`, `AppCondominium`, `Studio`, `Auth`, `Connect` e `theme()`.

## Funcionalidades

| Área | Funcionalidade | Estado |
|---|---|---|
| WEB | Home, proposta, páginas legais, artigos, busca e categorias | migrado/testado por HTTP |
| WEB | SEO, menus, responsividade, CSS/JS/imagens | migrado; assets testados |
| CMS | Login/logout, dashboard e busca | migrado; acesso anônimo testado |
| CMS | Posts, categorias, páginas, mídia e slides | migrado; CRUD automatizado parcial |
| CMS | Usuários/ACL, notificações, relatórios, logs e configurações | migrado; testes automatizados |
| CMS | Propostas, agenda, suporte e versões | migrado; testes automatizados parciais |
| E-mail | Fila, PHPMailer e worker | identificado/migrado; SMTP pendente |
| Bancário | Adaptador Banco Inter | identificado, não ativado; credenciais ausentes |
| ERP | Código do Connect ERP existente | preservado, não sobrescrito |

O grafo registrou três arestas pendentes e 26 SQLs sem parsing estrutural por
ausência de `tree_sitter_sql`; os SQLs foram inspecionados e o dump foi validado
diretamente pelo MariaDB.
