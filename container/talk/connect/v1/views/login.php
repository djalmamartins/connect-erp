<?php defined('CONF_DB_NAME') || exit; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connect Talk</title>
<link rel="stylesheet" href="/container/talk/connect/v1/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body class="login-page">
<div class="login-card">
  <img src="/container/talk/connect/v1/assets/logo.svg" alt="Connect Condomínios" class="login-logo">
  <h1>Talk</h1>
  <?php if (!empty($error)): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif ?>
  <p class="hint">O Talk usa o login do Connect ERP. Entre no ERP e escolha <b>Talk</b> no alternador de ambientes.</p>
  <a class="button" href="<?= htmlspecialchars($erpUrl ?? '/') ?>">Ir para o ERP</a>
</div>
</body>
</html>
