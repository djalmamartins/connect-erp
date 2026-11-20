<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Core\Session;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\VirtualDisk;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas da operação com dados reais que eram montadas dentro do Panel: disco virtual (AP-122),
 * agendamentos pendentes (AP-114) e integrações (AP-171, só leitura).
 *
 * O Panel::pageView() chama data() quando a página está em PAGES (a mesma regra das outras *Screens).
 */
final class OperationScreens
{
    /** Página => carregador dos dados reais. */
    public const PAGES = [
        'AP-122' => 'virtualDiskData',
        'AP-114' => 'pendingReservationData',
        'AP-171' => 'integrationData',
    ];

    /** @var \Closure(): list<array{id:int,name:string}> condomínios do escopo, do cache do Panel (o smoke injeta uma lista fixa) */
    private \Closure $condominiumOptions;

    /** @return array<string, mixed> */
    public function data(User $user, string $id, ?\Closure $condominiumOptions = null): array
    {
        $this->condominiumOptions = $condominiumOptions ?? static fn (): array => CondominiumContext::scopeOptions($user);
        return match (self::PAGES[$id] ?? null) {
            'virtualDiskData' => $this->virtualDiskData($user),
            'pendingReservationData' => $this->pendingReservationData($user),
            'integrationData' => $this->integrationData($user),
            default => [],
        };
    }

    /**
     * Disco Virtual (AP-122, ADR 0047 e 0049) no contexto da barra superior: arquivos por pasta
     * (?pasta=, ?q=, ?pagina=) e os condomínios do escopo para enviar. null = sem permissão ou sem
     * banco (a tela segue com a demonstração). Enviar, baixar e excluir usam /api/v1/documents.
     *
     * @return array<string, mixed>
     */
    private function virtualDiskData(User $user): array
    {
        $folder = is_string($_GET['pasta'] ?? null) && isset(VirtualDisk::FOLDERS[$_GET['pasta']]) ? $_GET['pasta'] : null;
        $q = is_string($_GET['q'] ?? null) ? trim(mb_substr($_GET['q'], 0, 100)) : '';
        $data = ['diskFiles' => null, 'diskFolder' => $folder, 'diskQuery' => $q, 'diskCondominiums' => [], 'canManageFiles' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.documents.view', $user)) {
            return $data;
        }
        try {
            $data['diskFiles'] = (new VirtualDisk())->list($user, CondominiumContext::id(), ['folder' => $folder, 'q' => $q, 'page' => $_GET['pagina'] ?? 1]);
            $data['diskCondominiums'] = $this->condominiumOptions($user);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'virtual_disk_screen_failed']);
            return ['diskFiles' => null] + $data;
        }
        $data['canManageFiles'] = Access::can('erp.documents.manage', $user);
        return $data;
    }

    /** Tamanho legível em pt-BR (regra pura): 512 B, 12,5 KB, 3,2 MB. */
    public static function fileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $kb = $bytes / 1024;
        return $kb < 1024 ? number_format($kb, 1, ',', '.') . ' KB' : number_format($kb / 1024, 1, ',', '.') . ' MB';
    }

    /**
     * Agendamentos Pendentes (AP-114, ADR 0051 e 0070): as reservas de áreas comuns aguardando
     * aprovação no condomínio da barra superior, pela listagem do motor (escopo e paginação dele).
     * Sem banco ou sem erp.reservations.view, null = a demonstração.
     */
    private function pendingReservationData(User $user): array
    {
        $data = ['pendingReservations' => null, 'pendingAreas' => [], 'pendingUnits' => [], 'canDecideReservations' => false, 'flash' => null];
        if (Connect::databaseDisabled() || !Access::can('erp.reservations.view', $user)) {
            return $data;
        }
        try {
            $service = new RegistryService();
            $data['pendingReservations'] = $service->list($user, 'reservations', [
                'condominium_id' => CondominiumContext::id(), 'status' => 'active', 'page' => $_GET['pagina'] ?? 1,
            ]);
            // Nomes da área e da unidade para a lista (a reserva guarda só os números).
            $areas = $service->references($user, RegistryService::definition('reservations'))['common_area_id'] ?? [];
            $data['pendingAreas'] = array_column($areas, 'label', 'id');
            $data['pendingUnits'] = array_column($service->units($user), 'label', 'id');
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'pending_reservations_screen_failed']);
            return $data;
        }
        $data['canDecideReservations'] = Access::can('erp.reservations.manage', $user);
        $data['flash'] = (new \Source\Core\Session())->flash();
        return $data;
    }

    /**
     * Integrações (AP-171, ADR 0091 e 0161), só leitura: o mesmo IntegrationStatus de GET /api/v1/integrations
     * (banco, e-mail, índices, fila de avisos e antivírus), só com números e sem endereços. Sem banco ou sem
     * erp.settings.view, null = a demonstração.
     */
    private function integrationData(User $user): array
    {
        $data = ['integrations' => null];
        if (Connect::databaseDisabled() || !Access::can('erp.settings.view', $user)) {
            return $data;
        }
        try {
            $data['integrations'] = (new \Source\Services\Erp\IntegrationStatus())->overview($user);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'integrations_screen_failed']);
        }
        // Banco Inter pela tela (ADR 0158): contas 077 do escopo e quem pode enviar a credencial.
        $data['bankCredentialAccounts'] = null;
        $data['canManageBankCredentials'] = Access::can('erp.settings.manage', $user) && Access::can('erp.bank_accounts.manage', $user);
        if (Access::can('erp.bank_accounts.view', $user)) {
            try {
                $data['bankCredentialAccounts'] = (new \Source\Services\Erp\Banking\BankCredentialSetup())->accounts($user);
            } catch (\Throwable $error) {
                \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'bank_credentials_screen_failed']);
            }
        }
        // Tokens de acesso das integrações (ADR 0623): lista para quem vê, criar e revogar para quem gerencia.
        $data['integrationTokens'] = null;
        $data['canManageIntegrationTokens'] = Access::can('erp.settings.manage', $user);
        try {
            $tokens = new \Source\Services\Talk\TalkTokens();
            $data['integrationTokens'] = ['tokens' => $tokens->list(), 'scopes' => \Source\Controllers\Api\V1\IntegrationTokens::scopes(),
                'users' => $data['canManageIntegrationTokens'] ? $tokens->eligibleUsers() : []];
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'integration_tokens_screen_failed']);
        }
        return $data;
    }

    /** @return list<array{id:int,name:string}> */
    private function condominiumOptions(User $user): array
    {
        return ($this->condominiumOptions)();
    }
}
