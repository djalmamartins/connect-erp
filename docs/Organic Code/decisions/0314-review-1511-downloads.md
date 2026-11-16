# 0314 — Revisão de 15/11, etapa 5: download em PDF e Excel, ocultar unidade, inadimplentes com data inicial e prestação de contas

- Status: Aceito (parte 1, item 18; parte 2, item 20; parte 3, itens 19 e 21)
- Data: 16/11/2026
- Issue: #544 (revisão de 15/11, etapa 5)
- Relacionadas: ADR 0081 (CSV dos relatórios), 0087 (limite de exportação, B5), 0174 (PDF com dompdf), 0310 (itens por página)

## Contexto

Na revisão de 15/11 (item 18) o Djalma pediu o botão Download (PDF e Excel) em todas as páginas com lista, no padrão
do modelo 3: cabeçalho da Connect, condomínio, título, data e rodapé com o site e o número da página. Até aqui os
relatórios só baixavam CSV ("Exportar CSV") e as listas dos cadastros não baixavam nada.

## Decisão

1. **Um gerador só** (`Source\Services\Erp\ListDocument`) recebe a mesma tabela do CSV (`{header, rows}`, células já
   saneadas por `csvCell`) e devolve CSV (o de sempre, sem mudança), PDF ou Excel.
   - **PDF** (dompdf, A4 deitado, sem acesso remoto): à direita o nome, o CNPJ e o endereço da Connect (CONFIRMADO no
     modelo 3; o endereço vem das configurações do site quando preenchidas), a logo à esquerda, a data, o condomínio
     ("NOME (Cidade / BAIRRO)" ou "Todos os condomínios") e o título no centro, a tabela com o cabeçalho repetido em
     cada página e, no rodapé, o site e o número da página. Até 5.000 linhas (avisa quando corta).
   - **Excel** (.xlsx feito com ZipArchive, sem biblioteca nova): as mesmas linhas de cabeçalho, a tabela com o
     cabeçalho congelado e valores no formato 1234,56 como número com duas casas (somam na planilha). O resto vai como
     texto, que nunca vira fórmula.
2. **Relatórios** (as 12 rotas da API que já tinham `?format=csv`): `format` aceita também `pdf` e `xlsx` (e `excel`);
   `title` opcional põe o nome da tela e o período no documento (texto simples, até 120 caracteres).
3. **Listas dos cadastros** (motor de cadastros, todas as listas de cadastros, lançamentos e contas): `?baixar=pdf|xlsx|csv`
   na própria rota da tela, com os filtros da tela, juntando as páginas até 10.000 linhas. As colunas são as da tela;
   dinheiro sai como número.
4. **Botão Download** (componente `components/tables/download`): menu com PDF, Excel e CSV no lugar de "Exportar CSV"
   nos relatórios, no balanço patrimonial e nas assembleias, e no alto das listas dos cadastros quando há linhas.
5. **Segurança**: a mesma permissão e o mesmo escopo da tela ou da API; o limite de 30 downloads por hora por pessoa
   (ADR 0087) vale para os três formatos; cada download fica na auditoria (`export`, `erp_reports`) com o formato.

PENDENTE: as telas de exemplo (`modules/list`, `modules/report`, `modules/chart`) continuam com o botão "Exportar"
pendente até terem dados reais. INFERIDO: o Excel leva as linhas de cabeçalho do PDF no topo da planilha, para o
arquivo ficar igual ao modelo quando impresso.

6. **Inadimplentes com data inicial opcional** (parte 2, item 20): os relatórios de inadimplentes (AP-057 a AP-061)
   ganharam "Vencidas a partir de" (`?inicio=` na tela, `from` em `GET /api/v1/reports/delinquents`). Em branco traz
   todas as vencidas até a data de referência, como antes; preenchida, só as que venceram a partir dela. Data inválida
   ou depois da referência é recusada (422). O filtro fica em `Agreements::delinquency` (parâmetro opcional), então o
   CSV, o PDF e o Excel saem com o mesmo corte. INFERIDO: o Djalma falou do "geral"; a data vale para as cinco visões,
   que usam a mesma consulta.

7. **Ocultar unidade** (parte 3, item 19): caixa "Ocultar unidade" nos demonstrativos com coluna de unidade
   (AP-053 demonstrativo analítico, AP-068 e AP-069 lançamentos, AP-070 boletos, AP-074 pagamentos e AP-071
   recebimentos) e na prestação de contas da tela (AP-101, lista de inadimplência). A tela tira a coluna Unidade e o
   Download leva `hide_unit=1`, que tira a coluna "Unidade" do CSV, do PDF e do Excel (`ListDocument::withoutUnit`).
   O link público da prestação de contas (ADR 0301) já mostra o recebimento das unidades só como soma. INFERIDO: a
   caixa não aparece em relatórios que são da unidade (rateio, inadimplentes por unidade, lista de unidades), onde
   tirar a unidade deixaria a lista sem sentido. A prestação de contas nova (item 21) leva a mesma opção.

