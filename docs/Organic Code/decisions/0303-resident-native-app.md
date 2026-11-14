# 0303 — App nativo do morador (React Native/Expo) e sessão por token Bearer

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:** ADR 0090 (convite do morador e matriz do portal), 0302 (app web do morador e rotas /api/v1/resident),
  0006 (acesso fail-closed), 0025/0036 (2FA e logout global), 0054 (fila de avisos)
- **Numeração:** faixa avulsa 0300–0319. A thread do app web ficou com o 0302 e a migration 20261114_0901; esta, com o
  0303 e a migration 20261114_0902 (combinado entre as duas threads em 14/11).

## Contexto

Pedido do Djalma (14/11/2026): "vamos criar um app react native também para os moradores", em paralelo ao app web do
morador em app.connect.lab (ADR 0302). Os módulos aprovados são os mesmos: boletos, extrato e prestação de contas,
comunicados, reservas e lista de espera, ocorrências, portaria (encomendas e visitantes), documentos, assembleias, pets
e veículos. Cada um é ligado por condomínio na matriz do portal e tudo começa desligado. O morador entra pelo convite.
Portão, interfone, câmeras e Pix ficam para depois.

A API REST de hoje autentica pela sessão do PHP (cookie) com CSRF. Um app nativo não deve depender de cookie: precisa
de um token guardado no cofre do aparelho.

## Decisão

### Um backend só

- As rotas e serviços do morador são os do ADR 0302 (`/api/v1/resident/...`, `ResidentLinks`, matriz do portal). O
  app nativo não tem rota própria de dados: usa exatamente as mesmas, com as mesmas regras de escopo e auditoria.
- O que o celular acrescenta é só a sessão por token:
  - `POST /api/v1/resident/app-sessions` `{login, password, device_name?}`: mesma conta e senha do login web
    (`Auth::attempt`), mesmo limite de tentativas (`LoginThrottle`: 6 por conta e 30 por IP em 15 minutos). Só entra
    quem tem vínculo de morador ativo (403 `not_resident`). Com 2FA ativo devolve 202 e um desafio de uso único
    (5 minutos, 5 tentativas); o token sai em `POST /api/v1/resident/app-sessions/verify` `{challenge, code}` (código
    do autenticador ou de recuperação). 2FA obrigatório e ainda não configurado = 409: configurar primeiro pela web.
  - `DELETE /api/v1/resident/app-sessions/current`: sai deste aparelho.
  - `ResidentApiAuthenticated` (ADR 0302) aceita a sessão com CSRF, como hoje, **ou** `Authorization: Bearer`. Com
    Bearer não há cookie, então não há CSRF; o token válido vira o usuário da requisição por `Auth::actingAs()`, que
    nunca grava na sessão. `Auth::user()` e a auditoria (`Audit`) passam a enxergar esse usuário.
- O token Bearer **não vale** fora de `/api/v1/resident` (o `ApiAuthenticated` do ERP continua só com sessão).

### Token (`ResidentAppSessions`, tabelas `erp_resident_app_tokens` e `erp_resident_app_challenges`, migration 20261114_0902)

- 64 caracteres hexadecimais (256 bits de `random_bytes`). Só o sha256 fica no banco; o token não vai para log nem
  auditoria.
- Vale 90 dias a partir do login e cai antes com 30 dias sem uso. Cai também no logout global (ADR 0036: sessão
  criada antes do "sair de todos os aparelhos") e quando a pessoa deixa de ter vínculo de morador ativo.
- No máximo 10 aparelhos por usuário: o 11º login derruba a sessão mais antiga.
- Nada é apagado: a sessão revogada fica com `revoked_at` e o motivo (`logout`, `logout_all`, `device_limit`).
- Auditoria: `resident_app.login` (usuário e nome do aparelho) e `resident_app.logout`.

### App (`apps/morador`)

- Expo SDK 58 (React Native 0.88, React 19.3), TypeScript estrito, expo-router. Pasta própria, fora do PHP: não entra
  no Composer nem no CI do PHP. Comandos: `npm test` (Jest), `npm run typecheck`.
- Visual da Connect: verde-escuro do ERP (`#0d2e27`) no topo e na entrada, verde das ações (`#07884c`), logo dourada
  (`#c5a131`) e fonte Inter. Ícone do app = símbolo dourado sobre o verde-escuro.
- Token no Keychain (iOS) / Keystore (Android) com `expo-secure-store`. Boleto em PDF e documentos são baixados com o
  token no cabeçalho para o cache do app e abertos no visualizador do sistema.
- Telas: entrada (com o passo do 2FA), Início (resumo só das funções ligadas), Financeiro (em aberto com copiar linha
  digitável e Pix, abrir PDF; histórico; prestação de contas), Reservas (nova, cancelar, lista de espera quando a
  data está ocupada), Ocorrências (registrar e acompanhar; sem foto na primeira versão, como no ADR 0302), Mais
  (comunicados, encomendas, visitantes, documentos, assembleias, pets e veículos, trocar de unidade, sair).
- Função desligada pelo condomínio: o menu não mostra e a API responde 403 `feature_disabled`, que vira o aviso
  "Função desligada".
- Endereço do servidor: `EXPO_PUBLIC_API_URL` ou `expo.extra.apiBaseUrl` (padrão `https://app.connect.lab`).

## Consequências

- Publicar nas lojas (Apple e Google) exige as contas de desenvolvedor da Connect, o build pelo EAS e a política de
  privacidade: fica com o Djalma. Até lá, o teste é pelo Expo Go ou por build interno.
- Avisos por push (boleto, encomenda, comunicado) ficam para uma próxima etapa: precisam guardar o token de push do
  aparelho e ligar à fila de avisos (ADR 0054).
- Foto na ocorrência entra quando o ADR 0302 liberar o envio pelo Disco Virtual com antivírus.
- Rollback: a down migration apaga as sessões; os aparelhos voltam para a tela de entrada. O app não guarda dados do
  condomínio fora do cache.
