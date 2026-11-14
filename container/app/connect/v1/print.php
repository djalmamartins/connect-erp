<?php
/**
 * Moldura para imprimir ou salvar em PDF (recibo e edital, ADR 0302): sem menu, folha branca, botão de imprimir
 * que some na impressão. @var string $head @var string $title
 */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?= $head ?? '' ?>
    <style>
        :root { --ink: #121a31; --muted: #68786f; --line: #dfe8e3; --brand: #078b4c; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f2f5f3; color: var(--ink); font: 14px/1.5 Inter, system-ui, -apple-system, "Segoe UI", sans-serif; }
        .doc-actions { display: flex; gap: 10px; justify-content: center; padding: 16px; }
        .doc-actions a, .doc-actions button { display: inline-flex; align-items: center; gap: 8px; height: 42px; padding: 0 18px; border-radius: 12px;
            border: 1px solid var(--line); background: #fff; color: var(--ink); font: 600 14px inherit; text-decoration: none; cursor: pointer; }
        .doc-actions button { background: var(--brand); border-color: var(--brand); color: #fff; }
        .doc { max-width: 760px; margin: 0 auto 32px; background: #fff; padding: 40px 44px; border-radius: 14px; box-shadow: 0 5px 20px #233a3014; }
        .doc-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; border-bottom: 2px solid var(--brand); padding-bottom: 16px; margin-bottom: 22px; }
        .doc-head img { height: 34px; }
        .doc-head small { display: block; color: var(--muted); text-align: right; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 22px 0 8px; }
        .doc-muted { color: var(--muted); }
        .doc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 24px; margin: 16px 0; }
        .doc-grid div span { display: block; font-size: 12px; color: var(--muted); }
        .doc-total { display: flex; justify-content: space-between; align-items: center; margin: 20px 0; padding: 16px 18px; border-radius: 12px; background: #eff8f2; }
        .doc-total strong { font-size: 24px; color: var(--brand); }
        .doc-sign { margin-top: 48px; text-align: center; color: var(--muted); font-size: 12px; }
        .doc-sign hr { width: 260px; border: 0; border-top: 1px solid var(--ink); margin: 0 auto 6px; }
        ol { padding-left: 20px; } li { margin: 6px 0; }
        @media (max-width: 600px) { .doc { padding: 24px 18px; border-radius: 0; } .doc-grid { grid-template-columns: 1fr; } }
        @media print { body { background: #fff; } .doc-actions { display: none; } .doc { box-shadow: none; margin: 0; max-width: none; padding: 0; } }
    </style>
</head>
<body>
<div class="doc-actions">
    <a href="javascript:history.back()">‹ Voltar</a>
    <button type="button" onclick="window.print()">Imprimir ou salvar em PDF</button>
</div>
<?= $this->section('content') ?>
</body>
</html>
