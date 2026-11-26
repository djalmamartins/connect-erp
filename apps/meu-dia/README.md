# Meu Dia Connect (iPhone e Android)

App da equipe da Connect com a agenda do Meu Dia (ADR 0630, 0658 e 0659). Mesma base do app do morador
(`apps/morador`): Expo SDK 58, expo-router, TypeScript estrito. Fala só com a API REST do ERP (`/api/v1`).

- **Hoje**: faixa de 7 dias, tarefas atrasadas em vermelho (levadas para hoje até fechar), agenda do dia, prazos,
  vistorias, chamados para agendar e aguardando o cliente.
- **Semana**: de segunda a domingo.
- **Nova tarefa**: para mim ou, com a permissão "Ver o Meu Dia da equipe", para outra pessoa (ela recebe push).
- **Conta**: ver o dia de outra pessoa, lembretes e sair.

## Rotas usadas

`POST /team/app-sessions` (+ `/verify`), `DELETE /team/app-sessions/current`, `GET /team/me`,
`POST|DELETE /team/push-devices` (ADR 0660) e `GET /my-day`, `GET /my-day/team`, `POST /my-day/events`,
`POST /my-day/events/{id}/status` (ADR 0630/0659). Contrato em `docs/api/openapi.yaml`.

## Desenvolvimento

```bash
npm ci
npm run typecheck
npm test
```

No celular, pelo Mac com o Connect no Docker: `bash tools/app-meu-dia/celular.sh` (na raiz do projeto) e leia o
QR code com o Expo Go. Endereço da API: `EXPO_PUBLIC_API_URL` ou `extra.apiBaseUrl` do `app.json`.

## Notificações

- Locais: 15 minutos antes de cada compromisso e, sem push do servidor, o resumo das 7h. Funcionam no Expo Go.
- Push do servidor (`tools/my-day-push.php`): tarefa marcada por outra pessoa e resumo das 7h. Precisa do
  `extra.eas.projectId` (build do EAS) e de `TEAM_PUSH_DELIVERY=expo` no servidor. No Android, push remoto só no
  build do EAS (o Expo Go não recebe).
