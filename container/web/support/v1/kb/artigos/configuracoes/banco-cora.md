---
codigo:
titulo: Ligar a cobrança pela Cora
resumo: Como a administradora liga uma conta da Cora à cobrança do ERP Connect Condomínios: credencial do ambiente de teste na Cora Web, envio do certificado pela tela Integrações, teste automático e o que já funciona.
categoria: configuracoes
rota: /configuracoes/integracoes
menu: Configurações > Aplicativo e notificações > Integrações
icone: list-outline
situacao: guia
relacionados: banco-inter, boletos, remessas, integracoes, contas-bancarias
palavras: cora, banco cora, api, certificado, integração direta, stage, ambiente de teste, credencial, integração bancária, pix, boleto
manual: sim
rascunho: nao
---

A Cora é o segundo banco ligado à cobrança do ERP Connect Condomínios, pelo mesmo caminho do Banco Inter. Com a integração ligada, o boleto é registrado direto na Cora, já com Pix, e o pagamento é baixado sozinho pela consulta automática de hora em hora.

>! Por enquanto só o **ambiente de teste da Cora** (stage) está liberado. Nenhum boleto real é emitido. O uso em produção depende da homologação no ambiente de teste e de aprovação.

## Antes de começar

- A conta da Cora precisa ter o plano **Cora Pro**, que é o que libera a **Integração Direta** (a API).
- A conta bancária de cobrança do condomínio precisa estar cadastrada em [[Financeiro > Contas bancárias]] com o banco **403 (Cora)**, e o condomínio precisa ter endereço completo (CEP, rua, bairro, cidade e UF).
- Cada condomínio usa a própria conta Cora, com o CNPJ dele.
- Para enviar a credencial você precisa das permissões de **gerenciar configurações** e **gerenciar contas bancárias**.

## 1. Gerar a credencial do ambiente de teste na Cora

1. Na Cora Web (app.cora.com.br), vá em **Conta > Integrações via APIs > Integração Direta** e clique em **Solicitar integração** (uma vez só; aceite os termos). O client ID é criado na hora.
2. Ainda em **Integração Direta**, abra **Ambiente de teste > Dados da sua credencial e client-id**.
3. Clique em **Gerar uma nova credencial**. O certificado e a chave privada baixam sozinhos para o computador. Anote o **client ID** que aparece na mesma tela.

>! A credencial de teste só pode ser gerada **duas vezes por ano** pela Cora Web. Guarde os arquivos em lugar seguro até enviar. A credencial de **produção** é outra e o ambiente de teste recusa.

## 2. Enviar pela tela

1. Abra [[Configurações > Aplicativo e notificações > Integrações]] e vá até o card **Banco Inter e Cora**.
2. Escolha a conta da Cora (o campo de secret some: a Cora não usa), preencha o **client ID** e, em **Pacote do banco**, escolha o certificado e a chave juntos (ou o ZIP, se vier compactado).
3. Clique em **Testar e gravar**. O sistema confere se a chave pertence ao certificado e se ele está na validade, e pede um acesso de teste à Cora, sem emitir nada.
4. Se o teste passar, a credencial é gravada cifrada e a conta passa a usar a Cora. Se falhar, **nada é gravado** e a tela explica o motivo.

## O que já funciona com a Cora

- Emitir boleto com Pix em [[Cobrança > Boletos]] e todos de uma vez pela fatura (**Gerar boletos**).
- Consultar, cancelar e, no ambiente de teste, **Simular pagamento**.
- Baixa sozinha pela consulta de hora em hora (o aviso de pagamento por webhook fica para quando o servidor tiver endereço público).
- Extrato em [[Importações > Extrato bancário]], com **Buscar extrato do banco**.
- A carta de cobrança mostra o QR do Pix do boleto.

## Desligar

No card, clique em **Remover** na linha da conta. A credencial é apagada e a conta volta para o banco de testes interno. Boletos já emitidos continuam na Cora.

>> Veja também: [Ligar a cobrança pelo Banco Inter](artigo:banco-inter) e [Boletos](artigo:boletos).
