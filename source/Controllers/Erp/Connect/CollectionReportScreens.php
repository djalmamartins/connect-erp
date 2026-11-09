<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\CollectionReports;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Relatórios de cobrança (ADR 0081 e 0077) com dados reais, lidos no servidor pelo mesmo serviço da
 * API: acordos (AP-047), taxas de acordos (AP-048), inadimplentes nas cinco visões (AP-057 a AP-061)
 * e recebimentos (AP-071) e por conta (AP-072). A tela recebe as colunas, as linhas e a URL do CSV
 * prontas; o CSV é a mesma consulta na API com format=csv (célula protegida contra fórmula).
 * Sem banco ou sem permissão, cReportReady false = a tela segue com a demonstração.
 */
final class CollectionReportScreens
{
    /** Página => [tipo do relatório, visão ou agrupamento]. */
    public const PAGES = [
        'AP-047' => ['agreements', null],
        'AP-048' => ['agreement-fees', null],
        'AP-057' => ['delinquents', 'general'],
        'AP-058' => ['delinquents', 'unit'],
        'AP-059' => ['delinquents', 'detailed'],
        'AP-060' => ['delinquents', 'fee'],
        'AP-061' => ['delinquents', 'resident'],
        'AP-071' => ['receipts', 'entry'],
        'AP-072' => ['receipts', 'bank_account'],
    ];

    public const AGREEMENT_STATUSES = ['active' => 'Ativo', 'breached' => 'Quebrado', 'cancelled' => 'Cancelado'];
    public const ACCOUNT_GROUPS = ['bank_account' => 'Conta bancária', 'chart_account' => 'Conta do plano'];

