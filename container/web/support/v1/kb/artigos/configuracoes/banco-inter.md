---
codigo:
titulo: Ligar a cobrança pelo Banco Inter
resumo: Como a administradora liga uma conta do Banco Inter à cobrança do Connect ERP: aplicação no sandbox do Inter, envio do pacote do certificado pela tela Integrações, teste automático e o endereço do aviso de pagamento.
categoria: configuracoes
rota: /configuracoes/integracoes
menu: Configurações > Aplicativo e notificações > Integrações
icone: list-outline
situacao: guia
relacionados: boletos, remessas, integracoes, contas-bancarias
palavras: inter, banco inter, api, certificado, zip, webhook, sandbox, credencial, integração bancária, pix, unknown ca
manual: sim
rascunho: nao
---

O Banco Inter é o primeiro banco ligado à cobrança do Connect ERP. Com a integração ligada, o boleto é registrado direto no Inter, já com Pix, e o pagamento é baixado sozinho quando o banco avisa.

>! Por enquanto só o **ambiente de testes do Inter** (sandbox) está liberado. Nenhum boleto real é emitido. O uso em produção depende da homologação no sandbox e de aprovação.

## Antes de começar

- A conta bancária de cobrança do condomínio precisa estar cadastrada em [[Financeiro > Contas bancárias]] com o banco **077 (Inter)**, e o condomínio precisa ter endereço completo (CEP, rua, cidade e UF).
- Cada condomínio usa a própria conta Inter, com o CNPJ dele.
- Para enviar a credencial você precisa das permissões de **gerenciar configurações** e **gerenciar contas bancárias**.

## 1. Criar a aplicação no sandbox do Inter

1. Entre no portal de sandbox do Inter (https://developers.inter.co/sandbox) com a conta Inter Empresas. O sandbox só funciona de **segunda a sexta, das 8h às 20h**.
2. Crie uma aplicação com as permissões de **leitura e escrita de boletos**.
3. Baixe o pacote (ZIP com o certificado `.crt` e a chave `.key`) e anote o **client ID** e o **client secret**.

>! Não use a integração criada no Internet Banking: o certificado de lá é de **produção**, e o sandbox recusa com o erro `unknown ca`. Guarde esse certificado fora do computador de uso diário até a produção ser aprovada.

## 2. Enviar pela tela

1. Abra [[Configurações > Aplicativo e notificações > Integrações]] e vá até o card **Banco Inter**.
2. Escolha a conta do Inter, preencha o **client ID** e o **client secret** e, em **Pacote do banco**, escolha o ZIP baixado (ou os arquivos `.crt` e `.key` juntos).
3. Clique em **Testar e gravar**. O sistema abre o pacote, confere se a chave pertence ao certificado e se ele está na validade, e pede um acesso de teste ao Inter, sem emitir nada.
4. Se o teste passar, a credencial é gravada cifrada e a conta passa a usar o Inter. Se falhar, **nada é gravado** e a tela explica o motivo:
   - "certificado de produção": volte ao passo 1 e use a aplicação do sandbox;
   - "client ID ou secret": confira se são da mesma aplicação do pacote;
   - "não respondeu": o sandbox pode estar fora do horário; tente de novo depois.

Só marque **Gravar mesmo se o teste falhar** quando souber por que ele falhou (por exemplo, fora do horário do sandbox).

O sistema guarda tudo cifrado e não grava os arquivos em disco. Na tela ficam só os 4 últimos caracteres do client ID e a validade do certificado. Depois de enviar, apague o ZIP da pasta de downloads.

## 3. Cadastrar o aviso de pagamento (webhook)

Depois de gravar, a tela mostra **uma única vez** o endereço do aviso, no formato `https://.../api/v1/webhooks/bank/inter/...`, com o botão **Copiar**. Cadastre esse endereço no Inter como webhook de cobrança. Não compartilhe: quem tem o endereço consegue enviar avisos, embora o sistema sempre confira cada boleto no próprio banco antes de dar baixa.

Se o endereço vazar, envie a credencial de novo: sai um endereço novo e o antigo para de funcionar.

## Como saber se está funcionando

- No card **Banco Inter**, a conta aparece com a credencial **Gravada**. O botão **Testar** confere a conexão de novo a qualquer momento.
- Em [[Cobrança > Boletos]], emita um boleto de teste: ele deve sair com **Pix** e **PDF**.
- Em [[Cobrança > Remessas]], o lote do dia aparece como **Confirmada**.

## Desligar

No card **Banco Inter**, clique em **Remover** na linha da conta. A credencial é apagada e a conta volta para o banco de testes interno. Boletos já emitidos continuam no Inter.

## Pelo terminal do servidor

Quem tem acesso ao servidor também pode cadastrar pelo comando `php tools/bank-credentials.php` (`gerar-chave`, `cadastrar`, `testar`, `situacao` e `remover`). A chave do cofre (`CONNECT_BANK_VAULT_KEY` no `.env`) é necessária nos dois caminhos e só é gerada uma vez por ambiente.

>> Veja também: [Boletos](artigo:boletos) e [Remessas de cobrança](artigo:remessas).
