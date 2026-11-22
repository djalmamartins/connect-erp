# 0635 — Base única de relatório: todo "Imprimir" abre a tela como folha A4 para imprimir ou salvar em PDF

- Status: Aceito
- Data: 22/11/2026
- Pedido: Djalma, 22/11/2026: na tela de consumo da unidade, o Imprimir "abre uma tela muito feia"; quer que todo
  Imprimir abra o relatório como se fosse o próprio PDF, com o mesmo modelo, a partir de uma base padrão de relatório.
- Relacionadas: ADR 0174 (Livro caixa, modelo da faixa verde-escura e dourada), 0302 (recibo e edital do app),
  0306 (PDF da prestação de contas), 0309/0625 (Sobre esta tela), 0628 (seletor de datas)

## Contexto

CONFIRMADO no código (main e67e9c5): 13 telas do ERP tinham o botão Imprimir chamando `window.print()` direto sobre
a tela. A impressão saía com o fundo verde-claro do sistema, os cartões com sombra, sem cabeçalho, sem logo, sem o
condomínio, sem os filtros usados e sem a data de emissão. Os documentos que já têm arquivo próprio (Livro caixa,
PDF da prestação de contas, relatório de vistoria, recibo e edital do app) já seguiam um visual de documento; o do
Livro caixa (faixa verde-escura com borda dourada e o logo Connect) é o mais completo.

## Decisão

1. **Modo relatório pela própria tela.** `?imprimir=1` em qualquer tela do ERP (`Source\Support\Erp\ReportSheet`)
   faz a moldura (`default.php`) trocar o menu e a barra superior pela folha do relatório
   (`components/report/sheet.php`): barra com Voltar e "Imprimir ou salvar em PDF" (some na impressão), folha A4 na
   tela e rodapé da Connect. Os dados são os mesmos da tela, pelo mesmo controlador e com as mesmas permissões:
   nada novo é consultado e nenhuma rota nova foi criada.
2. **Cabeçalho único.** No modo relatório, o `page-head` de cada tela vira `components/report/head.php`: faixa
   verde-escura (`--erp-sidebar`) com borda dourada (`--erp-accent`) e logo Connect, grupo e título da tela,
   condomínio da barra superior, "Emitido em" e "Emitido por". O quadro "Sobre esta tela" e as ações saem.
3. **Filtros viram parâmetros.** O erp.js lê os campos do `form.erp-filters` pelo rótulo (inclusive o mês do
   seletor de datas, ADR 0628) e escreve "De / Até / Unidade..." abaixo do cabeçalho. Botões, abas, paginação e
   filtros ficam escondidos no relatório (CSS `.erp-report-sheet`).
4. **Todo botão Imprimir de tela usa `data-erp-print`**, que abre a mesma URL com `imprimir=1` numa aba nova (na
   mesma aba se o navegador bloquear). O teste de renderização falha se uma tela do ERP voltar a chamar
   `window.print()` direto (exceto os documentos com arquivo próprio e a página pública).
5. **Impressão:** `@page` A4 com margens, número da página no canto (navegadores que suportam caixas de margem),
   cores preservadas (`print-color-adjust: exact`), cabeçalho das tabelas repetido em cada página.
6. **App do morador:** a moldura de impressão do recibo e do edital (`container/app/connect/v1/print.php`) ganhou a
   mesma faixa verde-escura e dourada e a folha A4.

## Fora do escopo

- Documentos com arquivo próprio (Livro caixa, PDF da prestação de contas, vistoria) continuam com o modelo deles,
  que já é o desta base. Gerar PDF no servidor (dompdf) para todas as telas não foi feito: a base usa o "Salvar
  como PDF" do navegador, o que mantém gráficos e tabelas idênticos aos da tela.
- Telas com paginação imprimem a página aberta (a paginação some, os itens das outras páginas não entram).

## Testes

- `tests/erp/render-smoke.php`: modo relatório no gráfico de consumo da unidade (sem menu, faixa, título,
  parâmetros, emissão, rodapé, mesmo conteúdo) e nenhuma tela do ERP com `window.print()` direto.
- `tests/legacy/Unit/ReportSheetTest.php`: ativação só com `imprimir=1`, URL mantendo os filtros e nome de quem
  emitiu.
- Conferido no Chromium: PDF A4 do gráfico de consumo da unidade (antes e depois em
  `/mnt/project-files/impressao-relatorio/`).
