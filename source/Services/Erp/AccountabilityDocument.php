<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;
use Source\Core\Connect;
use Source\Core\View;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Prestação de contas no padrão do PDF de outubro (revisão de 15/11 item 21, ADR 0314), montada sobre o livro caixa
 * do mês (CashBook, ADR 0174), sem dado novo:
 *
 * 1. capa (condomínio e período);
 * 2. termo de abertura (dados do condomínio);
 * 3. parecer do síndico e do conselho (nomes do cadastro Síndico e conselho, com linhas para assinar);
 * 4. demonstrativo: receitas e despesas pagas no mês por grupo (conta-pai do plano) e conta, com cada pagamento,
 *    o total do grupo e o total geral; resumo (receitas, despesas e resultado) e resumo das contas bancárias;
 * 5. relatório de lançamentos: as despesas pagas no mês e, para cada uma, a linha do lançamento seguida dos
 *    comprovantes (imagem dentro do PDF; anexo em PDF citado, porque o dompdf não junta PDFs).
 *
 * "Ocultar unidade" (item 19) tira a unidade das receitas. O documento não altera nada: só lê.
 */
final class AccountabilityDocument
{
    /** Meses na lista da tela. */
    public const MONTHS = 12;
    public const COUNCIL_ROLES = ['fiscal', 'consultivo', 'subsindico'];

