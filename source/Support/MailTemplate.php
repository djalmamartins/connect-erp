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
        return (new View($this->root . '/'))->render("pages/{$name}", $data + self::defaults());
    }

    /**
     * Dados da moldura (layout Connect, ADR 0397): logo, site, política de privacidade e a identificação
     * da Connect no rodapé (LGPD e CDC: quem envia, CNPJ, endereço e contato). Campo vazio não aparece.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        $const = static fn (string $name): string => defined($name) ? trim((string)constant($name)) : '';
        $site = $const('CONF_URL_SSL');
        $street = trim($const('CONF_SITE_ADDR_STREET') . ($const('CONF_SITE_ADDR_NUMBER') !== '' ? ', ' . $const('CONF_SITE_ADDR_NUMBER') : ''));
        $city = trim($const('CONF_SITE_ADDR_CITY') . ($const('CONF_SITE_ADDR_STATE') !== '' ? ' - ' . $const('CONF_SITE_ADDR_STATE') : ''), ' -');
        $address = implode(', ', array_filter([$street, $const('CONF_SITE_ADDR_DISTRICT'), $city], static fn (string $part): bool => $part !== ''));
        if ($address !== '' && $const('CONF_SITE_ADDR_ZIPCODE') !== '') {
            $address .= ', CEP ' . $const('CONF_SITE_ADDR_ZIPCODE');
        }
        $cnpj = trim((string)(getenv('CONNECT_CNPJ') ?: ''));
        return [
            'site_name' => $const('CONF_SITE_NAME') !== '' ? $const('CONF_SITE_NAME') : 'Connect',
            'site_url' => $site,
            'logo_url' => function_exists('moves_container_url') ? moves_container_url('send', 'default', 'assets/images/logo-connect.png') : '',
            'privacy_url' => $site !== '' ? $site . '/politica-de-privacidade' : '',
            'company_cnpj' => $cnpj,
            'company_address' => $address,
            'company_phone' => $const('CONF_SITE_PHONE'),
            'company_email' => $const('CONF_MAIL_SUPPORT'),
        ];
    }

    private static function validName(string $name): bool
    {
        return (bool)preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~', $name);
    }
}
