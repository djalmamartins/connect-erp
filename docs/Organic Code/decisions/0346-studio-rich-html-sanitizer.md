# 0346 — HTML do Studio limpo antes do site e da Central de Ajuda

- Status: Aceito
- Data: 17/11/2026
- Contexto: plano v1.0, §3.7 (segurança antes da produção)

## Contexto

O blog, as páginas (privacidade, termos), as matérias da Central de Ajuda e os destaques são escritos
no editor do Studio e gravados como HTML. Três telas públicas imprimiam esse HTML sem nenhuma limpeza:
`container/web/support/v1/pages/article.php`, `container/web/default/v1/pages/legal.php` e
`container/web/default/v1/pages/articles-post.php`. **CONFIRMADO** no código: um `<script>`, um
`onerror` ou um link `javascript:` colado no editor rodaria no navegador de quem visita o site.

Também **CONFIRMADO**: os quatro formulários do Studio punham o conteúdo dentro do `<textarea>` sem
escapar, então um texto com `</textarea>` quebrava o formulário e rodava no próprio Studio.

Quem escreve é da equipe (permissão `support.manage` ou de conteúdo), mas uma conta tomada ou um texto
copiado de outro site bastaria.

## Decisão

- Novo `Source\Support\RichHtml::clean()`: lista fechada de marcações de texto, links, imagens e tabelas
  (DOMDocument, que já vem com o dompdf). Remove scripts, estilos, formulários, SVG, comentários,
  eventos `on*`, `srcdoc`, links `javascript:`/`data:`/`vbscript:` e atributos fora da lista. `style`
  fica só com alinhamento, cor, peso e tamanho, sem `url()`. Vídeo incorporado só de YouTube ou Vimeo
  em https, com `sandbox`. Link com `target="_blank"` ganha `rel="noopener noreferrer"`.
  Marcação desconhecida some, mas o texto dela fica (escapado).
- Limpeza **ao salvar** (Studio: post, página, matéria, destaque) e **ao mostrar** (as três telas
  públicas), para cobrir o que já está gravado.
- Os `<textarea>` do Studio passam a escapar o conteúdo (o editor lê `textarea.value`, então nada muda
  para quem edita).

## Consequências

- Os 184 artigos da Central de Ajuda saem iguais depois da limpeza (só a forma das entidades muda);
  o teste `testHelpCenterArticlesSurviveUntouched` garante isso.
- Um vídeo de outro site que não YouTube/Vimeo deixa de aparecer. **PENDENTE**: se o Djalma quiser
  outro, é só incluir o domínio em `VIDEO_HOSTS`.
- O teste `testPublicTemplatesPrintStudioContentOnlyThroughClean` falha se uma tela nova do site
  imprimir `->content` direto.

## Testes

- `tests/legacy/Unit/RichHtmlTest.php`: 16 ataques, formatação comum preservada, artigos da Central,
  varredura das telas do site. Tirando a limpeza do `legal.php`, a varredura falhou (restaurado).
- `composer test:unit`, `composer test:integration`, `php tests/smoke.php`, `php tools/help.php check`.

## Lacunas

- App web do morador ainda sem a varredura de XSS.
- Não há Content-Security-Policy no site; seria a segunda barreira (fica para depois da hospedagem).
