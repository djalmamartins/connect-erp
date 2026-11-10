---
codigo: AP-161
titulo: Configurar saldo inicial (plano de contas)
resumo: Alterações valem para os condomínios indicados e ficam registradas no histórico.
categoria: configuracoes
rota: /configuracoes/saldo-inicial-plano-de-contas
menu: Configurações > Financeiro e parâmetros > Saldo inicial (plano de contas)
icone: options-outline
situacao: exemplo
palavras: saldo inicial (plano de contas), configurações, AP-161
rascunho: nao
---

>! Esta tela ainda mostra **dados de exemplo**. Ela já abre com os filtros e as colunas definitivos, mas os números reais chegam numa próxima versão. Use-a para conhecer o relatório; não tome decisões com os valores mostrados.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Saldo inicial (plano de contas)]].

```tela saldo-inicial-plano-de-contas
rota: /configuracoes/saldo-inicial-plano-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Saldo inicial (plano de contas)
1: menu | Saldo inicial (plano de contas) no menu lateral
2: main .erp-card | Histórico de alterações
```

## Como preencher

- **Condomínio** (obrigatório)
- **Data de referência** (obrigatório)
- **Conta do plano** (obrigatório)
- **Saldo inicial** (obrigatório)

Depois de preencher, clique em {{Salvar}}. O sistema avisa no próprio campo o que precisa corrigir.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

