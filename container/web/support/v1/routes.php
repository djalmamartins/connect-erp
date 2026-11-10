<?php

$route->namespace("Source\\Controllers\\Web\\Support");
$route->group("/suporte");
$route->get("/", "Support:home");
$route->get("/buscar", "Support:search");
$route->get("/widget", "Support:widget");
$route->post("/widget/abrir", "Support:widgetCreate");
$route->post("/widget/acompanhar", "Support:widgetLookup");
$route->post("/avaliar/{id}", "Support:vote");
$route->post("/mensagem", "Support:message");
$route->get("/tela/{code}", "Support:screen");
$route->get("/{section}", "Support:category");
$route->get("/{section}/{article}", "Support:article");
