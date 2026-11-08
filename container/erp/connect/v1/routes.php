<?php

/**
 * ERP Connect v1 — rotas (A2, docs/architecture/CONNECT_ERP_V1.md).
 * Incluído por index.php via moves_container_path('erp', CONF_VIEW_ERP).
 * Condomínios AP-004: consulta, cadastro e edição (ADR 0011). /novo e /{id}/editar
 * ficam antes de /{id} para não serem capturados pela rota de detalhe.
 */

$route->namespace("Source\\Controllers\\Erp\\Connect");
$route->group("/erp");

$route->get("/", "Condominiums:root");
$route->get("/condominios", "Condominiums:index");
$route->get("/condominios/novo", "Condominiums:create");
$route->post("/condominios/novo", "Condominiums:store");
$route->get("/condominios/{id}/editar", "Condominiums:edit");
$route->post("/condominios/{id}/editar", "Condominiums:update");
$route->get("/condominios/{id}", "Condominiums:show");
$route->get("/condominios/{id}/checklist", "CondominiumChecklist:show");
$route->post("/condominios/{id}/checklist/iniciar", "CondominiumChecklist:start");
$route->post("/condominios/{id}/checklist/itens", "CondominiumChecklist:add");
$route->post("/condominios/{id}/checklist/itens/{item}", "CondominiumChecklist:update");

$route->namespace(null);
$route->group(null);
