---
codigo:
titulo: App do morador no celular
resumo: O app Connect Morador (iPhone e Android) mostra ao morador os boletos, a prestação de contas, os comunicados, as reservas, as ocorrências, a portaria, os documentos e as assembleias da unidade dele, só com as funções que o condomínio ligou.
categoria: comunicacao
icone: phone-portrait-outline
situacao: guia
palavras: aplicativo, app, celular, morador, iphone, android, boleto, segunda via, reservas, encomendas, visitantes
rascunho: nao
---

O **Connect Morador** é o aplicativo de celular dos moradores. Ele usa os mesmos dados do ERP e as mesmas regras do app do morador na web: o morador só vê a própria unidade, e só aparecem as funções que o condomínio ligou.

## Quem pode entrar

1. A administração convida o morador pelo cadastro de moradores. O convite chega por e-mail e vale 7 dias.
2. O morador cria a senha pelo link do convite.
3. No app, ele entra com o e-mail (ou CPF) e a senha. Se tiver a verificação em duas etapas ativa, o app pede o código do autenticador.

> Quem deixa de ser morador (vínculo encerrado) perde o acesso ao app na hora, sem precisar fazer nada no celular.

## O que o morador vê

As telas seguem o jeito do iPhone: o nome da tela aparece grande no topo e vira uma barra pequena quando a pessoa rola. Embaixo fica uma barra flutuante e translúcida com **Início**, **Financeiro**, **Reservas**, **Ocorrências** e **Mais**. Nas telas abertas pelo Mais, o botão redondo no canto de cima volta.

- **Início**: a saudação com a data, um cartão com o condomínio, a unidade, o valor em aberto e o próximo vencimento, atalhos para reservar, registrar ocorrência, avisar visitante e abrir documentos (só os que o condomínio ligou), encomendas para retirar, reservas, ocorrências e comunicados novos.
- **Financeiro**: as contas em aberto com {{Copiar linha digitável}}, {{Copiar Pix copia e cola}} e {{Abrir boleto (PDF)}}, o histórico de pagamentos e a prestação de contas de cada mês.
- **Reservas**: os espaços liberados, com regras e taxa, a reserva de uma data e a lista de espera quando a data já está ocupada.
- **Ocorrências**: registrar uma ocorrência com assunto, descrição e prioridade e acompanhar a resposta.
- **Mais**: comunicados, encomendas, visitantes (avisar a portaria de quem vem), documentos do condomínio, assembleias com a pauta, pets e veículos da unidade, trocar de unidade e sair.

## Ligar e desligar funções

Cada função é ligada por condomínio na matriz do portal do morador. Tudo começa desligado. Uma função desligada some do menu do app e, se o morador tentar abrir por um atalho antigo, vê o aviso "Função desligada".

## Segurança

- A senha e o limite de tentativas são os mesmos do login na web.
- O app guarda só um código de acesso no cofre do celular (Keychain no iPhone, Keystore no Android). Ele vale 90 dias e cai com 30 dias sem uso.
- Quando o morador escolhe "sair de todos os aparelhos" na web, o app também sai.
- Cada entrada e saída pelo app fica na auditoria, com o nome do aparelho.

>! O app ainda não está nas lojas da Apple e do Google. Enquanto isso, o teste é feito pelo Expo Go, com a equipe da Connect.
