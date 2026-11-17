<?php

declare(strict_types=1);

namespace Source\Support\Storage;

/**
 * Escolhe onde os arquivos ficam, pelo .env (ADR 0374).
 *
 * DOCUMENT_STORAGE=local (padrão) guarda na pasta privada do servidor. DOCUMENT_STORAGE=onedrive guarda no OneDrive da
 * Connect (ADR 0373), em ONEDRIVE_FOLDER/{área}, com cópia local em storage/cache/onedrive/{área}. Se o OneDrive estiver
 * pedido mas incompleto, continua no disco local (o deploy-check acusa o erro antes de abrir).
 */
final class DocumentStores
{
    public const AREAS = ['documents', 'inspections', 'resident-photos'];
    public const ONEDRIVE_KEYS = ['ONEDRIVE_TENANT_ID', 'ONEDRIVE_CLIENT_ID', 'ONEDRIVE_CLIENT_SECRET', 'ONEDRIVE_DRIVE_ID', 'ONEDRIVE_FOLDER'];

    /** @return array<string, string> */
    public static function environment(): array
    {
        return [
            'DOCUMENT_STORAGE' => (string)getenv('DOCUMENT_STORAGE'),
            'ONEDRIVE_TENANT_ID' => (string)getenv('ONEDRIVE_TENANT_ID'),
            'ONEDRIVE_CLIENT_ID' => (string)getenv('ONEDRIVE_CLIENT_ID'),
            'ONEDRIVE_CLIENT_SECRET' => (string)getenv('ONEDRIVE_CLIENT_SECRET'),
            'ONEDRIVE_DRIVE_ID' => (string)getenv('ONEDRIVE_DRIVE_ID'),
            'ONEDRIVE_FOLDER' => (string)getenv('ONEDRIVE_FOLDER'),
        ];
    }

    /** "local", "onedrive" ou "" quando o valor é desconhecido. */
    public static function mode(array $env): string
    {
        $mode = strtolower(trim((string)($env['DOCUMENT_STORAGE'] ?? '')));
        return $mode === '' ? 'local' : (in_array($mode, ['local', 'onedrive'], true) ? $mode : '');
    }

    /**
     * Chaves do OneDrive que faltam (vazio quando completo).
     *
     * @param array<string, string> $env
     * @return list<string>
     */
    public static function missing(array $env): array
    {
        return array_values(array_filter(self::ONEDRIVE_KEYS, static fn (string $key): bool => trim((string)($env[$key] ?? '')) === ''));
    }

    /** @param array<string, string>|null $env null = lê do ambiente */
    public static function make(string $area, string $localRoot, ?array $env = null, ?GraphTransport $http = null): DocumentStore
    {
        if (!in_array($area, self::AREAS, true)) {
            throw new \InvalidArgumentException('Área de arquivos desconhecida: ' . $area);
        }
        $env ??= self::environment();
        if (self::mode($env) !== 'onedrive' || self::missing($env) !== []) {
            return new LocalDocumentStore($localRoot);
        }
        return self::oneDrive($area, $env, $http);
    }

    /**
     * OneDrive da área mesmo com DOCUMENT_STORAGE=local: a cópia dos arquivos existentes (ADR 0376) roda antes da troca.
     *
     * @param array<string, string> $env
     */
    public static function oneDrive(string $area, array $env, ?GraphTransport $http = null): OneDriveDocumentStore
    {
        if (!in_array($area, self::AREAS, true) || self::missing($env) !== []) {
            throw new \InvalidArgumentException('OneDrive incompleto no .env: ' . implode(', ', self::missing($env)));
        }
        return new OneDriveDocumentStore(trim($env['ONEDRIVE_TENANT_ID']), trim($env['ONEDRIVE_CLIENT_ID']), trim($env['ONEDRIVE_CLIENT_SECRET']),
            trim($env['ONEDRIVE_DRIVE_ID']), trim($env['ONEDRIVE_FOLDER'], " /") . '/' . $area,
            dirname(__DIR__, 3) . '/storage/cache/onedrive/' . $area, $http ?? new CurlGraphTransport());
    }
}
