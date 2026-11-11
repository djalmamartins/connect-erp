---
codigo: AP-037
titulo: Remessas de cobrança
resumo: Boletos enviados ao banco, agrupados por conta bancária e dia, com a confirmação do registro. No Banco Inter não há arquivo de remessa: cada boleto é registrado na hora.
categoria: cobranca
rota: /cobranca/remessas
menu: Cobrança > Remessas
icone: list-outline
situacao: funciona
relacionados: boletos, banco-inter, retornos, arquivo-de-retorno
palavras: remessas, cobrança, AP-037, inter, registro, cnab, lote
manual: sim
rascunho: nao
---

Boletos enviados ao banco, agrupados por conta bancária e dia, com a confirmação do registro.

> No **Banco Inter** não existe arquivo de remessa. Cada boleto é registrado na hora, quando você clica em {{Emitir}} na tela [Boletos](artigo:boletos). Esta tela mostra os lotes enviados e o que o banco já confirmou. Bancos que trabalham com arquivo CNAB entram aqui quando forem integrados.

## Onde fica

No menu lateral, abra [[Cobrança > Remessas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela remessas
rota: /cobranca/remessas
condominio: residencial-jardim-das-acacias
legenda: Tela Remessas
1: menu | Remessas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Ver boletos") | Botão {{Ver boletos}}
3: main form.erp-filters | Filtros: De, Até e Situação
4: main table thead | Colunas da lista
```

## O que a lista mostra

Cada linha é um lote: os boletos de uma conta bancária emitidos no mesmo dia.

- **Enviada em**: o dia do envio.
- **Conta bancária**: a conta de cobrança (com o condomínio, quando a barra superior está em "Todos").
- **Forma de envio**: *Registro online (API do Inter), sem arquivo* ou *Sandbox interno (testes)*.
- **Boletos** e **Confirmados**: quantos foram enviados e quantos o banco já registrou. Embaixo aparecem os que ainda aguardam o banco e os recusados.
- **Valor**: a soma dos boletos do lote.
- **Situação**:
  - **Confirmada**: o banco registrou todos (ou foram pagos ou cancelados depois).
  - **Enviada**: algum boleto ainda aguarda a confirmação do banco. Abra [Boletos](artigo:boletos) e use {{Consultar}}.
  - **Com recusa**: o banco recusou algum boleto. Veja o motivo na linha do boleto, corrija (por exemplo, o CPF do responsável) e emita de novo.

## Pesquisar e filtrar

Use **De**, **Até** e **Situação** e clique em {{Filtrar}}. Para voltar à lista completa, clique em {{Limpar filtros}}.

## Quem pode usar

A tela aparece para quem tem a permissão de ver boletos (**ERP Cobrança**). É só consulta: emitir, consultar e cancelar ficam na tela Boletos. Cada pessoa só vê os condomínios vinculados à própria conta.

>> Veja também: [Boletos](artigo:boletos), [Ligar a cobrança pelo Banco Inter](artigo:banco-inter) e [Retornos](artigo:retornos).
