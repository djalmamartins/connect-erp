<?php

$environmentFile = dirname(__DIR__, 2) . "/.env";
if (is_file($environmentFile)) {
    $environment = parse_ini_file($environmentFile, false, INI_SCANNER_RAW) ?: [];
    $allowedEnvironmentKeys = [
        "APP_ENV", "APP_DEBUG", "APP_URL",
        "DB_HOST", "DB_PORT", "DB_USER", "DB_PASS", "DB_NAME",
        "MOVESOS_ENV", "MOVESOS_DB_HOST", "MOVESOS_DB_PORT",
        "MOVESOS_DB_USER", "MOVESOS_DB_PASS", "MOVESOS_DB_NAME",
        "MOVESOS_TEST_DB_HOST", "MOVESOS_TEST_DB_PORT", "MOVESOS_TEST_DB",
        "MOVESOS_TEST_DB_USER", "MOVESOS_TEST_DB_PASS", "MOVESOS_TEST_DB_SOCKET",
        "INTER_API_URL", "INTER_CLIENT_ID", "INTER_CLIENT_SECRET",
        "INTER_SCOPE", "INTER_CERT_PATH", "INTER_KEY_PATH",
    ];
    foreach ($allowedEnvironmentKeys as $key) {
        if (getenv($key) === false && array_key_exists($key, $environment)) {
            putenv($key . "=" . (string)$environment[$key]);
        }
    }

    $environmentKeys = [
        "APP_ENV" => "MOVESOS_ENV",
        "DB_HOST" => "MOVESOS_DB_HOST",
        "DB_PORT" => "MOVESOS_DB_PORT",
        "DB_USER" => "MOVESOS_DB_USER",
        "DB_PASS" => "MOVESOS_DB_PASS",
        "DB_NAME" => "MOVESOS_DB_NAME"
    ];
    foreach ($environmentKeys as $source => $target) {
        if (getenv($target) === false && array_key_exists($source, $environment)) {
            putenv($target . "=" . (string)$environment[$source]);
        }
    }
}

/** MovesOS: configuração de infraestrutura e ambiente. */
$movesEnvironment = getenv("MOVESOS_ENV") ?: "production";

if ($movesEnvironment === "testing") {
    $testDatabase = getenv("MOVESOS_TEST_DB") ?: "movesos_test";
    if (!preg_match("~_test$~", $testDatabase)) throw new RuntimeException("O banco de testes deve terminar com _test.");
    define("CONF_DB_HOST", getenv("MOVESOS_TEST_DB_HOST") ?: "127.0.0.1");
    define("CONF_DB_PORT", (int)(getenv("MOVESOS_TEST_DB_PORT") ?: 3306));
    define("CONF_DB_USER", getenv("MOVESOS_TEST_DB_USER") ?: "root");
    define("CONF_DB_PASS", getenv("MOVESOS_TEST_DB_PASS") ?: "");
    define("CONF_DB_NAME", $testDatabase);
} elseif ($movesEnvironment === "local" || strpos($_SERVER["HTTP_HOST"] ?? "", "localhost") !== false || preg_match("/\.lab(?::\d+)?$/i", $_SERVER["HTTP_HOST"] ?? "")) {
    define("CONF_DB_HOST", getenv("MOVESOS_DB_HOST") ?: "127.0.0.1");
    define("CONF_DB_PORT", (int)(getenv("MOVESOS_DB_PORT") ?: 3306));
    define("CONF_DB_USER", getenv("MOVESOS_DB_USER") ?: "root");
    define("CONF_DB_PASS", getenv("MOVESOS_DB_PASS") ?: "");
    define("CONF_DB_NAME", getenv("MOVESOS_DB_NAME") ?: "moves_db");
} else {
    $productionDatabase = ["host" => getenv("MOVESOS_DB_HOST") ?: "", "user" => getenv("MOVESOS_DB_USER") ?: "", "pass" => getenv("MOVESOS_DB_PASS") ?: "", "name" => getenv("MOVESOS_DB_NAME") ?: ""];
    if (in_array("", $productionDatabase, true)) throw new RuntimeException("Configure MOVESOS_DB_HOST, MOVESOS_DB_USER, MOVESOS_DB_PASS e MOVESOS_DB_NAME no ambiente de produção.");
    define("CONF_DB_HOST", $productionDatabase["host"]);
    define("CONF_DB_PORT", (int)(getenv("MOVESOS_DB_PORT") ?: 3306));
    define("CONF_DB_USER", $productionDatabase["user"]);
    define("CONF_DB_PASS", $productionDatabase["pass"]);
    define("CONF_DB_NAME", $productionDatabase["name"]);
}

define("CONF_APP_ENV", $movesEnvironment);
define("CONF_APP_DEBUG", filter_var(getenv("APP_DEBUG") ?: false, FILTER_VALIDATE_BOOL));
define("CONF_APP_URL", rtrim(getenv("APP_URL") ?: "", "/"));

const CONF_DATE = "d/m/Y";
const CONF_DATE_BR = "d/m/Y H:i:s";
const CONF_DATE_APP = "Y-m-d H:i:s";
const CONF_UPLOAD_DIR = "storage";
const CONF_UPLOAD_IMAGE_DIR = "images";
const CONF_UPLOAD_FILE_DIR = "files";
const CONF_UPLOAD_MEDIA_DIR = "medias";
const CONF_IMAGE_CACHE = CONF_UPLOAD_DIR . "/" . CONF_UPLOAD_IMAGE_DIR . "/cache";
const CONF_IMAGE_SIZE = 2000;
const CONF_IMAGE_QUALITY = ["jpg" => 75, "png" => 5];
const CONF_PASSWD_MIN_LEN = 8;
const CONF_PASSWD_MAX_LEN = 40;
const CONF_PASSWD_ALGO = PASSWORD_DEFAULT;
const CONF_PASSWD_OPTION = ["cost" => 10];
