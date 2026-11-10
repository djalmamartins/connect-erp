<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\PatrimonialEntries;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Lançamentos patrimoniais (CN-006; ADR 0149, sobre o ADR 0169): lista do período pelo mesmo serviço da API
 * GET /api/v1/patrimonial-entries, com o formulário de partida dobrada (débito e crédito em contas patrimoniais
 * analíticas que não são calculadas pelo balanço), o estorno e a importação por planilha (ADR 0171). Gravações pelo erp.js na própria API.
 * Ver exige erp.entries.view e erp.chart.view, como a API; lançar e estornar, também erp.entries.manage.
 * Sem banco ou sem permissão, patrimonialReady false = a tela segue com a demonstração.
 */
final class PatrimonialEntryScreens
{
    public const PAGES = ['CN-006' => 'pages/finance/patrimonial-entries'];
    public const NATURES = ['asset' => 'ativo', 'liability' => 'passivo', 'equity' => 'PL'];

    /** @return array<string, string> */
    public static function pagePermissions(): array
    {
        return array_fill_keys(array_keys(self::PAGES), 'erp.entries.view');
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        if (!isset(self::PAGES[$id]) || Connect::databaseDisabled() || !Access::can('erp.entries.view', $user) || !Access::can('erp.chart.view', $user)) {
            return ['patrimonialReady' => false];
        }
        $context = CondominiumContext::id();
        $filters = self::filters($_GET, date('Y-m-d'));
        $data = ['patrimonialReady' => false, 'patrimonialContext' => $context, 'patrimonialFilters' => $filters, 'patrimonialError' => null,
            'patrimonialList' => null, 'patrimonialAccounts' => [], 'patrimonialBanks' => [],
            'canManagePatrimonial' => $context !== null && Access::can('erp.entries.manage', $user)];
        // Importação (ADR 0171): as mesmas permissões da API de prévia e aplicação.
        $data['canImportPatrimonial'] = $data['canManagePatrimonial'] && Access::can('erp.imports.view', $user) && Access::can('erp.imports.manage', $user);
        try {
            try {
                $data['patrimonialList'] = (new PatrimonialEntries())->list($user, $context, $filters['de'], $filters['ate']);
            } catch (InvalidArgumentException $error) {
                $data['patrimonialError'] = $error->getMessage();
            } catch (CondominiumAccessDenied) {
                $data['patrimonialError'] = 'Condomínio não encontrado no seu escopo.';
            }
            if ($data['canManagePatrimonial']) {
                $pdo = Connect::getInstance();
                $data['patrimonialAccounts'] = self::accounts($pdo, $context);
                $data['patrimonialBanks'] = self::banks($pdo, $context);
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'patrimonial_screen_failed', 'page' => $id]);
            return ['patrimonialReady' => false];
        }
        $data['patrimonialReady'] = true;
        return $data;
    }

    /**
     * Período da tela (regra pura): datas AAAA-MM-DD válidas; sem elas, de 1º de janeiro até hoje.
     *
     * @return array{de:string, ate:string}
     */
    public static function filters(array $query, string $today): array
    {
        $valid = static function (mixed $value): ?string {
            $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            return $parsed !== false && $parsed->format('Y-m-d') === $value && $value >= '2000-01-01' ? $value : null;
        };
        return ['de' => $valid($query['de'] ?? null) ?? substr($today, 0, 4) . '-01-01', 'ate' => $valid($query['ate'] ?? null) ?? $today];
    }

    /** Situação de uma linha (regra pura): [rótulo, tom, pode estornar]. */
    public static function situation(array $row): array
    {
        return match (true) {
            $row['reversal_of_id'] !== null => ['Estorno', 'info', false],
            $row['status'] === 'reversed' => ['Estornado', 'plain', false],
            default => ['Ativo', 'success', true],
        };
    }

    /**
     * Contas que aceitam lançamento patrimonial no condomínio: analíticas, ativas, de ativo, passivo ou PL, do
     * padrão ou do condomínio, fora das calculadas pelo balanço (7.1, 7.2, 8.1 e subcontas).
     *
     * @return list<array{id:int, label:string}>
     */
    private static function accounts(PDO $pdo, int $condominiumId): array
    {
        $stmt = $pdo->prepare("SELECT id, code, name, nature FROM erp_chart_accounts WHERE deleted_at IS NULL AND status = 'active' AND kind = 'analitica'
            AND nature IN ('asset', 'liability', 'equity') AND (condominium_id IS NULL OR condominium_id = :c) ORDER BY code LIMIT 500");
        $stmt->execute(['c' => $condominiumId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!PatrimonialEntries::computed((string)$r['code'])) {
                $out[] = ['id' => (int)$r['id'], 'label' => trim($r['code'] . ' ' . $r['name']) . ' (' . (self::NATURES[$r['nature']] ?? $r['nature']) . ')'];
            }
        }
        return $out;
    }

    /** @return list<array{id:int, label:string}> */
    private static function banks(PDO $pdo, int $condominiumId): array
    {
        $stmt = $pdo->prepare("SELECT id, name, kind FROM erp_bank_accounts WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active' ORDER BY name LIMIT 200");
        $stmt->execute(['c' => $condominiumId]);
        $kinds = ['corrente' => 'conta corrente', 'poupanca' => 'poupança', 'aplicacao' => 'aplicação'];
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'label' => $r['name'] . ' (' . ($kinds[$r['kind']] ?? $r['kind']) . ')'],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
