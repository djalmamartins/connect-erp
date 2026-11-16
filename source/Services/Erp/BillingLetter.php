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
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Audit;
use Source\Support\QrCode;

/**
 * Carta de cobrança impressa (pedido do Djalma, 16/11/2026, ADR 0320): uma folha A4 dobrada em três, com
 *
 * 1. parte externa: fundo floral cinza da Connect, aba de cima de cabeça para baixo (canais de atendimento e
 *    aplicativo), logo e QR do aplicativo, destinatário na altura da janela do envelope, quadro "Para uso do
 *    Correio" e remetente;
 * 2. parte interna: cabeçalho (conta, competência, emissão, vencimento e valor), destinatário, rateio do boleto,
 *    demonstrativo do mês anterior (o mesmo da prestação de contas, ADR 0314), resumo das contas bancárias,
 *    inadimplência, área de avisos e a ficha do boleto com linha digitável e código de barras (ITF-25);
 *
 * Ajustes de 16/11 à tarde (ADR 0319): nunca mais que as duas folhas; o demonstrativo é sempre um resumo (receitas em
 * cotas por conta, despesas por conta, encolhendo para grupos e totais quando não cabe), bancos, fundos e
 * inadimplência à direita; textos da aba externa e da área de avisos vêm dos parâmetros (ErpSettings, letter.*).
 *
 * Tudo vem do sistema: o boleto registrado (erp_bank_charges), a conta a receber e a fatura de origem, o pagador
 * (mesma regra do registro no banco), as regras de cobrança e o livro caixa do mês anterior. Só lê; a geração
 * fica na auditoria. Escopo pelo condomínio do boleto (fora = 404).
 */
final class BillingLetter
{
    /** Linhas que cabem na coluna do demonstrativo da parte interna (medido no dompdf: ~105 mm a ~3,5 mm por linha, com folga). */
    public const COLUMN_LINES = 28;

    private const BANKS = ['077' => 'Banco Inter', '341' => 'Itaú', '001' => 'Banco do Brasil', '237' => 'Bradesco', '104' => 'Caixa', '033' => 'Santander',
        '756' => 'Sicoob', '748' => 'Sicredi', '260' => 'Nubank'];

