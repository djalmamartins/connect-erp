# 0625 — "Sobre esta tela" em todas as páginas, sempre fechado

- Data: 20/11/2026
- Situação: Aceito
- Pedido: Djalma, 20/11/2026 ("sobre essa tela em todas as páginas explicando e levando para o tutorial na base de
  conhecimento... que ela iniciasse fechada; se a pessoa tiver a dúvida, ela clica para abrir").
- Relacionadas: 0309 (quadro e "O que acontece por trás"), 0141 (Central de Ajuda).

## Contexto

O quadro existia só nas 18 telas com explicação escrita à mão (etapa 1 da ADR 0309); as outras 156 páginas do menu
não mostravam nada. Ele nascia fechado, mas o ERP lembrava (localStorage) quem tinha aberto e reabria sozinho nas
visitas seguintes, por isso o Djalma via o quadro aberto.

## Decisão

1. **Sempre fechado.** O `erp.js` não guarda mais "aberto/fechado": toda visita começa só com a barra "Sobre esta
   tela". Continua o X (tira o quadro daquela tela, naquele navegador) e a chave `admin.page_intro`.
2. **Todas as páginas.** `PageGuides::forPage()` usa a explicação escrita à mão quando existe; senão, o `resumo` do
   artigo da Central de Ajuda (CONFIRMADO: as 174 páginas do menu têm artigo publicado).
3. **Seções do tutorial.** O quadro lista as seções do artigo ("No tutorial: Como cadastrar · Pesquisar e filtrar
   ...", sem "Onde fica" e "Quem pode usar"), cada uma com link para `/suporte/tela/{código}#seção`, além de
   "Abrir tutorial completo".
4. **Sem ler 180 artigos a cada página.** Resumos e seções vão para `source/Services/Help/page-summaries.php`,
   gerado por `php tools/help.php resumos`; o `check` (CI) falha se o arquivo divergir dos artigos.

## Acessibilidade

`<details>/<summary>` nativo: abre com Enter ou Espaço, e o leitor de tela anuncia "recolhido/expandido" (o mesmo
efeito de `aria-expanded`). Links com foco visível.

## Fora do escopo / lacunas

- Studio (CMS), Help Desk e apps do morador não têm artigos por tela na base; o quadro fica só no ERP (INFERIDO: é
  onde "faz sentido"). Se o Djalma quiser nos outros, primeiro precisam dos artigos.
- O texto "O que acontece por trás" escrito à mão continua por etapas (ADR 0309); o resumo do artigo é o mínimo.
- Rodapé: telas internas sem código do menu (ex.: estados de erro) não mostram o quadro.
