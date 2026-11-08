<?php
ob_start();
require __DIR__ . "/vendor/autoload.php";

/**
 * BOOTSTRAP
 */

use Source\Core\Router;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Support\Access;
use Source\Support\AppLogger;

$logger = AppLogger::bootstrap();
$session = new Session();
$route = new Router(url(), ":");

$route->namespace(null);
$route->group("/api/v1");
$route->get("/health", static function (): void {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    try {
        \Source\Core\Connect::getInstance()->query("SELECT 1")->fetchColumn();
        echo json_encode(["data" => ["service" => "connect-web-cms", "status" => "ready", "checks" => ["database" => "ready"]]], JSON_THROW_ON_ERROR);
    } catch (\Throwable $error) {
        http_response_code(503);
        echo json_encode(["data" => ["service" => "connect-web-cms", "status" => "degraded", "checks" => ["database" => "unavailable"]]], JSON_THROW_ON_ERROR);
    }
});

/**
 * API REST v1 (sessão do Organic Code; 2FA obrigatório quando ativo)
 */
$route->namespace("Source\\Controllers\\Api\\V1");
$route->post("/auth/2fa/verify", "TwoFactor:verify");
$route->get("/me", "Me:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/condominiums", "Condominiums:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/condominiums/{id}", "Condominiums:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
// Vistoria presencial (CN-001). Rotas fixas antes de /inspections/{id}.
$route->get("/inspection-templates", "Inspections:templates", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/inspections", "Inspections:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/inspections/schedule", "Inspections:schedule", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/inspections", "Inspections:store", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/inspections/{id}", "Inspections:show", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->put("/inspections/{id}/items/{item}", "Inspections:answer", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/inspections/{id}/items/{item}/photos", "Inspections:photo", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/inspections/{id}/complete", "Inspections:complete", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->namespace(null);


/**
 * WEB, STUDIO, HELP DESK E APP — não existem no host próprio do ERP (erp.connect.lab):
 * cada ambiente fica isolado pelo hostname. No host do ERP só entram o 2FA do login
 * compartilhado e a recuperação de senha do núcleo.
 */
$moduleRole = Access::role(Auth::user());
$developerBypass = ($moduleRole->slug ?? null) === "developer";
if (erp_host()) {
    $route->namespace("Source\\Controllers\\Studio");
    $route->group("/studio");
    $route->get("/login/2fa", "TwoFactor:challenge");
    $route->post("/login/2fa", "TwoFactor:challenge");
    $route->get("/seguranca/2fa", "TwoFactor:manage");
    $route->post("/seguranca/2fa", "TwoFactor:manage");
    $route->namespace("Source\\Controllers\\Web\\Connect");
    $route->group(null);
    $route->get("/forget", "Web:forget");
    $route->post("/forget", "Web:forget");
    $route->get("/forget/{code}", "Web:reset");
    $route->post("/reset", "Web:reset");
    $route->namespace(null);
} else {

/**
 * WEB ROUTES
 */
if (CONF_ACCESS_SITE || $developerBypass) {
    require moves_container_path('web', CONF_VIEW_THEMES) . "/routes.php";
} else {
    $route->namespace("Source\\Controllers\\Web\\Connect");
    $route->group("");
    $route->get("/", "Web:unavailable");
    $route->group("/ops");
    $route->get("/{errcode}", "Web:error");
}
$supportRoutes = moves_container_path('web', CONF_VIEW_SUPPORT) . "/routes.php";
if ((CONF_ACCESS_SUPPORT || $developerBypass) && is_file($supportRoutes)) {
    require $supportRoutes;
}

/**
 * STUDIO ROUTES
 */
require moves_container_path('studio', 'default') . "/routes.php";

/**
 * HELP DESK ROUTES
 */
$helpDeskRoutes = moves_container_path('helpdesk', 'default') . "/routes.php";
if (is_file($helpDeskRoutes)) {
    require $helpDeskRoutes;
}

/**
 * APP
 */
$appRoutes = moves_container_path('residents', CONF_VIEW_APP) . "/default.php";
if (is_file($appRoutes)) {
    require $appRoutes;
}
}

/**
 * ERP ROUTES
 */
// A1: default.php é a moldura do tema; as rotas ficam em routes.php.
$erpRoutes = moves_container_path('erp', CONF_VIEW_ERP) . "/routes.php";
if (is_file($erpRoutes)) {
    require $erpRoutes;
}

/**
 * ROUTE
 */
$route->dispatch();

/**
 * ERROR REDIRECT
 */
if ($route->error() && erp_host()) {
    AppLogger::log((int)$route->error() >= 500 ? 'error' : 'notice', 'Rota do ERP finalizada com erro HTTP', ['event_type' => 'http_error', 'code' => 'HTTP_' . $route->error(), 'http_status' => (int)$route->error(), 'status' => 'resolved'], 'http');
    (new \Source\Controllers\Erp\Connect\Panel())->routeError((int)$route->error());
} elseif ($route->error()) {
    AppLogger::log((int)$route->error() >= 500 ? 'error' : 'notice', 'Rota finalizada com erro HTTP', ['event_type' => 'http_error', 'code' => 'HTTP_' . $route->error(), 'http_status' => (int)$route->error(), 'status' => (int)$route->error() >= 500 ? 'open' : 'resolved'], 'http');
    $requestPath = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH);
    $isStudio = (bool)preg_match("~/studio(?:/|$)~", $requestPath);
    $isHelpDesk = (bool)preg_match("~/helpdesk(?:/|$)~", $requestPath);
    $isSystemArea = (bool)preg_match("~/(?:studio|helpdesk|app|erp|suporte)(?:/|$)~", $requestPath);
    $publicError = !CONF_ACCESS_SITE && !$developerBypass && !$isSystemArea ? "indisponivel" : $route->error();
    $route->redirect(($isHelpDesk ? "/helpdesk/ops/" : ($isStudio ? "/studio/ops/" : "/ops/")) . $publicError);
}

ob_end_flush();
