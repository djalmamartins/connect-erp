<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Erros do PHP fora da tela em produção (ADR 0366).
 *
 * Nada no sistema desligava o display_errors: com APP_DEBUG=false, um aviso do PHP ainda podia sair no meio da página
 * com o caminho do arquivo no servidor, se o php.ini da hospedagem viesse com display_errors ligado. Aqui, só na web
 * (o CLI do cron continua mostrando erro no log dele), sem debug: display_errors e display_startup_errors desligados,
 * log_errors ligado (o AppLogger também registra) e sem o cabeçalho X-Powered-By com a versão do PHP.
 */
final class ErrorDisplay
{
    /** @return array<string, string> diretivas do php.ini a aplicar; vazio com debug ligado */
    public static function settings(bool $debug): array
    {
        return $debug ? [] : ['display_errors' => '0', 'display_startup_errors' => '0', 'log_errors' => '1'];
    }

    public static function apply(bool $debug, string $sapi = PHP_SAPI): void
    {
        if ($sapi === 'cli') {
            return;
        }
        foreach (self::settings($debug) as $directive => $value) {
            ini_set($directive, $value);
        }
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }
}
