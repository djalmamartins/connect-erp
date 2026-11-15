# 0302 — Aplicativo do morador em app.connect.lab

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:** ADR 0092 (convite do morador), proposta de acesso de 09/11 (morador por convite, portal desligado
  no início, tudo auditado), ADR 0047/0160 (Disco Virtual e antivírus), ADR 0141 (Central de Ajuda), ADR 0301 (link
  público da prestação de contas), ADR 0303 (aplicativo nativo, thread paralela: sessão por token)
- **Numeração:** faixa avulsa 0300–0319. O 0303 fica com o aplicativo nativo (React Native), que consome as mesmas
  rotas.

## Contexto

Pedido do Djalma (14/11/2026): "app.connect.lab vamos criar o aplicativo do morador", no mesmo modelo do
erp.connect.lab. Antes, uma pesquisa do que as empresas do mercado oferecem (boletos e segunda via, prestação de
contas, comunicados, reservas, ocorrências, encomendas, visitantes, assembleias, documentos, cadastro da unidade). O
plano foi aprovado ("Ok"). Ficaram para depois: portão, interfone, câmeras e pagamento dentro do app (Pix depende da
integração com o Banco Inter).

Já existiam: o vínculo morador ↔ conta (`erp_residents.user_id`, criado no aceite do convite), a matriz do portal por
condomínio (`erp_resident_portal_permissions`, fechada por padrão), o motor de cadastros com as regras de reserva e
de visitas, o PDF do boleto, a prestação de contas pública (ADR 0301) e o Disco Virtual.

## Decisão

### Host e entrada

- Novo ambiente `residents` com host `app.` (`app_host()`, `app_base()`, `app_url()`; `Environments` com o papel
  `app`). Fora do host próprio, o mesmo aplicativo responde em `/app`. Os temas ficam em `container/app/connect/v1`.
- Login com o mesmo `Auth` do Studio e do ERP (limite de tentativas e 2FA; o 2FA termina em
  `/studio/login/2fa?next=app`). Só entra quem tem **vínculo válido**: morador ativo e não excluído, unidade ativa,
  condomínio ativo e dentro das datas de início e fim (`ResidentLinks::forUser`). Conta da equipe sem vínculo recebe a
  mensagem e sai. Não há cadastro aberto: a conta nasce no convite.
- Mais de uma unidade: seletor no topo (`ResidentSession`), guardado na sessão; toda leitura usa o condomínio e a
  unidade do vínculo escolhido, nunca um id vindo do navegador.

### Funções por condomínio

- Cada função só aparece se estiver ligada na matriz do portal do condomínio. A matriz ganhou `accountability`,
  `deliveries`, `visitors` e `assemblies` (migration 20261114_0901). Função desligada: 403 `feature_disabled` na API
  e página "não disponível" no aplicativo.
- Boletos só para proprietário, inquilino e responsável financeiro; o morador comum não vê cobrança.
- Boletos: lançamentos da unidade com situação e PDF do boleto (`BankCharges::residentPdf`, só da própria unidade,
  auditado). Prestação de contas: os links públicos do ADR 0301. Comunicados: gerais ativos já enviados, para todos ou
  para a unidade. Reservas e lista de espera: pelo motor de cadastros (`RegistryService::createForResident`) com
  condomínio e unidade forçados pelo vínculo e só os campos permitidos; cancelar só reserva ativa ou confirmada de hoje
  em diante e sem taxa lançada. Ocorrências, encomendas (abertas ou dos últimos 90 dias), visitantes (autorizar e
  cancelar; documento mascarado), assembleias (pauta, resultado e ata só depois de encerrada), documentos e animais e
  veículos.
- Documentos: só os arquivos que a equipe marcou como **visíveis aos moradores** no Disco Virtual
  (`erp_documents.shared_with_residents`, botões "Mostrar" e "Esconder" com a etiqueta "Visível aos moradores", `POST /api/v1/documents/{id}/share` com
  `erp.documents.manage`, auditado). O download pelo morador respeita o antivírus (ADR 0160).

### API e aplicativo instalável

- Rotas `/api/v1/resident/*` documentadas no OpenAPI (tag Morador), com sessão e CSRF
  (`ResidentApiAuthenticated`, onde o aplicativo nativo do ADR 0303 acrescenta o token).
- O site é instalável (manifest, ícones com o símbolo dourado sobre o verde do ERP, service worker que guarda só a
  página "sem conexão" e os arquivos estáticos; dados nunca ficam em cache). Visual do Realizze Pessoa Física (canvas claro, menu lateral branco, cartões e selos do Realizze PF, pedido do Djalma em 14/11). Celular primeiro: barra inferior flutuante com
  Início, Boletos, Reservas, Encomendas e "Mais"; no computador, menu lateral.
- Segurança: CSRF e limite de envios em toda ação, `Cache-Control: no-store`, `X-Frame-Options: DENY`, escrita
  auditada.

