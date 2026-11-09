# 0114 — Texto simples: domínio com TLD fora da lista

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão do ADR 0097

## Contexto

O ADR 0097 deixou uma lacuna aberta. `PlainText::looseDomain` só recusava domínios cujo TLD estivesse em
`COMMON_TLDS` ou que terminassem em ".com.br" e afins. Por isso "golpe.shop" ou "pagar.store/qr" passavam no
banner do aplicativo. A view escapa a saída, mas o texto continua sendo um endereço que o morador pode
digitar.

## Decisão

1. `COMMON_TLDS` passa a incluir os TLDs genéricos mais usados em golpes e lojas: shop, store, club, vip,
   click, bank, pay e outros.
2. Um domínio seguido de caminho ("golpe.qualquer/abc") é recusado, qualquer que seja o TLD.
3. Um TLD de 2 letras minúsculas (código de país, como "golpe.pt") é recusado, exceto as palavras curtas do
   português listadas em `SHORT_WORDS` (da, do, de, em...). Assim "Av.do Contorno" continua aceito.

## Consequências e lacunas

- Continuam aceitos "Sr.Paulo", "Fim do ano.Bom descanso!", "Bloco A.2/3" e "Horário seg.a sex.".
- Ainda passam:
  - um domínio sem caminho com TLD genérico fora da lista (por exemplo "golpe.qualquercoisa");
  - um código de país que coincide com uma palavra curta, como "banco.de" ou "pix.do".

  A view escapa a saída, e quem publica o banner é a administradora, com auditoria.
- Testes: `PlainTextAndSettingsAuditTest::testUncommonTldsWithPathOrCountryCodeAreRefused`.
