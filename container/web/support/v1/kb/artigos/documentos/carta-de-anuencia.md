---
codigo: AP-130
titulo: Emitir a carta de anuência
resumo: Carta em que a administradora declara que a unidade não tem débito vencido, para a finalidade informada (por exemplo, venda ou locação).
categoria: documentos
rota: /documentos/carta-de-anuencia
menu: Documentos > Carta de anuência
icone: ribbon-outline
situacao: funciona
relacionados: malotes, empenhos, copias-de-cheques, avisos-de-recebimento
palavras: carta de anuência, documentos, AP-130
rascunho: nao
---

Carta em que a administradora declara que a unidade não tem débito vencido, para a finalidade informada (por exemplo, venda ou locação).

## Onde fica

No menu lateral, abra [[Documentos > Carta de anuência]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela carta-de-anuencia
rota: /documentos/carta-de-anuencia
condominio: residencial-jardim-das-acacias
legenda: Tela Carta de anuência
1: menu | Carta de anuência no menu lateral
2: main form:not([method=get]) | Emitir
```

## Emitir

O documento é gerado das contas a receber da unidade e fica gravado com um código de verificação. Depois de emitido, não muda; para corrigir, cancele e emita outro.

1. Preencha **Unidade**, **Data de referência** e **Finalidade**.
2. Clique em {{Emitir documento}}.

```tela carta-de-anuencia-form
rota: /documentos/carta-de-anuencia
condominio: residencial-jardim-das-acacias
legenda: Emitir
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="unit_id"] | Unidade (obrigatório)
2: main form:not([method=get]) [name="as_of"] | Data de referência (obrigatório)
3: main form:not([method=get]) [name="purpose"] | Finalidade (obrigatório)
4: main form:not([method=get]) button:has-text("Emitir documento") | Botão {{Emitir documento}}
```

### O que preencher

- **Unidade** (obrigatório).
- **Data de referência** (obrigatório).
- **Finalidade** (obrigatório): Exemplo: Ex.: venda da unidade.

> Na tela: *Escolha a unidade acima e emita o primeiro.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Malotes](artigo:malotes), [Empenhos](artigo:empenhos) e [Cópias de cheques](artigo:copias-de-cheques).
