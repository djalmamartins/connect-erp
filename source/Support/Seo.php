<?php

namespace Source\Support;

use MovesCode\Seo\Seo as MovesSeo;

/**
 * ERP | Class Seo
 *
 * @author Djalma Martins
 * @package Source\Support
 */
class Seo
{
    /** @var MovesSeo */
    protected $optimizer;

    /** @var array<string, string> */
    protected array $twitter = [];

    /**
     * Seo constructor.
     * @param string $schema
     */
    public function __construct(string $schema = "article")
    {
        $facebookPage = CONF_SOCIAL_FACEBOOK_PAGE;
        if (filter_var($facebookPage, FILTER_VALIDATE_URL)) {
            $facebookPage = trim((string)parse_url($facebookPage, PHP_URL_PATH), "/");
        }
        $this->optimizer = new MovesSeo();
        $this->optimizer->openGraph(
            CONF_SITE_NAME,
            CONF_SITE_LANG,
            $schema
        )->publisher(
            $facebookPage,
            CONF_SOCIAL_FACEBOOK_AUTHOR
        )->facebook(
            CONF_SOCIAL_FACEBOOK_APP
        );

        $this->optimizer->socialProfiles([
            "facebook" => CONF_SOCIAL_FACEBOOK_PAGE,
            "instagram" => CONF_SOCIAL_INSTAGRAM_PAGE,
            "google_business" => CONF_SOCIAL_GOOGLE_PAGE,
            "linkedin" => CONF_SOCIAL_LINKEDIN_PAGE,
            "youtube" => CONF_SOCIAL_YOUTUBE_PAGE
        ]);

        $this->twitter = [
            "card" => "summary_large_image",
            "site" => CONF_SOCIAL_TWITTER_PUBLISHER,
            "creator" => CONF_SOCIAL_TWITTER_CREATOR,
            "domain" => CONF_SITE_DOMAIN
        ];
    }

    /**
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        return $this->optimizer->data()->$name;
    }

    /**
     * @param string $title
     * @param string $description
     * @param string $url
     * @param string $image
     * @param bool $follow
     * @return string
     */
    public function render(string $title, string $description, string $url, string $image, bool $follow = true): string
    {
        $output = $this->optimizer->optimize($title, $description, $url, $image, $follow)->render();
        foreach ($this->twitter as $key => $value) {
            if ($value !== "") {
                $output .= sprintf(
                    "\n<meta name=\"twitter:%s\" content=\"%s\">",
                    htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, "UTF-8"),
                    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, "UTF-8")
                );
            }
        }
        return $output;
    }

    /**
     * Dados da empresa para o Google (JSON-LD), lidos de Configurações do Studio (ADR 0399). Com
     * endereço completo vira LocalBusiness (aparece no mapa e na busca local); sem endereço, Organization.
     * Os perfis sociais já configurados no construtor entram como "sameAs".
     */
    public function business(?string $logo = null): self
    {
        $name = trim((string)CONF_SITE_NAME);
        if ($name === "") {
            return $this;
        }
        $siteUrl = url();
        $logo = $logo ?: site_logo_url();
        $street = trim(implode(", ", array_filter([trim((string)CONF_SITE_ADDR_STREET), trim((string)CONF_SITE_ADDR_NUMBER), trim((string)CONF_SITE_ADDR_COMPLEMENT)])));
        $city = trim((string)CONF_SITE_ADDR_CITY);
        $zip = trim((string)CONF_SITE_ADDR_ZIPCODE);

        if ($street !== "" && $city !== "" && $zip !== "") {
            $address = [
                "streetAddress" => $street,
                "addressLocality" => $city,
                "postalCode" => $zip,
                "addressCountry" => "BR",
            ];
            if (trim((string)CONF_SITE_ADDR_STATE) !== "") {
                $address["addressRegion"] = trim((string)CONF_SITE_ADDR_STATE);
            }
            $phone = trim((string)CONF_SITE_PHONE);
            $this->optimizer->localBusiness($name, $siteUrl, $logo, $address, $phone !== "" ? $phone : null);
            return $this;
        }

        $this->optimizer->organization($name, $siteUrl, $logo, trim((string)CONF_SITE_DESC) ?: null);
        return $this;
    }

    /**
    * @return MovesSeo
     */
    public function optimizer(): MovesSeo
    {
        return $this->optimizer;
    }

    /**
     * @param string|null $title
     * @param string|null $desc
     * @param string|null $url
     * @param string|null $image
     * @return null|object
     */
    public function data(?string $title = null, ?string $desc = null, ?string $url = null, ?string $image = null)
    {
        return $this->optimizer->data($title, $desc, $url, $image);
    }
}