    private PDO $pdo;
    private CondominiumScope $scope;
    private string $theme;
    private ?CashBookDocument $cash;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?string $theme = null, ?CashBookDocument $cash = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->theme = rtrim($theme ?? moves_container_path('erp', CONF_VIEW_ERP), '/') . '/';
        $this->cash = $cash;
    }

    /** "2026-10" → "1 de out. de 2026 - 31 de out. de 2026" (regra pura, como no modelo). */
    public static function periodLabel(string $competence): string
    {
        $short = ['jan.', 'fev.', 'mar.', 'abr.', 'mai.', 'jun.', 'jul.', 'ago.', 'set.', 'out.', 'nov.', 'dez.'];
        [$from, $to] = CashBook::period($competence);
        $fmt = static fn (string $d): string => (int)substr($d, 8, 2) . ' de ' . $short[(int)substr($d, 5, 2) - 1] . ' de ' . substr($d, 0, 4);
        return $fmt($from) . ' - ' . $fmt($to);
    }

    /** Código do grupo (regra pura): a conta-pai pelo código ("3.02.01" → "3.02"); sem pai, o próprio código. */
    public static function parentCode(string $code): string
    {
        $pos = strrpos($code, '.');
        return $pos === false ? $code : substr($code, 0, $pos);
    }

    /**
     * Demonstrativo (regra pura): receitas e despesas por grupo e conta, na ordem do código.
     *
     * @param list<array> $lines linhas do livro (nature, account_code, account_name, paid_on, description, amount_cents, counterpart)
     * @param array<string, string> $groupNames nome do grupo por código
     * @return array{receita:array, despesa:array, result_cents:int}
     */
    public static function demonstrative(array $lines, array $groupNames, bool $hideUnit = false): array
    {
        $out = [];
        foreach (['receita' => '( + ) Receitas', 'despesa' => '( - ) Despesas'] as $nature => $label) {
            $out[$nature] = ['label' => $label, 'total_cents' => 0, 'groups' => []];
        }
        foreach ($lines as $line) {
            $nature = $line['nature'] === 'receita' ? 'receita' : 'despesa';
            $code = (string)$line['account_code'];
            $parent = self::parentCode($code);
            $amount = (int)$line['amount_cents'];
            $group = &$out[$nature]['groups'][$parent];
            $group ??= ['code' => $parent, 'name' => $groupNames[$parent] ?? (string)$line['account_name'], 'total_cents' => 0, 'accounts' => []];
            $group['accounts'][$code] ??= ['code' => $code, 'name' => (string)$line['account_name'], 'total_cents' => 0, 'items' => []];
            $counterpart = $line['counterpart'] ?? null;
            $isUnit = is_string($counterpart) && str_starts_with($counterpart, 'Unidade ');
            $description = (string)$line['description'] . ($nature === 'receita' && $isUnit && !$hideUnit ? ' - ' . $counterpart : '');
            $group['accounts'][$code]['items'][] = ['paid_on' => (string)$line['paid_on'], 'description' => $description, 'amount_cents' => $amount];
            $group['accounts'][$code]['total_cents'] += $amount;
            $group['total_cents'] += $amount;
            $out[$nature]['total_cents'] += $amount;
            unset($group);
        }
        foreach ($out as &$section) {
            ksort($section['groups'], SORT_STRING);
            foreach ($section['groups'] as &$group) {
                ksort($group['accounts'], SORT_STRING);
                $group['accounts'] = array_values($group['accounts']);
            }
            unset($group);
            $section['groups'] = array_values($section['groups']);
        }
        unset($section);
        return $out + ['result_cents' => $out['receita']['total_cents'] - $out['despesa']['total_cents']];
    }

    /**
     * Lista dos meses (os últimos MONTHS até o mês atual): situação do fechamento e quantos pagamentos houve.
     * @return list<array{competence:string, label:string, status:string, payments:int}>
     */
    public function months(User $user, int $condominiumId, ?string $current = null): array
    {
        $this->scope->assertAccess($user, $condominiumId);
        $current ??= date('Y-m');
        $first = (new \DateTimeImmutable($current . '-01'))->modify('-' . (self::MONTHS - 1) . ' months');
        $stmt = $this->pdo->prepare("SELECT DATE_FORMAT(paid_on, '%Y-%m') AS m, COUNT(*) AS n FROM erp_entries
            WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active' AND paid_on >= :f GROUP BY m");
        $stmt->execute(['c' => $condominiumId, 'f' => $first->format('Y-m-d')]);
        $payments = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        $stmt = $this->pdo->prepare('SELECT competence, status FROM erp_month_closings WHERE condominium_id = :c AND competence >= :f');
        $stmt->execute(['c' => $condominiumId, 'f' => $first->format('Y-m')]);
        $closings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $months = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $m = $first->modify("+{$i} months")->format('Y-m');
            $months[] = ['competence' => $m, 'label' => CashBookDocument::monthLabel($m), 'status' => (string)($closings[$m] ?? 'open'), 'payments' => $payments[$m] ?? 0];
        }
        return array_reverse($months);
    }

    /** Documento do mês ($forPdf: caminhos dos anexos para pôr as imagens dentro do PDF). */
    public function build(User $user, int $condominiumId, string $competence, bool $hideUnit = false, bool $forPdf = false): array
    {
        $cash = $this->cash ?? new CashBookDocument(null, $this->theme);
        $book = (new CashBook($this->pdo, $this->scope))->build($user, $condominiumId, $competence, $forPdf);
        $book = $forPdf ? $cash->prepare($book) : $book;
        $stmt = $this->pdo->prepare('SELECT name, legal_name, document, street, number, complement, district, city, state FROM condominiums WHERE id = ?');
        $stmt->execute([$condominiumId]);
        $condo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $codes = array_values(array_unique(array_map(static fn (array $l): string => self::parentCode((string)$l['account_code']), $book['lines'])));
        $groupNames = [];
        if ($codes !== []) {
            $marks = implode(',', array_fill(0, count($codes), '?'));
            $stmt = $this->pdo->prepare("SELECT code, name FROM erp_chart_accounts WHERE deleted_at IS NULL AND (condominium_id = ? OR condominium_id IS NULL)
                AND code IN ({$marks}) ORDER BY condominium_id IS NULL");
            $stmt->execute(array_merge([$condominiumId], $codes));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $groupNames[(string)$row['code']] ??= mb_strtoupper((string)$row['name']);
            }
        }

        $stmt = $this->pdo->prepare("SELECT role, name FROM erp_board_members WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
            AND (term_ends_on IS NULL OR term_ends_on >= ?) ORDER BY FIELD(role, 'sindico', 'subsindico', 'fiscal', 'consultivo', 'suplente'), name");
        $stmt->execute([$condominiumId, $book['from']]);
        $board = ['sindico' => null, 'council' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $member) {
            if ($member['role'] === 'sindico') {
                $board['sindico'] ??= (string)$member['name'];
            } elseif (in_array($member['role'], self::COUNCIL_ROLES, true)) {
                $board['council'][] = (string)$member['name'];
            }
        }

        $annexesByLine = [];
        foreach ($book['annexes'] as $annex) {
            $annexesByLine[(int)$annex['line']][] = $annex;
        }
        $expenses = [];
        foreach ($book['lines'] as $line) {
            if ($line['nature'] !== 'receita') {
                $expenses[] = $line + ['annexes' => $annexesByLine[(int)$line['seq']] ?? []];
            }
        }
        $place = implode(' / ', array_filter([trim((string)($condo['city'] ?? '')), trim((string)($condo['district'] ?? ''))]));
        return [
            'condominium' => $book['condominium'] + [
                'label' => (string)($condo['name'] ?? '') . ($place !== '' ? " ({$place})" : ''),
                'street' => trim((string)($condo['street'] ?? '')), 'number' => trim((string)($condo['number'] ?? '')),
                'city' => trim((string)($condo['city'] ?? '')), 'state' => trim((string)($condo['state'] ?? '')),
            ],
            'competence' => $competence, 'from' => $book['from'], 'to' => $book['to'], 'period' => self::periodLabel($competence),
            'closed' => $book['closed'], 'hide_unit' => $hideUnit, 'board' => $board,
            'demonstrative' => self::demonstrative($book['lines'], $groupNames, $hideUnit),
            'banks' => $book['banks'],
            'expenses' => $expenses,
            'expense_total_cents' => array_sum(array_column($expenses, 'amount_cents')),
            'missing_proof' => count($book['missing_proof']),
        ];
    }

    public function html(array $doc, bool $forPdf = false, ?string $back = null): string
    {
        return (new View($this->theme))->render('pages/reports/accountability-document', [
            'doc' => $doc, 'forPdf' => $forPdf, 'back' => $back, 'issuer' => ListDocument::issuer(),
            'logo' => ListDocument::logo(), 'today' => date('d/m/Y'), 'longToday' => self::longDate(date('Y-m-d')),
            'imageSrc' => static fn (array $annex): string => $forPdf ? CashBookDocument::imageData((string)($annex['absolute'] ?? '')) : '',
        ]);
    }

    public function pdf(array $doc): string
    {
        $options = new Options();
        $options->set('tempDir', sys_get_temp_dir());
        $options->set('chroot', $this->theme);
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($doc, true), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(28, $canvas->get_height() - 26, ListDocument::issuer()['site'], $font, 7.5, [0.36, 0.42, 0.39]);
        $canvas->page_text($canvas->get_width() - 40, $canvas->get_height() - 26, '{PAGE_NUM}', $font, 7.5, [0.36, 0.42, 0.39]);
        return (string)$dompdf->output();
    }

    /** Nome do arquivo (regra pura): prestacao-de-contas-<condomínio>-<AAAA-MM>.pdf */
    public static function filename(array $doc): string
    {
        return 'prestacao-de-contas-' . substr(ListDocument::filename((string)$doc['condominium']['name'], 'pdf', (string)$doc['competence']), 0, -4) . '.pdf';
    }

    /** "2026-11-15" → "domingo, 15 de novembro de 2026" (regra pura). */
    public static function longDate(string $date): string
    {
        $days = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
        $d = new \DateTimeImmutable($date);
        return $days[(int)$d->format('w')] . ', ' . (int)$d->format('j') . ' de ' . explode(' de ', CashBookDocument::monthLabel($d->format('Y-m')))[0] . ' de ' . $d->format('Y');
    }
}
