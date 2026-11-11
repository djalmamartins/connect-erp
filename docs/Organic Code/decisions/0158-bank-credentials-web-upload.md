# ADR 0158 — Credencial do banco pela tela Integrações (pacote ZIP, teste antes de gravar)

- Situação: aceita (11/11/2026)
- Contexto: pedido do Djalma em 11/11/2026 ("não tem como criar uma página onde eu envie o certificado em zip e as
  credenciais e o sistema resolva e teste e realize a integração?"), depois de cadastrar pelo terminal um
  certificado de produção que o sandbox do Inter recusou com `tlsv1 alert unknown ca`.
- Altera o item 3 do ADR 0156 ("credenciais entram só pelo terminal"). O comando de terminal continua valendo.

## Contexto

CONFIRMADO: no ADR 0156 a credencial (client_id, client_secret, certificado e chave) só entrava por
`tools/bank-credentials.php`, para não passar pelo navegador. Na prática isso exigiu vários comandos no Mac
(chave do cofre, id da conta, caminhos com espaço) e não explicava o erro mais comum: usar o certificado de
produção do Internet Banking no sandbox. O Inter entrega um ZIP com `.crt` e `.key` em PEM.

## Decisão

1. **Tela.** Card "Banco Inter" em Configurações > Integrações (AP-171), que lista as contas do banco 077 nos
   condomínios do usuário, com a situação da credencial (sem segredo). Quem pode enviar: `erp.settings.manage` **e**
   `erp.bank_accounts.manage`. Ver exige `erp.settings.view` e `erp.bank_accounts.view`.
2. **API** (`BankCredentialsApi`, sessão e CSRF do `ApiAuthenticated`):
   `GET /api/v1/bank-credentials`, `POST /api/v1/bank-credentials/{id}`, `POST /api/v1/bank-credentials/{id}/test`
   e `DELETE /api/v1/bank-credentials/{id}`. Conta fora do escopo = 404. Corpo até 1 MB.
3. **Pacote em memória.** O navegador manda os arquivos em base64 dentro do JSON. `ZipEntries` lê o ZIP sem
   gravar em disco (só métodos stored/deflate, até 20 entradas, 64 KB cada e 256 KB no total, CRC conferido).
   `BankCredentialSetup::extract` acha o certificado e a chave **pelo conteúdo** (PEM), não pelo nome; chave
   protegida por senha é recusada com explicação.
4. **Teste antes de gravar.** Valida o par e a validade (`BankCredentials::inspectCertificate`) e pede um token
   ao sandbox (não emite nada). Falhou = nada é gravado e a resposta traz o diagnóstico em português:
   `unknown ca` = certificado de produção no sandbox; 401/400 no token = client ID ou secret errado; sem resposta
   = fora do horário do sandbox (seg a sex, 8h às 20h). Gravar mesmo assim só com `save_anyway` marcado.
5. **Gravação** igual à do terminal (`BankCredentials::save`, cofre `CONNECT_BANK_VAULT_KEY`, só sandbox), com
   auditoria `source: web` e o usuário. O endereço do webhook volta **só** nessa resposta.
6. **Proteções.** O secret, o certificado e a chave nunca voltam na resposta, não vão para log nem para a
   auditoria; o campo do secret é `type=password` e é limpo depois do envio. Limite de 10 tentativas por usuário
   por hora (`auth_throttle_events`, escopo `bank-cred`), que falha fechado.

## Consequências

- Os segredos passam pelo navegador e pelo PHP (HTTPS). Risco aceito pelo dono do produto em troca de cadastro
  sem terminal; mitigado pelas permissões, escopo, limite e auditoria. O terminal continua disponível.
- Produção continua bloqueada no `InterBankAdapter` (ADR 0156); esta tela não libera produção.
- Testes: `BankCredentialSetupTest` (ZIP, extração, diagnóstico) e `BankCredentialSetupIntegrationTest`
  (gravação cifrada, certificado de produção sem gravar, escopo, banco, limite e remoção).
