<?php

declare(strict_types=1);

namespace Source\Support;

use Source\Core\View;

/**
 * Renderiza e-mails transacionais do ambiente send (A4):
 * container/send/{tema}/v1/pages/{nome}.php com a moldura default.php.
 * A lógica de envio continua em Support/Email; aqui só existe o HTML.
 */
final class MailTemplate
{
    private string $root;

    public function __construct(?string $theme = null, ?string $root = null)
    {
        $this->root = rtrim($root ?? moves_container_path('send', $theme ?? (defined('CONF_VIEW_MAIL') ? CONF_VIEW_MAIL : 'default')), '/');
    }

    public function exists(string $name): bool
    {
        return self::validName($name) && is_file("{$this->root}/pages/{$name}.php");
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = []): string
    {
        if (!self::validName($name)) {
            throw new \InvalidArgumentException('Nome de template de e-mail inválido.');
        }
        if (!$this->exists($name)) {
            throw new \RuntimeException("Template de e-mail não encontrado: {$name}.");
        }
        $defaults = ['site_name' => defined('CONF_SITE_NAME') ? CONF_SITE_NAME : 'Connect'];
        return (new View($this->root . '/'))->render("pages/{$name}", $data + $defaults);
    }

    private static function validName(string $name): bool
    {
        return (bool)preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~', $name);
    }
}
