---
codigo: AP-001
titulo: Dashboard
resumo: Situação do Residencial Jardim das Acácias. Para a visão geral, escolha "Todos os condomínios" na barra superior.
categoria: primeiros-passos
rota: /dashboard
menu: Dashboard
icone: speedometer-outline
relacionados: escolher-o-condominio, encontrar-uma-tela
situacao: funciona
palavras: dashboard, AP-001
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Dashboard]].

```tela dashboard
rota: /dashboard
condominio: residencial-jardim-das-acacias
legenda: Tela Dashboard
1: menu | Dashboard no menu lateral
2: main :is(a,button):has-text("Comunicado rápido") | Botão {{Comunicado rápido}}
3: main :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main table thead | Lista com Mês, Receitas e Despesas
```

## O que a tela mostra

- **Resumo do Residencial Jardim das Acácias**
- **A receber vencido**
- **A pagar em 7 dias**
- **Manutenções atrasadas**
- **Ocorrências abertas**
- **Alertas**
- **Receitas e despesas**
- **Inadimplência por atraso**
- **Atividades recentes**
- **Agenda**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Dashboard**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

