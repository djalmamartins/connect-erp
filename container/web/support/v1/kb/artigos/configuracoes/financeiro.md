---
codigo: AP-157
titulo: Configurar financeiro
resumo: Alterações valem para os condomínios indicados e ficam registradas no histórico.
categoria: configuracoes
rota: /configuracoes/financeiro
menu: Configurações > Financeiro e parâmetros > Financeiro
icone: options-outline
situacao: exemplo
palavras: financeiro, configurações, AP-157
rascunho: nao
---

>! Esta tela ainda mostra **dados de exemplo**. Ela já abre com os filtros e as colunas definitivos, mas os números reais chegam numa próxima versão. Use-a para conhecer o relatório; não tome decisões com os valores mostrados.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Financeiro]].

```tela financeiro
rota: /configuracoes/financeiro
condominio: residencial-jardim-das-acacias
legenda: Tela Financeiro
1: menu | Financeiro no menu lateral
2: main .erp-card | Histórico de alterações
```

## Como preencher

- **Multa por atraso (%)** (obrigatório)
- **Juros ao mês (%)** (obrigatório)
- **Dias de carência**
- **Correção**
- **Desconto por pontualidade (%)**
- **Conta bancária padrão**
- **Carteira de cobrança**

Depois de preencher, clique em {{Salvar}}. O sistema avisa no próprio campo o que precisa corrigir.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

