# ADR 0352 — Aviso do certificado do banco perto de vencer

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0333 (vigia da hospedagem), ADR 0336 (certificado HTTPS), ADR 0322 (Cora), AP-171 (Integrações)

## Contexto

O Inter e a Cora exigem certificado de cliente (mTLS) para emitir, consultar e baixar boletos. O certificado vale cerca de
um ano. Quando vence, a cobrança para sem aviso na tela: só aparecem falhas na consulta dos boletos. A data de vencimento já
é gravada em `erp_bank_credentials.certificate_expires_on` quando a credencial é enviada em Configurações › Integrações.
O ROADMAP §3.6 pedia o aviso "de certificado do banco perto de vencer".

## Decisão

- `HostingWatchdog::bankCertProblems()` lê as credenciais (banco, ambiente, conta e condomínio) e devolve um problema por
  credencial que vence em menos de 30 dias (`BANK_CERT_WARN_DAYS`) ou já venceu.
- O vigia de hora em hora (`tools/hosting/watchdog.php`) junta esses problemas aos outros e manda o e-mail para
  `ALERT_EMAIL` como já fazia. A chave é `bankcert:{id}` antes de vencer e `bankcert-expired:{id}` depois. Como o aviso só
  repete quando as chaves mudam (ou a cada 12 horas), a contagem de dias não gera um e-mail por hora, e o dia do vencimento
  gera um aviso novo.
- Credencial sem data é ignorada. Se a tabela não existir ou o banco falhar, o vigia segue com o resto.
- O texto vai escapado no e-mail (o nome da conta é texto do usuário).

## Consequências

- O aviso depende do `ALERT_EMAIL` que o Djalma ainda vai escolher (HOSPEDAGEM.md §8). Sem ele, aparece só no log do cron.
- Não há aviso na tela do ERP; fica para depois, se o piloto pedir.

## Testes

- `HostingMonitorTest::testBankCertificateWarnsBeforeAndAfterExpiry`: 40 dias não avisa; 29 e 10 dias avisam; vencido
  avisa com outra chave; sem data é ignorado; a contagem de dias não repete o e-mail na hora seguinte; o vigia chama a função.
