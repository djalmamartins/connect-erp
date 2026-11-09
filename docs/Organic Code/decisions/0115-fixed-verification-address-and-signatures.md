# 0115 — Endereço fixo da verificação pública e assinaturas nos documentos da unidade

Data: 2026-11-09 · Status: aceito · Origem: teste da main feito pelo Djalma (09/11, item 7)

## Contexto

O Djalma testou a main e encontrou três problemas:

- A página `/verificar` **ficava carregando** depois de enviar o código. O formulário estava num layout do site cujo
  `scripts.js` intercepta todo `form:not('.ajax_off')`. Esse script manda o formulário por AJAX e espera JSON, mas
  o POST devolve HTML, então o carregamento nunca terminava.
- Ele pediu que **o link não mude**. Até aqui, o link impresso levava o código no fragmento
  (`/verificar#CODIGO`, ADR 0099) e a página reescrevia o endereço. O pedido é um endereço fixo,
  `/verificar-certidao-debitos`: a pessoa abre o endereço, digita o código e vê se o documento é válido ou não.
- O documento impresso **não tinha onde o síndico ou o administrador assinar**, e a impressão precisava de
  melhorias.

## Decisão

- O formulário da verificação passa a ter `class="ajax_off"`, então o envio é um POST normal com a página de
  resposta. O resultado aparece em verde quando o documento é autêntico e em vermelho quando não é.
- O endereço fixo é `DocumentVerification::PATH = /verificar-certidao-debitos` (GET e POST). Ele fica no
  `index.php`, antes do `if (erp_host())`, como o `/verificar` (ADR 0099).
- O link e o QR impressos apontam para esse endereço, sem o código. O código aparece em destaque no rodapé, e a
  pessoa o digita.
- O `/verificar` continua por causa dos documentos já impressos:
  - o GET responde 301 para o endereço fixo. O navegador mantém o `#código`, e a página ainda preenche o campo;
  - o POST continua aceito, para abas abertas antes da mudança.
- Todos os documentos da unidade ganham, antes do rodapé:
  - o local e a data;
  - duas linhas de assinatura, "Síndico(a)" e "Connect · administradora de condomínios".
- Ajustes na impressão:
  - o cabeçalho da página do ERP some;
  - assinaturas e rodapé não se partem entre páginas;
  - o código aparece uma vez só, com o texto "acesse … e digite o código ao lado".
- A base do link continua vindo só de `CONF_URL_SSL` (ADR 0160).

## Consequências

- Testes cobrem:
  - as rotas nova e antiga antes do `if (erp_host())`;
  - o formulário `ajax_off`;
  - o redirecionamento 301;
  - o link sem código;
  - as assinaturas no modelo e no `render-smoke`.
- O `erp.min.css` foi regenerado.
- Ainda não houve teste de fumaça HTTP com banco. Falta conferir no Mac: abrir `/verificar-certidao-debitos`, digitar
  um código válido e um inválido, e imprimir uma certidão.
- O nome do síndico ainda não sai preenchido. Isso depende do cadastro do síndico no condomínio, que fica para depois.
