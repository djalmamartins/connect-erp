---
codigo: AP-036
titulo: Importar o arquivo de retorno do banco
resumo: O arquivo de retorno é o que o banco devolve com os boletos registrados e pagos. Importando aqui, o sistema registra os boletos e dá baixa nos pagamentos sozinho. Pagamento de valor diferente ou de boleto desconhecido vai para a fila de divergências, para alguém conferir.
categoria: cobranca
rota: /cobranca/arquivo-de-retorno
menu: Cobrança > Arquivo de Retorno
icone: cloud-upload-outline
situacao: funciona
relacionados: retornos, boletos, remessas
palavras: arquivo de retorno, cobrança, AP-036, cnab, retorno, baixa automática, divergência
rascunho: nao
---

O arquivo de retorno é o que o banco devolve com os boletos registrados e pagos. Importando aqui, o sistema registra os boletos e dá baixa nos pagamentos sozinho. Pagamento de valor diferente ou de boleto desconhecido vai para a fila de divergências, para alguém conferir.

## Onde fica

No menu lateral, abra [[Cobrança > Arquivo de Retorno]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela arquivo-de-retorno
rota: /cobranca/arquivo-de-retorno
condominio: residencial-jardim-das-acacias
legenda: Tela Arquivo de Retorno
1: menu | Arquivo de Retorno no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Divergências") | Botão {{Divergências}}
3: main form:not([method=get]) | Formulário
```

## Outros botões da tela

- {{Divergências}}: abre a fila das ocorrências que não deram baixa sozinhas.

## Como importar

1. Escolha as opções do envio e selecione o **arquivo** no seu computador.
2. Clique em {{Importar retorno}}.
3. Confira a prévia: cada linha mostra a situação e, se houver, o erro. Nada foi gravado ainda.
4. Com todas as linhas certas, clique em {{Aplicar importação}}. Se alguma linha tiver erro, corrija o arquivo e envie de novo.

```tela arquivo-de-retorno-form
rota: /cobranca/arquivo-de-retorno
condominio: residencial-jardim-das-acacias
legenda: Como importar
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="bank_account_id"] | Conta bancária (obrigatório)
2: main form:not([method=get]) [name="file"] | Arquivo de retorno bancário (CNAB 240/400) (obrigatório)
3: main form:not([method=get]) button:has-text("Importar retorno") | Botão {{Importar retorno}}
```

### O que preencher

- **Condomínio**.
- **Conta bancária** (obrigatório).
- **Arquivo de retorno bancário (CNAB 240/400)** (obrigatório): Escolha o arquivo no seu computador.

> Importar o mesmo arquivo duas vezes não duplica nada: o sistema reconhece o arquivo.

> Na tela: *Ambiente de testes. Só o retorno do banco de testes (sandbox, primeira linha SANDBOX-RETORNO;v1) é aceito. Arquivos CNAB de bancos reais ainda não são lidos.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Retornos importados e divergências](artigo:retornos), [Boletos](artigo:boletos) e [Remessas de cobrança](artigo:remessas).
