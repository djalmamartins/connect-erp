# 0163 — API: padrão da Connect junto do condomínio nos cadastros de escopo opcional

Data: 2026-11-10 · Status: proposto para revisão · Correção de bug (tela "Preparar fatura")

## Contexto

`GET /api/v1/invoice-types?condominium_id=N` só trazia os tipos do condomínio N. Os tipos padrão
(`condominium_id` NULL) ficavam de fora, e a tela "Preparar fatura" mostrava "Nenhum tipo de fatura ativo"
quando só havia tipo padrão. As telas de cadastro já incluíam o padrão (`include_standard`, ADR 0032), mas a
API não.

## Decisão

- **Padrão incluído:** na API de cadastros (`Api\V1\Registry::index`), um cadastro de escopo `optional` com
  `condominium_id=N` traz `condominium_id IS NULL OR condominium_id = N`.
  - Isso vale para tipos de faturas, plano de contas e centros de custo.
  - `include_standard=0` volta ao comportamento antigo, só com os registros do condomínio.
- **Escopo:** N fora do escopo do usuário responde 404. Antes, vinha lista vazia.
- **O que não muda:** a chamada sem `condominium_id` e os cadastros de outro escopo.
- **Sobreposição:** com `override` na definição, o registro do condomínio com o mesmo valor da coluna
  prevalece sobre o padrão.
  - Hoje só o tipo de fatura tem `override`, pelo nome, que é a mesma chave da regra única "condomínio +
    nome".
  - Vale só na API (`prefer_condominium`). Na tela de cadastro os dois continuam aparecendo, para que o
    padrão possa ser editado.
  - O tipo do condomínio prevalece mesmo inativo: um condomínio que desativou o seu "Taxa condominial" não
    volta a ver o padrão de mesmo nome.

## Consequências

- `InvoiceTypesStandardListTest` cobre:
  - lista só com tipos padrão;
  - padrão mais tipos do condomínio, com a sobreposição;
  - nenhum tipo de outro condomínio;
  - 404 fora do escopo;
  - `include_standard=0`;
  - a chamada sem `condominium_id`.
- O `erp.js` não muda: ele já chamava `?status=active&condominium_id=N` e esperava o padrão junto.
