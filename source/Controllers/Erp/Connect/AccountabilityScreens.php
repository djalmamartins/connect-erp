<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use InvalidArgumentException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Models\Auth;
use Source\Services\Erp\Accountability;
use Source\Services\Erp\AccountabilityDocument;
use Source\Support\AppLogger;
use Source\Support\Message;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Prestação de contas mensal (AP-101; ADR 0146, sobre o ADR 0166), lida no servidor pelo mesmo serviço da API
 * GET /api/v1/reports/accountability: o resumo do mês, os avisos, o fechamento, a DRE, os saldos bancários e a
 * inadimplência no fim do mês de um condomínio. Somente leitura, para tela e impressão; cada parte tem o CSV
 * no relatório de origem. Exige erp.entries.view e erp.chart.view, como a API. Sem banco ou sem permissão,
 * accountabilityReady false = a tela segue com a demonstração.
 */
final class AccountabilityScreens
{
    public const PAGES = ['AP-101' => 'pages/reports/accountability'];
    public const ROUTE = '/prestacao-de-contas/prestacao-de-contas';

    public const INCOME_COLUMNS = [['Código', 'code', 'text'], ['Conta', 'name', 'tree'], ['Natureza', 'nature', 'label:nature'], ['Valor', 'amount_cents', 'money']];
    public const BANK_COLUMNS = [['Conta bancária', 'name', 'text'], ['Saldo anterior', 'opening_cents', 'signed'], ['Entradas', 'received_cents', 'money'],
        ['Saídas', 'paid_cents', 'money'], ['Saldo final', 'closing_cents', 'signed']];
    public const DELINQUENCY_COLUMNS = [['Unidade', 'unit_label', 'text'], ['Contas', 'entries', 'int'], ['Vencimento mais antigo', 'oldest_due_on', 'date'],
        ['Atraso', 'max_days_late', 'days'], ['Em aberto', 'amount_cents', 'money']];

    /** @return array<string, string> */
    public static function pagePermissions(): array
    {
        return array_fill_keys(array_keys(self::PAGES), 'erp.entries.view');
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        if (!isset(self::PAGES[$id]) || Connect::databaseDisabled() || !Access::can('erp.entries.view', $user) || !Access::can('erp.chart.view', $user)) {
            return ['accountabilityReady' => false];
        }
        $context = CondominiumContext::id();
        $month = self::month($_GET['mes'] ?? null, date('Y-m-d'));
        $data = ['accountabilityReady' => false, 'accountabilityMonth' => $month, 'accountabilityMonths' => [], 'accountabilityNeedsCondo' => $context === null,
            'accountabilityError' => null, 'accountability' => null];
        try {
            if ($context !== null) {
                try {
                    // Lista dos meses com o documento no padrão do PDF de outubro (revisão de 15/11 item 21, ADR 0314).
                    $data['accountabilityMonths'] = (new AccountabilityDocument())->months($user, $context);
                    $data['accountability'] = (new Accountability())->build($user, $context, $month);
                } catch (InvalidArgumentException $error) {
                    $data['accountabilityError'] = $error->getMessage();
                } catch (CondominiumAccessDenied | RegistryNotFound) {
                    $data['accountabilityError'] = 'Condomínio não encontrado no seu escopo.';
                }
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'accountability_screen_failed', 'page' => $id]);
            return ['accountabilityReady' => false];
        }
        $data['accountabilityReady'] = true;
        return $data;
    }

    /** GET {ROUTE}/documento?mes=&ocultar_unidade=1: a prestação de contas para ver e imprimir no navegador. */
    public function document(array $data): void
    {
        $this->deliver(function (array $doc, AccountabilityDocument $service, string $back): void {
            header('Cache-Control: private, no-store');
            header('X-Frame-Options: DENY');
            echo $service->html($doc, false, $back);
        }, false, 'accountability_document_view');
    }

    /** GET {ROUTE}/pdf?mes=&ocultar_unidade=1: o PDF com capa, termo, parecer, demonstrativo, lançamentos e comprovantes. */
    public function documentPdf(array $data): void
    {
        $this->deliver(function (array $doc, AccountabilityDocument $service): void {
            $pdf = $service->pdf($doc);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . AccountabilityDocument::filename($doc) . '"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            echo $pdf;
        }, true, 'accountability_document_pdf');
    }

    /** Mesma permissão da tela (erp.entries.view e erp.chart.view), condomínio da barra e limite de downloads. */
    private function deliver(callable $send, bool $forPdf, string $event): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return;
        }
        $month = self::month($_GET['mes'] ?? null, date('Y-m-d'));
        $hideUnit = ($_GET['ocultar_unidade'] ?? '') === '1';
        $back = erp_url(self::ROUTE . '?mes=' . $month . ($hideUnit ? '&ocultar_unidade=1' : ''));
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user) || !Access::can('erp.chart.view', $user)) {
            http_response_code(403);
            (new Message())->error('Você não tem permissão para ver a prestação de contas.')->flash();
            redirect($back);
            return;
        }
        $condominium = CondominiumContext::id();
        if ($condominium === null) {
            (new Message())->warning('Escolha um condomínio na barra superior para gerar a prestação de contas.')->flash();
            redirect($back);
            return;
        }
        if (request_limit('erp_accountability_document', 30, 600)) {
            (new Message())->error('Muitos downloads em pouco tempo. Aguarde alguns minutos.')->flash();
            redirect($back);
            return;
        }
        try {
            $service = new AccountabilityDocument();
            $doc = $service->build($user, $condominium, $month, $hideUnit, $forPdf);
            \Source\Support\Audit::record('export', 'erp_reports', 0, null, ['report' => 'accountability-document', 'condominium_id' => $condominium,
                'competence' => $month, 'format' => $forPdf ? 'pdf' : 'html', 'hide_unit' => $hideUnit]);
            $send($doc, $service, $back);
        } catch (InvalidArgumentException $error) {
            (new Message())->error($error->getMessage())->flash();
            redirect($back);
        } catch (CondominiumAccessDenied) {
            (new Message())->error('Condomínio fora do seu escopo de acesso.')->flash();
            redirect($back);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => $event . '_failed']);
            (new Message())->error('Não foi possível gerar a prestação de contas agora. Tente de novo.')->flash();
            redirect($back);
        }
    }

    /**
     * Mês da tela (regra pura): AAAA-MM válido e não futuro; senão o mês anterior a $today, que é o mês que
     * costuma ser prestado.
     */
    public static function month(mixed $value, string $today): string
    {
        $current = substr($today, 0, 7);
        if (is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1 && $value >= '2000-01' && $value <= $current) {
            return $value;
        }
        return (new \DateTimeImmutable(substr($today, 0, 7) . '-01'))->modify('-1 month')->format('Y-m');
    }

    /** "Novembro de 2026" (regra pura). */
    public static function monthLabel(string $month): string
    {
        $names = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        return ucfirst($names[(int)substr($month, 5, 2) - 1]) . ' de ' . substr($month, 0, 4);
    }
}
