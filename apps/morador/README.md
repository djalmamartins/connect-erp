# Connect Morador (app nativo)

App iOS/Android do morador, em React Native com Expo (ADR 0303). Usa as mesmas rotas do app web do morador
(`/api/v1/resident`, ADR 0302) com uma sessão por token Bearer, guardada no Keychain/Keystore do aparelho.

## Rodar no celular (desenvolvimento)

```bash
cd apps/morador
npm install
EXPO_PUBLIC_API_URL=https://app.connect.lab npx expo start
```

Abra o app **Expo Go** no celular e leia o QR code. O celular precisa alcançar o endereço do servidor (no laboratório,
o mesmo Wi-Fi do Mac e o `app.connect.lab` resolvendo para o Mac).

## Testes

```bash
npm test            # Jest: formatação, cliente da API, login com 2FA
npm run typecheck   # TypeScript estrito
```

## Estrutura

- `app/`: telas (expo-router). `(tabs)/` tem Início, Financeiro, Reservas, Ocorrências e Mais.
- `src/api/`: cliente da API (`client.ts`) e as rotas do morador com os tipos (`resident.ts`).
- `src/auth/`: sessão do aparelho (token no cofre, vínculo escolhido, 2FA).
- `src/ui/kit.tsx`: componentes com as cores do ERP (`src/theme.ts`).

## O que fica para depois

- Publicar nas lojas: contas de desenvolvedor da Connect na Apple e no Google, build pelo EAS e política de privacidade.
- Avisos por push (boleto, encomenda, comunicado).
- Foto na ocorrência, quando o envio pelo Disco Virtual com antivírus estiver pronto.
- Portão, interfone, câmeras e Pix.
