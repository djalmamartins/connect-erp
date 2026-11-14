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
  (`erp_documents.shared_with_residents`, botão "Mostrar aos moradores", `POST /api/v1/documents/{id}/share` com
  `erp.documents.manage`, auditado). O download pelo morador respeita o antivírus (ADR 0160).

### API e aplicativo instalável

- Rotas `/api/v1/resident/*` documentadas no OpenAPI (tag Morador), com sessão e CSRF
  (`ResidentApiAuthenticated`, onde o aplicativo nativo do ADR 0303 acrescenta o token).
- O site é instalável (manifest, ícones com o símbolo dourado sobre o verde do ERP, service worker que guarda só a
  página "sem conexão" e os arquivos estáticos; dados nunca ficam em cache). Celular primeiro: barra inferior com
  Início, Boletos, Reservas, Encomendas e "Mais"; no computador, menu lateral.
- Segurança: CSRF e limite de envios em toda ação, `Cache-Control: no-store`, `X-Frame-Options: DENY`, escrita
  auditada.

## Consequências

- O morador vê só a própria unidade e só o que o condomínio ligou; nada aparece enquanto a matriz estiver desligada.
- No Mac, o host `app.connect.lab` precisa de vhost e certificado como os outros hosts; enquanto isso, o aplicativo
  abre em `/app` no host principal.
- Pendências: pagamento dentro do app (Pix, depois do Banco Inter), notificações push, foto na ocorrência,
  portão/interfone/câmeras.
