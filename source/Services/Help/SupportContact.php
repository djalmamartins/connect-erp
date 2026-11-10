<?php

declare(strict_types=1);

namespace Source\Services\Help;

/**
 * Canais e dados de contato da Central de Ajuda (ADR 0141), lidos das configurações do site
 * (Studio › Configurações). Campo vazio some da página: nada é inventado.
 */
final class SupportContact
{
    /** @return array{phone:string,whatsapp_url:string,email:string,address:string,hours:string,system_url:string,social:list<array{url:string,label:string,icon:string}>} */
    public static function data(): array
    {
        $constant = static fn (string $name): string => defined($name) ? trim((string)constant($name)) : '';
        $whatsapp = preg_replace('/\D+/', '', $constant('CONF_SITE_WHATSAPP')) ?? '';
        if ($whatsapp !== '' && strlen($whatsapp) <= 11) {
            $whatsapp = '55' . $whatsapp;
        }
        $street = $constant('CONF_SITE_ADDR_STREET');
        $address = '';
        if ($street !== '') {
            $address = $street
                . ($constant('CONF_SITE_ADDR_NUMBER') !== '' ? ', ' . $constant('CONF_SITE_ADDR_NUMBER') : '')
                . ($constant('CONF_SITE_ADDR_COMPLEMENT') !== '' ? ' - ' . $constant('CONF_SITE_ADDR_COMPLEMENT') : '')
                . ($constant('CONF_SITE_ADDR_DISTRICT') !== '' ? ', ' . $constant('CONF_SITE_ADDR_DISTRICT') : '')
                . ($constant('CONF_SITE_ADDR_CITY') !== '' ? ', ' . $constant('CONF_SITE_ADDR_CITY') : '')
                . ($constant('CONF_SITE_ADDR_STATE') !== '' ? '/' . $constant('CONF_SITE_ADDR_STATE') : '')
                . ($constant('CONF_SITE_ADDR_ZIPCODE') !== '' ? ' - CEP ' . $constant('CONF_SITE_ADDR_ZIPCODE') : '');
        }
        $social = [];
        foreach ([
            ['CONF_SOCIAL_INSTAGRAM_PAGE', 'https://www.instagram.com/', 'Instagram', 'logo-instagram'],
            ['CONF_SOCIAL_FACEBOOK_PAGE', 'https://www.facebook.com/', 'Facebook', 'logo-facebook'],
            ['CONF_SOCIAL_YOUTUBE_PAGE', 'https://www.youtube.com/', 'YouTube', 'logo-youtube'],
            ['CONF_SOCIAL_LINKEDIN_PAGE', 'https://www.linkedin.com/', 'LinkedIn', 'logo-linkedin'],
        ] as [$name, $base, $label, $icon]) {
            $value = $constant($name);
            if ($value === '') {
                continue;
            }
            $url = str_starts_with($value, 'https://') ? $value : $base . ltrim($value, '/@');
            $social[] = ['url' => $url, 'label' => $label, 'icon' => $icon];
        }
        $erpHost = trim((string)(getenv('CONNECT_ERP_URL') ?: ''));

        return [
            'phone' => $constant('CONF_SITE_PHONE'),
            'whatsapp_url' => $whatsapp !== '' ? 'https://wa.me/' . $whatsapp : '',
            'email' => filter_var($constant('CONF_MAIL_SUPPORT'), FILTER_VALIDATE_EMAIL) ? $constant('CONF_MAIL_SUPPORT') : '',
            'address' => $address,
            'hours' => trim((string)(getenv('CONNECT_SUPPORT_HOURS') ?: 'De segunda a sexta, das 8h às 18h')),
            'system_url' => $erpHost !== '' && str_starts_with($erpHost, 'https://') ? $erpHost : (function_exists('url') ? url('/erp') : '/erp'),
            'social' => $social,
        ];
    }
}
