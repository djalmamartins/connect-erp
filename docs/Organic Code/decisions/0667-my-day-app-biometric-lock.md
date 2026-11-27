# 0667 — App Meu Dia: abrir com Face ID / digital

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026: "continuar desenvolvendo e melhorando o sistema a cada 30 min" (ciclo 4 do app Meu Dia).
  A biometria ficou como pendência na ADR 0659; o app do morador já tem (ADR 0303).
- Depende de: ADR 0659 (app Meu Dia) e ADR 0660 (token Bearer da equipe no cofre do aparelho).

## Decisão

- Em **Conta**, a opção **Abrir com Face ID** (Touch ID, digital ou rosto, conforme o aparelho) só aparece quando o
  celular tem biometria cadastrada. Ligar pede a biometria uma vez (no iPhone, é aí que o sistema pede a permissão).
- Com a opção ligada, o app abre na tela de trava (`src/ui/LockScreen.tsx`) e só carrega a agenda depois do Face ID /
  digital; volta a pedir depois de 5 minutos em segundo plano. "Entrar com e-mail e senha" sai do aparelho e volta ao
  login.
- A biometria só destrava o token Bearer que já está no Keychain/Keystore; a senha nunca é guardada e o app nunca vê a
  digital ou o rosto. A escolha fica só neste aparelho (`connect.meudia.biometric`).
- Mesmo código do app do morador (`biometrics.ts` com os mesmos testes), com o texto do Meu Dia.
  Dependência nova: `expo-local-authentication` (mesma versão do morador), com o texto do Face ID no `app.json`.
- Nada muda no servidor.
