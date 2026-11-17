<?php

declare(strict_types=1);

/**
 * Primeiro administrador da instalação limpa (ADR 0380), depois de composer db:migrate:
 *
 *   php tools/hosting/criar-admin.php --email=voce@connectcondominios.com.br --nome=Nome --sobrenome=Sobrenome --cpf=00000000000
 *
 * Mostra a senha uma vez só. Recusa se já existir administrador.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['email:', 'nome:', 'sobrenome:', 'cpf:']);
try {
    $admin = \Source\Support\FirstAdmin::create(\Source\Core\Connect::getInstance(), (string)($options['email'] ?? ''),
        (string)($options['nome'] ?? ''), (string)($options['sobrenome'] ?? ''), (string)($options['cpf'] ?? ''));
} catch (\DomainException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
echo "Administrador criado (id {$admin['id']}).\n";
echo "Senha (anote agora, ela não aparece de novo): {$admin['password']}\n";
echo "No primeiro login, troque a senha e ative o 2FA.\n";
