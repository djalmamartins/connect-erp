<?php

namespace Source\Core;

use MovesCode\Router\Router as MovesRouter;
use ReflectionMethod;

final class Router
{
    private MovesRouter $router;
    private ?string $namespace = null;

    public function __construct(string $projectUrl, ?string $separator = ":")
    {
        $this->router = new MovesRouter($projectUrl, $separator);
    }

    public function namespace(?string $namespace): self
    {
        $this->namespace = $namespace;
        $this->router->namespace($namespace);
        return $this;
    }

    public function group(?string $group, array|string|null $middleware = null): self
    {
        $this->router->group($group, $middleware);
        return $this;
    }

    public function get(string $route, callable|string $handler, ?string $name = null, array|string|null $middleware = null): self
    {
        return $this->add("get", $route, $handler, $name, $middleware);
    }

    public function post(string $route, callable|string $handler, ?string $name = null, array|string|null $middleware = null): self
    {
        return $this->add("post", $route, $handler, $name, $middleware);
    }

    public function put(string $route, callable|string $handler, ?string $name = null, array|string|null $middleware = null): self
    {
        return $this->add("put", $route, $handler, $name, $middleware);
    }

    public function patch(string $route, callable|string $handler, ?string $name = null, array|string|null $middleware = null): self
    {
        return $this->add("patch", $route, $handler, $name, $middleware);
    }

    public function delete(string $route, callable|string $handler, ?string $name = null, array|string|null $middleware = null): self
    {
        return $this->add("delete", $route, $handler, $name, $middleware);
    }

    public function dispatch(): bool
    {
        return $this->router->dispatch();
    }

    public function error(): ?int
    {
        return $this->router->error();
    }

    public function redirect(string $route, ?array $data = null): void
    {
        $this->router->redirect($route, $data);
    }

    public function route(string $name, ?array $data = null): ?string
    {
        return $this->router->route($name, $data);
    }

    public function data(): ?array
    {
        return $this->router->data();
    }

    public function current(): ?object
    {
        return $this->router->current();
    }

    public function home(): string
    {
        return $this->router->home();
    }

    private function add(string $method, string $route, callable|string $handler, ?string $name, array|string|null $middleware): self
    {
        $namespace = $this->namespace;
        $this->router->{$method}($route, function (array $data) use ($handler, $namespace): void {
            $requestData = array_merge($_POST, $data);
            if (is_callable($handler)) {
                $handler($requestData, $this->router);
                return;
            }

            [$controller, $action] = explode(":", $handler, 2);
            $class = $namespace ? $namespace . "\\" . ltrim($controller, "\\") : ltrim($controller, "\\");
            $instance = new $class($this->router);
            $reflection = new ReflectionMethod($instance, $action);
            $reflection->invoke($instance, $requestData);
        }, $name, $middleware);
        return $this;
    }
}