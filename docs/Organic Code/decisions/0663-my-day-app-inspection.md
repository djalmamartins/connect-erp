# 0663 — App Meu Dia: vistoria inteira pelo celular

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026: "continuar desenvolvendo e melhorando o sistema a cada 30 min" (ciclo 2 do app Meu Dia).
  O "Iniciar visita" estava na prévia aprovada e ficou pendente na ADR 0662.
- Depende de: ADR 0662 (Painel da visita), CN-001 (vistoria presencial e rotas `/api/v1/inspections`), ADR 0660 (Bearer).

## Decisão

- No Painel da visita, **Iniciar vistoria** (ou **Continuar vistoria** quando o plano tem `open_inspection_id`) faz o
  check-in pelo `POST /inspections` com `condominium_id`, `template_id` (já vêm em `day_visits` do `GET /my-day`) e a
  posição do aparelho (`expo-location`, só com o app aberto, 10 s de limite). Quando o servidor devolve o campo
  `checkin_note` (longe do prédio ou sem localização), o app mostra o campo **Motivo do check-in** e manda de novo.
- Tela `app/vistoria/[id].tsx`: itens por seção, **Conforme / Com problema / Não se aplica** salvos na hora
  (`PUT /inspections/{id}/items/{item}`; "Com problema" pede descrição), **Foto** por item
  (`POST .../photos` multipart, foto reduzida a 1600 px no aparelho com `expo-image-manipulator`, posição à parte),
  barra de progresso e **Concluir** (`POST .../complete`) com o mesmo bloqueio do servidor (todo item respondido,
  foto nos que pedem). O app não mostra as fotos já enviadas (o endereço delas é da tela do ERP, com sessão), só a
  quantidade.
- `GET /team/me` ganha `can_inspect` (`erp.inspections.perform`), para o app esconder o botão de quem não faz vistoria.
  Permissão e escopo continuam no `InspectionService`.
- Dependências novas do app: `expo-location`, `expo-image-picker` e `expo-image-manipulator` (as duas últimas nas mesmas
  versões do app do morador), com os textos de permissão no `app.json`.
- Sem migration e sem rota nova.

## Consequências

- Regras puras em `apps/meu-dia/src/lib/inspection.ts` com testes (`__tests__/inspection.test.ts`).
- Ainda não: cancelar a vistoria pelo app, ver as fotos enviadas e trabalhar sem internet (cada resposta vai na hora).
