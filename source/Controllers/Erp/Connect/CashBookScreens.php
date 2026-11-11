<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use InvalidArgumentException;
use Source\Core\Connect;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\CashBook;
use Source\Services\Erp\CashBookDocument;
use Source\Services\Erp\RecurringEntries;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Erp\CondominiumContext;
use Source\Support\Message;

/**
 * Livro caixa mensal (CN-007, ADR 0174), do condomínio da barra superior, pelo mesmo serviço da API
 * GET /api/v1/reports/cash-book. A tela (página do catálogo, dados em data()) mostra o mês; os botões baixam:
 *
 *  GET /prestacao-de-contas/livro-caixa/pdf?mes=AAAA-MM       PDF pronto (com as imagens anexadas)
 *  GET /prestacao-de-contas/livro-caixa/pacote?mes=AAAA-MM    ZIP com o PDF e todos os anexos numerados
 *  GET /prestacao-de-contas/livro-caixa/imprimir?mes=AAAA-MM  o mesmo documento para imprimir no navegador
 *
 * Exige erp.entries.view (como a API). Sem banco ou sem permissão, cashReady false = demonstração.
 */
final class CashBookScreens
{
    public const PAGES = ['CN-007' => 'pages/reports/cash-book'];
    public const ROUTE = '/prestacao-de-contas/livro-caixa';

    /** @return array<string, string> */
    public static function pagePermissions(): array
    {
        return array_fill_keys(array_keys(self::PAGES), 'erp.entries.view');
    }

    /**
     * Mês da tela (regra pura): AAAA-MM válido e não futuro. Sem mês (ou inválido), o mês anterior ao atual: o livro
     * costuma ser fechado e entregue no começo do mês seguinte.
     */
    public static function month(mixed $value, string $current): string
    {
        if (is_string($value) && RecurringEntries::isCompetence($value) && $value <= $current) {
            return $value;
        }
        return (new \DateTimeImmutable($current . '-01'))->modify('-1 month')->format('Y-m');
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        if (!isset(self::PAGES[$id]) || Connect::databaseDisabled() || !Access::can('erp.entries.view', $user)) {
            return ['cashReady' => false];
        }
        $context = CondominiumContext::id();
        $month = self::month($_GET['mes'] ?? null, date('Y-m'));
        $data = ['cashReady' => false, 'cashMonth' => $month, 'cashNeedsCondo' => $context === null, 'cashError' => null, 'cashBook' => null,
            'cashCanManage' => Access::can('erp.entries.manage', $user)];
        try {
            if ($context !== null) {
                try {
                    $data['cashBook'] = (new CashBook())->build($user, $context, $month);
                } catch (InvalidArgumentException $error) {
                    $data['cashError'] = $error->getMessage();
                } catch (CondominiumAccessDenied) {
                    $data['cashError'] = 'Condomínio não encontrado no seu escopo.';
                }
            }
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'cash_book_screen_failed']);
            return ['cashReady' => false];
        }
        $data['cashReady'] = true;
        return $data;
    }

    public function pdf(array $data): void
    {
        $this->deliver(function (array $book, CashBookDocument $document): void {
            $pdf = $document->pdf($book);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . CashBook::filename($book) . '"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: private, no-store');
            echo $pdf;
        }, 'cash_book_pdf');
    }

    public function package(array $data): void
    {
        $this->deliver(function (array $book, CashBookDocument $document): void {
            $zip = $document->package($book);
            try {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . CashBook::filename($book, 'zip') . '"');
                header('Content-Length: ' . (int)filesize($zip));
                header('Cache-Control: private, no-store');
                header('X-Content-Type-Options: nosniff');
                readfile($zip);
            } finally {
                @unlink($zip);
            }
        }, 'cash_book_package');
    }

    public function printView(array $data): void
    {
        $this->deliver(function (array $book, CashBookDocument $document): void {
            header('Cache-Control: private, no-store');
            header('X-Frame-Options: DENY');
            echo $document->html($book, false, erp_url(self::ROUTE . '?mes=' . $book['competence']));
        }, 'cash_book_print');
    }

    /** @param callable(array, CashBookDocument): void $send */
    private function deliver(callable $send, string $event): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return;
        }
        $month = self::month($_GET['mes'] ?? null, date('Y-m'));
        $back = erp_url(self::ROUTE . '?mes=' . $month);
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user)) {
            http_response_code(403);
            (new Message())->error('Você não tem permissão para ver o livro caixa.')->flash();
            redirect($back);
            return;
        }
        $condominium = CondominiumContext::id();
        if ($condominium === null) {
            (new Message())->warning('Escolha um condomínio na barra superior para gerar o livro caixa.')->flash();
            redirect($back);
            return;
        }
        if (request_limit('erp_cash_book_download', 30, 600)) {
            (new Message())->error('Muitos downloads em pouco tempo. Aguarde alguns minutos.')->flash();
            redirect($back);
            return;
        }
        try {
            $document = new CashBookDocument();
            $book = $document->prepare((new CashBook())->build($user, $condominium, $month, true));
            \Source\Support\Audit::record('export', 'erp_reports', 0, null, ['report' => 'cash-book', 'condominium_id' => $condominium, 'competence' => $month, 'format' => $event, 'annexes' => count($book['annexes'])]);
            $send($book, $document);
        } catch (InvalidArgumentException $error) {
            (new Message())->error($error->getMessage())->flash();
            redirect($back);
        } catch (CondominiumAccessDenied) {
            (new Message())->error('Condomínio fora do seu escopo de acesso.')->flash();
            redirect($back);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => $event . '_failed']);
            (new Message())->error('Não foi possível gerar o livro caixa agora. Tente de novo.')->flash();
            redirect($back);
        }
    }
}
