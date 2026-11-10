# Central de Ajuda e base de conhecimento (ADR 0141)

Toda página ou funcionalidade nova do ERP entra na base de conhecimento **no mesmo PR**. O CI roda
`php tools/help.php check --sem-prints` e falha se uma página do menu (`catalog.php`) ficar sem artigo.

## Onde ficam

- Categorias: `container/web/support/v1/kb/categorias.json`
- Artigos: `container/web/support/v1/kb/artigos/<categoria>/<slug>.md`
- Prints gerados: `container/web/support/v1/assets/kb/<id>.jpg`
- Página pública: `/suporte` (tema `container/web/support/v1`); no ERP, o menu **Suporte** (ícone ?) da barra superior tem "Ajuda desta página", que abre o
  artigo da página atual (`/suporte/tela/{código}`).

## Passo a passo para uma página nova

1. `php tools/help.php scaffold` cria o rascunho do artigo de cada página do menu que ainda não tem (usa o
   inventário das telas, se existir: `node tools/help/shots.mjs --inventario`).
2. Escreva o artigo de verdade: para que serve, onde fica, o passo a passo do fluxo principal, o que preencher,
   dicas e cuidados. Tire `rascunho: sim` quando estiver pronto.
3. Declare os prints com blocos `tela` (abaixo) e gere: `bash tools/help/prints.sh --only <slug>` no Mac, ou
   `node tools/help/shots.mjs --only <slug>` com um servidor local apontando para um banco `*_test`.
4. `php tools/help.php check` (sem `--sem-prints`) precisa passar. Confira os JPEGs antes do commit.
5. Depois do merge, `php tools/help.php sync` publica no banco (o `tools/testar-main.sh` já faz isso).

## Formato do artigo

```
---
codigo: AP-006            # páginas do catálogo cobertas (vírgula); vazio em guias gerais
titulo: Unidades
resumo: Uma ou duas frases (20 a 320 caracteres), aparece na busca e no topo do artigo.
categoria: condominios    # chave de categorias.json; o arquivo fica nessa pasta
rota: /cadastros/unidades
menu: Condomínios > Unidades
icone: albums-outline     # nomes em source/Services/Help/Icons.php
situacao: funciona        # funciona | exemplo | sem-backend | guia
relacionados: proprietarios-e-inquilinos, painel-da-unidade
palavras: apartamento, sala, loja
rascunho: nao
---
```

No texto:

| Escreva | Vira |
| --- | --- |
| `## Título` / `### Subtítulo` | seção (o `##` entra no índice "Navegue pelo conteúdo") |
| `[[Cadastros > Unidades]]` | caminho do menu destacado |
| `{{Salvar}}` | nome do botão destacado |
| `**negrito**`, `` `código` `` | ênfase, texto digitado |
| `> texto` | dica (azul) |
| `>! texto` | atenção (amarelo) |
| `>> Tudo sobre [Unidades](artigo:unidades)` | link de aprofundamento no fim da seção |
| `[texto](artigo:slug)`, `[texto](/caminho)`, `[texto](https://...)` | links (outros esquemas são recusados) |

Print marcado:

````
```tela unidades-novo
rota: /cadastros/unidades/novo
condominio: residencial-jardim-das-acacias   # condomínio da barra superior; sem ele, "Todos"
legenda: Formulário de nova unidade
antes: clicar main button:has-text("Cadastro rápido")   # clicar, preencher sel => valor, escolher sel => opção, rolar, esperar ms
recorte: main form.erp-form                   # janela (padrão), pagina ou um seletor
sessao: nenhuma                               # só para telas sem login
1: menu | Condomínios > Unidades no menu lateral
2: input[name=label] | Número ou nome da unidade
3: .erp-form__actions button[type=submit] | Botão {{Salvar}}
```
````

As marcações usam seletores do Playwright (`:has-text()` vale). `menu` aponta para o item ativo do menu lateral.
Rota que começa com `/!` sai do `/erp` (ex.: `/!/suporte`). Marcação não encontrada aparece no relatório do
`shots.mjs`; `--strict` faz falhar.

## Regras

- Prints só de banco `*_test` com dados fictícios (`tools/help/demo-seed.php`). Nunca do lab ou de produção.
- Não copie texto, imagem ou identidade visual de outras empresas; as referências servem só de inspiração.
- Tela proposta ou sem backend: `situacao: exemplo` ou `sem-backend`, com aviso no artigo.
