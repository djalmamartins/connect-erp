---
codigo: AP-099
titulo: Boletos enviados
resumo: Os avisos de boleto emitido que entraram na fila de envio por e-mail no período.
categoria: relatorios
rota: /relatorios/boletos-enviados
menu: Relatórios > Gerais > Boletos Enviados
icone: document-text-outline
situacao: funciona
relacionados: pessoas-com-acesso, relatorios-enviados, logs, relatorio-de-enderecos
palavras: boletos enviados, relatórios, AP-099
rascunho: nao
---

Os avisos de boleto emitido que entraram na fila de envio por e-mail no período.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Boletos Enviados]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela boletos-enviados
rota: /relatorios/boletos-enviados
condominio: residencial-jardim-das-acacias
legenda: Tela Boletos Enviados
1: menu | Boletos Enviados no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De e Até
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

> Na tela: *Nada neste filtro Não há registros para os filtros escolhidos.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Pessoas com acesso aos condomínios](artigo:pessoas-com-acesso), [Relatórios enviados](artigo:relatorios-enviados) e [Logs de auditoria](artigo:logs).
