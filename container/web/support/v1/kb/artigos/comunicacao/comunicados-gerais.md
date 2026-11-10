---
codigo: AP-111
titulo: Enviar um comunicado por e-mail
resumo: Envia um comunicado por e-mail para as unidades do condomínio, como aviso de obra, convocação ou regra nova. Recebem as pessoas ativas com e-mail válido que não recusaram comunicado no cadastro.
categoria: comunicacao
rota: /comunicacao/comunicados-gerais
menu: Comunicação > Canais > Comunicados Gerais
icone: list-outline
situacao: funciona
relacionados: agendada, enderecos, assembleia-virtual, votacao
palavras: comunicados gerais, comunicação, AP-111
rascunho: nao
---

Envia um comunicado por e-mail para as unidades do condomínio, como aviso de obra, convocação ou regra nova. Recebem as pessoas ativas com e-mail válido que não recusaram comunicado no cadastro.

## Onde fica

No menu lateral, abra [[Comunicação > Canais > Comunicados Gerais]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela comunicados-gerais
rota: /comunicacao/comunicados-gerais
condominio: residencial-jardim-das-acacias
legenda: Tela Comunicados Gerais
1: menu | Comunicados Gerais no menu lateral
2: main form:not([method=get]) | Novo comunicado
```

## Novo comunicado

Cada unidade vira um aviso na fila. O texto sai como você escrever, sem formatação.

1. Preencha **Assunto** e **Texto**.
2. Clique em {{Enviar comunicado}}.

```tela comunicados-gerais-form
rota: /comunicacao/comunicados-gerais
condominio: residencial-jardim-das-acacias
legenda: Novo comunicado
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="subject"] | Assunto (obrigatório)
2: main form:not([method=get]) [name="body"] | Texto (obrigatório)
3: main form:not([method=get]) button:has-text("Enviar comunicado") | Botão {{Enviar comunicado}}
```

### O que preencher

- **Assunto** (obrigatório): Até 120 caracteres.
- **Texto** (obrigatório): Até 5.000 caracteres.

> Escolha **Agendar** para o comunicado sair numa data e hora; até lá ele aparece em Comunicação > Agendada e pode ser cancelado.

> Confira em **Endereços** quem vai receber antes de enviar.

> Na tela: *Modo só registro: os avisos entram na fila e ficam registrados, mas nenhum e-mail sai. "Enviado" aqui quer dizer processado, não entregue. Ver na Fila de avisos*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Comunicação**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Comunicados agendados](artigo:agendada), [Quem recebe os comunicados (endereços)](artigo:enderecos) e [Assembleia virtual](artigo:assembleia-virtual).
