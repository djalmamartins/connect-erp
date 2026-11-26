# 0659 — App Meu Dia para iPhone e Android e push do Meu Dia

- Status: Aceito
- Data: 26/11/2026
- Pedido: Djalma, 26/11/2026 19:30: "um aplicativo para iPhone e Android do meu dia, para ele notificar tudo que
  tem que fazer, ser tipo uma agenda para me consultar todo dia [...] uma pessoa cadastrar para o outro [...] dois
  aplicativos separados (Meu Dia e SAC), mas todos interligados, pela API REST".
- Depende de: ADR 0630 (Meu Dia), ADR 0660 (sessão e push da equipe no celular, thread do app do SAC) e
  ADR 0658 (tarefas atrasadas em vermelho, levadas para hoje até fechar, thread "Ligar o SAC ao Meu Dia").

## Contexto

- O Meu Dia (CN-010) já tinha tela no ERP e API REST (`/api/v1/my-day`), mas só com sessão e CSRF do navegador.
- O app do morador (`apps/morador`, ADR 0303) já tem a base em Expo/React Native, com token Bearer no cofre do
  aparelho e push pelo Expo. O app da equipe é separado do do morador: outra conta, outras regras e outro ícone.
- O login da equipe por token e o registro do aparelho ficam na ADR 0660, comum aos apps Meu Dia e SAC.

## Decisão

- **`apps/meu-dia`** (Expo SDK 58, expo-router, TypeScript estrito, mesmas versões e o mesmo kit visual do app do
  morador). Nome "Meu Dia Connect", `br.com.connectcondominios.meudia`. Abas: **Hoje** (faixa de 7 dias, quadro
  vermelho das atrasadas, números do dia, agenda, prazos, vistorias, Para agendar, Aguardando, SAC sem responsável,
  vencimentos), **Semana**, **Nova tarefa** (para mim ou, com `erp.myday.team`, para outra pessoa; atalho a partir
  de um chamado de Para agendar) e **Conta** (ver o dia de outra pessoa, lembretes, sair). Concluir, cancelar e
  reabrir pelo `POST /my-day/events/{id}/status`.
- **Atrasadas**: o app mostra a lista `overdue` do `GET /my-day` (ADR 0658) em vermelho no topo de Hoje, com
  há quantos dias, e pinta de vermelho o dia da faixa em que a tarefa ficou para trás. Enquanto o servidor não manda
  a lista, o app monta a mesma coisa com o que já vem (compromisso de dia passado ainda marcado, chamado e vistoria
  vencidos). O número no ícone é a quantidade de atrasadas.
- **Lembretes locais** (no aparelho, funcionam sem internet e no Expo Go): 15 minutos antes de cada compromisso da
  semana; refeitos cada vez que Hoje carrega o dia da própria pessoa. Sem push do servidor, também o resumo das 7h.
- **Push do servidor** (`Source\Services\Erp\MyDayPush`, `tools/my-day-push.php` no cron de cada minuto e no
  scheduler do lab): "Fulano marcou uma tarefa para você" para compromisso marcado por outra pessoa nas últimas
  24 h e ainda em aberto; e o resumo das 7h (atrasadas, compromissos, chamados para agendar) uma vez por dia para quem
  tem o app, sem aviso em dia vazio. Cada aviso sai uma vez (`my_day_push_log`, gravado antes do envio). O envio usa
  o `TeamPush` da ADR 0660 (Expo só com `TEAM_PUSH_DELIVERY=expo`). O texto leva só título, condomínio e horário.
- **`GET /api/v1/my-day/team`**: a equipe para o "Para quem", só com `erp.myday.team` (403 sem).
- **Laboratório**: `bash tools/app-meu-dia/celular.sh` abre o app no Expo Go. A ponte do celular
  (`tools/app-morador/ponte.mjs`) roda com `PONTE_APP=sac` (modo dos apps da equipe, ADR 0660), na porta 8091
  para rodar junto com a do morador.
- Migration `20261126_2345_my_day_push` (só a tabela `my_day_push_log`).

## Consequências

- Push remoto no Android não funciona no Expo Go (limite do Expo desde o SDK 53); lá valem os lembretes locais. Para
  as lojas e para o push no Android é preciso o build do EAS com as contas Apple Developer e Google Play da Connect
  (pendente com o Djalma) e o `extra.eas.projectId` no `app.json`.
- Chamado novo atribuído a alguém não gera push pelo Meu Dia (não há data de atribuição no chamado); aparece em
  Para agendar e no resumo das 7h. O push de mensagem nova no chamado é do app do SAC.
- Sem biometria nesta primeira versão (o app do morador tem); entra depois se o Djalma quiser.
- Testes: Jest (regras do dia, lembretes, cliente da API) e integração `MyDayPushTest` no MariaDB de teste.
