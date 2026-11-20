# 0600 — LGPD e CDC no site: rastreadores, cookies, aceite na proposta, identificação e textos

- Status: Aceito (etapa 1 de 2; a etapa 2 traz o aceite dos termos nas contas e o pedido do titular)
- Data: 20/11/2026
- Pedido: Djalma, 20/11/2026 (checklist legal: política e termos com aceite registrado, banner de cookies com recusar, nada
  de rastreador antes do consentimento, marketing separado, CNPJ e contato no site, encarregado, incidente, crianças,
  avaliações falsas, promessas, acessibilidade). Auditoria item a item no documento "LGPD e CDC: o que falta no Connect ERP".
- Relacionadas: ADR 0350 (registro das operações), 0328/0330/0332 (relatório e anonimização), 0397 (layout dos e-mails),
  0141 (Central de Ajuda)
- Numeração: primeira avulsa da nova faixa 0600–0799 (0300–0399 esgotada).

## Contexto (CONFIRMADO no código da main 0c0f4f4)

- `container/shared/assets/vendor/scripts/tracker.js` entrava no `site.min.js` **e no `scripts.js` do app do morador**:
  Pixel do Facebook (`fbq('init', '207569866352882')`, `PageView`) e Google Ads (`AW-953362805`), sem perguntar.
  `layouts/theme.php` e `sales.php` carregavam o `gtag.js` (`UA-53658515-18`). O mapa do Google carregava com a página.
- `pages/testimonials.php` mostrava três depoimentos inventados (nomes, condomínios e 5 estrelas) quando não havia
  depoimento no Studio.
- `ProposalService` gravava `consent_at` sem checkbox no formulário.
- Página inicial: selos do Google Play e da App Store com `href="#"` (o app não está nas lojas) e "96% de satisfação" sem
  pesquisa; na página de proposta, também 96%.
- Política e termos: texto curto no código ou página do Studio; `pages/login/terms.php` (sem rota) com texto da Agência Moves.
- Sem razão social, CNPJ e encarregado nas Configurações; sem plano de incidente.

## Decisão

1. **Rastreadores fora.** `tracker.js` apagado e retirado das listas do `Minify/Web.php` e `Minify/App.php`; `gtag` UA
   retirado dos layouts. Estatística só por GA4 (`SITE_GA4_ID=G-...` no `.env`), carregado pelo banner **depois** do
   "Aceitar" ou da categoria "Estatística", com `anonymize_ip`. Vazio (padrão) = nenhum rastreador. Facebook e Google Ads
   não voltam sem decisão do Djalma (e, se voltarem, só pelo banner).
2. **Banner de cookies** (`pages/cookies.php` + `assets/js/cookie-consent.js`, no `theme` e no `sales`): Recusar, Escolher e
   Aceitar com o mesmo estilo; a escolha fica no cookie necessário `connect_cookie_consent` (180 dias, versão em
   `LegalDocuments::COOKIES`) e a prova em `POST /cookies` (CSRF, limite por sessão). Link "Cookies" no rodapé reabre.
   O mapa do Google vira um quadro com "Mostrar mapa" e "Abrir no Google Maps"; só carrega com o clique ou o aceite.
3. **Proposta:** caixa obrigatória "Li a Política de Privacidade" (sem ela nada é gravado) e caixa separada, opcional e
   desmarcada, para novidades (`proposals.marketing_consent_at`). Base legal da proposta: art. 7º, V.
4. **Prova dos aceites:** tabela `privacy_consents` (só acrescenta; retirar grava linha nova com `granted = 0`), serviço
   `ConsentLog`, guarda o sha256 do IP e do navegador, nunca o IP.
5. **Identificação:** `settings.site_company_name`, `site_cnpj`, `privacy_officer_name`, `privacy_email` (TEXT, a linha de
   `settings` está perto do limite do InnoDB), no Studio em Configurações > "Identificação e encarregado de dados". Rodapé:
   razão social, CNPJ, encarregado e e-mail; o rodapé dos e-mails (ADR 0397) passa a usar o CNPJ das Configurações, com o
   `CONNECT_CNPJ` como reserva.
6. **Textos versionados no código** (`pages/legal/privacidade.php`, `termos.php`, `_identity.php`; versão em
   `LegalDocuments`): a página do Studio com o mesmo endereço não substitui mais o texto, para cada aceite apontar para um
   texto fixo. **Rascunho técnico, revisar com advogado.** Política: controlador/operador, dados e finalidades, bases legais,
   prazos, compartilhamento e transferência internacional, cookies, direitos (art. 18, 15 dias, ANPD), crianças (art. 14),
   segurança e incidentes. Termos: serviço sem custo para o morador, conta por convite e maior de 18, uso permitido,
   responsabilidades sem cláusula abusiva (CDC art. 51), foro do consumidor (CDC art. 101). `pages/login/terms.php` e o
   `Web::terms()` sem rota foram apagados.
7. **CDC:** depoimentos de exemplo apagados (a seção só aparece com depoimento publicado); 96% trocado por "App: portal e
   aplicativo para moradores"; selos das lojas só com `APP_PLAY_STORE_URL`/`APP_APPLE_STORE_URL` reais, senão botão para o
   app no navegador.
8. **Incidente:** `docs/security/INCIDENTE_LGPD.md` (conter, provas, risco, aviso ao condomínio, ANPD e titulares em até 3
   dias úteis, registro por 5 anos, modelos). Registro LGPD ganhou as operações 17 (proposta) e 18 (aceites).
9. **Acessibilidade:** rodapé legível (0,8em e contraste maior), textos alternativos das lojas e do app, banner por teclado.

## Consequências

- Testes: `PrivacyAndConsumerSiteTest` (unit: nenhum rastreador em `container/` nem no app nativo, banner com recusar
  igual ao aceitar, mapa sem iframe, sem depoimento inventado, 96% e selo `#`; caixas da proposta; textos versionados) e
  `PrivacyConsentIntegrationTest` (proposta recusada sem a caixa; privacidade e marketing gravados à parte; histórico de
  consentimento; colunas novas). `tests/legacy/bootstrap.php` cria `proposals` no banco de teste.
- Migration `20261120_1900_privacy_consents` (com `.down.sql`).
- Fica para o Djalma: razão social, CNPJ, encarregado e e-mail de privacidade (Studio); revisão dos textos com advogado;
  confirmar "+40 condomínios", "+1300 famílias/unidades" e os anos de experiência (a home diz +5 e a página de proposta diz
  +10); licença da fonte Gotham e origem das fotos; se quer GA4, Facebook ou Google Ads (só com aceite); prazos PENDENTES
  do registro; contratos com suboperadores e com os condomínios.
- Ainda carrega do Google: as fontes (Google Fonts) do site e do Studio. Não grava cookie, mas envia o IP; servir as
  fontes do próprio site é a próxima melhoria.
