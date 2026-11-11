---
codigo:
titulo: Ligar a cobrança pelo Banco Inter
resumo: Como a administradora liga uma conta do Banco Inter à cobrança do Connect ERP: credencial e certificado no Internet Banking, cadastro seguro no servidor e o endereço do aviso de pagamento.
categoria: configuracoes
rota: /cobranca/boletos
menu: Cobrança > Boletos
icone: list-outline
situacao: guia
relacionados: boletos, remessas, integracoes, contas-bancarias
palavras: inter, banco inter, api, certificado, webhook, sandbox, credencial, integração bancária, pix
manual: sim
rascunho: nao
---

O Banco Inter é o primeiro banco ligado à cobrança do Connect ERP. Com a integração ligada, o boleto é registrado direto no Inter, já com Pix, e o pagamento é baixado sozinho quando o banco avisa.

>! Por enquanto só o **ambiente de testes do Inter** (sandbox) está liberado. Nenhum boleto real é emitido. O uso em produção depende da homologação no sandbox e de aprovação.

## Antes de começar

- A conta bancária de cobrança do condomínio precisa estar cadastrada em [[Financeiro > Contas bancárias]] com o banco **077 (Inter)**.
- Cada condomínio usa a própria conta Inter, com o CNPJ dele.
- Quem cadastra a credencial precisa de acesso ao servidor. A credencial **não** é digitada em nenhuma tela do sistema: assim ela não passa pelo navegador.

## 1. Criar a integração no Internet Banking do Inter

1. No Internet Banking do Inter Empresas, abra a área de **Integrações / API** e crie uma integração para a cobrança (boleto com Pix).
2. Marque as permissões de **leitura e escrita de boletos**.
3. Baixe o **certificado** (.crt) e a **chave** (.key) e anote o **client_id** e o **client_secret**.

> Para os testes, crie a integração no portal de sandbox do Inter (https://developers.inter.co/sandbox), e não no Internet Banking. O certificado do Internet Banking é de produção e o sandbox recusa esse certificado com o erro `tlsv1 alert unknown ca`. O sandbox só funciona de segunda a sexta, das 8h às 20h.

## 2. Guardar a credencial no cofre do sistema

No servidor, na pasta do sistema:

1. Só na primeira vez: `php tools/bank-credentials.php gerar-chave`. Cole a linha `CONNECT_BANK_VAULT_KEY=...` no arquivo `.env` do servidor e guarde uma cópia no cofre de senhas da Connect.
2. Cadastre: `php tools/bank-credentials.php cadastrar --conta=ID --banco=inter --client-id=SEU_CLIENT_ID --certificado=/caminho/Inter.crt --chave=/caminho/Inter.key`. O `client_secret` é pedido sem aparecer na tela.
3. Confira: `php tools/bank-credentials.php testar --conta=ID`. Ele só pede um token ao Inter, sem emitir nada.
4. Apague os arquivos .crt e .key da pasta de downloads.

O sistema guarda tudo cifrado. No banco de dados ficam só os 4 últimos caracteres do client_id e a validade do certificado.

## 3. Cadastrar o aviso de pagamento (webhook)

O comando do passo 2 mostra **uma única vez** o endereço do aviso, no formato `https://.../api/v1/webhooks/bank/inter/...`. Cadastre esse endereço no Inter como webhook de cobrança. Não compartilhe: quem tem o endereço consegue enviar avisos, embora o sistema sempre confira cada boleto no próprio banco antes de dar baixa.

Se o endereço vazar, rode o `cadastrar` de novo: sai um endereço novo e o antigo para de funcionar.

## Como saber se está funcionando

- Em [[Configurações > Aplicativo e notificações > Integrações]], o Inter aparece como disponível.
- Em [[Cobrança > Boletos]], emita um boleto de teste: ele deve sair com **Pix** e **PDF**.
- Em [[Cobrança > Remessas]], o lote do dia aparece como **Confirmada**.

## Desligar

`php tools/bank-credentials.php remover --conta=ID` apaga a credencial e volta a conta para o sandbox interno. Boletos já emitidos continuam no Inter.

>> Veja também: [Boletos](artigo:boletos) e [Remessas de cobrança](artigo:remessas).
