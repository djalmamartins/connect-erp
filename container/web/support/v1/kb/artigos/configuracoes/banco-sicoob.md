---
codigo:
titulo: Ligar a cobrança pelo Sicoob
resumo: Como a administradora liga uma conta do Sicoob à cobrança do ERP Connect Condomínios: client ID e access token do sandbox no portal do Sicoob, número do cliente, teste automático pela tela Integrações e o que já funciona.
categoria: configuracoes
rota: /configuracoes/integracoes
menu: Configurações > Aplicativo e notificações > Integrações
icone: list-outline
situacao: guia
relacionados: banco-inter, boletos, remessas, integracoes, contas-bancarias
palavras: sicoob, banco sicoob, cooperativa, 756, api, sandbox, access token, número do cliente, código do beneficiário, integração bancária, pix, boleto
manual: sim
rascunho: nao
---

O Sicoob é ligado à cobrança do ERP Connect Condomínios pelo mesmo caminho do Banco Inter. Com a integração ligada, o boleto é registrado direto no Sicoob, já com o QR do Pix, e o pagamento é baixado sozinho pela consulta automática de hora em hora.

>! Por enquanto só o **sandbox do Sicoob** (ambiente de testes) está liberado. Nenhum boleto real é emitido. O uso em produção depende de homologação e de aprovação.

## Antes de começar

- A conta bancária de cobrança do condomínio precisa estar cadastrada em [[Financeiro > Contas bancárias]] com o banco **756 (Sicoob)**, a agência e o número da conta corrente. O condomínio precisa ter endereço completo (CEP, rua, bairro, cidade e UF).
- Em **Carteira**, deixe em branco para usar a modalidade 1 (cobrança simples com registro) ou informe o número da modalidade que a cooperativa passar.
- Cada condomínio usa a própria conta no Sicoob, com o CNPJ dele.
- Para enviar a credencial você precisa das permissões de **gerenciar configurações** e **gerenciar contas bancárias**.

## 1. Pegar a credencial do sandbox

1. Entre no portal de desenvolvedores do Sicoob (developers.sicoob.com.br) e crie o seu login.
2. Abra a área **Sandbox**. O portal mostra um **client ID** e um **access token** fixos para testes. Copie os dois.

>! No sandbox o Sicoob responde sempre com um boleto de exemplo, seja qual for o pedido. Isso serve para conferir a ligação, não os dados do boleto.

## 2. Enviar pela tela

1. Abra [[Configurações > Aplicativo e notificações > Integrações]] e vá até o card **Banco Inter, Cora e Sicoob**.
2. Escolha a conta do Sicoob. O campo de secret passa a se chamar **Access token do sandbox** e aparece o campo **Número do cliente**.
3. Preencha o **client ID**, o **access token** e o **número do cliente** (o código do beneficiário na cooperativa, só números; no sandbox pode ser um número de exemplo). No sandbox não precisa de certificado.
4. Clique em **Testar e gravar**. O sistema faz uma consulta de teste ao Sicoob, sem emitir nada.
5. Se o teste passar, a credencial é gravada cifrada e a conta passa a usar o Sicoob. Se falhar, **nada é gravado** e a tela explica o motivo.

## O que já funciona com o Sicoob

- Emitir boleto com Pix em [[Cobrança > Boletos]] e todos de uma vez pela fatura (**Gerar boletos**).
- Consultar e cancelar (o Sicoob chama de "baixa").
- Baixa sozinha pela consulta de hora em hora (o aviso de pagamento por webhook fica para quando o servidor tiver endereço público).
- Extrato e saldo em [[Importações > Extrato bancário]], com **Buscar extrato do banco**.
- O sandbox do Sicoob não tem simulação de pagamento, então o botão **Simular pagamento** não aparece para ele.

## Desligar

No card, clique em **Remover** na linha da conta. A credencial é apagada e a conta volta para o banco de testes interno. Boletos já emitidos continuam no Sicoob.

>> Veja também: [Ligar a cobrança pelo Banco Inter](artigo:banco-inter) e [Boletos](artigo:boletos).
