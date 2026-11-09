# 0029 — QR code na configuração do 2FA, gerado no servidor

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

Issue #7: as telas de configuração do 2FA (login do Studio no modo `setup`, "Segurança e 2FA" do
Studio e "Segurança da conta" do ERP) mostravam só a chave em texto e o link `otpauth://`. No
celular é preciso digitar a chave de 16 caracteres. O link `otpauth://` leva o segredo TOTP, então
ele não pode ir para um serviço externo de QR nem para uma biblioteca de frontend de CDN.

## Decisão

- **QR code em SVG gerado no PHP** pela biblioteca `bacon/bacon-qr-code` (^3.0, BSD-2, mantida,
  sem extensão gráfica: usa o `SvgImageBackEnd`, só exige `ext-iconv`). Ela entra no
  `composer.json` com a dependência `dasprid/enum`.
- `Source\Support\QrCode::svg($conteudo, $tamanho, $rotulo)` devolve o `<svg>` pronto para embutir
  (sem a declaração XML, com `role="img"` e `aria-label`). Ele devolve `null` quando a biblioteca
  não está instalada ou falha, e a tela segue com a chave em texto e o link, como antes.
- Os controllers (`Studio\TwoFactor` nos dois fluxos e `Erp\Connect\Security`) põem o SVG em
  `$setup['qr']`. As views mostram o QR só quando ele existe, sempre com a chave ao lado.
- No ERP o estilo é `.erp-qr` em `erp.css` (bundle `erp.min.css` recompilado). Nas telas de login
  do Studio o estilo fica inline, porque o `studio.min.css` não é recompilado (ADR 0026).

## Consequências

- Depois do merge é preciso rodar `composer install` em cada ambiente (Mac e servidor). Até lá a
  tela não quebra: só não mostra o QR.
- O segredo continua só na sessão do servidor e na página do próprio usuário. O SVG não tem texto do
  segredo nem referências externas (`QrCodeTest`).
- Testes: `QrCodeTest` (unitário), smoke do Studio (telas de 2FA com e sem QR) e smoke do ERP
  ("Segurança da conta" com e sem QR). Conferi também, decodificando a captura da tela, que o QR
  devolve o link `otpauth://` original.
