# ADR 0154 — Visão geral única do condomínio (painel fundido), endereço na lista e Selecionar discreto

- Situação: aceita (10/11/2026)
- Contexto: pedido do Djalma em 10/11/2026, depois do ADR 0151 (visão geral e botão Selecionar);
  painéis do condomínio e da unidade (ADR 0054 e 0071); contexto global do condomínio (ADR 0030)

## Contexto

Com o ADR 0151, o condomínio passou a ter duas telas para a mesma coisa: a **visão geral**
(`/condominios` com um condomínio na barra superior: cadastro, passos e atalhos) e o **Painel do
condomínio** (AP-002, `/condominios/painel-do-condominio`: ocupação, financeiro e rotina). O Djalma pediu:

1. juntar as duas em uma tela só, mantendo a lateral de cadastros e o cadastro por etapas em cima, com a
   identificação e os dados no centro;
2. na lista (em "Todos os condomínios"), um endereço curto depois do CNPJ, para diferenciar condomínios de
   nome parecido (rua e cidade bastam);
3. o botão **Selecionar** menos chamativo: fundo branco com contorno, verde cheio só ao passar o mouse,
   cursor de mão; e um aviso na barra superior do tipo "Selecione o condomínio".

## Decisão

- **AP-002 fundido em AP-004.** Sai do catálogo (`tools/erp-catalog.py`, `MERGED`), do menu, das fichas
  visuais (`page-specs.php`) e da Central de Ajuda (o artigo `painel-do-condominio` foi absorvido por
  `condominios`). A rota antiga redireciona para `/condominios`, que com o slug abre a visão geral; links e
  favoritos antigos continuam funcionando. A API `GET /api/v1/panels/condominium/{id}` não muda.
- `Condominiums::overview` chama `PanelSummaries::condominium` (o mesmo serviço da API) quando há banco e
  `erp.units.view`. Sem `erp.units.view`, a tela mostra só o cadastro; com `RegistryForbidden` (sem
  financeiro ou fechamento), um aviso no lugar dos indicadores. `Panel::condominiumPanelData` e o template
  `pages/condominiums/panel` saíram.
- Layout de `pages/condominiums/show`, de cima para baixo: cabeçalho com ações (Todos os condomínios,
  Checklist de chegada, Editar); **Cadastro do condomínio** por etapas (ADR 0151); os quatro indicadores
  (unidades, saldo em bancos, inadimplência, resultado do mês). Depois, em duas colunas: no centro,
  **Identificação** em um cartão só (identificação, endereço e contato, estrutura, contrato e banco),
  **Alertas** e **Inadimplência por atraso** lado a lado e **Contas bancárias**; na lateral, **Rotina** (curta, em cima) e **Cadastros
  do condomínio**.
- Lista: `CondominiumScope::listing()` devolve o mesmo escopo de `allowed()` mais `address`
  ("Rua, número · Cidade/UF", `CondominiumScope::shortAddress`). `allowed()` fica como está para não
  mudar o contrato de `/api/v1/condominiums` e `/api/v1/me`. A coluna **Endereço** entra depois do CNPJ e
  a pesquisa da tabela também procura nela.
- Botão `erp-btn--pick`: contorno verde claro, fundo branco, texto verde; hover e foco com o verde da
  marca e texto branco; `cursor: pointer`.
- Barra superior: em "Todos os condomínios" (com condomínios no escopo), o rótulo do seletor passa de
  "Condomínio" para **Selecione o condomínio**, em verde. No celular o rótulo continua escondido.

## Consequências

- Uma tela por condomínio; o menu Condomínios perde o item "Painel do condomínio".
- A matriz `docs/ERP_V1_ROUTE_MATRIX.md` registra AP-002 como FUNDIDA (170 validadas, 4 fundidas).
- Prints `condominios` e `condominios-visao-geral` foram gerados de novo; o print `painel-do-condominio` saiu.
- Testes: `CondominiumSetupTest` (endereço curto, botão discreto, rota antiga, AP-002 fora do catálogo),
  `ErpOperationalScreensTest` e `tests/erp/render-smoke.php` (visão geral com os indicadores e o aviso de
  permissão).
- Correção: o botão **Todos os condomínios** da visão geral apontava para `/condominios` sem slug e a sessão levava de volta ao mesmo condomínio; agora usa o segmento `/todos` (`CondominiumContext::ALL_SEGMENT`).
