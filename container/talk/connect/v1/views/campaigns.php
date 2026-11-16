<?php defined('CONF_DB_NAME') || exit; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Envio em massa · Connect Talk</title>
<link rel="stylesheet" href="/container/talk/connect/v1/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>" data-sup="<?= $isSupervisor ? '1' : '0' ?>">
<div class="shell">
<?php $active = 'campaigns'; require __DIR__ . '/partials/sidebar.php'; ?>
<div class="page">
<?php require __DIR__ . '/partials/topbar.php'; ?>
<header class="page-head">
  <div><h1>Envio em massa</h1><p>Avisos para muitos moradores de uma vez, pela API oficial da Meta, sempre com modelo aprovado.</p></div>
  <button type="button" class="primary" id="cp-new">+ Novo envio</button>
</header>
<nav class="t-tabs" role="tablist">
  <button type="button" role="tab" data-tab="campaigns" aria-selected="true">Envios</button>
  <button type="button" role="tab" data-tab="templates" aria-selected="false">Modelos da Meta <b id="n-tpl"></b></button>
</nav>
<main class="campaigns">
  <section data-panel="campaigns" id="cp-list"><p class="meta">Carregando…</p></section>
  <section data-panel="templates" hidden>
    <div class="card">
      <div class="tp-head"><p class="meta">A Meta só deixa mandar mensagem para quem não escreveu nas últimas 24 h usando um <b>modelo aprovado</b>. Crie e aprove os modelos no WhatsApp Manager e depois sincronize aqui.</p>
        <div class="tp-actions" id="tp-actions"></div></div>
      <div class="q-scroll"><table><thead><tr><th>Modelo</th><th>Texto</th><th>Variáveis</th><th>Situação</th><th>Número</th><th></th></tr></thead><tbody id="tp-rows"></tbody></table></div>
    </div>
  </section>
</main>
</div>
</div>

<dialog id="cp-dialog">
  <form method="dialog" class="cp-form" id="cp-form">
    <h3 id="cp-title">Novo envio</h3>
    <div class="cp-cols">
      <div class="cp-left">
        <label>Nome do envio<input name="name" maxlength="120" required placeholder="Ex.: Boletos de dezembro"></label>
        <div class="ch-grid">
          <label>Número<select name="channel_id" id="cp-channel"></select></label>
          <label>Respostas vão para<select name="sector_id" id="cp-sector"></select></label>
        </div>
        <label>Modelo<select name="template_id" id="cp-template"></select></label>
        <fieldset class="cp-vars" id="cp-vars"></fieldset>
        <fieldset class="cp-aud">
          <legend>Para quem</legend>
          <div class="cp-radios"><label><input type="radio" name="aud" value="tag" checked> Contatos com a etiqueta</label><label><input type="radio" name="aud" value="list"> Lista colada (planilha)</label></div>
          <select id="cp-tag" aria-label="Etiqueta"></select>
          <div id="cp-list-box" hidden>
            <textarea id="cp-rows" rows="6" placeholder="telefone;nome;condomínio;valor&#10;31988881111;Mariana Souza;Residencial Jardins;R$ 520,00&#10;31977772222;Paulo Lima;Ed. Aurora;R$ 480,00"></textarea>
            <small class="meta">Uma pessoa por linha: telefone com DDD, nome e outras colunas separadas por ponto e vírgula, vírgula ou tabulação (pode colar direto do Excel). A primeira linha pode ser o cabeçalho.</small>
          </div>
        </fieldset>
        <div class="ch-grid">
          <label>Quando<select id="cp-when"><option value="now">Assim que iniciar</option><option value="later">Agendar</option></select></label>
          <label>Ritmo<select name="per_minute"><option value="20">20 por minuto</option><option value="60" selected>60 por minuto</option><option value="120">120 por minuto</option></select></label>
        </div>
        <label id="cp-at-box" hidden>Dia e hora<input type="datetime-local" id="cp-at"></label>
      </div>
      <aside class="cp-right">
        <h4>Prévia</h4>
        <div id="cp-preview" class="cp-preview"><p class="meta">Escolha o modelo e o público.</p></div>
      </aside>
    </div>
    <p class="form-error" id="cp-error" role="alert" hidden></p>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button type="button" id="cp-draft">Salvar rascunho</button><button value="ok" class="primary" id="cp-go">Salvar e iniciar</button></menu>
  </form>
</dialog>

<dialog id="tp-dialog">
  <form method="dialog" id="tp-form">
    <h3>Cadastrar modelo</h3>
    <p class="meta">Use exatamente o nome e o idioma do modelo aprovado no WhatsApp Manager. O texto serve para a prévia.</p>
    <label>Número<select name="channel_id" id="tp-channel"></select></label>
    <div class="ch-grid"><label>Nome na Meta<input name="name" required placeholder="boleto_disponivel"></label><label>Idioma<input name="language" value="pt_BR"></label></div>
    <label>Texto (com {{1}}, {{2}}…)<textarea name="body" rows="4" required placeholder="Olá {{1}}! O boleto de {{2}} do {{3}} já está disponível."></textarea></label>
    <p class="form-error" id="tp-error" role="alert" hidden></p>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary">Salvar</button></menu>
  </form>
</dialog>

<dialog id="rc-dialog">
  <div class="rc-box">
    <h3 id="rc-title">Destinatários</h3>
    <div class="rc-filter" id="rc-filter"></div>
    <div class="q-scroll"><table><thead><tr><th>Contato</th><th>Situação</th><th>Detalhe</th></tr></thead><tbody id="rc-rows"></tbody></table></div>
    <menu><button type="button" class="primary" id="rc-close">Fechar</button></menu>
  </div>
</dialog>
<script src="/container/talk/connect/v1/assets/campaigns.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
</body>
</html>
