---
codigo: AP-092
titulo: Relatório Parecer
resumo: O resultado de cada item da pauta da assembleia, com o placar e o quórum, e a ata gerada no encerramento.
categoria: relatorios
rota: /relatorios/parecer
menu: Relatórios > Gerais > Parecer
icone: document-text-outline
situacao: funciona
relacionados: assembleia-virtual, votacao, listagem-de-presencas
palavras: parecer, resultado, ata, assembleia, votação, relatórios, AP-092
rascunho: nao
---

## Para que serve

Mostra o resultado da assembleia: cada item com a base do voto, o quórum, os votos Sim, Não e Abstenção, o total do condomínio e se foi aprovado. Com a assembleia encerrada, mostra também a **ata** e o código de conferência (SHA-256).

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Parecer]].

```tela parecer
rota: /relatorios/parecer
condominio: residencial-jardim-das-acacias
legenda: Tela Parecer
1: menu | Parecer no menu lateral
2: main [data-erp-assemblies] | Assembleia escolhida, resultados e ata
```

## Como usar

1. Em **Assembleia**, escolha a assembleia e clique em {{Abrir}}. A mais recente encerrada já vem escolhida.
2. Confira os resultados. Na base **fração ideal**, os votos aparecem em fração (de 0 a 1); na base **uma unidade, um voto**, em número de unidades.
3. Use {{Imprimir}} ou {{Exportar CSV}}.

A ata é gerada no encerramento e não muda mais. O código SHA-256 permite conferir que o texto é o mesmo.

## Quem pode usar

Exige **erp.assemblies.view**. A exportação fica na auditoria e tem limite de exportações por hora.