    /** @return list<string> permissões da página, como na API */
    public static function permissions(string $id): array
    {
        [$kind, $variant] = self::PAGES[$id];
        if ($kind === 'receipts') {
            return ['erp.entries.view'];
        }
        return $variant === 'resident' ? ['erp.agreements.view', 'erp.residents.view'] : ['erp.agreements.view'];
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        $data = ['cReportReady' => false];
        if (!isset(self::PAGES[$id]) || Connect::databaseDisabled()) {
            return $data;
        }
        foreach (self::permissions($id) as $permission) {
            if (!Access::can($permission, $user)) {
                return $data;
            }
        }
        [$kind, $variant] = self::PAGES[$id];
        $today = date('Y-m-d');
        $filters = [
            'de' => self::date($_GET['de'] ?? null) ?? date('Y-01-01'),
            'ate' => self::date($_GET['ate'] ?? null) ?? $today,
            'referencia' => self::date($_GET['referencia'] ?? null) ?? $today,
            'situacao' => isset(self::AGREEMENT_STATUSES[$_GET['situacao'] ?? '']) ? $_GET['situacao'] : null,
            'agrupar' => $id === 'AP-072' && isset(self::ACCOUNT_GROUPS[$_GET['agrupar'] ?? '']) ? $_GET['agrupar'] : ($id === 'AP-072' ? 'bank_account' : null),
        ];
        $variant = $filters['agrupar'] ?? $variant;
        $data += ['cReportKind' => $kind, 'cReportVariant' => $variant, 'cReportFilters' => $filters, 'cReportError' => null, 'cReport' => null,
            'cReportColumns' => self::columns($kind, $variant), 'cReportRows' => [], 'cReportCsv' => null];
        try {
            $context = CondominiumContext::id();
            $service = new CollectionReports();
            $report = match ($kind) {
                'agreements' => $service->agreements($user, $context, $filters['de'], $filters['ate'], $filters['situacao']),
                'agreement-fees' => $service->agreementFees($user, $context, $filters['de'], $filters['ate']),
                'delinquents' => $service->delinquents($user, $context, $filters['referencia'], (string)$variant),
                default => $service->receipts($user, $context, $filters['de'], $filters['ate'], (string)$variant),
            };
            $data['cReport'] = $report;
            $data['cReportRows'] = $report['agreements'] ?? $report['condominiums'] ?? $report['rows'] ?? [];
            $data['cReportCsv'] = self::csvUrl($kind, $variant, $filters, $context);
        } catch (\InvalidArgumentException $error) {
            $data['cReportError'] = $error->getMessage();
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'collection_report_screen_failed', 'page' => $id]);
            return ['cReportReady' => false];
        }
        $data['cReportReady'] = true;
        return $data;
    }

    /** Data AAAA-MM-DD válida ou null (regra pura). */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Endereço do CSV na API (regra pura): a mesma consulta da tela, com format=csv e o condomínio da
     * barra superior (vazio = todos do escopo).
     */
    public static function csvUrl(string $kind, ?string $variant, array $filters, ?int $context): string
    {
        $query = ['format' => 'csv'];
        if ($context !== null) {
            $query['condominium_id'] = $context;
        }
        if ($kind === 'delinquents') {
            $query += ['as_of' => $filters['referencia'], 'view' => $variant];
        } else {
            $query += ['from' => $filters['de'], 'to' => $filters['ate']];
        }
        if ($kind === 'agreements' && $filters['situacao'] !== null) {
            $query['status'] = $filters['situacao'];
        }
        if ($kind === 'receipts') {
            $query['group'] = $variant;
        }
        return '/api/v1/reports/' . $kind . '?' . http_build_query($query);
    }

    /**
     * Colunas da tabela (regra pura): [rótulo, caminho no registro, tipo]. Tipos: text, int, money
     * (soma no rodapé), date, status e days.
     *
     * @return list<array{0:string, 1:string, 2:string}>
     */
    public static function columns(string $kind, ?string $variant): array
    {
        return match ($kind . ($variant !== null ? '-' . $variant : '')) {
            'agreements' => [['Condomínio', 'condominium_name', 'text'], ['Unidade', 'unit_label', 'text'], ['Acordo', 'id', 'int'], ['Situação', 'status', 'status'],
                ['Criado em', 'created_on', 'date'], ['Parcelas pagas', 'paid_installments', 'paid'], ['Total', 'total_cents', 'money'], ['Pago', 'paid_cents', 'money'],
                ['Em aberto', 'open_cents', 'money'], ['Vencido', 'overdue_cents', 'money'], ['Próximo vencimento', 'next_due_on', 'date']],
            'agreement-fees' => [['Condomínio', 'condominium_name', 'text'], ['Acordos', 'agreements', 'int'], ['Cancelados', 'cancelled', 'int'], ['Dívida original', 'original_cents', 'money'],
                ['Encargos', 'fees_cents', 'money'], ['Descontos', 'discount_cents', 'money'], ['Encargos líquidos', 'net_fees_cents', 'money'], ['Total', 'total_cents', 'money']],
            'delinquents-general' => [['Condomínio', 'condominium_name', 'text'], ['Unidades', 'units', 'int'], ['Contas', 'entries', 'int'], ['Valor', 'amount_cents', 'money'],
                ['Encargos', 'charges_cents', 'money'], ['Total', 'total_cents', 'money'], ['Maior atraso', 'max_days_late', 'days']],
            'delinquents-unit' => [['Condomínio', 'condominium_name', 'text'], ['Unidade', 'unit_label', 'text'], ['Contas', 'entries', 'int'], ['Valor', 'amount_cents', 'money'],
                ['Encargos', 'charges_cents', 'money'], ['Total', 'total_cents', 'money'], ['Mais antiga', 'oldest_due_on', 'date'], ['Maior atraso', 'max_days_late', 'days']],
            'delinquents-detailed' => [['Condomínio', 'condominium_name', 'text'], ['Unidade', 'unit_label', 'text'], ['Descrição', 'description', 'text'], ['Competência', 'competence', 'text'],
                ['Vencimento', 'due_on', 'date'], ['Atraso', 'days_late', 'days'], ['Valor', 'amount_cents', 'money'], ['Encargos', 'charges_cents', 'money'], ['Total', 'total_cents', 'money']],
            'delinquents-fee' => [['Condomínio', 'condominium_name', 'text'], ['Taxa (conta)', 'account', 'text'], ['Unidades', 'units', 'int'], ['Contas', 'entries', 'int'],
                ['Valor', 'amount_cents', 'money'], ['Encargos', 'charges_cents', 'money'], ['Total', 'total_cents', 'money']],
            'delinquents-resident' => [['Condomínio', 'condominium_name', 'text'], ['Unidade', 'unit_label', 'text'], ['Responsável', 'responsible.name', 'text'], ['Documento', 'responsible.document', 'text'],
                ['Telefone', 'responsible.phone', 'text'], ['E-mail', 'responsible.email', 'text'], ['Contas', 'entries', 'int'], ['Total', 'total_cents', 'money'], ['Maior atraso', 'max_days_late', 'days']],
            'receipts-entry' => [['Condomínio', 'condominium_name', 'text'], ['Unidade', 'unit_label', 'text'], ['Descrição', 'description', 'text'], ['Competência', 'competence', 'text'],
                ['Vencimento', 'due_on', 'date'], ['Pagamento', 'paid_on', 'date'], ['Atraso', 'days_late', 'days'], ['Valor', 'amount_cents', 'money'], ['Conta bancária', 'bank_account', 'text']],
            'receipts-bank_account', 'receipts-chart_account' => [['Condomínio', 'condominium_name', 'text'], ['Conta', 'account', 'text'], ['Recebimentos', 'entries', 'int'],
                ['Pagos com atraso', 'late', 'int'], ['Valor', 'amount_cents', 'money']],
            default => [],
        };
    }

    /** Valor de um caminho "a.b" no registro (regra pura). */
    public static function value(array $row, string $path): mixed
    {
        $value = $row;
        foreach (explode('.', $path) as $part) {
            $value = is_array($value) ? ($value[$part] ?? null) : null;
        }
        return $value;
    }
}
