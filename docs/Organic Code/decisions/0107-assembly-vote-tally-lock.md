# 0107 — Voto e apuração da assembleia com o item travado

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão do ciclo 37 (ADR 0088)

## Contexto

Na ADR 0088, `Assemblies::vote` conferia se o item estava em votação e gravava o voto sem trava.
`closeItem` somava os votos e depois marcava o item como apurado, também sem trava. Um voto gravado
entre a soma e a marcação ficava registrado num item já apurado, fora do resultado e da ata.

## Decisão

- `vote` grava numa transação que trava o item em modo compartilhado (`LOCK IN SHARE MODE`) e confere
  de novo se o item está em votação. Votos de unidades diferentes não esperam uns pelos outros.
- `closeItem` trava o item com exclusividade (`FOR UPDATE`) antes de somar. Assim, espera os votos em
  andamento terminarem. Um voto que chega depois encontra o item apurado e é recusado.

## Consequências

- A resposta da API não muda.
- Lacuna: não há teste automático da corrida (exige duas conexões). O mesmo padrão do teste da ADR 0100
  pode ser aplicado no Mac.
