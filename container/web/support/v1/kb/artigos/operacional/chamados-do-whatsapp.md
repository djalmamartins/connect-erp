---
codigo:
titulo: Chamados que chegam pelo WhatsApp (Connect Talk)
resumo: Toda conversa atendida no Connect Talk vira um chamado do Help Desk, com o nome, o condomínio e a unidade do morador puxados do cadastro do ERP. Veja como reconhecer esses chamados, quando o Talk manda a 2ª via do boleto e como ligar o Talk ao ERP.
categoria: operacional
rota: /operacional/chamados
menu: Agenda > Atendimento > Chamados
icone: chatbubbles-outline
situacao: guia
relacionados: chamados, proprietarios-e-inquilinos, usuarios-do-sistema
palavras: whatsapp, talk, connect talk, chamado, protocolo, help desk, integração, token, morador, telefone, boleto, segunda via, 2ª via, pix
manual: sim
rascunho: nao
---

O **Connect Talk** é o atendimento da Connect pelo WhatsApp. O Talk em uso roda num computador no escritório da Connect e conversa com o ERP pela API (veja a última seção). Quando um morador escreve, o Talk descobre de quem é aquele telefone e abre um chamado no Help Desk.

## Onde fica o Talk

O Talk roda só no computador do escritório. Desde 20/11/2026 o ERP não tem mais uma versão própria do Talk: o item **Talk** do alternador de ambientes abre o Talk do escritório, e o ERP só responde às consultas que ele faz pela API.

## Como o Talk sabe quem está escrevendo

O Talk procura o telefone em [[Cadastros > Proprietários e inquilinos]]. Só entram cadastros **ativos**, com vínculo **vigente** (sem data de saída ou com saída futura) e de condomínios ativos (no Talk separado, só os que o usuário de integração pode ver).

- O telefone pode estar com ou sem o 55 e com ou sem o nono dígito: o ERP confere as duas formas.
- Se o mesmo telefone estiver em mais de um condomínio ou unidade, o Talk mostra o mais recente e avisa quantos cadastros existem.
- Para identificar quem escreveu, o Talk recebe só nome, vínculo, e-mail, condomínio e unidade. O CPF **não** sai do ERP.

> Para o Talk reconhecer o morador, mantenha o telefone celular atualizado no cadastro.

## 2ª via do boleto pelo WhatsApp

Quando o morador pede o boleto, o Talk consulta o ERP e manda a **linha digitável** e o **Pix copia e cola** na conversa. Para proteger o morador, o ERP só responde quando tudo confere:

- o telefone que escreveu é o mesmo do cadastro em [[Cadastros > Proprietários e inquilinos]] (com ou sem 55 e nono dígito);
- o cadastro está ativo, ligado a uma unidade, e o vínculo é **proprietário** ou **responsável financeiro**. Inquilino e morador não recebem; quando o inquilino é quem paga, cadastre-o também como responsável financeiro;
- o boleto está **registrado no banco** e ainda não foi pago, com vencimento de até 60 dias atrás. Vão no máximo 3, do mais antigo para o mais novo, com o link da página do boleto no aplicativo do morador.

Se algo não confere, o ERP responde que não encontrou, sem dizer o motivo, e o Talk passa a conversa para o **Financeiro**. Boletos vencidos há mais de 60 dias também vão para o Financeiro.

> Cada consulta de boleto fica registrada na auditoria do ERP, com o telefone escondido.

>! O boleto vai para quem escreve do telefone do cadastro. Quando o morador trocar de número, atualize o cadastro antes.

## O chamado no Help Desk

Cada conversa vira **um** chamado, mesmo que o Talk tente de novo por falha de rede. Em [[Agenda > Atendimento > Chamados]] ele aparece com:

- origem **WhatsApp (Connect Talk)** (use o filtro de origem para ver só esses);
- o nome do cadastro do ERP, o condomínio, a unidade e o telefone de quem escreveu;
- no texto, o link **Conversa no Connect Talk**, que abre a conversa no Talk;
- o número `CH...`, que é o protocolo que o morador recebe no WhatsApp.

Quando o atendente do Talk **transfere** a conversa de setor (Financeiro, Manutenção, Administrativo), a área do chamado muda e fica uma nota interna com o setor e a observação. Quando ele **encerra** a conversa, o chamado fica **Resolvido**, com a resolução como resposta.

>! Contato que não está cadastrado também gera chamado, como "Contato do WhatsApp (final 7777)", sem condomínio. Confira e complete na triagem.

## Ligar o Talk do escritório ao ERP (equipe de TI)

O Talk que roda no escritório conversa com o ERP pela API:


1. Crie em [[Configurações > Acesso > Usuários do Sistema]] um usuário só para a integração, por exemplo "Connect Talk (integração)", com um papel que tenha a permissão de atender chamados (`support.manage`) e acesso aos condomínios que o Talk vai atender.
2. Em [[Configurações > Aplicativo e notificações > Integrações]], no quadro **Tokens de acesso (Connect Talk)**, crie o token: escolha o usuário de integração, a validade e os escopos (o que o Talk pode ver ou fazer). Para o Talk abrir e encerrar chamados, marque também **Abrir, responder, transferir e encerrar chamados**. O token aparece **uma vez só**, com o botão Copiar.
3. No `.env` do Talk, preencha `ERP_API_URL` com o endereço da API do ERP (termina em `/api/v1`) e `ERP_API_TOKEN` com o token. Nunca coloque o token no Git.
4. No mesmo quadro você vê o último uso de cada token e o revoga na hora. Pelo terminal do servidor também dá: `php tools/talk-token.php listar` e `php tools/talk-token.php revogar --id=N`.

> Se o ERP ficar fora do ar, o Talk continua atendendo; o chamado só não é aberto naquele momento.

>> Tudo sobre [Chamados](artigo:chamados)
