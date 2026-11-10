<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\DeliveryLog;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Registros de Entregas (AP-136; ADR 0146, sobre o ADR 0166), lidos no servidor pelo mesmo serviço da API
 * GET /api/v1/reports/deliveries: filtros de situação, período e unidade, o resumo e a lista. A baixa da
 * retirada vai pela API POST /api/v1/delivery-pickups (erp.js, data-erp-deliveries), com CSRF no cabeçalho.
 * Ver exige erp.deliveries.view; dar baixa, erp.deliveries.manage. Sem banco ou sem permissão,
 * deliveryReady false = a tela segue com a demonstração.
 */
final class DeliveryScreens
{
    public const PAGES = ['AP-136' => 'pages/operational/deliveries'];
    public const STATUS = ['active' => ['Aguardando', 'warning'], 'picked_up' => ['Retirada', 'success'], 'inactive' => ['Cancelada', 'plain']];
    /** Transportadoras do cadastro de Entregas (enum de erp_deliveries). */
    public const CARRIERS = ['correios' => 'Correios', 'mercado_livre' => 'Mercado Livre', 'amazon' => 'Amazon', 'outra' => 'Outra'];

    /** @return array<string, string> */
    public static function pagePermissions(): array
    {
        return array_fill_keys(array_keys(self::PAGES), 'erp.deliveries.view');
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        if (!isset(self::PAGES[$id]) || Connect::databaseDisabled() || !Access::can('erp.deliveries.view', $user)) {
            return ['deliveryReady' => false];
        }
        $context = CondominiumContext::id();
        $filters = self::filters($_GET);
        $data = ['deliveryReady' => false, 'deliveryContext' => $context, 'deliveryFilters' => $filters, 'deliveryError' => null, 'deliveryLog' => null,
            'deliveryUnits' => [], 'canPickUp' => Access::can('erp.deliveries.manage', $user)];
        try {
            if ($context !== null) {
                $data['deliveryUnits'] = self::units(Connect::getInstance(), $context);
            }
            try {
                $data['deliveryLog'] = (new DeliveryLog())->log($user, $context, ['status' => $filters['situacao'], 'from' => $filters['de'], 'to' => $filters['ate'],
                    'unit_id' => $filters['unidade']]);
            } catch (InvalidArgumentException $error) {
                $data['deliveryError'] = $error->getMessage();
            } catch (CondominiumAccessDenied) {
                $data['deliveryError'] = 'Condomínio não encontrado no seu escopo.';
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'delivery_screen_failed', 'page' => $id]);
            return ['deliveryReady' => false];
        }
        $data['deliveryReady'] = true;
        return $data;
    }

    /**
     * Filtros da tela (regra pura): situação conhecida ou vazia; período só com as duas datas válidas;
     * unidade inteira positiva. O serviço valida de novo (até 366 dias).
     *
     * @return array{situacao:?string, de:?string, ate:?string, unidade:?int}
     */
    public static function filters(array $query): array
    {
        $status = $query['situacao'] ?? null;
        $date = static function (mixed $v): ?string {
            if (!is_string($v)) {
                return null;
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
        };
        $from = $date($query['de'] ?? null);
        $to = $date($query['ate'] ?? null);
        $unit = filter_var($query['unidade'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return ['situacao' => in_array($status, DeliveryLog::STATUSES, true) ? $status : null, 'de' => $from !== null && $to !== null ? $from : null,
            'ate' => $from !== null && $to !== null ? $to : null, 'unidade' => $unit === false || $unit === null ? null : (int)$unit];
    }

    /** @return list<array{id:int, label:string}> */
    private static function units(PDO $pdo, int $condominium): array
    {
        $stmt = $pdo->prepare("SELECT id, label FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL ORDER BY label LIMIT 5000");
        $stmt->execute(['c' => $condominium]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'label' => (string)$r['label']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
