---
codigo:
titulo: Ligar a cobrança pela CondoConta
resumo: Como a administradora liga uma conta da CondoConta à cobrança do Connect ERP: pedir o client ID e o client secret do sandbox, enviar pela tela Integrações, o teste automático e o que já funciona.
categoria: configuracoes
rota: /configuracoes/integracoes
menu: Configurações > Aplicativo e notificações > Integrações
icone: list-outline
situacao: guia
relacionados: banco-inter, banco-sicoob, boletos, remessas, integracoes, contas-bancarias
palavras: condoconta, condo conta, conta digital do condomínio, api, sandbox, client id, client secret, integração bancária, pix, boleto
manual: sim
rascunho: nao
---

A CondoConta é ligada à cobrança do Connect ERP pelo mesmo caminho do Banco Inter. Com a integração ligada, o boleto é registrado direto na CondoConta, já com o Pix, e o pagamento é baixado sozinho pela consulta automática de hora em hora.

>! Por enquanto só o **sandbox da CondoConta** (ambiente de testes) está liberado. Nenhum boleto real é emitido. O uso em produção depende de homologação e de aprovação.

## Antes de começar

- A conta de cobrança do condomínio precisa estar cadastrada em [[Financeiro > Contas bancárias]] com o código do banco da CondoConta, a agência e o número da conta. O condomínio precisa ter endereço completo (CEP, rua, bairro, cidade e UF).
- Cada condomínio usa a própria conta na CondoConta, com o CNPJ dele.
- Para enviar a credencial você precisa das permissões de **gerenciar configurações** e **gerenciar contas bancárias**.

## 1. Pedir a credencial do sandbox

1. Mande um e-mail para **api@condoconta.com.br** com o CNPJ do condomínio e o e-mail de quem cuida da integração.
2. Peça o **client ID** e o **client secret** do **sandbox** da CondoConta Open API.

>! Nunca mande o client secret por chat ou e-mail para ninguém. Ele entra só pela tela Integrações.

## 2. Enviar pela tela

1. Abra [[Configurações > Aplicativo e notificações > Integrações]] e vá até o card **Banco Inter, Cora, Sicoob e CondoConta**.
2. Escolha a conta da CondoConta.
3. Preencha o **client ID** e o **client secret**. A CondoConta não usa certificado: deixe o pacote do banco vazio.
4. Clique em **Testar e gravar**. O sistema pede um token de teste à CondoConta, sem emitir nada.
5. Se o teste passar, a credencial é gravada cifrada e a conta passa a usar a CondoConta. Se falhar, **nada é gravado** e a tela explica o motivo.

## O que já funciona com a CondoConta

- Emitir boleto com Pix em [[Cobrança > Boletos]] e todos de uma vez pela fatura (**Gerar boletos**). Na CondoConta o boleto vai de R$ 2,50 a R$ 250.000,00.
- Consultar, baixar o PDF e cancelar.
- Baixa sozinha pela consulta de hora em hora.
- Extrato e saldo em [[Importações > Extrato bancário]], com **Buscar extrato do banco**.
- A CondoConta não tem simulação de pagamento, então o botão **Simular pagamento** não aparece para ela.

## Desligar

No card, clique em **Remover** na linha da conta. A credencial é apagada e a conta volta para o banco de testes interno. Boletos já emitidos continuam na CondoConta.

>> Veja também: [Ligar a cobrança pelo Banco Inter](artigo:banco-inter) e [Boletos](artigo:boletos).
