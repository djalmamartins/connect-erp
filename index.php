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
$route->get("/condominiums/{id}/onboarding", "CondominiumOnboarding:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/condominiums/{id}/onboarding", "CondominiumOnboarding:start", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/condominiums/{id}/onboarding/items", "CondominiumOnboarding:add", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->patch("/condominiums/{id}/onboarding/items/{item}", "CondominiumOnboarding:update", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->get("/onboarding/templates", "OnboardingTemplates:index", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->post("/onboarding/templates", "OnboardingTemplates:create", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->patch("/onboarding/templates/{id}", "OnboardingTemplates:update", null, \Source\Support\Middleware\ApiAuthenticated::class);
$route->namespace(null);


/**
 * WEB ROUTES
 */
$moduleRole = Access::role(Auth::user());
$developerBypass = ($moduleRole->slug ?? null) === "developer";
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
if ($route->error()) {
    AppLogger::log((int)$route->error() >= 500 ? 'error' : 'notice', 'Rota finalizada com erro HTTP', ['event_type' => 'http_error', 'code' => 'HTTP_' . $route->error(), 'http_status' => (int)$route->error(), 'status' => (int)$route->error() >= 500 ? 'open' : 'resolved'], 'http');
    $requestPath = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH);
    $isStudio = (bool)preg_match("~/studio(?:/|$)~", $requestPath);
    $isHelpDesk = (bool)preg_match("~/helpdesk(?:/|$)~", $requestPath);
    $isSystemArea = (bool)preg_match("~/(?:studio|helpdesk|app|erp|suporte)(?:/|$)~", $requestPath);
    $publicError = !CONF_ACCESS_SITE && !$developerBypass && !$isSystemArea ? "indisponivel" : $route->error();
    $route->redirect(($isHelpDesk ? "/helpdesk/ops/" : ($isStudio ? "/studio/ops/" : "/ops/")) . $publicError);
}

ob_end_flush();
