# 0662 — App Meu Dia: Painel da visita

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026: "continuar desenvolvendo e melhorando o sistema a cada 30 min", depois de aprovar a
  prévia das telas do app Meu Dia (ícone A, "o resto está ok"), em que aparece o Painel da visita.
- Depende de: ADR 0659 (app Meu Dia), ADR 0630 (Meu Dia e `GET /my-day/visit/{condominium_id}`), ADR 0660 e 0661
  (app do SAC e Novo chamado).

## Contexto

- A tela Meu Dia do ERP já tinha o painel do prédio antes da visita, com a API `GET /api/v1/my-day/visit/{condominium_id}`
  (chamados abertos, urgentes, documentos e vencimentos do checklist do condomínio, pendências da última vistoria e a
  última vistoria concluída), com o acesso por condomínio do ERP (404 fora do acesso).
- No app, a vistoria aparecia em Hoje, mas sem nada para tocar.

## Decisão

- Tocar numa vistoria em **Hoje** abre `app/visita/[id].tsx` (id = `condominium_id`). Topo verde-escuro com
  dourado ("O que acompanhar"): número de chamados abertos (vermelho com urgente/alta), de documentos e vencimentos
  (vermelho com vencido) e de pendências da última vistoria (dourado), uma frase com o que pede atenção primeiro e a
  última vistoria ("Há 12 dias", problemas encontrados).
- **Checklist de chegada** com 5 passos fixos (portaria e livro, zelador/síndico, pendências, áreas comuns, fotos).
  Fica só no aparelho (SecureStore, chave `connect.meudia.arrival`), guardado para um prédio num dia: outro prédio
  ou outro dia recomeça vazio. Não vai para o servidor e não tem dado pessoal.
- **Abrir chamado com foto no SAC** abre `connectsac://novo` (aba Novo do app do SAC, ADR 0661); sem o app do SAC,
  um aviso. Os chamados do prédio abrem no app do SAC (`connectsac://chamados/{id}`).
- Nenhuma mudança no servidor, nenhuma migration e nenhuma rota nova.
- `tools/app-meu-dia/celular.sh` passa a usar a ponte 8092 e o Expo 8083 (`PONTE_PORTA` e `EXPO_PORTA` mudam), porque o
  app do SAC usa a 8091 e a 8082: os dois podem ficar abertos ao mesmo tempo no Mac.

## Consequências

- Regras puras em `apps/meu-dia/src/lib/visit.ts` com testes (`__tests__/visit.test.ts`).
- Ainda não: fazer a vistoria inteira pelo app (as rotas `/inspections` já existem) e o "Iniciar visita" da prévia;
  fica para o próximo ciclo.