    private PDO $pdo;
    private CondominiumScope $scope;
    private string $theme;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?string $theme = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->theme = rtrim($theme ?? moves_container_path('erp', CONF_VIEW_ERP), '/') . '/';
    }

    /** Linha digitável com os espaços e pontos do padrão Febraban (regra pura); fora do padrão, devolve como veio. */
    public static function formatDigitable(?string $line): string
    {
        $d = preg_replace('/\D/', '', (string)$line);
        if (strlen($d) !== 47) {
            return trim((string)$line);
        }
        return substr($d, 0, 5) . '.' . substr($d, 5, 5) . ' ' . substr($d, 10, 5) . '.' . substr($d, 15, 6) . ' '
            . substr($d, 21, 5) . '.' . substr($d, 26, 6) . ' ' . substr($d, 32, 1) . ' ' . substr($d, 33, 14);
    }

    /**
     * Código de barras ITF-25 (intercalado 2 de 5) do boleto, regra pura: lista de larguras em módulos, alternando
     * barra e espaço a partir de uma barra. Estreita = 1, larga = 3. Número ímpar de dígitos ou não numérico = [].
     *
     * @return list<int>
     */
    public static function itfWidths(?string $code): array
    {
        $digits = (string)$code;
        if ($digits === '' || !ctype_digit($digits) || strlen($digits) % 2 !== 0) {
            return [];
        }
        $patterns = ['00110', '10001', '01001', '11000', '00101', '10100', '01100', '00011', '10010', '01010'];
        $widths = [1, 1, 1, 1];   // início: barra, espaço, barra, espaço estreitos
        for ($i = 0; $i < strlen($digits); $i += 2) {
            $bars = $patterns[(int)$digits[$i]];
            $spaces = $patterns[(int)$digits[$i + 1]];
            for ($k = 0; $k < 5; $k++) {
                $widths[] = $bars[$k] === '1' ? 3 : 1;
                $widths[] = $spaces[$k] === '1' ? 3 : 1;
            }
        }
        return array_merge($widths, [3, 1, 1]);   // fim: barra larga, espaço, barra estreitos
    }

    /** O código de barras em SVG (data URI) a partir das larguras do ITF-25. */
    public static function barcodeSvg(?string $code, int $height = 50): string
    {
        $widths = self::itfWidths($code);
        if ($widths === []) {
            return '';
        }
        $x = 0;
        $rects = '';
        foreach ($widths as $i => $w) {
            if ($i % 2 === 0) {
                $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '"/>';
            }
            $x += $w;
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $x . '" height="' . $height . '" viewBox="0 0 ' . $x . ' ' . $height
            . '" preserveAspectRatio="none" shape-rendering="crispEdges"><g fill="#000">' . $rects . '</g></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** Instruções ao caixa a partir das regras de cobrança (regra pura): multa e juros em pontos-base. */
    public static function instructions(int $fineBp, int $monthlyInterestBp): array
    {
        $pct = static fn (int $bp): string => rtrim(rtrim(number_format($bp / 100, 2, ',', '.'), '0'), ',') . '%';
        $lines = [];
        if ($fineBp > 0 || $monthlyInterestBp > 0) {
            $parts = [];
            if ($fineBp > 0) {
                $parts[] = 'multa de ' . $pct($fineBp);
            }
            if ($monthlyInterestBp > 0) {
                $parts[] = 'juros de ' . $pct($monthlyInterestBp) . ' ao mês, proporcional aos dias de atraso';
            }
            $lines[] = 'Após o vencimento cobrar ' . implode(' e ', $parts) . '.';
        }
        $lines[] = 'Não receber em cheque. Em caso de dúvida, fale com o Financeiro da Connect.';
        return $lines;
    }

    /**
     * Resumo do demonstrativo para a carta (regra pura): receitas por conta com as cotas ("6 cotas de 650,00", agrupando os
     * recebimentos pelo valor; só quando a conta tem mais de um), despesas por conta, e os grupos com o total.
     *
     * @return array{receita:array, despesa:array, result_cents:int}
     */
    public static function summary(array $demonstrative): array
    {
        $out = ['result_cents' => (int)($demonstrative['result_cents'] ?? 0)];
        foreach (['receita', 'despesa'] as $nature) {
            $section = $demonstrative[$nature] ?? ['total_cents' => 0, 'groups' => []];
            $groups = [];
            foreach ($section['groups'] ?? [] as $g) {
                $accounts = [];
                foreach ($g['accounts'] as $a) {
                    $quotas = [];
                    if ($nature === 'receita' && count($a['items']) > 1) {
                        foreach ($a['items'] as $it) {
                            $quotas[(int)$it['amount_cents']] = ($quotas[(int)$it['amount_cents']] ?? 0) + 1;
                        }
                        uksort($quotas, static fn (int $x, int $y): int => [$quotas[$y], $y] <=> [$quotas[$x], $x]);
                        $quotas = array_map(static fn (int $amount, int $count): array => ['count' => $count, 'amount_cents' => $amount, 'total_cents' => $count * $amount],
                            array_keys($quotas), array_values($quotas));
                    }
                    $accounts[] = ['name' => (string)$a['name'], 'total_cents' => (int)$a['total_cents'], 'quotas' => $quotas];
                }
                $groups[] = ['name' => (string)$g['name'], 'total_cents' => (int)$g['total_cents'], 'accounts' => $accounts];
            }
            $out[$nature] = ['total_cents' => (int)($section['total_cents'] ?? 0), 'groups' => $groups];
        }
        return $out;
    }

    /**
     * Quanto do resumo cabe na coluna da esquerda (regra pura), que leva a composição do boleto, as receitas, as despesas
     * e o resultado. Receitas: 'quotas' → 'accounts' → 'groups' → 'totals'; despesas: 'accounts' → 'groups' → 'totals'.
     * Encolhe primeiro o lado maior. Nunca cria folha a mais: no pior caso ficam só os totais.
     *
     * @return array{receita:string, despesa:string}
     */
    public static function fit(array $summary, int $rateioRows = 1, int $limit = self::COLUMN_LINES): array
    {
        $lines = static function (array $section, string $level): int {
            if ($level === 'totals') {
                return 0;
            }
            $n = 0;
            foreach ($section['groups'] ?? [] as $g) {
                $n++;   // grupo (com o total na mesma linha)
                if ($level === 'groups') {
                    continue;
                }
                foreach ($g['accounts'] as $a) {
                    $n += 1 + ($level === 'quotas' ? count($a['quotas']) : 0);
                }
            }
            return $n;
        };
        $fixed = 4 + $rateioRows + 2 + 2 + 3;   // composição (título, cabeçalho, total, respiro) + títulos/totais das receitas e despesas + resultado
        $rev = ['quotas', 'accounts', 'groups', 'totals'];
        $exp = ['accounts', 'groups', 'totals'];
        $r = 0;
        $d = 0;
        while ($fixed + $lines($summary['receita'] ?? [], $rev[$r]) + $lines($summary['despesa'] ?? [], $exp[$d]) > $limit) {
            $canR = $r < count($rev) - 1;
            $canD = $d < count($exp) - 1;
            if (!$canR && !$canD) {
                break;
            }
            $sizeR = $lines($summary['receita'] ?? [], $rev[$r]);
            $sizeD = $lines($summary['despesa'] ?? [], $exp[$d]);
            if ($canR && (!$canD || $sizeR >= $sizeD)) {
                $r++;
            } else {
                $d++;
            }
        }
        return ['receita' => $rev[$r], 'despesa' => $exp[$d]];
    }

    /**
     * Texto livre dos parâmetros em blocos (regra pura): "# " subtítulo, "! " destaque, "- " item de lista, "~ " letra
     * miúda; o resto é parágrafo. Linhas vazias somem.
     *
     * @return list<array{kind:string, text:string}>
     */
    public static function blocks(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $kind = match (mb_substr($line, 0, 2)) { '# ' => 'title', '! ' => 'highlight', '- ' => 'item', '~ ' => 'small', default => 'text' };
            $out[] = ['kind' => $kind, 'text' => $kind === 'text' ? $line : trim(mb_substr($line, 2))];
        }
        return $out;
    }

    /** Competência anterior (regra pura): "2026-01" → "2025-12". */
    public static function previousCompetence(string $competence): string
    {
        return (new \DateTimeImmutable($competence . '-01'))->modify('-1 month')->format('Y-m');
    }

    /**
     * Dados da carta a partir de um boleto (registrado ou pago, com linha digitável). $notice = área de avisos
     * (title, body, image); null usa os parâmetros letter.notice_* do condomínio (vazios = sem área de avisos).
     */
    public function build(User $user, int $chargeId, ?array $notice = null): array
    {
        $stmt = $this->pdo->prepare('SELECT c.*, b.bank_code, b.branch, b.account_number, b.name AS bank_account_name
            FROM erp_bank_charges c JOIN erp_bank_accounts b ON b.id = c.bank_account_id WHERE c.id = :id');
        $stmt->execute(['id' => $chargeId]);
        $charge = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($charge) || !$this->scope->canAccess($user, (int)$charge['condominium_id'])) {
            throw new RegistryNotFound();
        }
        if (!in_array($charge['status'], ['registered', 'paid'], true) || empty($charge['digitable_line'])) {
            throw new \DomainException('A carta só sai para boleto registrado no banco (com linha digitável).');
        }
        $condominiumId = (int)$charge['condominium_id'];

        $stmt = $this->pdo->prepare('SELECT e.id, e.condominium_id, e.unit_id, e.description, e.competence, e.due_on, e.amount_cents, e.invoice_item_id,
                u.label AS unit_label, u.block, u.number AS unit_number, u.ideal_fraction,
                i.amount_cents AS invoice_amount_cents, i.units_count, i.issued_at, i.competence AS invoice_competence, t.name AS invoice_type
            FROM erp_entries e LEFT JOIN erp_units u ON u.id = e.unit_id
            LEFT JOIN erp_invoice_items it ON it.id = e.invoice_item_id LEFT JOIN erp_invoices i ON i.id = it.invoice_id
            LEFT JOIN erp_invoice_types t ON t.id = i.invoice_type_id WHERE e.id = :e');
        $stmt->execute(['e' => $charge['entry_id']]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new RegistryNotFound();

        $stmt = $this->pdo->prepare('SELECT name, legal_name, document, street, number, complement, district, city, state, zip_code FROM condominiums WHERE id = ?');
        $stmt->execute([$condominiumId]);
        $condo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $payer = (new BankCharges($this->pdo, $this->scope))->payerFor($entry);
        $competence = (string)($entry['invoice_competence'] ?: $entry['competence'] ?: substr((string)$entry['due_on'], 0, 7));
        $previous = self::previousCompetence($competence);
        $statement = (new AccountabilityDocument($this->pdo, $this->scope, $this->theme))->build($user, $condominiumId, $previous, true);
        $funds = Accountability::funds((new AccountingReports($this->pdo, $this->scope))->ledger($user, $condominiumId, $statement['from'], $statement['to'], 'competence', '8'));
        $setting = fn (string $key): string => (string)ErpSettings::value($this->pdo, $condominiumId, $key);
        $notice ??= ['title' => $setting('letter.notice_title'), 'body' => $setting('letter.notice_body')];
        if (trim((string)($notice['title'] ?? '')) === '' && trim((string)($notice['body'] ?? '')) === '' && empty($notice['image'])) {
            $notice = null;
        }
        $summary = self::summary($statement['demonstrative']);
        $asOf = min((string)$statement['to'], date('Y-m-d'));
        $delinquency = (new Agreements($this->pdo, $this->scope))->delinquency($user, $condominiumId, $asOf);
        $rule = (new BillingRules($this->pdo))->rule($condominiumId);

        $label = (string)($entry['invoice_type'] ?: $entry['description']);
        $rateio = [['label' => $label, 'condominium_cents' => $entry['invoice_amount_cents'] !== null ? (int)$entry['invoice_amount_cents'] : null,
            'unit_cents' => (int)$entry['amount_cents']]];
        $street = trim((string)($condo['street'] ?? ''));
        $address = [
            'line1' => trim($street . ($street !== '' && trim((string)($condo['number'] ?? '')) !== '' ? ', ' . trim((string)$condo['number']) : '')),
            'unit' => trim(implode(' - ', array_filter([
                trim((string)($entry['unit_number'] ?? '')) !== '' ? 'Apto ' . trim((string)$entry['unit_number']) : '',
                trim((string)($entry['block'] ?? '')) !== '' ? 'Bloco ' . trim((string)$entry['block']) : '',
            ]))),
            'district' => trim((string)($condo['district'] ?? '')),
            'zip' => self::zip((string)($condo['zip_code'] ?? '')),
            'city' => trim((string)($condo['city'] ?? '')), 'state' => trim((string)($condo['state'] ?? '')),
        ];

        Audit::record('read', 'erp_bank_charges', $chargeId, null, ['billing_letter' => true]);
        return [
            'charge' => [
                'id' => (int)$charge['id'], 'our_number' => (string)$charge['our_number'], 'status' => (string)$charge['status'],
                'due_on' => (string)$charge['due_on'], 'amount_cents' => (int)$charge['amount_cents'],
                'digitable_line' => self::formatDigitable($charge['digitable_line']), 'barcode' => (string)($charge['barcode'] ?? ''),
                'pix' => trim((string)($charge['pix_copy_paste'] ?? '')),
                'bank_code' => (string)$charge['bank_code'], 'bank_name' => self::BANKS[(string)$charge['bank_code']] ?? ('Banco ' . $charge['bank_code']),
                'issued_on' => substr((string)($charge['registered_at'] ?? $charge['created_at']), 0, 10),
            ],
            'competence' => $competence, 'competence_label' => CashBookDocument::monthLabel($competence),
            'period' => ['from' => $competence . '-01', 'to' => (new \DateTimeImmutable($competence . '-01'))->format('Y-m-t')],
            'condominium' => ['name' => (string)($condo['name'] ?? ''), 'legal_name' => (string)($condo['legal_name'] ?? ''),
                'document' => self::cnpj((string)($condo['document'] ?? ''))],
            'unit' => ['label' => (string)($entry['unit_label'] ?? ''), 'fraction' => $entry['ideal_fraction'] !== null ? (float)$entry['ideal_fraction'] : null],
            'payer' => $payer === null ? null : ['name' => (string)$payer['name'], 'document' => self::cpfCnpj((string)($payer['document'] ?? ''))],
            'address' => $address,
            'rateio' => $rateio,
            'statement' => ['competence' => $previous, 'label' => CashBookDocument::monthLabel($previous), 'from' => $statement['from'], 'to' => $statement['to'],
                'summary' => $summary, 'fit' => self::fit($summary, count($rateio)), 'banks' => $statement['banks'], 'funds' => $funds, 'closed' => $statement['closed']],
            'flap' => [
                'left' => ['title' => $setting('letter.flap_left_title'), 'blocks' => self::blocks($setting('letter.flap_left_body'))],
                'right' => ['title' => $setting('letter.flap_right_title'), 'blocks' => self::blocks($setting('letter.flap_right_body'))],
            ],
            'delinquency' => ['as_of' => $asOf, 'units' => (int)$delinquency['totals']['units'], 'amount_cents' => (int)$delinquency['totals']['amount_cents']],
            'instructions' => self::instructions((int)$rule['fine_bp'], (int)$rule['monthly_interest_bp']),
            'notice' => $notice,
        ];
    }

    public function html(array $letter, bool $forPdf = false): string
    {
        $appLink = function_exists('app_url') ? self::absolute(app_url('/')) : 'https://www.connectcondominios.com.br';
        return (new View($this->theme))->render('pages/finance/billing-letter', [
            'letter' => $letter, 'forPdf' => $forPdf, 'issuer' => ListDocument::issuer(), 'logo' => ListDocument::logo(),
            'floral' => self::asset($this->theme . 'assets/images/billing/floral-cinza.png', 'image/png'),
            'barcode' => self::barcodeSvg($letter['charge']['barcode']),
            'pixQr' => ($letter['charge']['pix'] ?? '') !== '' ? self::qr((string)$letter['charge']['pix'], 'QR code do Pix') : '',
            'appLink' => $appLink, 'qr' => self::qr($appLink, 'Acesse o aplicativo'),
        ]);
    }

    public function pdf(array $letter): string
    {
        $options = new Options();
        $options->set('tempDir', sys_get_temp_dir());
        $options->set('chroot', $this->theme);
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($letter, true), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return (string)$dompdf->output();
    }

    /** carta-de-cobranca-<condomínio>-<unidade>-<AAAA-MM>.pdf (regra pura). */
    public static function filename(array $letter): string
    {
        $slug = static fn (string $t): string => strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t)), '-'));
        return 'carta-de-cobranca-' . implode('-', array_filter([$slug((string)$letter['condominium']['name']), $slug((string)$letter['unit']['label']), $letter['competence']])) . '.pdf';
    }

    private static function zip(string $zip): string
    {
        $d = preg_replace('/\D/', '', $zip);
        return strlen((string)$d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5) : trim($zip);
    }

    private static function cnpj(string $doc): string
    {
        $d = (string)preg_replace('/\D/', '', $doc);
        return strlen($d) === 14 ? vsprintf('%s.%s.%s/%s-%s', [substr($d, 0, 2), substr($d, 2, 3), substr($d, 5, 3), substr($d, 8, 4), substr($d, 12)]) : $doc;
    }

    /** Documento do pagador (regra pura): CPF ou CNPJ com máscara; vazio fica vazio. */
    public static function cpfCnpj(string $doc): string
    {
        $d = (string)preg_replace('/\D/', '', $doc);
        if (strlen($d) === 11) {
            return vsprintf('%s.%s.%s-%s', [substr($d, 0, 3), substr($d, 3, 3), substr($d, 6, 3), substr($d, 9)]);
        }
        return self::cnpj($d);
    }

    private static function asset(string $path, string $mime): string
    {
        return is_file($path) ? 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path)) : '';
    }

    private static function qr(string $content, string $label): string
    {
        $svg = QrCode::svg($content, 240, $label);
        return $svg !== null ? 'data:image/svg+xml;base64,' . base64_encode($svg) : '';
    }

    private static function absolute(string $path): string
    {
        if (preg_match('#^https?://#', $path)) {
            return $path;
        }
        $base = defined('CONF_URL_BASE') ? rtrim((string)CONF_URL_BASE, '/') : 'https://www.connectcondominios.com.br';
        return $base . $path;
    }
}
