# Connect Morador (app nativo)

App iOS/Android do morador, em React Native com Expo (ADR 0303). Usa as mesmas rotas do app web do morador
(`/api/v1/resident`, ADR 0302) com uma sessão por token Bearer, guardada no Keychain/Keystore do aparelho.

## Rodar no celular (desenvolvimento)

No Mac do laboratório, com o Docker Desktop aberto e o celular no mesmo Wi-Fi:

```bash
bash tools/app-morador/celular.sh
```

O script confere o Node, sobe o Connect no Docker se precisar, acha o IP do Mac, abre uma ponte na porta 8090
(`tools/app-morador/ponte.mjs`, que repassa só `/api/v1/resident/*` e `/api/v1/health`, porque o container só escuta
em 127.0.0.1) e roda `npx expo start --lan` com `EXPO_PUBLIC_API_URL=http://IP-do-Mac:8090`. No celular, abra o
**Expo Go** e leia o QR code (no iPhone, pela Câmera).

Para ter um morador de teste: ligue as funções em Permissões de Moradores, convide o morador em Criação de usuários
para moradores e, como o e-mail não sai no laboratório, pegue o link com
`docker compose exec app php tools/app-morador/link-convite.php`. Abra o link no navegador do Mac, crie a senha e
entre no app com o e-mail (ou CPF) e essa senha.

## Testes

```bash
npm test            # Jest: formatação, cliente da API, login com 2FA
npm run typecheck   # TypeScript estrito
```

## Estrutura

- `app/`: telas (expo-router). `(tabs)/` tem Início, Financeiro, Reservas, Ocorrências e Mais.
- `src/api/`: cliente da API (`client.ts`) e as rotas do morador com os tipos (`resident.ts`).
- `src/auth/`: sessão do aparelho (token no cofre, vínculo escolhido, 2FA).
- `src/ui/kit.tsx`: componentes com as cores do ERP (`src/theme.ts`). Sem barra de navegação nativa: `Screen` desenha o título grande no topo (estilo iPhone), a barra pequena com desfoque ao rolar e o botão de voltar (`back`). Telas de aba usam `tab` para deixar espaço à barra de baixo.
- `src/ui/FloatingTabBar.tsx`: barra de baixo flutuante e translúcida (expo-blur), com `accessibilityRole="tab"` em cada item.

## O que fica para depois

- Publicar nas lojas: contas de desenvolvedor da Connect na Apple e no Google, build pelo EAS e política de privacidade.
- Avisos por push (boleto, encomenda, comunicado).
- Foto na ocorrência, quando o envio pelo Disco Virtual com antivírus estiver pronto.
- Portão, interfone, câmeras e Pix.
