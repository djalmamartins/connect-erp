<?php
$route->namespace("Source\Controllers\Web\Connect");
/**
 * WEB ROUTES
 */
$route->group("");
$route->get("/", "Web:home");
$route->get("/solicite-sua-proposta", "Web:sales");
$route->post("/solicite-sua-proposta", "Web:proposalSubmit");
$route->get("/politica-de-privacidade", "Web:legal");
$route->get("/termos-de-uso", "Web:legal");


//articles
$route->group("/artigos");
$route->get("/", "Web:articles");
$route->get("/p/{page}", "Web:articles");
$route->get("/{uri}", "Web:articlesPost");
$route->post("/buscar", "Web:articlesSearch");
$route->get("/buscar/{search}/{page}", "Web:articlesSearch");
$route->get("/em/{category}", "Web:articlesCategory");
$route->get("/em/{category}/{page}", "Web:articlesCategory");

/**
 * ERROR ROUTES
 */
$route->group("/ops");
$route->get("/{errcode}", "Web:error");


//END WEB
$route->namespace("Source\Controllers\Web\Connect");