### Ajustes depois do primeiro teste (Djalma, 14/11/2026)

- Barra superior com o nome da pessoa e a unidade, busca (`/buscar`, `GET /api/v1/resident/search`) e sininho (`/avisos`,
  `GET /api/v1/resident/notifications`), montados pelo `ResidentFeed` só com o que o vínculo já vê. O "visto por
  último" fica num cookie do navegador (`app_seen`); sem tabela nova.
- Conta clicável (`/boletos/{id}`, `GET /api/v1/resident/entries/{id}`): paga mostra o recibo para imprimir
  (`/boletos/{id}/recibo`, leitura auditada); em aberto oferece baixar o boleto. Edital da assembleia para imprimir
  (`/assembleias/{id}/edital`), com base do voto e quórum de cada item. Recibo e edital são páginas HTML com
  "Imprimir ou salvar em PDF", sem biblioteca de PDF nova.
- Minha unidade com os dados da pessoa (documento mascarado). Comunicados e assembleias mais espaçados.
- `tools/lab-morador-teste.php` cria exemplos fictícios (`ResidentLabExamples`): comunicados, encomendas, pet,
  documento visível aos moradores e a prestação de contas dos meses fechados, sem duplicar e sem enviar e-mail.

## Consequências

- O morador vê só a própria unidade e só o que o condomínio ligou; nada aparece enquanto a matriz estiver desligada.
- No Mac, o host `app.connect.lab` precisa de vhost e certificado como os outros hosts; enquanto isso, o aplicativo
  abre em `/app` no host principal.
- Pendências: pagamento dentro do app (Pix, depois do Banco Inter), notificações push, foto na ocorrência,
  portão/interfone/câmeras.

### Padrão único das telas (Djalma, 14/11/2026: "precisa padronizar todo app")
- Toda lista do app usa a mesma peça `partials/item.php`: cartão com ícone da função (ou caixa de data), título, linhas de detalhe, selo à direita e seta (ou download). Quando há detalhe para abrir (ocorrência, regras da área), a mesma linha vira um item que abre e fecha.
- Todo estado vazio usa `partials/empty.php`: ícone da função, uma frase e, se fizer sentido, um botão.
- Boletos, comunicados e assembleias seguem as mesmas medidas (ícone 40px, cantos 12px, espaço de 10px entre cartões).
- Corrigido: a prestação de contas passava a variável `links` para a tela e apagava a lista de unidades do layout, por isso o seletor de unidade aparecia vazio nessa página. Agora a variável se chama `publications`.

### Mesmas mudanças do app de celular (Djalma, 14/11/2026)
- Início: cartão com marca d'água da Connect, olho para esconder valores (localStorage do aparelho), 2ª via do boleto (conta em aberto mais antiga, `summary.charges.next_entry_id`) e Copiar código (linha digitável ou Pix), troca de unidade pela setinha e atalhos.
- Reservas: abas Nova reserva / Minhas reservas, filtro por taxa e calendário do mês em `/reservas/area/{id}` (`ResidentPortal::calendar`, regra pura: já passou, fora do prazo, suspensa, livre, parte do dia, ocupado). Mostra só os horários tomados, nunca a unidade.
- Boletos: rolagem infinita de 6 em 6 meses (`ResidentPortal::previousWindow`, `/boletos?parcial=1`), sem o filtro de datas.
- Chamados: a tela de Ocorrências vira Chamados pelo `ResidentTickets` (ADR 0305), com número, situação, conversa e resposta (`/ocorrencias/{id}`, `/ocorrencias/{id}/mensagens`).

### Revisão do Djalma de 15/11/2026, etapa 1 (navegação), igual ao app de celular
- Versão anterior guardada nas branches `versao/app-morador-v1-2026-11-15` e `versao/app-morador-antes-revisao-15-11` (main 4bdbf65).
- Barra inferior com Início, Resolva fácil, Unidade e Perfil (`AppScreens::TABS`). Cada item do `MENU` diz a aba a que pertence, e `tab_active` acende essa aba. O menu "Mais" saiu.
- Início: cartão da unidade, até 6 botões com ícone, dois por linha (`partials/actions`: Boletos, Reservas, Chamados, Autorizar entrada, Documentos e Prestação de contas), e as 5 notificações mais recentes do `ResidentFeed`.
- `/resolva-facil`: atalhos para os serviços. CND e Ocorrência aparecem como "Em breve" até as etapas 3 e 5.
- `/minha-unidade` (Unidade): atalhos (Moradores "Em breve" até a etapa 4), veículos e pets. `/perfil`: dados da pessoa, duas etapas, avisos, instalar e sair. O avatar da barra de cima leva ao Perfil, ao lado do sino.
- Próximas etapas, combinadas com a thread do app de celular: 2 login (recuperação de senha e boas-vindas com foto), 3 chamados e ocorrência, 4 moradores da unidade, 5 CND. As rotas novas da API do morador ficam com o web.
