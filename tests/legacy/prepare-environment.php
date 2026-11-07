<?php

declare(strict_types=1);

$environment = getenv('MOVESOS_ENV') ?: '';
$database = getenv('MOVESOS_TEST_DB') ?: 'movesos_test';

if ($environment !== 'testing') {
    throw new RuntimeException('A suíte somente pode ser executada com MOVESOS_ENV=testing.');
}
if (!preg_match('/_test$/', $database)) {
    throw new RuntimeException('O banco da suíte deve terminar com _test.');
}

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/tests';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$sessionDirectory = sys_get_temp_dir() . '/movesos-test-sessions';
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Não foi possível preparar as sessões isoladas dos testes.');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path($sessionDirectory);
}

$host = getenv('MOVESOS_TEST_DB_HOST') ?: 'localhost';
$user = getenv('MOVESOS_TEST_DB_USER') ?: 'root';
$password = getenv('MOVESOS_TEST_DB_PASS') ?: '';
$configuredSocket = getenv('MOVESOS_TEST_DB_SOCKET') ?: '';
$socketCandidates = array_filter([
    $configuredSocket,
    (string)ini_get('pdo_mysql.default_socket'),
    '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock',
]);
$socket = null;
foreach ($socketCandidates as $candidate) {
    if (file_exists($candidate)) {
        $socket = $candidate;
        break;
    }
}

$serverDsn = $socket
    ? "mysql:unix_socket={$socket};charset=utf8mb4"
    : "mysql:host={$host};charset=utf8mb4";
$databaseDsn = $socket
    ? "mysql:unix_socket={$socket};dbname={$database};charset=utf8mb4"
    : "mysql:host={$host};dbname={$database};charset=utf8mb4";

$server = new PDO($serverDsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
]);
$server->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', str_replace('`', '``', $database)));

$pdo = new PDO($databaseDsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
]);

$settingsColumns = [
    'site_name', 'site_title', 'site_desc', 'site_photo', 'site_logo_svg', 'site_icon', 'site_favicon', 'site_lang', 'site_domain', 'site_domain_ssl',
    'site_domain_off', 'site_street', 'site_phone', 'site_whatsapp', 'site_number', 'site_complement',
    'site_city', 'site_state', 'site_code', 'site_district', 'view_theme', 'view_support', 'view_app', 'view_erp',
    'view_admin', 'view_mail', 'view_upkeep', 'upload_dir', 'upload_image', 'upload_file', 'upload_media',
    'image_cache', 'image_size', 'image_jpg', 'image_png', 'mail_host', 'mail_port', 'mail_user',
    'mail_pass', 'mail_name', 'mail_address', 'mail_suport', 'mail_lang', 'mail_html', 'mail_auth',
    'mail_secure', 'mail_charset', 'pay_mode', 'pay_live', 'pay_test', 'pay_back', 'social_tw_creator',
    'social_tw_publisher', 'social_fb_app', 'social_fb_page', 'social_fb_author', 'social_google_page',
    'social_google_author', 'social_instagram_page', 'social_youtube_page', 'social_linkedin_page', 'timezone_set'
];
$columnSql = implode(', ', array_map(static fn(string $column): string => "`{$column}` VARCHAR(255) NULL", $settingsColumns));
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, mode INT NULL DEFAULT 1, {$columnSql},
    access_studio TINYINT(1) NOT NULL DEFAULT 1, access_erp TINYINT(1) NOT NULL DEFAULT 1,
    access_app TINYINT(1) NOT NULL DEFAULT 1, access_site TINYINT(1) NOT NULL DEFAULT 1,
    access_support TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("ALTER TABLE settings
    ADD COLUMN IF NOT EXISTS site_logo_svg VARCHAR(255) NULL AFTER site_photo,
    ADD COLUMN IF NOT EXISTS site_icon VARCHAR(255) NULL AFTER site_logo_svg,
    ADD COLUMN IF NOT EXISTS site_favicon VARCHAR(255) NULL AFTER site_icon,
    ADD COLUMN IF NOT EXISTS view_support VARCHAR(255) NULL DEFAULT 'support' AFTER view_theme,
    ADD COLUMN IF NOT EXISTS access_site TINYINT(1) NOT NULL DEFAULT 1 AFTER access_app,
    ADD COLUMN IF NOT EXISTS access_support TINYINT(1) NOT NULL DEFAULT 1 AFTER access_site");
$pdo->exec("INSERT INTO settings
    (id,mode,site_name,site_title,site_desc,site_lang,site_domain_ssl,view_theme,view_support,view_app,view_erp,view_admin,view_mail,view_upkeep,mail_name,mail_address,mail_lang,mail_html,mail_auth,mail_charset,pay_mode,pay_back,timezone_set,access_studio,access_erp,access_app)
    VALUES (1,1,'MovesOS Test','MovesOS Test','Ambiente automatizado','pt_BR','https://localhost','default','support','default','default','default','default','default','MovesOS Test','test@localhost','pt_BR','1','0','UTF-8','test','/','America/Sao_Paulo',1,1,1)
    ON DUPLICATE KEY UPDATE site_name=VALUES(site_name),timezone_set=VALUES(timezone_set),access_studio=1,access_erp=1,access_app=1,access_site=1,access_support=1");
