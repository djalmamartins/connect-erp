---
codigo: AP-137
titulo: Enviar aviso de cobrança
resumo: Envia por e-mail o aviso de débitos em aberto para as unidades com conta vencida. Só recebe quem autorizou cobrança por e-mail, e a unidade avisada nos últimos 7 dias fica de fora para não repetir.
categoria: comunicacao
rota: /comunicacao/cobranca
menu: Comunicação > Mala direta > Cobrança
icone: list-outline
situacao: funciona
relacionados: agendada, atendimento-cobranca, enderecos
palavras: cobrança, comunicação, AP-137
rascunho: nao
---

Envia por e-mail o aviso de débitos em aberto para as unidades com conta vencida. Só recebe quem autorizou cobrança por e-mail, e a unidade avisada nos últimos 7 dias fica de fora para não repetir.

## Onde fica

No menu lateral, abra [[Comunicação > Mala direta > Cobrança]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela cobranca
rota: /comunicacao/cobranca
condominio: residencial-jardim-das-acacias
legenda: Tela Cobrança
1: menu | Cobrança no menu lateral
2: main :is(form[method=get], .erp-filters) | Filtros: Data de referência
3: main table thead | Colunas da lista
```

## Quando enviar

1. Preencha **Atraso mínimo (dias)**.
2. Clique em {{Enviar aviso de cobrança}}.

```tela cobranca-form
rota: /comunicacao/cobranca
condominio: residencial-jardim-das-acacias
legenda: Quando enviar
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="min_days_late"] | Atraso mínimo (dias) (obrigatório)
2: main form:not([method=get]) button:has-text("Enviar aviso de cobrança") | Botão {{Enviar aviso de cobrança}}
```

### O que preencher

- **Atraso mínimo (dias)** (obrigatório).

## Pesquisar e filtrar

Use os filtros no alto da lista: **Data de referência**.
Depois clique em {{Atualizar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Marcar todas as unidades**, **Unidade**, **Contas**, **Em aberto**, **Vencimento mais antigo**, **Atraso** e **Aviso**.

## Como enviar

1. Ajuste a **Data de referência** e o **Atraso mínimo (dias)**.
2. Marque as unidades que vão receber, ou **Marcar todas as unidades**.
3. Escolha **Agora** ou **Agendar**.
4. Clique em {{Enviar aviso de cobrança}}.

> Na tela: *Modo só registro: os avisos entram na fila e ficam registrados, mas nenhum e-mail sai. "Enviado" aqui quer dizer processado, não entregue. Ver na Fila de avisos*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Comunicação**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Comunicados agendados](artigo:agendada), [Atender as unidades em atraso](artigo:atendimento-cobranca) e [Quem recebe os comunicados (endereços)](artigo:enderecos).
