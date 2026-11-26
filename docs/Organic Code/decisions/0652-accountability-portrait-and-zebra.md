# 0652 — Prestação de contas em A4 retrato, totais em verde e linhas alternadas

- Status: Aceito
- Data: 26/11/2026
- Pedido: Djalma, 26/11/2026 07:23: as prestações de contas saem em A4 paisagem; "seria melhor se fosse retrato ...
  de comprido". 07:24: destacar com cor as linhas de total, subtotal e total do grupo, e testar linhas alternadas
  "um branco e um verde bem clarinho ... para ficar mais fácil de ler".
- Relacionadas: ADR 0314 (prestação de contas no modelo do PDF de outubro), 0323 (comprovantes em PDF juntados),
  0635 (base única de relatório)
- Número: 0651 está em uso no branch da conferência de restauração.

## Contexto

`AccountabilityDocument::pdf()` era o único documento da prestação de contas em paisagem
(`setPaper('A4', 'landscape')`). Livro caixa, livro anual, carta de cobrança, CND e vistoria já saem em retrato.
As listas genéricas do ERP (`ListDocument`) continuam em paisagem: têm colunas variáveis e não fazem parte do pedido.

## Decisão

1. O PDF da prestação de contas passa a A4 **retrato**; a página no navegador declara `@page { size: A4 portrait }`
   para o Imprimir do navegador sair igual, e a folha na tela tem a largura de um A4 em pé (794 px).
2. As tabelas cabem na largura menor sem cortar colunas: datas não quebram; as duas tabelas de lançamentos
   (6 e 7 colunas) usam fonte 7,8 pt e espaçamento menor e "Competência" vira "Compet."; o recuo das datas no demonstrativo diminuiu.
3. Linhas de **total do grupo** em verde claro (#e2efe7) com texto verde-escuro; **total geral**, resultado
   (receitas − despesas) e total do resumo das contas num verde mais forte (#c9e1d3) com linha verde-escura.
4. Teste de leitura pedido pelo Djalma: **linhas alternadas** branco e verde bem claro (#f2f8f4) nos lançamentos do
   demonstrativo (por conta), no resumo das contas e no relatório de lançamentos. É só CSS (`tr.z`): se ele preferir
   sem listras, basta tirar a regra `.grid tr.z td`.
5. Comprovantes em imagem podem ocupar até 200 mm de altura (antes 150 mm, pensado para a folha deitada).
   Comprovantes em PDF continuam entrando no tamanho e orientação originais.

## Consequências

- O documento fica com mais páginas no demonstrativo quando há muitos lançamentos (a folha em pé tem mais linhas
  por página, mas o mesmo conteúdo).
- Nada muda nos dados, na API nem no link público (`/prestacao/{token}/pdf` usa o livro anual, que já era retrato).
