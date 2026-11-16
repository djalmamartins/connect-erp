<?php defined('CONF_DB_NAME') || exit; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Conexão WhatsApp · Connect Talk</title>
<link rel="stylesheet" href="/container/talk/connect/v1/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>">
<div class="shell">
<?php $active = 'channels'; require __DIR__ . '/partials/sidebar.php'; ?>
<div class="page">
<?php require __DIR__ . '/partials/topbar.php'; ?>
<header class="page-head">
  <div><h1>Conexão WhatsApp</h1><p>Os números da Connect no Talk: como cada um conecta, para qual setor vai e se está no ar.</p></div>
</header>
<main class="channels">
  <section class="card ch-hero">
    <div class="ch-hero-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M10.5 18h3"/></svg></div>
    <div>
      <h2>Conecte um número de WhatsApp</h2>
      <p>Use <b>QR Code</b> (WhatsApp no celular, lido como no WhatsApp Web) para o atendimento do dia a dia, ou a <b>API oficial da Meta</b> para envios em massa. Cada número pode ir direto para um setor.</p>
    </div>
    <button type="button" class="primary" id="ch-new">+ Conectar novo número</button>
  </section>
  <section class="card ch-list">
    <h3>Números conectados <span class="meta" id="ch-count"></span></h3>
    <ul id="ch-rows"><li class="meta">Carregando…</li></ul>
  </section>
  <section class="card ch-about">
    <h3>Sobre a conexão</h3>
    <ul>
      <li><b>QR Code:</b> o celular precisa ficar ligado e com internet. Se o WhatsApp desconectar, o número aparece como <i>Desconectado</i> aqui e basta ler o QR Code de novo.</li>
      <li><b>API oficial:</b> não tem QR Code. Cadastre o número no Meta for Developers, cole o token e configure lá o webhook com o endereço e o token de verificação mostrados no número.</li>
      <li><b>Setor do número:</b> quando o número tem setor, quem escreve nele vai direto para a fila desse setor. Sem setor, a Jack lê a mensagem e escolhe.</li>
      <li><b>Padrão:</b> é o número usado quando o Talk começa uma conversa (por exemplo, um aviso enviado a partir do ERP).</li>
    </ul>
  </section>
</main>
</div>
</div>

<dialog id="ch-dialog">
  <form method="dialog" class="ch-form" id="ch-form">
    <h3 id="ch-form-title">Conectar novo número</h3>
    <fieldset class="ch-kind" id="ch-kind">
      <legend>Tipo de conexão</legend>
      <label><input type="radio" name="kind" value="qrcode" checked><b>QR Code</b><small>WhatsApp no celular, lido como no WhatsApp Web</small></label>
      <label><input type="radio" name="kind" value="official"><b>API oficial</b><small>WhatsApp Business Platform (Meta)</small></label>
      <?php if ($isLab): ?><label><input type="radio" name="kind" value="lab"><b>Laboratório</b><small>Número simulado, só para testes</small></label><?php endif; ?>
    </fieldset>
    <div class="ch-grid">
      <label>Nome da conexão<input name="name" maxlength="80" required placeholder="Ex.: Atendimento Connect"></label>
      <label>Número do WhatsApp<input name="phone" inputmode="tel" placeholder="(31) 99999-0000"></label>
      <label id="ch-sector-field">Setor deste número<select name="sector_id" id="ch-sector"><option value="">Todos — a Jack escolhe o setor</option></select></label>
      <label id="ch-purpose-field">Uso<select name="purpose"><option value="attendance">Atendimento</option><option value="bulk">Envios em massa</option><option value="both">Atendimento e envios</option></select></label>
    </div>
    <p class="meta" data-kind="qrcode">Depois de salvar, o Talk gera o QR Code para você ler com o celular deste número.</p>
    <div class="ch-grid" data-kind="official">
      <label>Phone number ID<input name="cfg.phone_number_id"></label>
      <label>WhatsApp Business Account ID<input name="cfg.business_account_id"></label>
      <label class="wide">Token de acesso<input name="cfg.access_token" type="password" autocomplete="new-password"></label>
      <label>App secret<input name="cfg.app_secret" type="password" autocomplete="new-password"></label>
      <label>Versão da API<input name="cfg.api_version" placeholder="v21.0"></label>
    </div>
    <p class="meta" id="ch-secret-hint" hidden>Campos de senha em branco mantêm o valor já salvo.</p>
    <p class="form-error" id="ch-error" role="alert" hidden></p>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary" id="ch-save">Salvar</button></menu>
  </form>
</dialog>

<dialog id="qr-dialog">
  <div class="qr-box">
    <h3 id="qr-title">Ler o QR Code</h3>
    <div class="qr-wrap">
      <ol>
        <li>Abra o WhatsApp no celular do número</li>
        <li>Toque em <b>Mais opções ⋮</b> ou <b>Configurações</b> e depois em <b>Aparelhos conectados</b></li>
        <li>Toque em <b>Conectar um aparelho</b> e aponte o celular para esta tela</li>
      </ol>
      <figure><img id="qr-img" alt="QR Code para conectar o WhatsApp"><figcaption id="qr-state" class="meta">Aguardando a leitura…</figcaption></figure>
    </div>
    <p class="form-error" id="qr-error" role="alert" hidden></p>
    <menu>
      <button type="button" id="qr-sim" class="ghost" hidden>Simular leitura (laboratório)</button>
      <button type="button" id="qr-renew">Gerar novo QR Code</button>
      <button type="button" class="primary" id="qr-close">Fechar</button>
    </menu>
  </div>
</dialog>
<script src="/container/talk/connect/v1/assets/channels.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
</body>
</html>
