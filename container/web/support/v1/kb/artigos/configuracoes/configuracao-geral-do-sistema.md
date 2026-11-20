---
codigo:
titulo: Configuração geral do sistema (Studio)
resumo: Onde a Connect configura o sistema inteiro no Studio: nome e título do site, domínio, contato, e-mail de envio pela Amazon (AWS SES) com usuário e senha guardados cifrados, botão de e-mail de teste, aparência, redes sociais e módulos.
categoria: configuracoes
rota: /studio/settings
menu: Studio > Sistema > Configurações
icone: list-outline
situacao: guia
relacionados: fila-de-avisos, alertas, integracoes
palavras: configurações, domínio, título do site, smtp, aws, ses, amazon, e-mail de teste, remetente, senha, manutenção, tema, redes sociais
manual: sim
rascunho: nao
---

A tela **Configurações** do Studio guarda as informações que valem para o sistema todo: site, ERP, aplicativo do morador e e-mails. Só quem tem a permissão de **gerenciar configurações** abre a tela, e toda gravação fica no registro de atividades.

## Geral

Em {{Geral}} ficam o **nome do site**, o **título complementar** (aparece na aba do navegador e nos compartilhamentos), a **descrição**, o idioma, o fuso horário e o **domínio**:

- **Domínio público com HTTPS**: o endereço completo, por exemplo `https://www.connectcondominios.com.br`. É a base dos links dos e-mails e do aplicativo.
- **Domínio sem https**: só o nome, por exemplo `connectcondominios.com.br`.
- **Modo do site**: em **Manutenção**, o público vê o tema escolhido em {{Aparência}} > **Tema da manutenção**.

## E-mail pela Amazon (AWS SES)

1. No console da Amazon, em **Amazon SES**, verifique o domínio (ou o endereço) do remetente e gere as **credenciais SMTP**. O usuário SMTP começa com `AKIA` e a senha SMTP é diferente da senha da conta da Amazon.
2. Em [[Studio > Sistema > Configurações]], abra {{E-mail}}.
3. Em **Provedor**, escolha **Amazon SES** e a região da sua conta. O servidor, a porta 587 e o TLS são preenchidos sozinhos.
4. Preencha **usuário SMTP**, **senha SMTP**, **nome do remetente** e **e-mail do remetente** e clique em {{Salvar configurações}}.

>! Usuário e senha são guardados **cifrados** e nunca aparecem de novo na tela. Para trocar, digite o novo valor; para manter, deixe vazio. Para apagar, marque **Apagar o usuário e a senha SMTP guardados** e salve.

> Se o servidor tiver os dados do e-mail no arquivo `.env` (variáveis `MOVESOS_SMTP_*`), eles valem sobre a tela. O campo mostra o aviso **Definido no .env do servidor**.

## Enviar e-mail de teste

Depois de salvar, ainda em {{E-mail}}, use o quadro **Enviar e-mail de teste**: informe para quem mandar e clique em {{Enviar e-mail de teste}}. O sistema:

- manda o e-mail direto pelo servidor salvo, sem a fila;
- confere no DNS do domínio do remetente se existem **SPF**, **DKIM** e **DMARC**, que evitam a mensagem cair no spam.

A resposta diz se o servidor aceitou e o que falta no DNS. São no máximo 5 testes a cada 10 minutos.

## Contato, aparência, redes sociais e módulos

- {{Contato}}: telefone, WhatsApp, e-mail de suporte e endereço do rodapé do site.
- {{Aparência}}: logo, ícone, favicon e os temas do site, da central de ajuda, do aplicativo, do ERP e da manutenção.
- {{Redes sociais}}: links dos perfis usados no site e nos compartilhamentos.
- {{Módulos}}: bloqueia uma área inteira (Studio, ERP, aplicativo, site ou suporte). Só a administração global altera.

>> Avisos por e-mail: [Fila de avisos](artigo:fila-de-avisos)
