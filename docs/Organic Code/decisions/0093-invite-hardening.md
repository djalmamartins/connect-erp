# 0093 — Convite do morador: limite que falha fechado e documento obrigatório

Data: 2026-11-09 · Status: aceito (revisado na 22ª rodada; abrir vale para o GET antigo e o POST /convite/abrir do ADR 0112) · Continuação dos ADRs 0090, 0092, 0111 e 0112

## Contexto

A revisão do aceite (ADR 0092) achou duas lacunas:
- o limite de tentativas falhava aberto: se a tabela `auth_throttle_events` desse erro, o pedido
  passava;
- o morador sem documento no cadastro ficava protegido só pelo token.

## Decisão

- **`InviteThrottle`** (arquivo novo) concentra o limite: 20 tentativas por IP e 5 por token em 15
  minutos, com os identificadores só em HMAC.
  - `check()` responde OK, BLOCKED ou UNAVAILABLE. UNAVAILABLE, que é erro de leitura, recusa o pedido
    com "Não foi possível conferir o convite agora. Tente de novo mais tarde.";
  - `fail()` devolve false se não conseguir gravar, e o aceite também responde "tente mais tarde";
  - vale para abrir o convite (por IP) e para aceitar (por IP e por token);
  - o log registra só a operação e a classe do erro, sem token, IP nem hash.
- **`InvitationRules`** (arquivo novo):
  - `validDocument()` confere o CPF ou o CNPJ com os dígitos verificadores;
  - `assertInvitable()` impede o convite de morador sem documento válido. `ResidentInvitations::invite`
    lança `InvalidArgumentException`, que o controller responde com 422 e "Cadastre o documento do
    morador antes de convidar.", com os ids dos moradores;
  - `documentMatches()`: sem documento válido no cadastro, nunca confere.
- **No aceite:** o morador que ficou sem documento válido depois do convite recebe a mensagem genérica,
  e a tentativa conta no limite. O aceite nunca dispensa a conferência do documento.
- **Nada novo de confirmação:** nem e-mail extra, nem SMS.

## Consequências

- Se `auth_throttle_events` ficar fora do ar, ninguém aceita convite até a tabela voltar. É a escolha
  segura.
- O morador antigo sem documento precisa ter o documento cadastrado antes de receber convite.
- Não precisou de migration.
