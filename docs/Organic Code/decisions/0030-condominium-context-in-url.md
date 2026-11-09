# 0030 — Condomínio da barra superior como contexto global do ERP, com slug na URL

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O seletor "Condomínio" da barra superior gravava a escolha na sessão, mas nenhuma tela a usava:
listas, filtros e dashboard continuavam mostrando todos os condomínios. O Djalma pediu que todas as
telas respondam ao condomínio escolhido, que a URL mostre qual é
(`erp.connect.lab/residencial-exemplo/cadastros/sindico-e-conselho`), que "Todos" dê a visão geral
e que escolher um condomínio no filtro de uma tela troque também a barra superior.

## Decisão

- **Slug na URL, logo depois da base do ERP** (`/` no host do ERP, `/erp` nos outros hosts). Sem
  slug, o contexto é "todos os condomínios do escopo". `Source\Support\Erp\CondominiumContext`
  tira o slug do `REQUEST_URI` antes do dispatch (`index.php`), então as rotas não mudam.
- **O slug vem do nome**, sem coluna nova nem migration: minúsculas, sem acentos, hífens.
  Nome repetido, só com números ou igual a uma rota do ERP ganha o id no fim
  (`residencial-exemplo-12`). As rotas reservadas saem do próprio router
  (`Source\Core\Router::firstSegments()`), então um slug nunca esconde uma rota. Se o nome do
  condomínio mudar, o slug antigo deixa de valer (responde 404); a URL sem slug continua valendo.
- **`erp_url()` acrescenta o slug do contexto** em todos os links. Login, sair, segurança, a troca
  de contexto e as rotas do Studio/API não levam slug (`CONTEXT_FREE`). `erp_context_url()` monta o
  link para outro condomínio (ou `null` = todos).
- **Escopo no servidor**: o slug é conferido contra `CondominiumScope` a cada requisição. Slug fora
  do escopo (ou de condomínio inativo) responde 404, sem revelar se o condomínio existe. O contexto
  só restringe o que o escopo já permite; os serviços continuam aplicando o escopo.
- **Redirecionamentos (GET)**, para as URLs antigas continuarem funcionando:
  - URL sem slug com um condomínio na sessão → a mesma URL com o slug dele;
  - `/todos/...` → a URL sem slug, e limpa a sessão;
  - `?condominio=<id>` (filtro das listas, links "ver deste condomínio") → troca o contexto e tira o
    parâmetro; `?condominio=` vazio volta para "todos". Id fora do escopo é ignorado.
- **Troca pela barra superior** (`POST /contexto/condominio`, com CSRF): volta para a mesma tela
  com o novo slug. Em detalhe, edição ou cadastro novo (`/…/{id}`, `/novo`) volta para a lista,
  porque o registro aberto é de outro condomínio.
- **Telas que respondem ao contexto nesta fatia**: cadastros do motor único (lista e filtro;
  novo registro já vem com o condomínio), Condomínios (lista mostra só o escolhido, com link para
  todos; abrir a ficha de um condomínio troca o contexto), Vistorias (devidas, recentes e nova) e
  Dashboard (resumo com dados reais dos cadastros do condomínio ou de todos; a tabela de todos os
  condomínios some quando um está escolhido). Abrir um registro de outro condomínio troca a barra
  superior para o dele.
- O filtro "Condomínio" das listas continua na tela, para facilitar a busca, e passa a mostrar o
  condomínio do contexto.

## Consequências

- As páginas do catálogo ainda com dados de demonstração (financeiro, relatórios etc.) mostram o
  condomínio na barra superior e nos links, mas os números delas não mudam até ganharem dados reais.
  Cada fatia nova deve ler `CondominiumContext::id()` em vez de `$_GET['condominio']`.
- A API REST (`/api/v1`) não usa o contexto: o cliente continua mandando `condominium_id`.
- Um condomínio renomeado muda de URL; links salvos com o slug antigo dão 404. Se isso incomodar,
  a evolução é uma coluna `slug` fixa (migration nova).
