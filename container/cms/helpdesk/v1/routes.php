<?php

$route->namespace("Source\\Controllers\\Studio");
$route->group("/helpdesk");

$route->get("/", "Studio:tickets");
$route->get("/login", "Studio:login");
$route->post("/login", "Studio:login");
$route->get("/sair", "Studio:logout");
$route->get("/tickets", "Studio:tickets");
$route->post("/tickets", "Studio:tickets");
$route->get("/agenda", "Studio:agenda");
$route->post("/agenda", "Studio:agenda");
$route->get("/support", "Studio:support");
$route->get("/users", "Studio:users");
$route->get("/users/p/{page}", "Studio:users");
$route->get("/user", "Studio:userForm");
$route->post("/user", "Studio:userForm");
$route->get("/user/{id}", "Studio:userForm");
$route->post("/user/{id}", "Studio:userForm");
$route->get("/settings", "Studio:settings");
$route->post("/settings", "Studio:settings");
$route->get("/versions", "Studio:versions");
$route->post("/versions", "Studio:versions");
$route->get("/system-logs", "Studio:systemLogs");
$route->get("/system-logs/p/{page}", "Studio:systemLogs");
$route->post("/system-logs/action/{id}", "Studio:systemLogAction");
$route->get("/support/category", "Studio:supportCategory");
$route->post("/support/category", "Studio:supportCategory");
$route->get("/support/category/{id}", "Studio:supportCategory");
$route->post("/support/category/{id}", "Studio:supportCategory");
$route->get("/support/article", "Studio:supportArticle");
$route->post("/support/article", "Studio:supportArticle");
$route->get("/support/article/{id}", "Studio:supportArticle");
$route->post("/support/article/{id}", "Studio:supportArticle");
$route->post("/support/image", "Studio:supportImage");
$route->get("/ops/{errcode}", "Studio:error");
