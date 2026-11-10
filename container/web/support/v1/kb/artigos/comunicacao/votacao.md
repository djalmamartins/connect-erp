---
codigo: AP-017
titulo: Votação
resumo: Votar os itens da pauta da assembleia aberta, um por vez, com o voto de cada unidade presente e quite e a apuração pelo quórum do item.
categoria: comunicacao
rota: /comunicacao/votacao
menu: Comunicação > Canais > Votação
icone: checkbox-outline
situacao: funciona
relacionados: assembleia-virtual, parecer
palavras: votação, voto, assembleia, pauta, quórum, apuração, fração ideal, AP-017
rascunho: nao
---

## Para que serve

A votação acontece na assembleia **em andamento**. Um item da pauta fica em votação por vez; cada unidade presente e quite vota uma vez no item, e a apuração usa a base do voto (fração ideal ou uma unidade, um voto) e o quórum do item.

## Onde fica

No menu lateral, abra [[Comunicação > Canais > Votação]].

```tela votacao
rota: /comunicacao/votacao
condominio: residencial-jardim-das-acacias
legenda: Tela Votação
1: menu | Votação no menu lateral
2: main [data-erp-assemblies] | Assembleia aberta, pauta e voto
```

## Como votar

1. Em **Assembleia**, escolha a assembleia aberta e clique em {{Abrir}}. Com uma só aberta, ela já vem escolhida.
2. Na **Pauta**, clique em {{Abrir votação}} no item.
3. Em **Voto do item**, escolha a unidade, marque Sim, Não ou Abstenção e clique em {{Registrar voto}}. A lista só traz as unidades presentes, com direito a voto, que ainda não votaram no item.
4. Quando todos votarem, clique em {{Apurar}}. O item mostra Aprovado ou Rejeitado e o placar. Depois disso, ninguém mais vota nele.
5. Repita para os outros itens. Com todos apurados, encerre a assembleia em [Assembleia Virtual](artigo:assembleia-virtual).

>! Unidade sem presença registrada ou com conta vencida em aberto na hora da presença não vota. Registre a presença em [Assembleia Virtual](artigo:assembleia-virtual).

## Quem pode usar

Ver exige **erp.assemblies.view**; abrir a votação, registrar voto e apurar, **erp.assemblies.manage**. Cada voto fica na auditoria.
