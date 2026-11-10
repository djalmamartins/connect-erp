---
codigo: AP-167
titulo: Configurar fração Ideal
resumo: Fração ideal e área privativa de todas as unidades do condomínio em uma grade. Divida igualmente ou pela área e ajuste o que for diferente. A soma das frações precisa dar 100%.
categoria: configuracoes
rota: /configuracoes/fracao-ideal
menu: Configurações > Financeiro e parâmetros > Fração Ideal
icone: options-outline
situacao: funciona
palavras: fração ideal, configurações, AP-167
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Fração Ideal]].

```tela fracao-ideal
rota: /configuracoes/fracao-ideal
condominio: residencial-jardim-das-acacias
legenda: Tela Fração Ideal
1: menu | Fração Ideal no menu lateral
2: main :is(a,button):has-text("Cadastro rápido") | Botão {{Cadastro rápido}}
3: main :is(a,button):has-text("Unidades") | Botão {{Unidades}}
4: main table thead | Lista com Unidade, Andar, Tipo, Área privativa (m²), Fração ideal e %
```

## Como preencher

Depois de preencher, clique em {{Salvar}}. O sistema avisa no próprio campo o que precisa corrigir.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

