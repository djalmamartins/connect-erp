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

## Reescrever o passo a passo a partir das telas

Os artigos das páginas do menu são escritos pela combinação de duas fontes:

- **o levantamento real de cada tela**, feito por `node tools/help/details.mjs` (grava `tools/help/.detalhes.json`,
  fora do Git): botões do cabeçalho e o que cada um abre (modal ou formulário, com campos, obrigatórios, dicas e
  botão final), filtros, colunas, ações da linha e avisos;
- **os textos escritos à mão** em `tools/help/textos.php`: título, para que serve, dicas, cuidados, o que fazem os
  botões que levam a outra tela e seções extras.

`php tools/help.php escrever [--only AP-006]` junta as duas e reescreve o artigo (rode duas vezes seguidas para os
títulos dos "Veja também" saírem atualizados). Artigos com `manual: sim` no cabeçalho, como os de Primeiros passos,
Faturas, Boletos e Acordos, não são tocados. Depois, regere os prints e confira.

Quando uma tela mudar: rode o `details.mjs` só nela, ajuste o texto em `textos.php` se preciso, `escrever --only` e
os prints daquele artigo.

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

## "Sobre esta tela" e "O que acontece por trás" (ADR 0309)

Cada página do ERP mostra, logo abaixo do título, o quadro **Sobre esta tela**: o que a tela faz e o que o sistema
faz por trás quando você grava (validações, duplicidades, travas, histórico e o efeito nas outras telas), com o link
para o artigo. O mesmo texto vira a seção `## O que acontece por trás` do artigo.

- Os textos ficam em `source/Services/Help/guides/<etapa>.php`, por código da página: `faz`, `por_tras` (lista) e
  `mais` (só no artigo). Escreva só o que o código faz; confira no serviço da tela antes.
- Depois de mudar um texto: `php tools/help.php explicar` (escreve a seção nos artigos) e `php tools/help.php check`.
  O `check` falha se o artigo ficar diferente da explicação.
- Página nova: escreva a explicação dela no mesmo PR do artigo.
- Todas as páginas têm o quadro (ADR 0625). Tela sem explicação escrita à mão mostra o `resumo` do artigo; todas
  mostram as seções do tutorial ("No tutorial: ...") com link direto. Isso sai de
  `source/Services/Help/page-summaries.php`, gerado por `php tools/help.php resumos`: rode depois de mudar o resumo
  ou os títulos `##` de um artigo. O `check` falha se o arquivo estiver desatualizado.
- O quadro sempre nasce fechado (só o título); a pessoa abre se tiver dúvida. O X tira o quadro daquela tela,
  naquele navegador; em Configurações > Administrativo dá para desligar para todos.
- O guia geral para quem está chegando é `primeiros-passos/como-o-sistema-funciona.md`.
- Cada categoria tem (ou terá, por etapa) o resumo "Como funciona esta área" em
  `container/web/support/v1/kb/areas/<categoria>.md`, mostrado no topo da página da categoria em `/suporte`.
