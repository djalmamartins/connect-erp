# 0099 — Código e link da verificação pública impressos nos documentos da unidade

Data: 2026-11-09 · Status: aceito (rotas /verificar no index.php, revisadas na 28ª rodada) · Continuação do ADR 0098 · Pedido do Djalma ("pode seguir")

## Contexto

O `/verificar` (ADR 0098) confere documentos sem login, mas o documento impresso não dizia onde conferir.
O rodapé mostrava só o código, com a frase "pode ser conferida no ERP da Connect", e o ERP exige login.

## Decisão

- **Onde fica:** o rodapé comum a todos os documentos da unidade, em
  `container/erp/connect/v1/pages/documents/unit-document.php`. É o único modelo de impressão. Vale para
  certidão de débitos, declaração de inadimplência, comprovante de quitação, carta de anuência e recibo.
- **O que o rodapé imprime:**
  - "Código de verificação: XXXX-XXXX-XXXX-XXXX";
  - "Confira em <URL base>/verificar", com link para `<URL base>/verificar#XXXX-XXXX-XXXX-XXXX`;
  - um QR code com o mesmo link.
- **URL base:** vem do `url()` do sistema, que usa `CONF_URL_SSL` em produção e o host da requisição no
  laboratório. O ambiente não é lido direto, e o `Boot/Settings.php` não muda.
- **QR code:** gerado no servidor, em SVG, pelo `Source\Support\QrCode`, com a biblioteca bacon/bacon-qr-code
  que já está no vendor (ADR 0029). Não usa serviço externo. Se a biblioteca faltar, o rodapé sai sem o QR.
- **Código no fragmento:** o código vai depois do `#`, que o navegador não manda ao servidor, então não
  aparece no log de acesso. A página `/verificar` lê o fragmento com JS inline na própria view (sem
  Minify), preenche o campo só se o formato for válido e tira o código do endereço com
  `history.replaceState`. Ela não envia nada sozinha: quem confere é a pessoa, pelo botão (POST com CSRF e
  limite, como no ADR 0098).
- **Funções puras novas** em `DocumentVerification`:
  - `normalize()` aceita maiúsculas ou minúsculas, hífens, espaços e tabulações, e também os traços e
    espaços especiais que editores e PDFs costumam trocar (travessão, meia-risca, hífen sem quebra, espaço
    sem quebra). Entradas com mais de 64 bytes são recusadas. O `check()` passa a usar essa função.
  - `formatCode()` devolve o código em grupos de 4.
  - `link()` e `footer()` montam o link e o rodapé. Só aceitam base http ou https, sem query nem fragmento.
- **Rotas:** o `/verificar` precisa responder no host onde o link aponta. Em produção é o site
  (`CONF_URL_SSL`); no laboratório pode ser o host do ERP. Por isso, as rotas vão no `index.php`, fora dos
  blocos de host e da chave `CONF_ACCESS_SITE`. Isso corrige o handoff do 0098, que as punha no
  `routes.php` da web: lá, elas não responderiam no host do ERP nem com o site desligado.

## Consequências

- Testes unit:
  - `DocumentVerificationFooterTest`: normalização (7 formas aceitas e 8 recusadas), formatação, ida e
    volta do código, link e base inválida;
  - o rodapé no modelo comum a todos os tipos;
  - o JS do `/verificar` não envia o formulário sozinho.
- Smoke do ERP: o `render-smoke.php` ganhou uma asserção do rodapé com o código e o link (AP-123). A asserção
  não foi executada aqui: o smoke para antes, no AP-139, com um erro do PHP 8.5 que já existia
  (`addresses.php:17`). O rodapé foi conferido no HTML renderizado.
- Teste de integração: `DocumentVerificationIntegrationTest::testPrintedCodeVerifies`, em que o código
  impresso, o fragmento e o código digitado em minúsculas com espaços conferem em `/verificar`.
- Sem migration.
