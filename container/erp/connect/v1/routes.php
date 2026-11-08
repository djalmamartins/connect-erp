<?php

/**
 * ERP Connect v1 — rotas (A2, docs/architecture/CONNECT_ERP_V1.md).
 * Incluído por index.php via moves_container_path('erp', CONF_VIEW_ERP).
 * Somente leitura nesta fatia: cadastro e edição aguardam a spec AP-004.
 */

$route->namespace("Source\\Controllers\\Erp\\Connect");
$route->group("/erp");

$route->get("/", "Condominiums:root");
$route->get("/condominios", "Condominiums:index");
$route->get("/condominios/{id}", "Condominiums:show");
$route->get("/condominios/{id}/checklist", "CondominiumChecklist:show");
$route->post("/condominios/{id}/checklist/iniciar", "CondominiumChecklist:start");
$route->post("/condominios/{id}/checklist/itens", "CondominiumChecklist:add");
$route->post("/condominios/{id}/checklist/itens/{item}", "CondominiumChecklist:update");

$route->namespace(null);
$route->group(null);
