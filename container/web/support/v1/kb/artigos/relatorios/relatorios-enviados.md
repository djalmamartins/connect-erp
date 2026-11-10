---
codigo: AP-100
titulo: Relatórios enviados
resumo: Quem exportou qual relatório em CSV, quando e de qual condomínio, lido da auditoria.
categoria: relatorios
rota: /relatorios/relatorios-enviados
menu: Relatórios > Gerais > Relatórios Enviados
icone: document-text-outline
situacao: funciona
relacionados: administracao, logs, pessoas-com-acesso
palavras: relatórios enviados, exportação, CSV, auditoria, AP-100
rascunho: nao
---

## Para que serve

Lista as exportações de relatórios em CSV feitas no ERP. É o mesmo registro da auditoria que conta o limite de exportações por hora.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Relatórios Enviados]].

```tela relatorios-enviados
rota: /relatorios/relatorios-enviados
condominio: residencial-jardim-das-acacias
legenda: Tela Relatórios Enviados
1: menu | Relatórios Enviados no menu lateral
2: main form[method=get] | Filtros: De, Até e Relatório
3: main [data-erp-access] | Exportações do período
```

## Escolher o período

Preencha **De** e **Até**, escolha o **Relatório** (ou deixe **Todos**) e clique em {{Gerar}}. O período tem limite; se a lista vier cortada, diminua o período.

## O que aparece

Data, usuário, relatório, condomínio, tamanho do arquivo e IP. O nome e o IP aparecem mascarados.

>! Quem não acessa todos os condomínios vê só as exportações dos condomínios do próprio escopo e as que fez.
