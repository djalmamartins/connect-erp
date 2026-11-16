# 0309 — "Sobre esta tela" no ERP e "O que acontece por trás" na Central de Ajuda

- **Status:** aceito
- **Data:** 2026-11-15
- **Relacionados:** ADR 0141 (Central de Ajuda e base de conhecimento), 0151 (guia de cadastro do condomínio)
- **Numeração:** faixa avulsa 0300–0319 (0308 reservado pela integração do Connect Talk).

## Contexto

Pedido do Djalma (15/11/2026): cada tela do ERP precisa de uma explicação no topo, antes de tudo, dizendo o que ela
faz; e a base de conhecimento precisa servir para quem usa o sistema pela primeira vez, explicando também o que
acontece por trás de cada ação ("quando eu cadastro um pagamento, o que acontece?"). O trabalho é por etapas, uma
área do menu por vez, cada uma na main antes da próxima.

## Decisão

- **Uma fonte só.** `Source\Services\Help\PageGuides` lê `source/Services/Help/guides/*.php` (um arquivo por etapa),
  com, por código de página: `faz` (o que a tela faz, 30 a 420 caracteres), `por_tras` (o que o sistema faz quando
  você grava: validações, duplicidades, travas, histórico, efeitos em outras telas) e `mais` (texto só do artigo).
  Os textos são conferidos no código de cada tela; nada que o sistema não faça.
- **ERP:** `components/layout/page-intro.php`, inserido pelo `page-head.php` (que todas as páginas usam), mostra o
  quadro "Sobre esta tela" logo abaixo do título, no modelo que o Djalma mandou em 15/11/2026: caixa clara de borda
  fina, título pequeno com ícone, o que a tela faz, até 3 itens do que acontece por trás e "Abrir tutorial completo"
  (`/suporte/tela/{código}`). Desde 16/11/2026 a caixa nasce fechada (só o título) e a pessoa decide abrir; quem
  abre pelo título deixa aberta naquela tela, naquele navegador (localStorage). Depois, também a pedido dele: tom verde do ERP, X que fecha e não
  volta mais naquela tela, e a chave global `admin.page_intro` (Configurações > Administrativo, padrão ligado) que tira
  o quadro de todas as telas. Não aparece na impressão. Página sem explicação não mostra nada.
- **Resumo de cada área na Central de Ajuda:** `kb/areas/<categoria>.md` (Markdown da base) aparece no topo da página
  da categoria em `/suporte`, como "Como funciona esta área": o que é a área, a ordem de uso e o que acontece por trás.
  `KnowledgeBase::areaIntro/areaProblems`; o `check` confere categoria e links. Escrito junto com a etapa da área.
- **Artigo:** `php tools/help.php explicar` escreve a seção `## O que acontece por trás` no .md (antes de "Quem pode
  usar"), e o `HelpWriter` (`escrever`) mantém a seção ao reescrever o artigo. O `check` (já no CI) falha se a seção
  do artigo estiver diferente da explicação ou se houver explicação de página que não existe no menu.
- **Guia geral:** `primeiros-passos/como-o-sistema-funciona.md` (manual) explica como as partes se ligam e o que
  acontece por trás de registrar um pagamento, cobrar os moradores e fechar o mês.
- **Etapas:** 1 = Dashboard, Condomínios e Cadastros; 2 = Financeiro e Cobrança; 3 = Prestação de contas,
  Operacional, Comunicação e Documentos; 4 = Relatórios, Importações e Configurações.

## Consequências

- Somente leitura: nenhuma tabela, migration, permissão ou rota nova. O quadro usa o mesmo renderizador seguro de
  Markdown da Central de Ajuda (escapa tudo antes das marcações).
- Mudou a regra de uma tela? Atualize o texto em `guides/`, rode `php tools/help.php explicar` e o `check`.
- Testes: `tests/legacy/Unit/HelpPageGuidesTest.php`; o smoke de renderização cobre todas as páginas.
