<?php

namespace Source\Support;

use Source\Core\Connect;
use Source\Models\User;

final class StudioModules
{
    private static ?self $instance = null;
    private string $basePath;
    private ?array $manifests = null;

    private function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?: dirname(__DIR__, 2) . '/container/cms/studio/v1/components';
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function discover(): array
    {
        if ($this->manifests !== null) return $this->manifests;
        $found = [];
        foreach (glob($this->basePath . '/*/module.php') ?: [] as $file) {
            $manifest = require $file;
            if (!is_array($manifest)) continue;
            $slug = $this->validSlug((string)($manifest['slug'] ?? basename(dirname($file))));
            if (!$slug) continue;
            $manifest['slug'] = $slug;
            $manifest['name'] = trim((string)($manifest['name'] ?? ucfirst($slug)));
            $manifest['description'] = trim((string)($manifest['description'] ?? ''));
            $manifest['version'] = $this->validVersion((string)($manifest['version'] ?? '1.0.0'));
            $manifest['path'] = dirname($file);
            $manifest['permission'] = (string)($manifest['permission'] ?? 'studio.access');
            $manifest['core'] = !empty($manifest['core']);
            $found[$slug] = $manifest;
        }
        uasort($found, fn(array $a, array $b) => ($a['menu']['position'] ?? 999) <=> ($b['menu']['position'] ?? 999));
        return $this->manifests = $found;
    }

    public function synchronize(): void
    {
        $pdo = Connect::getInstance();
        $statement = $pdo->prepare("INSERT INTO modules (slug,name,description,available_version,is_core,enabled,installed_version,installed_at)
            VALUES (:slug,:name,:description,:version,:core,:enabled,:installed,:installed_at)
            ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),available_version=VALUES(available_version),is_core=VALUES(is_core)");
        foreach ($this->discover() as $manifest) {
            $core = !empty($manifest['core']);
            $statement->execute([
                'slug' => $manifest['slug'], 'name' => $manifest['name'], 'description' => $manifest['description'],
                'version' => $manifest['version'], 'core' => $core ? 1 : 0, 'enabled' => $core ? 1 : 0,
                'installed' => $core ? $manifest['version'] : null, 'installed_at' => $core ? date('Y-m-d H:i:s') : null
            ]);
        }
    }

    public function catalog(): array
    {
        $this->synchronize();
        $records = [];
        foreach (Connect::getInstance()->query('SELECT * FROM modules ORDER BY is_core DESC,name')->fetchAll() as $record) {
            $records[$record->slug] = $record;
        }
        return array_map(function (array $manifest) use ($records): array {
            $manifest['record'] = $records[$manifest['slug']] ?? null;
            return $manifest;
        }, $this->discover());
    }

    public function active(?User $user = null): array
    {
        $catalog = $this->catalog();
        return array_filter($catalog, function (array $module) use ($user): bool {
            $record = $module['record'];
            if (!$record || !$record->installed_version || !(int)$record->enabled) return false;
            if ($user && !$user->can($module['permission'])) return false;
            $visible = $module['visible'] ?? true;
            return !is_callable($visible) || (bool)$visible($user, $record);
        });
    }

    public function registerRoutes(object $router): void
    {
        foreach ($this->active() as $module) {
            foreach ((array)($module['routes'] ?? []) as $route) {
                [$method, $path, $handler] = array_pad($route, 3, null);
                $method = strtolower((string)$method);
                if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true) || !$path || !$handler) continue;
                $router->{$method}($path, $handler);
            }
        }
    }

    public function install(string $slug, int $userId): array
    {
        $manifest = $this->discover()[$slug] ?? null;
        if (!$manifest) throw new \RuntimeException('Módulo não encontrado.');
        foreach ((array)($manifest['requires'] ?? []) as $dependency) {
            if (!$this->isEnabled((string)$dependency)) throw new \RuntimeException("Instale e ative o módulo {$dependency} primeiro.");
        }
        $this->runMigrations($manifest);
        $stmt = Connect::getInstance()->prepare("UPDATE modules SET installed_version=:version,enabled=1,installed_by=:user,installed_at=COALESCE(installed_at,NOW()) WHERE slug=:slug");
        $stmt->execute(['version' => $manifest['version'], 'user' => $userId, 'slug' => $slug]);
        Audit::record('install', 'modules', $slug, [], ['version' => $manifest['version']]);
        return $manifest;
    }

    public function setEnabled(string $slug, bool $enabled): void
    {
        $manifest = $this->discover()[$slug] ?? null;
        if (!$manifest) throw new \RuntimeException('Módulo não encontrado.');
        $record = Connect::getInstance()->prepare('SELECT * FROM modules WHERE slug=:slug LIMIT 1');
        $record->execute(['slug' => $slug]);
        $installed = $record->fetch();
        if (!$installed?->installed_version) throw new \RuntimeException('Instale o módulo antes de ativá-lo.');
        if (!empty($manifest['core']) && !$enabled) throw new \RuntimeException('Módulos essenciais não podem ser desativados.');
        Connect::getInstance()->prepare('UPDATE modules SET enabled=:enabled WHERE slug=:slug')->execute(['enabled' => $enabled ? 1 : 0, 'slug' => $slug]);
        Audit::record($enabled ? 'enable' : 'disable', 'modules', $slug);
    }

    public function isEnabled(string $slug): bool
    {
        try {
            $stmt = Connect::getInstance()->prepare('SELECT COUNT(*) FROM modules WHERE slug=:slug AND enabled=1 AND installed_version IS NOT NULL');
            $stmt->execute(['slug' => $slug]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable) { return false; }
    }

    private function runMigrations(array $manifest): void
    {
        $directory = $manifest['path'] . '/database';
        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $pdo = Connect::getInstance();
        $batch = (int)$pdo->query('SELECT COALESCE(MAX(batch),0)+1 FROM module_migrations')->fetchColumn();
        $check = $pdo->prepare('SELECT checksum FROM module_migrations WHERE module_slug=:slug AND migration=:migration');
        $save = $pdo->prepare('INSERT INTO module_migrations (module_slug,migration,checksum,batch) VALUES (:slug,:migration,:checksum,:batch)');
        foreach ($files as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            $check->execute(['slug' => $manifest['slug'], 'migration' => $name]);
            $previous = $check->fetchColumn();
            if ($previous) {
                if (!hash_equals((string)$previous, $checksum)) throw new \RuntimeException("A migration {$name} foi alterada após ser executada.");
                continue;
            }
            $sql = trim((string)file_get_contents($file));
            if ($sql !== '') $pdo->exec($sql);
            $save->execute(['slug' => $manifest['slug'], 'migration' => $name, 'checksum' => $checksum, 'batch' => $batch]);
        }
    }

    private function validSlug(string $slug): ?string
    {
        $slug = strtolower(trim($slug));
        return preg_match('/^[a-z][a-z0-9_-]{1,99}$/', $slug) ? $slug : null;
    }

    private function validVersion(string $version): string
    {
        return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version) ? $version : '1.0.0';
    }
}
