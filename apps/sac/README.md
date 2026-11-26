# Connect SAC (app da equipe)

App iOS/Android de quem atende o SAC, em React Native com Expo (ADR 0660). Mesmo visual do app do morador.
Fala só com a API REST do ERP: login da equipe por token Bearer (`/api/v1/team/*`), fila e atendimento
(`/api/v1/sac/*`) e o resumo do dia (`/api/v1/my-day`). Trabalha junto com o app Meu Dia (`apps/meu-dia`):
o SAC abre o Meu Dia por `connectmeudia://` e o Meu Dia abre um chamado por `connectsac://chamados/{id}`.

## Rodar no celular (desenvolvimento)

No Mac do laboratório, com o Docker Desktop aberto e o celular no mesmo Wi-Fi:

```bash
bash tools/app-sac/celular.sh
```

O script confere o Node, o Connect e as migrations, acha o IP do Mac, abre uma ponte na porta 8091 que repassa só
as rotas dos apps da equipe e abre o Expo na porta 8082 (o app do morador usa a 8081). Leia o QR code com a câmera
(iPhone) ou com o Expo Go (Android) e entre com a sua conta do ERP.

## Comandos

```bash
npm ci
npm run typecheck
npm test
```

## Push

O push sai pela fila `team_push_queue` (cron `tools/team-push-dispatch.php`). No lab fica em modo log (não envia).
Para enviar de verdade: `TEAM_PUSH_DELIVERY=expo` no `.env` e o `extra.eas.projectId` do EAS no `app.json`
(precisa da conta Expo/EAS da Connect). No Expo Go o resto do app funciona sem push.
