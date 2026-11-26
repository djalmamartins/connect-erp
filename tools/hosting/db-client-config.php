<?php
/**
 * Imprime a seção [client] do mariadb-dump/mariadb com os dados do banco do .env (ADR 0325).
 * O backup grava a saída num arquivo 0600 e usa --defaults-extra-file, para a senha não aparecer
 * na lista de processos da hospedagem compartilhada.
 *
 *   php tools/hosting/db-client-config.php          # seção [client]
 *   php tools/hosting/db-client-config.php nome     # só o nome do banco
 *   php tools/hosting/db-client-config.php restore  # seção [client] do banco de teste da restauração: usa
 *       BACKUP_RESTORE_USER/BACKUP_RESTORE_PASS do ambiente quando existirem (o hPanel cria um usuário por banco)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2) . '/source/Boot/Config.php';

if (CONF_DB_HOST === '' || CONF_DB_NAME === '' || CONF_DB_USER === '') {
    fwrite(STDERR, "Banco não configurado no .env (MOVESOS_DB_HOST, MOVESOS_DB_USER, MOVESOS_DB_NAME).\n");
    exit(2);
}
if (($argv[1] ?? '') === 'nome') {
    echo CONF_DB_NAME . "\n";
    exit(0);
}
$quote = static fn (string $value): string => '"' . addcslashes($value, "\"\\") . '"';
echo "[client]\n";
echo 'host=' . $quote(CONF_DB_HOST) . "\n";
echo 'port=' . (int)CONF_DB_PORT . "\n";
$user = CONF_DB_USER;
$password = CONF_DB_PASS;
if (($argv[1] ?? '') === 'restore' && (string)getenv('BACKUP_RESTORE_USER') !== '') {
    $user = (string)getenv('BACKUP_RESTORE_USER');
    $password = (string)getenv('BACKUP_RESTORE_PASS');
}
echo 'user=' . $quote($user) . "\n";
echo 'password=' . $quote($password) . "\n";
