---
codigo:
titulo: Chamados que chegam pelo WhatsApp (Connect Talk)
resumo: Toda conversa atendida no Connect Talk vira um chamado do Help Desk, com o nome, o condomínio e a unidade do morador puxados do cadastro do ERP. Veja como reconhecer esses chamados e como ligar o Talk ao ERP.
categoria: operacional
rota: /operacional/chamados
menu: Operacional > Rotina > Chamados
icone: chatbubbles-outline
situacao: guia
relacionados: chamados, proprietarios-e-inquilinos, usuarios-do-sistema
palavras: whatsapp, talk, connect talk, chamado, protocolo, help desk, integração, token, morador, telefone
manual: sim
rascunho: nao
---

O **Connect Talk** é o atendimento da Connect pelo WhatsApp. Ele fica num endereço separado do ERP e conversa com o ERP pela API. Quando um morador escreve, o Talk pergunta ao ERP de quem é aquele telefone e, quando a conversa é atendida, abre um chamado no Help Desk.

## Como o Talk sabe quem está escrevendo

O Talk procura o telefone em [[Cadastros > Proprietários e inquilinos]]. Só entram cadastros **ativos**, com vínculo **vigente** (sem data de saída ou com saída futura) e dos condomínios que o usuário de integração pode ver.

- O telefone pode estar com ou sem o 55 e com ou sem o nono dígito: o ERP confere as duas formas.
- Se o mesmo telefone estiver em mais de um condomínio ou unidade, o Talk mostra o mais recente e avisa quantos cadastros existem.
- O Talk recebe só nome, vínculo, e-mail, condomínio e unidade. CPF e dados financeiros **não** saem do ERP.

> Para o Talk reconhecer o morador, mantenha o telefone celular atualizado no cadastro.

## O chamado no Help Desk

Cada conversa vira **um** chamado, mesmo que o Talk tente de novo por falha de rede. Em [[Operacional > Rotina > Chamados]] ele aparece com:

- origem **WhatsApp (Connect Talk)** (use o filtro de origem para ver só esses);
- o nome do cadastro do ERP, o condomínio, a unidade e o telefone de quem escreveu;
- no texto, o link **Conversa no Connect Talk**, que abre a conversa no Talk;
- o número `CH...`, que é o protocolo que o morador recebe no WhatsApp.

Quando o atendente do Talk **transfere** a conversa de setor (Financeiro, Manutenção, Administrativo), a área do chamado muda e fica uma nota interna com o setor e a observação. Quando ele **encerra** a conversa, o chamado fica **Resolvido**, com a resolução como resposta.

>! Contato que não está cadastrado também gera chamado, como "Contato do WhatsApp (final 7777)", sem condomínio. Confira e complete na triagem.

## Ligar o Talk ao ERP (equipe de TI)

1. Crie em [[Configurações > Acesso > Usuários do Sistema]] um usuário só para a integração, por exemplo "Connect Talk (integração)", com um papel que tenha a permissão de atender chamados (`support.manage`) e acesso aos condomínios que o Talk vai atender.
2. No terminal do servidor do ERP, gere o token: `php tools/talk-token.php criar --usuario=email-do-usuario`. Ele aparece **uma vez só**.
3. No `.env` do Talk, preencha `ERP_API_URL` com o endereço da API do ERP (termina em `/api/v1`) e `ERP_API_TOKEN` com o token. Nunca coloque o token no Git.
4. Para ver os tokens: `php tools/talk-token.php listar`. Para cortar o acesso na hora: `php tools/talk-token.php revogar --id=N`.

> Se o ERP ficar fora do ar, o Talk continua atendendo; o chamado só não é aberto naquele momento.

>> Tudo sobre [Chamados](artigo:chamados)