8. **Prestação de contas igual ao PDF de outubro** (parte 3, item 21): na tela Prestação de contas (AP-101):
   - **lista dos meses**: os 12 últimos meses, com a situação do fechamento, quantos pagamentos houve e os botões
     Visualizar e PDF;
   - **visualização**: `GET /prestacao-de-contas/prestacao-de-contas/documento?mes=AAAA-MM` abre o documento no
     navegador, para conferir e imprimir;
   - **PDF**: `.../pdf?mes=AAAA-MM` baixa o arquivo (dompdf, A4 deitado). Cada parte começa numa página nova, com o
     cabeçalho do modelo 3. As partes são capa, termo de abertura, parecer do síndico e conselho, demonstrativo e
     relatório de lançamentos.
   - O **termo de abertura** usa os dados do condomínio.
   - O **parecer** traz o síndico e os conselheiros fiscal, consultivo e subsíndico ativos no mês (cadastro Síndico e
     conselho), com linhas para assinar.
   - O **demonstrativo** mostra as receitas e despesas pagas no mês por grupo (a conta-pai do plano) e conta, com
     total do grupo e total geral. Depois vêm o resumo e o resumo das contas bancárias.
   - O **relatório de lançamentos** lista as despesas pagas no mês. Cada uma vem depois numa página, com a linha do
     lançamento e os comprovantes: imagem dentro do PDF, e anexo em PDF citado, porque o dompdf não junta PDFs.
   - Tudo sai do livro caixa do mês (`CashBook`, ADR 0174). Serviço `AccountabilityDocument`, só leitura.
   - Segurança: mesma permissão da tela (erp.entries.view e erp.chart.view), condomínio da barra, limite de 30 por 10
     minutos e auditoria (`export`, `accountability-document`).
   - Ocultar unidade tira a unidade das receitas do demonstrativo.
   - PENDENTE: as colunas "Sobras de rateio" e "Fundo de reserva" do relatório de lançamentos do modelo (o sistema não
     separa esses valores por despesa) e juntar os anexos em PDF dentro do mesmo arquivo.
   - INFERIDO: "Lançamento" na linha do comprovante é o vencimento.

## Testes

- `tests/legacy/Unit/ListDocumentTest.php`: formato, título, nome do arquivo, célula protegida e número; PDF com o
  cabeçalho da Connect, condomínio, título e células escapadas; Excel com número, texto sem fórmula e cabeçalho
  congelado; CSV igual ao de sempre; tabela das listas com as colunas da tela.
- `tests/legacy/Integration/ListDownloadTest.php`: nome do condomínio como no modelo e a lista de unidades só do
  condomínio do filtro, com a célula protegida.
- Render smoke: Download nas listas com os filtros (sem a página), sem botão em lista vazia, e PDF/Excel/CSV com título
  nos relatórios.
- `CollectionReportsIntegrationTest`: data inicial corta as mais antigas, vazia traz tudo, inválida ou futura recusa.
- `ErpCollectionReportScreensTest`: CSV com `from`. Render smoke: campo opcional e "vencidas desde" no cabeçalho.
- `ListDocumentTest`: arquivo sem a coluna Unidade com `hide_unit`. `ErpReportScreensTest`: `hide_unit=1` no CSV, colunas
  da tela sem a unidade e caixa só nos demonstrativos. Render smoke: caixa marcada, sem a coluna e lista de unidades sem a caixa.
- `tests/legacy/Integration/AccountabilityDocumentTest.php`: grupos e contas do demonstrativo, totais, receita com a
  unidade e sem ela (ocultar), síndico e conselho, despesas do mês com o comprovante dentro do PDF, as partes do
  documento, a lista dos meses e o escopo. Render smoke: lista dos meses, Visualizar, PDF e ocultar unidade na AP-101.

## Ajuste de 16/11 (pedido do Djalma)

- **PDF abre no navegador**: o PDF das listas e relatórios, da prestação de contas, do livro-caixa e da vistoria sai
  com `Content-Disposition: inline` e o link abre numa aba nova. A pessoa vê antes e decide se baixa ou imprime ("mais
  honesto e não enche o computador"). Excel e CSV continuam baixando. O PDF do link público do morador continua como
  download, porque a página dele roda com CSP `sandbox`, que impede o leitor de PDF do navegador.
- **Leiaute igual ao modelo 3**: logo à esquerda e os dados da Connect centralizados à direita, linha escura embaixo,
  data à direita, condomínio e título centralizados em negrito, tabela com o cabeçalho em negrito entre linhas cinza e
  rodapé com linha, o site e o número da página em negrito itálico. O cabeçalho inteiro se repete em cada página. A
  prestação de contas usa o mesmo rodapé com linha, em texto normal como no PDF de outubro.
