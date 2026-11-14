<?php

declare(strict_types=1);

/**
 * Aplicativo do morador (ADR 0302) — rotas. Incluído por index.php a partir de
 * moves_container_path('residents', CONF_VIEW_APP). No host do app (app.connect.lab) as rotas ficam
 * na raiz e também em /app (links antigos e o retorno do 2FA); nos demais hosts, só em /app.
 * Os dados vêm de Source\Services\Resident (os mesmos da API /api/v1/resident).
 */

$route->namespace("Source\\Controllers\\App\\Resident");
foreach (app_host() ? ['', '/app'] : ['/app'] as $appBase) {
    $route->group($appBase);
    $route->get("/", "AppScreens:home");
    $route->get("/login", "AppLogin:login");
    $route->post("/login", "AppLogin:login");
    $route->post("/sair", "AppLogin:logout");
    $route->post("/unidade", "AppScreens:chooseUnit");
    $route->get("/seguranca/codigos", "AppScreens:recoveryCodes");
    $route->get("/manifest.webmanifest", "AppScreens:manifest");
    $route->get("/sw.js", "AppScreens:serviceWorker");
    $route->get("/offline", "AppScreens:offline");

    $route->get("/boletos", "AppScreens:charges");
    $route->get("/boletos/{id}/pdf", "AppScreens:chargePdf");
    $route->get("/prestacao-de-contas", "AppScreens:accountability");
    $route->get("/comunicados", "AppScreens:announcements");
    $route->get("/reservas", "AppScreens:reservations");
    $route->post("/reservas", "AppScreens:reserve");
    $route->post("/reservas/lista-de-espera", "AppScreens:waitlist");
    $route->post("/reservas/{id}/cancelar", "AppScreens:cancelReservation");
    $route->get("/ocorrencias", "AppScreens:occurrences");
    $route->post("/ocorrencias", "AppScreens:reportOccurrence");
    $route->get("/encomendas", "AppScreens:deliveries");
    $route->get("/visitantes", "AppScreens:visits");
    $route->post("/visitantes", "AppScreens:authorizeVisit");
    $route->post("/visitantes/{id}/cancelar", "AppScreens:cancelVisit");
    $route->get("/assembleias", "AppScreens:assemblies");
    $route->get("/assembleias/{id}", "AppScreens:assembly");
    $route->get("/documentos", "AppScreens:documents");
    $route->get("/documentos/{id}", "AppScreens:documentFile");
    $route->get("/minha-unidade", "AppScreens:unit");
    $route->get("/ops/{errcode}", "AppScreens:error");
}
unset($appBase);
$route->namespace(null);
$route->group(null);
