---
codigo: AP-171
titulo: Situação das integrações
resumo: Mostra a situação das integrações da Connect: banco, e-mail, antivírus e índices de correção. No card Banco Inter você também liga uma conta do Inter (ambiente de testes) à cobrança; o resto da configuração fica no servidor.
categoria: configuracoes
rota: /configuracoes/integracoes
menu: Configurações > Aplicativo e notificações > Integrações
icone: list-outline
situacao: funciona
relacionados: fila-de-avisos, alertas, banners, notificacoes-aplicativo
palavras: integrações, configurações, AP-171, token, token de acesso, api, connect talk, whatsapp, escopo, login único, entrar no talk
rascunho: nao
---

Mostra a situação das integrações da Connect: banco, e-mail, antivírus e índices de correção. No card Banco Inter você também liga uma conta do Inter (ambiente de testes) à cobrança; o resto da configuração fica no servidor.

## Onde fica

No menu lateral, abra [[Configurações > Aplicativo e notificações > Integrações]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela integracoes
rota: /configuracoes/integracoes
condominio: residencial-jardim-das-acacias
legenda: Tela Integrações
1: menu | Integrações no menu lateral
2: main table thead | Colunas da lista
```

## O que a lista mostra

Colunas: **Situação** e **Arquivos**.

> Na tela: *Antivírus desligado Os arquivos enviados entram como "não verificados". Quando o antivírus for ligado, a reverificação passa por eles em lotes.*

## Ligação com o Connect Talk

O quadro **Ligação com o Connect Talk** mostra o endereço do Talk em uso e quantas pessoas podem entrar nele. O Talk não tem usuários próprios: cada funcionário entra com o e-mail e a senha do ERP, e só quem tem a permissão **Atendimento por WhatsApp (Connect Talk)**. A tela Equipe do Talk traz essas pessoas do ERP; lá se escolhem só o papel e os setores.

Para ligar (precisa da permissão de **gerenciar configurações** e acesso a todos os condomínios):

1. Em **Endereço do Connect Talk**, informe só o endereço, por exemplo `https://talk.connectcondominios.com.br`, e clique em **Salvar endereço**.
2. Crie um token no quadro abaixo com o escopo **Entrar no Talk com o usuário do ERP** e cole no Talk como `ERP_API_TOKEN`.
3. Dê a permissão a cada funcionário que vai atender, pelo papel ou no cadastro da pessoa, em [[Studio > Usuários]].

## Tokens de acesso (Connect Talk)

O Connect Talk, o atendimento pelo WhatsApp, entra na API do ERP com um **token de acesso**. No quadro **Tokens de acesso (Connect Talk)** você vê cada token com o nome, os 4 últimos caracteres, o usuário de integração, os escopos, a validade, o último uso (com o IP) e a situação.

Para criar um token (precisa da permissão de **gerenciar configurações**):

1. Dê um nome, por exemplo "Connect Talk do escritório".
2. Escolha o **usuário de integração**. Só aparecem usuários ativos com a permissão de atender chamados; o token só enxerga os condomínios desse usuário.
3. Escolha a **validade** (sem validade, 30 dias, 90 dias, 6 meses ou 1 ano).
4. Marque os **escopos**. Os de leitura já vêm marcados. O de escrita (abrir, responder, transferir e encerrar chamados) vem desmarcado e pede confirmação.
5. Se o Talk sair sempre pelo mesmo IP, informe em **IPs permitidos**. Com o Talk atrás do Cloudflare Tunnel, deixe vazio.
6. Clique em **Criar token** e copie o token. Ele **não aparece de novo**: se perder, revogue e crie outro.

>! Revogar corta o acesso na hora. O Talk só volta a falar com o ERP com um token novo no `.env` dele.

> Com o escopo **Condomínios, unidades, áreas comuns e comunicados**, o Talk vê só o nome e o endereço dos condomínios ativos do usuário de integração, o bloco e o número das unidades (nunca quem mora), as áreas que aceitam reserva e os comunicados enviados para todo o condomínio. Não vê CNPJ, conta bancária nem síndico.

> Com o escopo **Entrar no Talk com o usuário do ERP**, os funcionários entram no Talk com o mesmo e-mail e senha do ERP (e o 2FA). Só entra quem tem a permissão **Atendimento por WhatsApp (Connect Talk)**, dada pelo papel ou liberada no cadastro do usuário em [[Studio > Usuários]]. Tirou a permissão, a pessoa sai do Talk em poucos minutos. Um token criado antes não tem esse escopo: crie um novo e troque no `.env` do Talk.

> Cada token tem limite de 120 pedidos por minuto. Criar e revogar ficam registrados na auditoria, sem o token.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Chamados que chegam pelo WhatsApp](artigo:chamados-do-whatsapp), [Ligar a cobrança pelo Banco Inter](artigo:banco-inter), [Fila de avisos por e-mail](artigo:fila-de-avisos), [Alertas do sistema](artigo:alertas) e [Banners do aplicativo](artigo:banners).
