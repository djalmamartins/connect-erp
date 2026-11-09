# 0097 — Texto simples sem falso positivo e auditoria das configurações com documento mascarado

Data: 2026-11-09 · Status: aceito (revisado na 26ª rodada) · Continuação do ADR 0096

## Contexto

A regra de texto simples dos banners (ADR 0096) recusava textos legítimos que só parecem domínio: "Sr.João",
"R$1.500", "Bloco A.2", "obs.: trazer RG" e "10.000,00". Além disso, a auditoria das configurações só
mascarava pelo tipo do campo. Um CPF ou CNPJ escrito num campo de texto ia em claro.

## Decisão

- **`PlainText::problem` continua recusando:**
  - marcação e entidades HTML;
  - esquemas (http, https, ftp, javascript, data, mailto, tel);
  - endereço começando com `//`;
  - `www.`.
- **Domínio solto** (`PlainText::looseDomain`) só é recusado quando há pelo menos um rótulo ASCII antes
  de um TLD só de letras, com 2 a 24 caracteres, que não seja seguido de letra (acentuada ou não), dígito
  ou hífen. Além disso, é preciso que:
  - o TLD esteja em `COMMON_TLDS`: com, net, org, br, io, info, app, xyz, top, online, site, link, me,
    co, ly e gl;
  - ou o domínio termine em `.com.br`, `.net.br` ou `.org.br`.

  Rótulos só de números não contam. Letra acentuada logo depois do ponto corta o TLD, então "Sr.João" e
  "Dra.Ângela" passam.
- **`ErpSettings::auditValue`** passa primeiro pelo `AuditMask::fields` (pelo tipo) e, se o valor for
  texto, pelo `AuditMask::text`. Assim, CPF, CNPJ, e-mail e telefone no meio do texto saem mascarados. As
  regex são as do AuditMask, sem duplicar.

## Consequências

- `PlainTextAndSettingsAuditTest`: 12 textos legítimos aceitos e 17 endereços recusados (entre eles
  "golpe.com", "pix.link/abc", "bit.ly/x", "exemplo.com.br" e "WWW.X.COM"), mais o mascaramento de CPF e
  CNPJ na auditoria.
- Um domínio com TLD fora da lista ("exemplo.dev") passa como texto. É uma troca deliberada: a lista cobre
  os TLDs mais usados em golpe, e o esquema e o `www.` continuam bloqueados para qualquer TLD.
- Sem migration.
