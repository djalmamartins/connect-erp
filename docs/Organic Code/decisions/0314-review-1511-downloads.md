# 0314 — Revisão de 15/11, etapa 5: download das listas e relatórios em PDF e Excel

- Status: Aceito (parte 1, item 18)
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

## Testes

- `tests/legacy/Unit/ListDocumentTest.php`: formato, título, nome do arquivo, célula protegida e número; PDF com o
  cabeçalho da Connect, condomínio, título e células escapadas; Excel com número, texto sem fórmula e cabeçalho
  congelado; CSV igual ao de sempre; tabela das listas com as colunas da tela.
- `tests/legacy/Integration/ListDownloadTest.php`: nome do condomínio como no modelo e a lista de unidades só do
  condomínio do filtro, com a célula protegida.
- Render smoke: Download nas listas com os filtros (sem a página), sem botão em lista vazia, e PDF/Excel/CSV com título
  nos relatórios.
