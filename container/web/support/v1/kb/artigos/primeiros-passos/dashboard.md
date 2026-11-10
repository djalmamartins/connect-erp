---
codigo: AP-001
titulo: Dashboard: o resumo do dia
resumo: O Dashboard é a primeira tela depois de entrar. Ele mostra, de um condomínio ou da carteira inteira, a cobrança vencida, o que vence em 7 dias, as manutenções, as receitas e despesas do mês como no balancete, a inadimplência por atraso, os alertas, os cadastros, a agenda e as últimas ações da equipe.
categoria: primeiros-passos
rota: /dashboard
menu: Dashboard
icone: speedometer-outline
situacao: funciona
palavras: dashboard, AP-001, início, painel, resumo, alertas, agenda, balancete, receitas, despesas, inadimplência, atividades
rascunho: nao
---

O Dashboard é a primeira tela depois de entrar. Todos os números vêm dos lançamentos e cadastros do sistema, no condomínio escolhido na barra superior (ou em todos os condomínios da sua conta). É daqui que você decide o que fazer primeiro.

## Onde fica

No menu lateral, abra [[Dashboard]].

```tela dashboard
rota: /dashboard
legenda: Tela Dashboard
1: menu | Dashboard no menu lateral
2: main #dash-period | Competência do resumo
3: main .erp-grid--kpi | Indicadores de cobrança e operação
4: main #dash-cash-title | Receitas e despesas do mês
5: main #dash-summary-title | Alertas e cadastros na lateral
```

## Como a tela está organizada

1. **Indicadores no topo**: **A receber vencido** (com o que vence nos próximos 7 dias), **A pagar em 7 dias**, **Manutenções atrasadas** e **Ocorrências abertas**. Clique em um cartão para abrir a tela de origem.
2. **Receitas e despesas**: o mês escolhido como no balancete, com o recebido e o pago, o resultado do mês (recebido menos pago) e o gráfico dos últimos 6 meses por competência. {{Abrir balancete}} leva ao Balancete de verificação do mesmo mês.
3. **Inadimplência por atraso**: o valor das receitas vencidas e não pagas até hoje, separado em até 30 dias, 31 a 60, 61 a 90 e acima de 90 dias.
4. **Condomínios** (só em "Todos os condomínios"): unidades, quanto da receita do mês já foi recebido e a inadimplência do mês. A situação fica **Em dia** até 5%, **Atenção** até 15% e **Crítico** acima disso.
5. **Atividades recentes** e **Agenda**: as últimas ações da equipe nos módulos do ERP e as assembleias, manutenções e reservas dos próximos 30 dias.
6. **Lateral**: **Alertas** do dia (seguros e contratos a renovar, reservas aguardando, entregas e outros) e **Cadastros**, com quantos registros cada cadastro tem.

## Trocar o mês

1. Escolha o mês no campo de competência, no alto da tela.
2. A tela recarrega com as receitas, as despesas e o quadro de condomínios daquele mês. Os indicadores, a inadimplência por atraso, os alertas e a agenda sempre olham para hoje.

## Imprimir ou exportar

- {{Balancete do mês}} abre o Balancete de verificação da competência escolhida, onde dá para imprimir e exportar.
- {{Comunicado}} abre os Comunicados gerais para escrever e enviar um aviso.

> Escolha **Todos os condomínios** na barra superior para ver a carteira inteira; escolha um condomínio para ver só os números dele.

> Cada bloco aparece para quem tem permissão no módulo dele: receitas, despesas e inadimplência pedem acesso aos lançamentos; as atividades da equipe pedem acesso à auditoria.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Dashboard**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta. O Dashboard só lê: nada é gravado por ele.
