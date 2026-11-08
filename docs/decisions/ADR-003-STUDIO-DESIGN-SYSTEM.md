# ADR-003 — Design System do Studio em camada única

Status: aplicado no branch `claude-layout` em 07/11/2026, aguardando revisão de Djalma antes de ir para a `main`.

## Contexto
O CSS do Studio (`container/cms/studio/v1/assets/css/`) acumulou camadas de correção. `studio-system.css` tinha 11 blocos sucessivos (2026.1, V5 a V11) que redefiniam os mesmos componentes, dois conjuntos de tokens com valores diferentes e 227 `!important`. `admin.css` repetia no fim os mesmos primitivos (botões, campos, painéis, tabelas, abas, badges, modais) com outros valores e um roxo legado (`#6900a9`), e zerava a borda de todos os campos com `!important`. O resultado era um visual que dependia da ordem do cascade, campos brancos sem contorno sobre painéis brancos e botões (38px) desalinhados dos campos (39px a 44px).

## Decisão
- `studio-system.css` é a única fonte dos tokens e dos componentes do Studio. Cada componente é definido uma vez, em um bloco nomeado. Ajustes futuros entram no bloco do componente; não se cria nova camada no fim do arquivo.
- `admin.css` deixa de definir primitivos. Ficam nele apenas telas legadas específicas, o ambiente Help Desk e o menu recolhido.
- Nomes de classe não mudam: são contrato com os templates do Studio e do Help Desk, que recebe o mesmo `studio.min.css`.
- Escala de controles: 32px (`.sm`), 38px (padrão) e 44px (`.lg`), compartilhada por botões, campos, abas e paginação.
- Campos são preenchidos e sem contorno (mantendo a decisão anterior "campos sem contorno"), mas com fundo próprio para ficarem visíveis sobre painéis brancos. O foco volta ao fundo branco com anel na cor primária. Inválido usa `aria-invalid="true"` ou `.is-invalid`.
- Variantes de botão: padrão (secundário), `.primary`, `.danger`, `.outline`, `.ghost`, `.icon`, além de `.sm`, `.lg`, `:disabled` e `.is-loading`. As classes legadas `.btn-green`/`.btn-blue` mapeiam para primário e `.btn-red` para perigo.
- `!important` fica restrito à navegação lateral, ao cabeçalho de página, aos KPIs e a poucos pontos que precisam vencer o seletor genérico de campos.
- O tema escuro e o Help Desk funcionam trocando tokens, sem regras paralelas por componente.

## Consequências
- `studio.min.css` foi regenerado a partir das fontes. O minificador MovesCode remove o espaço antes de `:`, então seletores descendentes de pseudo-classe precisam de `*` explícito (`.studio-v2 *:focus-visible`). O bundle anterior tinha esse defeito.
- Telas que dependiam de valores do bloco removido de `admin.css` passam a usar os do Design System (raio 7px em vez de 8px, botões 38px em vez de 42px).
- Catálogo visual antes/depois: https://claude.ai/artifact/8QDqJkasvG1NA4YVysvDBT
