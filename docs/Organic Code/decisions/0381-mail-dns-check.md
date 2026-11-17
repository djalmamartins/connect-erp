# 0381 — Conferência do e-mail de produção (SPF, DKIM, DMARC e envio de teste)

- Status: Aceito
- Data: 17/11/2026
- Contexto: ROADMAP_V1 §3.4 (e-mail da produção com SPF, DKIM e DMARC no domínio `connectcondominios.com.br`).

## Contexto
Boletos, avisos e o vigia da hospedagem saem por e-mail (`mail_queue`, ADR 0358). Sem SPF, DKIM e DMARC o Gmail e o
Outlook mandam para o spam ou recusam. O `deploy-check` já avisa se o SMTP está vazio ou `ERP_MAIL_DELIVERY` não é
`production`, mas nada conferia o DNS do domínio nem se o SMTP aceita o envio de verdade.

## Decisão
- `Source\Support\MailDns::check(domínio, seletor DKIM, resolvedor)` lê só registros TXT públicos:
  - SPF: um registro `v=spf1`, terminando em `~all` ou `-all` (sem fim é aviso; `+all`/`all` ou dois registros é erro);
  - DMARC: um `v=DMARC1` em `_dmarc` com `p=none|quarantine|reject` (`p=none` é aviso, bom para começar);
  - DKIM: chave `p=` no `seletor._domainkey`; sem seletor informado fica como aviso (o seletor vem do hPanel);
  - falha de consulta ao DNS é informada à parte, não como registro ausente.
- `php tools/hosting/email-teste.php --para=... [--dkim-seletor=...] [--so-dns]` usa o domínio do remetente
  (`MOVESOS_SMTP_FROM_ADDRESS`), mostra a conferência e manda um e-mail direto pelo SMTP (sem a fila). Saída 0, 1 ou 2.
- Nada é alterado no DNS nem no banco. HOSPEDAGEM.md §4 ganhou o passo.

## Consequências
- Na etapa 4, depois de criar a conta de e-mail no hPanel, um comando mostra o que falta no DNS e prova o envio.
- O DNS de cada provedor pode demorar para propagar; repetir o comando depois de algumas horas.
- Testes: `MailDnsTest` (7 testes, resolvedor falso). No ambiente do CI não há DNS público; o comando real roda na hospedagem.
