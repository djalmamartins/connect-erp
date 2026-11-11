<?php

namespace Source\Core;

use MovesCode\Router\Router as MovesRouter;
use ReflectionMethod;

final class Router
{
    private MovesRouter $router;
    private ?string $namespace = null;
    private string $group = '';
    /** @var array<string, true> primeiro segmento de cada rota registrada (contexto de condomínio, ADR 0030) */
    private array $firstSegments = [];

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
        $this->group = trim((string)$group, '/');
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

    /** @return list<string> primeiros segmentos fixos das rotas registradas (sem parâmetros). */
    public function firstSegments(): array
    {
        return array_keys($this->firstSegments);
    }

    private function add(string $method, string $route, callable|string $handler, ?string $name, array|string|null $middleware): self
    {
        // O segmento logo depois do grupo também conta: no host principal o ERP fica em /erp.
        foreach ([trim($this->group . '/' . trim($route, '/'), '/'), trim($route, '/')] as $path) {
            $segment = explode('/', $path)[0];
            if ($segment !== '' && !str_contains($segment, '{')) {
                $this->firstSegments[$segment] = true;
            }
        }
        $namespace = $this->namespace;
        $this->router->{$method}($route, function (array $data) use ($handler, $namespace): void {
            $requestData = array_merge($_POST, $data);
            if (is_callable($handler)) {
                $handler($requestData, $this->router);
                return;
            }

            [$controller, $action] = explode(":", $handler, 2);
            $class = $namespace ? $namespace . "\\" . ltrim($controller, "\\") : ltrim($controller, "\\");
            try {
                $instance = new $class($this->router);
                $reflection = new ReflectionMethod($instance, $action);
                $reflection->invoke($instance, $requestData);
            } catch (\Throwable $error) {
                // O MovesCode Router troca qualquer exceção por "erro 500" sem registrar a causa:
                // registra aqui (com o handler) antes de devolver, para o erro aparecer nos logs.
                \Source\Support\AppLogger::exception($error, 'http', ['handler' => $handler]);
                throw $error;
            }
        }, $name, $middleware);
        return $this;
    }
}