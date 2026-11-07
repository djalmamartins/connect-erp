<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
$project = 'connect';
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title><?= htmlspecialchars(ucfirst($project)) ?> LAB</title></head>
<body><h1><?= htmlspecialchars(ucfirst($project)) ?> LAB</h1><p>PHP <?= htmlspecialchars(PHP_VERSION) ?> ativo.</p></body></html>
