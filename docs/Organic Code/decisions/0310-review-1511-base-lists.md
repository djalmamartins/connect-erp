# 0310 — Revisão de 15/11, etapa 1: unidade só com condomínio, unidade vazia, itens por página e máscara R$

- **Status:** aceito
- **Data:** 2026-11-15
- **Relacionados:** ADR 0030 (condomínio da barra superior como contexto), 0014/0015 (motor de cadastros), 0141 (Central de Ajuda)
- **Numeração:** faixa avulsa 0300–0319.
- **Issue:** #540 (lista completa da revisão: #540 a #545)

## Contexto

Na revisão de 15/11/2026 o Djalma pediu, para todas as telas: (1) o filtro de unidade só aparece com um condomínio
escolhido e lista só as unidades dele; (2) marcar a unidade como vazia ou ocupada e filtrar a lista por isso;
(3) paginação em todas as listas com 50 itens por padrão e a escolha de 100, 200 ou 500, usando o `movescode/pager`;
(4) máscara `R$ 0.000,00` em todo campo de dinheiro. As telas de referência (modelos 1 a 8) servem só para as opções;
o layout continua o do Connect ERP.

## Decisão

1. **Unidade depende do condomínio.** `Panel::contextUnits()` devolve as unidades do condomínio da barra superior e
   nenhuma sem condomínio. O painel da unidade, a emissão de documentos e recibos da unidade e o gráfico de consumo
   por unidade usam essa regra; sem condomínio, a tela mostra `components/feedback/needs-condominium.php` no lugar do
   campo. As telas que já exigiam condomínio (entregas, cobranças extras, casos jurídicos) continuam iguais.
   O chamado (Help Desk) mantém o par condomínio + unidade do próprio formulário.
2. **Unidade vazia.** Coluna `erp_units.is_vacant` (migration `20261115_2330_unit_vacant`), marcada pela equipe no
   cadastro da unidade ("Unidade vazia"). O sistema não a muda sozinho: a regra automática de vaga por vínculos
   (ADR 0270, relatório de unidades vagas) continua separada. A lista de unidades ganha a coluna e o filtro
   **Ocupação** (`?ocupacao=vazias|ocupadas`; na API, `flag=`), pela chave genérica `flag_filter` da definição do
   cadastro. O painel da unidade mostra "Unidade vazia" ou "Unidade ocupada".
3. **Itens por página.** `Source\Support\Erp\PerPage` (50 padrão; 100, 200, 500; qualquer outro valor volta a 50) e o
   componente `components/tables/pagination.php`: "Exibindo X–Y de N itens", os números das páginas pelo
   `Source\Support\Pager` (moves/pager) e o seletor "Itens por página" (`?por_pagina=`). Nesta etapa entram todos os
   cadastros do motor (`pages/registry/index.php`: unidades, pessoas, fornecedores, advogados e os outros 38). Na API
   dos cadastros, `per_page` é opcional e sem ele o tamanho continua 25 (compatível com quem já usa).
   As demais listas recebem o mesmo componente junto com o botão Download (etapa 5, #544), que passa por todas elas.
4. **Máscara de dinheiro.** `data-erp-money` (erp.js): os dígitos entram pelos centavos e o "R$" fica fora do valor,
   numa moldura visual. O valor enviado continua no formato `1.250,00`, que todas as validações (servidor e telas) já
   aceitam, então nenhum contrato muda. `data-erp-money="signed"` aceita o sinal de menos (saldos de abertura).
   Entram os campos de dinheiro do motor de cadastros, do `field.php` e das telas financeiras.

## Consequências

- Quem abria o painel ou emitia documento sem condomínio escolhido agora escolhe o condomínio antes.
- A marca de vazia é manual; se o Djalma quiser que ela siga os vínculos (sem inquilino ⇒ vazia), é uma mudança
  pequena no mesmo campo.
- Testes: `PerPageTest` (regra pura) e `UnitVacantFilterTest` (integração: filtro e paginação no motor de cadastros).
