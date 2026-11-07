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
