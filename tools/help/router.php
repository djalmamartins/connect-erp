<?php

/*
 * Roteador do servidor embutido do PHP para os prints da Central de Ajuda (ADR 0141):
 *   php -S 0.0.0.0:8089 tools/help/router.php
 * Arquivos estáticos (CSS, JS, imagens) saem direto; o resto passa pelo index.php. Só para o
 * banco de demonstração (*_test) do tools/help/prints.sh; não é servidor de produção.
 */
$root = dirname(__DIR__, 2);
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = realpath($root . $path);
if ($path !== '/' && $file !== false && str_starts_with($file, $root . '/') && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir($root);
require $root . '/index.php';
