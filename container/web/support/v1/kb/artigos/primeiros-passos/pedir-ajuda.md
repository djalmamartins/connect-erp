---
codigo:
titulo: Como pedir ajuda e abrir um chamado
resumo: Os canais de atendimento da Connect: pesquisa na Central de Ajuda, chat, WhatsApp e mensagem que vira chamado com protocolo para você acompanhar.
categoria: primeiros-passos
rota: /suporte
menu:
icone: help-buoy-outline
situacao: guia
relacionados: entrar-no-sistema, encontrar-uma-tela
palavras: ajuda, suporte, chamado, protocolo, atendimento, chat, whatsapp, contato, help desk
manual: sim
rascunho: nao
---

Antes de abrir um chamado, vale pesquisar aqui na Central de Ajuda: cada tela do sistema tem um tutorial com prints marcados mostrando onde clicar.

## Pesquisar uma dúvida

Na página inicial da Central, escreva a dúvida no campo de pesquisa e clique em {{Buscar}}. Use palavras simples, como `segunda via`, `cadastrar unidade` ou `acordo`.

```tela ajuda-inicio
rota: /!/suporte
sessao: nenhuma
legenda: Página inicial da Central de Ajuda
1: .hc-search | Pesquise a sua dúvida
2: .hc-channels | Canais de atendimento
3: .hc-top .hc-btn | Ir para o Connect ERP
```

## Falar com o atendimento

- **Conversar no chat**: abre uma janela no canto da tela, onde você descreve o problema e recebe o número do protocolo. Na mesma janela dá para acompanhar um protocolo já aberto.
- **WhatsApp**: para uma conversa rápida, quando o número estiver disponível.
- **Deixe a sua mensagem**: o formulário no fim da página inicial.

## Abrir um chamado pela mensagem

1. Na página inicial, clique em **Abra um chamado** ou role até **Deixe a sua mensagem**.
2. Preencha nome, e-mail, assunto e a mensagem. Conte em qual tela aconteceu e o que você esperava ver.
3. Se quiser resposta pelo WhatsApp, marque a opção e informe o telefone.
4. Clique em {{Enviar mensagem}}. O número do protocolo aparece na hora, e a resposta chega no seu e-mail.

```tela ajuda-mensagem
rota: /!/suporte
sessao: nenhuma
legenda: Formulário que abre um chamado no Help Desk
recorte: #mensagem
1: #mensagem input[name=name] | Seu nome
2: #mensagem input[name=email] | E-mail para a resposta
3: #mensagem input[name=subject] | Assunto
4: #mensagem textarea[name=message] | O que aconteceu
5: #mensagem button[type=submit] | Botão {{Enviar mensagem}}
```

> Guarde o protocolo. Com ele você acompanha o chamado pelo chat, sem precisar explicar tudo de novo.

>! Não envie senhas, números de cartão ou documentos pessoais na mensagem. A equipe nunca pede a sua senha.
