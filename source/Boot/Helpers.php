<?php
/**
 * ####################
 * ###   VALIDATE   ###
 * ####################
 */



/**
 * @param string $email
 * @return bool
 */
function is_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * @param string $document
 * @return bool
 */
function is_document(string $document): bool
{
    // Extrai somente os números
    $document = preg_replace('/[^0-9]/is', '', $document);

    // Verifica se é cpf ou cnpj
    if (strlen($document) <= 11){

        // Verifica se foi informado todos os digitos corretamente
        if (strlen($document) != 11) {
            return false;
        }
        // Verifica se foi informada uma sequência de digitos repetidos. Ex: 111.111.111-11
        if (preg_match('/(\d)\1{10}/', $document)) {
            return false;
        }

        // Faz o calculo para validar o CPF
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $document[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($document[$c] != $d) {
                return false;
            }
        }
        return true;
    }else{

        // Verificar se o CNPJ possui 14 dígitos
        if (strlen($document) != 14) {
            return false;
        }

        // Verificar se todos os dígitos são iguais (CNPJ inválido)
        if (preg_match('/(\d)\1{13}/', $document)) {
            return false;
        }

        // Calcular os dígitos verificadores
        for ($i = 0, $j = 5, $soma = 0; $i < 12; $i++) {
            $soma += $document[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }

        $resto = $soma % 11;
        $digito1 = ($resto < 2) ? 0 : 11 - $resto;

        for ($i = 0, $j = 6, $soma = 0; $i < 13; $i++) {
            $soma += $document[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }

        $resto = $soma % 11;
        $digito2 = ($resto < 2) ? 0 : 11 - $resto;

        // Verificar se os dígitos calculados são iguais aos dígitos originais do CNPJ
        if ($document[12] != $digito1 && $document[13] != $digito2){
            return false;
        }
        return true;
    }


}

/**
 * @param string $password
 * @return bool
 */
function is_passwd(string $password): bool
{
    if (password_get_info($password)['algo'] || (mb_strlen($password) >= CONF_PASSWD_MIN_LEN && mb_strlen($password) <= CONF_PASSWD_MAX_LEN)) {
        return true;
    }
    return false;
}

/**
 * ##################
 * ###   STRING   ###
 * ##################
 */

/**
 * @param string $string
 * @return string
 */
function str_slug(string $string): string
{
    $string = filter_var(mb_strtolower($string), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $formats = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜüÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûýýþÿRr"!@#$%&*()_-+={[}]/?;:.,\\\'<>°ºª';
    $replace = 'aaaaaaaceeeeiiiidnoooooouuuuuybsaaaaaaaceeeeiiiidnoooooouuuyybyrr                                 ';

    $slug = str_replace(["-----", "----", "---", "--"], "-",
        str_replace(" ", "-",
            trim(strtr(utf8_decode($string), utf8_decode($formats), $replace))
        )
    );
    return $slug;
}

/**
 * @param string $string
 * @return string
 */
function str_studly_case(string $string): string
{
    $string = str_slug($string);
    $studlyCase = str_replace(" ", "",
        mb_convert_case(str_replace("-", " ", $string), MB_CASE_TITLE)
    );

    return $studlyCase;
}

/**
 * @param string $string
 * @return string
 */
function str_camel_case(string $string): string
{
    return lcfirst(str_studly_case($string));
}

/**
 * @param string $string
 * @return string
 */
function str_title(string $string): string
{
    return mb_convert_case(filter_var($string, FILTER_SANITIZE_SPECIAL_CHARS), MB_CASE_TITLE);
}

/**
 * @param string $text
 * @return string
 */
function str_textarea(string $text): string
{
    $text = filter_var($text, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $arrayReplace = ["&#10;", "&#10;&#10;", "&#10;&#10;&#10;", "&#10;&#10;&#10;&#10;", "&#10;&#10;&#10;&#10;&#10;"];
    return "<p>" . str_replace($arrayReplace, "</p><p>", $text) . "</p>";
}

/**
 * @param string $string
 * @param int $limit
 * @param string $pointer
 * @return string
 */
function str_limit_words(string $string, int $limit, string $pointer = "..."): string
{
    $string = trim(filter_var($string, FILTER_SANITIZE_SPECIAL_CHARS));
    $arrWords = explode(" ", $string);
    $numWords = count($arrWords);

    if ($numWords < $limit) {
        return $string;
    }

    $words = implode(" ", array_slice($arrWords, 0, $limit));
    return "{$words}{$pointer}";
}

/**
 * @param string $string
 * @param int $limit
 * @param string $pointer
 * @return string
 */
function str_limit_chars(string $string, int $limit, string $pointer = "..."): string
{
    $string = trim(filter_var($string, FILTER_SANITIZE_SPECIAL_CHARS));
    if (mb_strlen($string) <= $limit) {
        return $string;
    }

    $chars = mb_substr($string, 0, mb_strrpos(mb_substr($string, 0, $limit), " "));
    return "{$chars}{$pointer}";
}

/**
 * Estimate reading time from HTML or plain text.
 *
 * @param string|null $content
 * @param int $wordsPerMinute
 * @return int Estimated minutes, always at least 1
 */
function reading_time(?string $content, int $wordsPerMinute = 200): int
{
    if ($wordsPerMinute < 1) {
        $wordsPerMinute = 200;
    }

    $plainText = trim(html_entity_decode(strip_tags($content ?? ""), ENT_QUOTES | ENT_HTML5, "UTF-8"));
    if ($plainText === "") {
        return 1;
    }

    $words = preg_split('/\s+/u', $plainText, -1, PREG_SPLIT_NO_EMPTY);
    return max(1, (int)ceil(count($words) / $wordsPerMinute));
}

/**
 * @param string $price
 * @return string
 */
function str_price(?string $price): string
{
    return "R$ " . number_format((!empty($price) ? $price : 0), 2, ",", ".");
}

/**
 * @param string|null $search
 * @return string
 */
function str_search(?string $search): string
{
    if (!$search) {
        return "all";
    }

    $search = preg_replace("/[^a-z0-9A-Z\@\ ]/", "", $search);
    return (!empty($search) ? $search : "all");
}

/**
 * ###############
 * ###   URL   ###
 * ###############
 */

/**
 * @param string $path
 * @return string
 */
function is_local_host(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return strpos($host, 'localhost') !== false
        || (bool)preg_match('/\.lab(?::\d+)?$/i', $host);
}

/**
 * Keep local asset and route URLs on the host/path used by the request.
 * This supports both a dedicated *.lab virtual host and /_connect on localhost.
 */
function local_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? parse_url(CONF_URL_LOCAL, PHP_URL_HOST);
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || $forwardedProto === 'https';
    $scheme = $https ? 'https' : 'http';
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = rtrim(str_replace('/index.php', '', $scriptName), '/');

    return "{$scheme}://{$host}{$basePath}";
}

function url(?string $path = null): string
{

    if (is_local_host()) {
        $baseUrl = local_url();
        if ($path) {
            return $baseUrl . "/" . ($path[0] == "/" ? mb_substr($path, 1) : $path);
        }
        return $baseUrl;
    }

    if ($path) {
        return CONF_URL_SSL . "/" . ($path[0] == "/" ? mb_substr($path, 1) : $path);
    }

    return CONF_URL_SSL;
}

/**
 * @return string
 */
function url_back(): string
{
    if(!empty($_SERVER["HTTP_REFERER"]) && str_contains($_SERVER["HTTP_REFERER"], CONF_SITE_DOMAIN)){
        return $_SERVER["HTTP_REFERER"];
    }
    return url();
}

/**
 * @param string $url
 */
function redirect(string $url): void
{
    header("HTTP/1.1 302 Redirect");
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        header("Location: {$url}");
        exit;
    }

    if (filter_input(INPUT_GET, "route", FILTER_DEFAULT) != $url) {
        $location = url($url);
        header("Location: {$location}");
        exit;
    }
}

/**
 * ##################
 * ###   ASSETS   ###
 * ##################
 */

/**
 * @return \Source\Models\User|null
 */
function user(): ?\Source\Models\User
{
    return \Source\Models\Auth::user();
}

/**
 * @return \Source\Core\Session
 */
function session(): \Source\Core\Session
{
    return new \Source\Core\Session();
}

function searchOwner($units_id, $owner): ?string
{
    if(!empty($units_id)){
        $ownerList = (new \Source\Models\Corporation\AppOwner())->find("units_id = :units_id AND owner = :owner AND status = 'confirmed'", "units_id={$units_id}&owner={$owner}")->fetch();
        if(!empty($ownerList)){
            $user = (new \Source\Models\User())->find("id = :id", "id={$ownerList->users_id}")->fetch();
            if(!empty($user)){
                return $user->first_name . " " . $user->last_name;
            }
        }
    }
    return "Adicionar";
}

/**
 * @param string|null $path
 * @param string $theme
 * @return string
 */
function moves_container_theme(string $area, string $theme): string
{
    $aliases = [
        'web' => ['connect_by_moves' => 'default', 'support_by_moves' => 'support'],
        'studio' => ['moves_studio' => 'studio', 'default' => 'studio'],
        'erp' => ['connect' => 'default'],
        'residents' => ['app_connect' => 'default'],
        'mail' => ['mail' => 'default'],
    ];

    return $aliases[$area][$theme] ?? $theme;
}

function moves_container_path(string $area, string $theme): string
{
    if ($area === 'web') {
        return dirname(__DIR__, 2) . "/container/web/" . moves_container_theme($area, $theme) . "/v1";
    }
    if ($area === 'studio') {
        return dirname(__DIR__, 2) . "/container/cms/" . moves_container_theme($area, $theme) . "/v1";
    }
    $section = in_array($area, ['studio', 'helpdesk', 'erp', 'residents'], true)
        ? "apps/{$area}"
        : $area;

    return dirname(__DIR__, 2) . "/container/{$section}/" . moves_container_theme($area, $theme);
}

function moves_container_url(string $area, string $theme, ?string $path = null): string
{
    if ($area === 'web') {
        return url("container/web/" . moves_container_theme($area, $theme) . "/v1" . ($path ? "/" . ltrim($path, "/") : ""));
    }
    if ($area === 'studio') {
        return url("container/cms/" . moves_container_theme($area, $theme) . "/v1" . ($path ? "/" . ltrim($path, "/") : ""));
    }
    $section = in_array($area, ['studio', 'helpdesk', 'erp', 'residents'], true)
        ? "apps/{$area}"
        : $area;
    $theme = moves_container_theme($area, $theme);

    return url("container/{$section}/{$theme}" . ($path ? "/" . ltrim($path, "/") : ""));
}

function theme(?string $path = null, string $theme = CONF_VIEW_THEME): string
{
    return moves_container_url('web', $theme, $path);
}

/**
 * @param string|null $path
 * @param string $theme
 * @return string
 */
function themeApp(?string $path = null, string $theme = CONF_VIEW_APP): string
{
    return moves_container_url('residents', $theme, $path);
}

/**
 * @param string|null $path
 * @param string $theme
 * @return string
 */
function themeErp(?string $path = null, string $theme = CONF_VIEW_ERP): string
{
    return moves_container_url('erp', $theme, $path);
}


/**
 * @param string|null $path
 * @param string $theme
 * @return string
 */
function themeMail(?string $path = null, string $theme = CONF_VIEW_MAIL): string
{
    return moves_container_url('mail', $theme, $path);
}

/**
 * @param string|null $path
 * @param string $theme
 * @return string
 */
function themeStudio(?string $path = null, string $theme = 'moves_studio'): string
{
    return moves_container_url('studio', $theme, $path);
}
/**
 * @param string $image
 * @param int $width
 * @param int|null $height
 * @return string
 */
function image(?string $image, int $width, ?int $height = null): ?string
{
    if ($image) {
        $generated = (new \Source\Support\Thumb())->make($image, $width, $height);
        if (!$generated) {
            return theme("/assets/images/avatar.jpg");
        }
        $thumb = str_replace("\\", "/", $generated);
        $root = str_replace("\\", "/", dirname(__DIR__, 2)) . "/";
        if (str_starts_with($thumb, $root)) {
            $thumb = substr($thumb, strlen($root));
        }
        return url("/" . ltrim($thumb, "/"));
    }

    return null;
}

/**
 * ################
 * ###   DATE   ###
 * ################
 */

/**
 * @param string $date
 * @param string $format
 * @return string
 * @throws Exception
 */
function date_fmt(?string $date, string $format = "d/m/Y H\hi"): string
{
    $date = (empty($date) ? "now" : $date);
    return (new DateTime($date))->format($format);
}

function date_br_fmt(?string $date, string $format = "d/m/Y"): string
{
    $date = (empty($date) ? "now" : $date);
    return (new DateTime($date))->format($format);
}

function hoje():string
{
    $data = date('D');
    $mes = date('M');
    $dia = date('d');
    $ano = date('Y');

    $semana = array(
        'Sun' => 'Domingo',
        'Mon' => 'Segunda-Feira',
        'Tue' => 'Terca-Feira',
        'Wed' => 'Quarta-Feira',
        'Thu' => 'Quinta-Feira',
        'Fri' => 'Sexta-Feira',
        'Sat' => 'Sábado'
    );

    $mes_extenso = array(
        'Jan' => 'Janeiro',
        'Feb' => 'Fevereiro',
        'Mar' => 'Março',
        'Apr' => 'Abril',
        'May' => 'Maio',
        'Jun' => 'Junho',
        'Jul' => 'Julho',
        'Aug' => 'Agosto',
        'Nov' => 'Novembro',
        'Sep' => 'Setembro',
        'Oct' => 'Outubro',
        'Dec' => 'Dezembro'
    );

    return $semana["$data"] . ", {$dia} de " . $mes_extenso["$mes"] . " de {$ano}";

}

/**
 * @param string $date
 * @return string
 * @throws Exception
 */
function date_fmt_br(?string $date): string
{
    $date = (empty($date) ? "now" : $date);
    return (new DateTime($date))->format(CONF_DATE_BR);
}

/**
 * @param string $date
 * @return string
 * @throws Exception
 */
function date_fmt_app(?string $date): string
{
    $date = (empty($date) ? "now" : $date);
    return (new DateTime($date))->format(CONF_DATE_APP);
}

/**
 * @param string|null $date
 * @return string|null
 */
function date_fmt_back(?string $date): ?string
{
    if (!$date) {
        return null;
    }

    if (strpos($date, " ")) {
        $date = explode(" ", $date);
        return implode("-", array_reverse(explode("/", $date[0]))) . " " . $date[1];
    }

    return implode("-", array_reverse(explode("/", $date)));
}

/**
 * ####################
 * ###   PASSWORD   ###
 * ####################
 */

/**
 * @param string $password
 * @return string
 */
function passwd(string $password): string
{
    if (!empty(password_get_info($password)['algo'])) {
        return $password;
    }

    return password_hash($password, CONF_PASSWD_ALGO, CONF_PASSWD_OPTION);
}

/**
 * @param string $password
 * @param string $hash
 * @return bool
 */
function passwd_verify(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

/**
 * @param string $hash
 * @return bool
 */
function passwd_rehash(string $hash): bool
{
    return password_needs_rehash($hash, CONF_PASSWD_ALGO, CONF_PASSWD_OPTION);
}

/**
 * ###################
 * ###   REQUEST   ###
 * ###################
 */

/**
 * @return string
 */
function csrf_input(): string
{
    $session = new \Source\Core\Session();
    $session->csrf();
    return "<input type='hidden' name='csrf' value='" . ($session->csrf_token ?? "") . "'/>";
}

/**
 * @param $request
 * @return bool
 */
function csrf_verify($request): bool
{
    $session = new \Source\Core\Session();
    if (empty($session->csrf_token) || empty($request['csrf']) || $request['csrf'] != $session->csrf_token) {
        return false;
    }
    return true;
}

/**
 * @return null|string
 */
function flash(): ?string
{
    $session = new \Source\Core\Session();
    if ($flash = $session->flash()) {
        return $flash;
    }
    return null;
}

function salutation(string $user) {
    date_default_timezone_set('America/Sao_Paulo');
    $hora = date('H');
    $frase = rand(0,2);

    if( $hora >= 4 && $hora <= 11 ) {
        switch ($frase) {
            case 0:
                echo 'Olá' . (empty($user) ? '' : ', ' . $user) . '! <span>&#128521</span>';
                break;
            case 1:
                echo 'Bom dia' . (empty($user) ? '' : ', ' . $user) . '. <span>&#127774</span>';
                break;
            case 2:
                echo 'Um bom dia de trabalho' . (empty($user) ? '' : ', ' . $user) . '. <span>&#128512</span>';
                break;
        }

    } else if ( $hora >= 12 && $hora <=17  ) {
        switch ($frase) {
            case 0:
                echo 'Olá' . (empty($user) ? '' : ', ' . $user) . '! <span>&#128526</span>';
                break;
            case 1:
                echo 'Boa tarde' . (empty($user) ? '' : ', ' . $user) . '. <span>&#127861</span>';
                break;
            case 2:
                echo 'Bem-vindo de volta' . (empty($user) ? '' : ', ' . $user) . '. <span>&#129299</span>';
                break;
        }
    }else {
        switch ($frase) {
            case 0:
                echo 'Boa noite' . (empty($user) ? '' : ', ' . $user) . '. <span>&#127773</span>';
                break;
            case 1:
                echo 'Uma boa noite de trabalho' . (empty($user) ? '' : ', ' . $user) . '. <span>&#127772</span>';
                break;
            case 2:
                echo 'Olha a hora' . (empty($user) ? '' : ', ' . $user) . '! <span>&#128564</span>';
                break;
        }
    }

}

/**
 * @param string $key
 * @param int $limit
 * @param int $seconds
 * @return bool
 */
function request_limit(string $key, int $limit = 5, int $seconds = 60, bool $reset = false): bool
{
    $session = new \Source\Core\Session();

    if($reset && $session->has($key)) {
        $session->unset($key);
        return false;
    }

    if ($session->has($key) && $session->$key->time >= time() && $session->$key->requests < $limit) {
        $session->set($key, [
            "time" => time() + $seconds,
            "requests" => $session->$key->requests + 1
        ]);
        return false;
    }

    if ($session->has($key) && $session->$key->time >= time() && $session->$key->requests >= $limit) {
        return true;
    }

    $session->set($key, [
        "time" => time() + $seconds,
        "requests" => 1
    ]);

    return false;
}

/**
 * @param string $field
 * @param string $value
 * @return bool
 */
function request_repeat(string $field, string $value): bool
{
    $session = new \Source\Core\Session();
    if ($session->has($field) && $session->$field == $value) {
        return true;
    }

    $session->set($field, $value);
    return false;
}

/**
 * Return the logo selected in settings when it exists, with a theme fallback.
 */
function site_logo_url(): string
{
    $photo = trim((string)(defined('CONF_SITE_LOGO_SVG') && CONF_SITE_LOGO_SVG !== '' ? CONF_SITE_LOGO_SVG : (defined('CONF_SITE_PHOTO') ? CONF_SITE_PHOTO : '')));
    if ($photo !== '' && filter_var($photo, FILTER_VALIDATE_URL)) {
        return $photo;
    }
    if ($photo !== '') {
        $relative = ltrim($photo, '/');
        foreach ([$relative, CONF_UPLOAD_DIR . '/' . $relative] as $candidate) {
            if (is_file(dirname(__DIR__, 2) . '/' . $candidate)) {
                return url('/' . $candidate);
            }
        }
    }
    return theme('/assets/images/logo-connect-condominios.svg');
}

function site_favicon_url(): string
{
    $favicon = trim((string)(defined('CONF_SITE_FAVICON') ? CONF_SITE_FAVICON : ''));
    if ($favicon !== '') {
        $relative = ltrim($favicon, '/');
        foreach ([$relative, CONF_UPLOAD_DIR . '/' . $relative] as $candidate) {
            if (is_file(dirname(__DIR__, 2) . '/' . $candidate)) return url('/' . $candidate);
        }
    }
    return theme('/assets/images/favicon.png');
}

function movesos_version(): string
{
    static $version;
    if ($version) { return $version; }
    try {
        $current = \Source\Core\Connect::getInstance()->query("SELECT version FROM movesos_versions WHERE status='current' ORDER BY id DESC LIMIT 1")->fetch();
        return $version = ($current->version ?? "1.0.0");
    } catch (\Throwable $exception) {
        \Source\Support\AppLogger::exception($exception, 'versioning', ['event_type' => 'version_lookup_failed']);
        return $version = (defined("VERSION_STUDIO") ? VERSION_STUDIO : "1.0.0");
    }
}
