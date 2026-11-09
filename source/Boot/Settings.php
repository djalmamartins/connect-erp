<?php

/**
 * ERP | A configuração base para o sistema
 *
 *
 * Este arquivo contém as seguintes configurações:
 *
 * * PROJECT URLs
 * * DAODS DO SISTEMA
 * * REDES SOCIAL
 * * VERSION DO SISTEMA
 * * THEMES VIEW
 * * CONFIGURAÇÕES ENVIO DE MAIL
 * * CONFIGURAÇÕES PAGAR.ME
 *
 * @author Djalma Martins
 *
 */

/**
 * CONNECT
 */

use Source\Models\Auth;

if (\Source\Core\Connect::databaseDisabled()) {
    // Somente MOVESOS_ENV=testing + MOVESOS_TEST_DB_DISABLED=1 (suíte unit sem MariaDB, A6).
    $setup = new class {
        // Mesmos valores semeados em tests/legacy/prepare-environment.php.
        private array $values = [
            'timezone_set' => 'America/Sao_Paulo', 'site_domain_ssl' => 'https://localhost', 'mode' => '1',
            'site_name' => 'MovesOS Test', 'site_lang' => 'pt_BR', 'view_theme' => 'default', 'view_support' => 'support',
            'view_app' => 'default', 'view_erp' => 'default', 'view_admin' => 'default', 'view_mail' => 'default',
            'view_upkeep' => 'default', 'mail_charset' => 'UTF-8', 'pay_back' => '/',
        ];
        public function __get(string $name): string { return $this->values[$name] ?? ''; }
        public function __isset(string $name): bool { return isset($this->values[$name]); }
    };
} else {
    $setup = \Source\Models\Settings\Settings::dados();
}
date_default_timezone_set($setup->timezone_set);

/**
 * PROJECT URLs
 */
define("CONF_URL_SSL", rtrim((string)($setup->site_domain_ssl ?: "https://www.connectcondominios.com.br"), "/"));
define("CONF_URL_LOCAL", "https://localhost/erp");


/**
 * SITE
 */
define("CONF_SITE_NAME", "{$setup->site_name}");
define("CONF_SITE_TITLE", "{$setup->site_title}");
define("CONF_SITE_DESC", "{$setup->site_desc}");
define("CONF_SITE_LANG", "{$setup->site_lang}");
define("CONF_SITE_DOMAIN", "{$setup->site_domain_ssl}");
define("CONF_SITE_PHOTO", "{$setup->site_photo}");
define("CONF_SITE_LOGO_SVG", "{$setup->site_logo_svg}");
define("CONF_SITE_ICON", "{$setup->site_icon}");
define("CONF_SITE_FAVICON", "{$setup->site_favicon}");
define("CONF_SITE_PHONE", "{$setup->site_phone}");
define("CONF_SITE_WHATSAPP", "{$setup->site_whatsapp}");
define("CONF_SITE_ADDR_STREET", "{$setup->site_street}");
define("CONF_SITE_ADDR_NUMBER", "{$setup->site_number}");
define("CONF_SITE_ADDR_COMPLEMENT", "{$setup->site_complement}");
define("CONF_SITE_ADDR_CITY", "{$setup->site_city}");
define("CONF_SITE_ADDR_STATE", "{$setup->site_state}");
define("CONF_SITE_ADDR_ZIPCODE", "{$setup->site_code}");
define("CONF_SITE_ADDR_DISTRICT", "{$setup->site_district}");

/**
 * SOCIAL
 */
define("CONF_SOCIAL_TWITTER_CREATOR", "{$setup->social_tw_creator}");
define("CONF_SOCIAL_TWITTER_PUBLISHER", "{$setup->social_tw_publisher}");
define("CONF_SOCIAL_FACEBOOK_APP", "{$setup->social_fb_app}");
define("CONF_SOCIAL_FACEBOOK_PAGE", "{$setup->social_fb_page}");
define("CONF_SOCIAL_FACEBOOK_AUTHOR", "{$setup->social_fb_author}");
define("CONF_SOCIAL_GOOGLE_PAGE", "{$setup->social_google_page}");
define("CONF_SOCIAL_GOOGLE_AUTHOR", "{$setup->social_google_author}");
define("CONF_SOCIAL_INSTAGRAM_PAGE", "{$setup->social_instagram_page}");
define("CONF_SOCIAL_YOUTUBE_PAGE", "{$setup->social_youtube_page}");
define("CONF_SOCIAL_LINKEDIN_PAGE", "{$setup->social_linkedin_page}");


/**
 * VIEW
 */
define("CONF_MODE", "{$setup->mode}");
define("CONF_VIEW_PATH", __DIR__ . "/../../shared/pages");
define("CONF_VIEW_EXT", "php");
define("CONF_VIEW_THEMES", "{$setup->view_theme}");
define("CONF_VIEW_SUPPORT", "{$setup->view_support}");
define("CONF_VIEW_APP", "{$setup->view_app}");
define("CONF_VIEW_ERP", "{$setup->view_erp}");
define("CONF_VIEW_STUDIO", "{$setup->view_admin}");
define("CONF_VIEW_MAIL", "{$setup->view_mail}");
define("CONF_ACCESS_SITE", (bool)($setup->access_site ?? true));
define("CONF_ACCESS_SUPPORT", (bool)($setup->access_support ?? true));

if (CONF_MODE === "2")
{
    $user = Auth::user();
    if ($user && $user->level >= 5) {
        define("CONF_VIEW_THEME", CONF_VIEW_THEMES);
    } else {
        define("CONF_VIEW_THEME", "{$setup->view_upkeep}");
    }
}else{
    define("CONF_VIEW_THEME", CONF_VIEW_THEMES);
}


/**
 * versin
 */
const VERSION_SITE = "1.0.0";
const VERSION_APP = "ALFA";
const VERSION_STUDIO = "3.0.0";
const VERSION_ERP = "ALFA";
const VERSION_SUPPORT = "1.0.0";


/**
 * MAIL
 */
// Credenciais SMTP do ambiente (MOVESOS_SMTP_*), que vale sobre a tabela settings (ADR 0060).
$mailConfig = \Source\Support\MailConfig::current($setup);
define("CONF_MAIL_HOST", $mailConfig["host"]);
define("CONF_MAIL_PORT", $mailConfig["port"]);
define("CONF_MAIL_USER", $mailConfig["user"]);
define("CONF_MAIL_PASS", $mailConfig["pass"]);
define("CONF_MAIL_SENDER", ["name" => $mailConfig["from_name"], "address" => $mailConfig["from_address"]]);
define("CONF_MAIL_SUPPORT", "{$setup->mail_suport}");
define("CONF_MAIL_OPTION_LANG", "{$setup->mail_lang}");
define("CONF_MAIL_OPTION_HTML", $setup->mail_html);
define("CONF_MAIL_OPTION_AUTH", $mailConfig["auth"]);
define("CONF_MAIL_OPTION_SECURE", $mailConfig["secure"]);
unset($mailConfig);
define("CONF_MAIL_OPTION_CHARSET", "{$setup->mail_charset}");

/**
 * PAGAR.ME
 */

define("CONF_PAGARME_MODE", "{$setup->pay_mode}");
define("CONF_PAGARME_LIVE", "{$setup->pay_live}");
define("CONF_PAGARME_TEST", "{$setup->pay_test}");
define("CONF_PAGARME_BACK", CONF_URL_SSL . "{$setup->pay_back}");
