<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\DebtCertificateBlocked;
use Source\Services\Erp\DocumentVerification;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\UnitDocuments;
use Source\Services\Support\TicketDesk;
use Source\Services\Support\TicketService;
use Source\Support\Audit;
use Source\Support\QrCode;

/**
 * CND no aplicativo do morador (revisão de 15/11, etapa 5; ADR 0637): o morador NÃO emite a certidão. Ele pede, o
 * pedido vira chamado do Help Desk (ADR 0305) para a administradora, a equipe emite no ERP (AP-123) e a certidão
 * aparece no app, com o chamado respondido e resolvido sozinho.
 * - Com conta vencida em aberto, nem o pedido é aberto: o app só informa o débito (DebtCertificateBlocked). O ERP
 *   recusa a emissão pela mesma regra (UnitDocuments), então nenhum caminho gera certidão com débito.
 * - Pedido repetido com um aberto para a unidade devolve o mesmo pedido (não duplica chamado).
 * - Mostra débitos, então segue a função boletos do portal, que só vale para proprietário, inquilino e responsável
 *   financeiro (ResidentLinks::FINANCIAL_RELATIONSHIPS).
 * - Lista só as certidões negativas válidas ("nada consta") da unidade dos últimos 12 meses. As antigas "com débitos",
 *   de antes da regra, ficam só no histórico do ERP e na verificação pública.
 */
final class ResidentCertificates
{
    public const FEATURE = 'boletos';
    /** Categoria do chamado do pedido (Help Desk). */
    public const CATEGORY = 'Pedido de CND';
    private const KIND = 'debt_certificate';

    private PDO $pdo;
    private UnitDocuments $documents;

    public function __construct(?PDO $pdo = null, ?UnitDocuments $documents = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->documents = $documents ?? new UnitDocuments($this->pdo);
    }

    /** @return list<array<string, mixed>> */
    public function list(array $link, ?string $today = null): array
    {
        ResidentPortal::assertFeature($link, self::FEATURE);
        $since = (new \DateTimeImmutable($today ?? date('Y-m-d')))->modify('-12 months')->format('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT id, as_of, result, total_cents, verification_code, created_at FROM erp_unit_documents
            WHERE unit_id = :u AND condominium_id = :c AND kind = :k AND status = 'issued' AND result = 'clear' AND as_of >= :s
            ORDER BY created_at DESC, id DESC LIMIT 20");
        $stmt->execute(['u' => (int)$link['unit_id'], 'c' => (int)$link['condominium_id'], 'k' => self::KIND, 's' => $since]);
        return array_map([self::class, 'present'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Situação para a tela: débito vencido hoje (então não dá para pedir) e o pedido aberto da unidade, se houver.
     *
     * @return array{blocked:bool, message:?string, overdue_count:int, overdue_cents:int, request:?array}
     */
    public function status(array $link): array
    {
        ResidentPortal::assertFeature($link, self::FEATURE);
        $debt = $this->documents->overdueSummary((int)$link['unit_id'], (int)$link['condominium_id'], date('Y-m-d'));
        return ['blocked' => $debt['count'] > 0, 'message' => $debt['count'] > 0 ? DebtCertificateBlocked::text($debt['count'], $debt['cents']) : null,
            'overdue_count' => $debt['count'], 'overdue_cents' => $debt['cents'], 'request' => $this->openRequest($link)];
    }

    /**
     * Pede a certidão à administradora: abre o chamado (ou devolve o aberto). Com débito vencido, recusa.
     *
     * @return array{id:int, protocol:string, status:string, created_at:string, created:bool}
     * @throws DebtCertificateBlocked
     */
    public function request(User $user, array $link): array
    {
        ResidentPortal::assertFeature($link, self::FEATURE);
        $debt = $this->documents->overdueSummary((int)$link['unit_id'], (int)$link['condominium_id'], date('Y-m-d'));
        if ($debt['count'] > 0) {
            Audit::record('refuse', 'erp_unit_documents', null, null, ['kind' => self::KIND, 'unit_id' => (int)$link['unit_id'],
                'reason' => 'overdue_debts', 'overdue_count' => $debt['count'], 'overdue_cents' => $debt['cents'], 'by' => (int)$user->id, 'origin' => 'residents']);
            throw new DebtCertificateBlocked($debt['count'], $debt['cents']);
        }
        $open = $this->openRequest($link);
        if ($open !== null) {
            return $open + ['created' => false];
        }
        $unit = (string)$link['unit_label'];
        $opened = (new TicketService($this->pdo))->open([
            'subject' => 'Pedido de certidão negativa de débitos · unidade ' . $unit,
            'message' => '<p>O morador pediu pelo aplicativo a certidão negativa de débitos da unidade ' . htmlspecialchars($unit, ENT_QUOTES, 'UTF-8')
                . '.</p><p>Emita no ERP em Documentos › Certidão de Débitos. O sistema só emite sem conta vencida em aberto; ao emitir, este chamado é respondido e resolvido sozinho e a certidão aparece no app.</p>',
            'area' => 'financial', 'priority' => 'medium', 'category' => self::CATEGORY,
            'condominium_id' => (int)$link['condominium_id'], 'unit_id' => (int)$link['unit_id'], 'requester_id' => (int)$user->id,
        ], 'residents');
        return ['id' => $opened['id'], 'protocol' => $opened['protocol'], 'status' => 'open', 'created_at' => date('Y-m-d H:i:s'), 'created' => true];
    }

    /**
     * Depois que a equipe emite a certidão negativa no ERP: responde e resolve os pedidos abertos da unidade.
     * @return int pedidos atendidos
     */
    public function fulfil(User $agent, array $document): int
    {
        if (($document['kind'] ?? null) !== self::KIND || ($document['result'] ?? null) !== 'clear' || ($document['status'] ?? null) !== 'issued') {
            return 0;
        }
        $stmt = $this->pdo->prepare("SELECT id FROM studio_support_tickets WHERE category = :cat AND unit_id = :u AND condominium_id = :c
            AND status NOT IN ('resolved', 'closed')");
        $stmt->execute(['cat' => self::CATEGORY, 'u' => (int)$document['unit_id'], 'c' => (int)$document['condominium_id']]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $desk = new TicketDesk($this->pdo);
        $code = (string)$document['verification_display'];
        foreach ($ids as $id) {
            $desk->reply($agent, $id, '<p>A certidão negativa de débitos nº ' . (int)$document['id'] . ' foi emitida e já está no aplicativo, em CND.</p><p>Código de verificação: '
                . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '.</p>', false, 0, true);
        }
        return count($ids);
    }

    /** O pedido aberto da unidade (de qualquer morador dela), ou null. */
    private function openRequest(array $link): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, protocol, status, created_at FROM studio_support_tickets WHERE category = :cat AND unit_id = :u
            AND condominium_id = :c AND status NOT IN ('resolved', 'closed') ORDER BY id DESC LIMIT 1");
        $stmt->execute(['cat' => self::CATEGORY, 'u' => (int)$link['unit_id'], 'c' => (int)$link['condominium_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['id' => (int)$row['id'], 'protocol' => (string)$row['protocol'], 'status' => (string)$row['status'],
            'created_at' => (string)$row['created_at']] : null;
    }

    /** O documento completo, só se for certidão negativa válida da unidade do vínculo. */
    public function document(array $link, int $id): array
    {
        ResidentPortal::assertFeature($link, self::FEATURE);
        $doc = $this->documents->load($id);
        if ($doc['kind'] !== self::KIND || $doc['status'] !== 'issued' || $doc['result'] !== 'clear' || $doc['unit_id'] !== (int)$link['unit_id']
            || $doc['condominium_id'] !== (int)$link['condominium_id']) {
            throw new RegistryNotFound();
        }
        return $doc;
    }

    public function pdf(array $link, int $id): string
    {
        $options = new Options();
        $options->set('tempDir', sys_get_temp_dir());
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(self::html($this->document($link, $id), DocumentVerification::configuredBase()), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return (string)$dompdf->output();
    }

    /** Item da lista (regra pura). */
    public static function present(array $row): array
    {
        $clear = (string)$row['result'] === 'clear';
        return ['id' => (int)$row['id'], 'as_of' => (string)$row['as_of'], 'result' => $clear ? 'clear' : 'debts',
            'result_label' => $clear ? 'Nada consta' : 'Com débitos', 'total_cents' => (int)$row['total_cents'],
            'verification_code' => implode('-', str_split((string)$row['verification_code'], 4)),
            'issued_at' => (string)$row['created_at']];
    }

    /** HTML da certidão para o PDF (regra pura, sem nada de fora: Dompdf sem rede). */
    public static function html(array $doc, ?string $verifyBase): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
        $date = static fn (?string $v): string => $v ? date('d/m/Y', (int)strtotime($v)) : '—';
        $month = static fn (?string $v): string => $v ? substr($v, 5, 2) . '/' . substr($v, 0, 4) : '—';
        $content = $doc['content'] ?? [];
        $overdue = $content['overdue'] ?? [];
        $upcoming = $content['upcoming'] ?? [];
        $unit = $e($doc['unit_label']);
        $condo = $e($doc['condominium_name']);
        if ($doc['result'] === 'clear') {
            $text = '<p class="clear">Nada consta.</p><p>Certificamos que a unidade <b>' . $unit . '</b> do ' . $condo
                . ' não tem contas vencidas em aberto em ' . $date($doc['as_of']) . ', conforme os registros da administradora.</p>';
        } else {
            $text = '<p>Certificamos que a unidade <b>' . $unit . '</b> do ' . $condo . ' tem ' . count($overdue) . ' conta(s) vencida(s) em aberto em '
                . $date($doc['as_of']) . ', no total atualizado de <b>' . $brl((int)$doc['total_cents']) . '</b> (' . $brl((int)($content['overdue_cents'] ?? 0))
                . ' de principal e ' . $brl((int)($content['charges_cents'] ?? 0)) . ' de encargos).</p>';
        }
        $table = '';
        if ($overdue !== []) {
            $table .= '<h3>Contas vencidas</h3><table><tr><th>Conta</th><th>Competência</th><th>Vencimento</th><th class="n">Valor</th><th class="n">Encargos</th><th class="n">Atualizado</th></tr>';
            foreach ($overdue as $o) {
                $table .= '<tr><td>' . $e($o['description']) . '</td><td>' . $month((string)$o['competence']) . '</td><td>' . $date((string)$o['due_on'])
                    . '</td><td class="n">' . $brl((int)$o['amount_cents']) . '</td><td class="n">' . $brl((int)($o['charges_cents'] ?? 0))
                    . '</td><td class="n"><b>' . $brl((int)($o['total_cents'] ?? $o['amount_cents'])) . '</b></td></tr>';
            }
            $table .= '</table>';
        }
        if ($upcoming !== []) {
            $table .= '<h3>Contas a vencer em aberto (' . $brl((int)($content['upcoming_cents'] ?? 0)) . ')</h3><table><tr><th>Conta</th><th>Competência</th><th>Vencimento</th><th class="n">Valor</th></tr>';
            foreach ($upcoming as $u) {
                $table .= '<tr><td>' . $e($u['description']) . '</td><td>' . $month((string)$u['competence']) . '</td><td>' . $date((string)$u['due_on'])
                    . '</td><td class="n">' . $brl((int)$u['amount_cents']) . '</td></tr>';
            }
            $table .= '</table>';
        }
        $footer = DocumentVerification::footer((string)$doc['verification_code'], $verifyBase);
        $qr = $footer !== null ? QrCode::svg($footer['link'], 96, 'QR code') : null;
        $verify = $footer !== null ? '<p>Para conferir a autenticidade, acesse <b>' . $e($footer['address']) . '</b> e digite o código.</p>' : '';
        $qrImg = $qr !== null ? '<img class="qr" src="data:image/svg+xml;base64,' . base64_encode($qr) . '" alt="">' : '';
        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>
            body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #17221d; margin: 24px 28px; }
            .issuer { color: #5b6b63; font-size: 10px; } h1 { font-size: 20px; margin: 2px 0 14px; color: #0d2e27; }
            .meta { width: 100%; border-collapse: collapse; margin-bottom: 14px; } .meta td { padding: 6px 8px; border: 1px solid #dfe6e2; }
            .meta span { display: block; color: #5b6b63; font-size: 9px; } .clear { font-size: 16px; font-weight: bold; color: #078b4c; margin: 4px 0; }
            h3 { font-size: 12px; margin: 16px 0 6px; } table { width: 100%; border-collapse: collapse; }
            th, td { text-align: left; padding: 5px 6px; border-bottom: 1px solid #e6ece9; } th { font-size: 9px; color: #5b6b63; } .n { text-align: right; }
            .sign { margin-top: 36px; } .sign td { width: 50%; padding: 26px 12px 0; border: 0; } .line { border-top: 1px solid #17221d; padding-top: 4px; text-align: center; }
            .foot { margin-top: 26px; border-top: 1px solid #dfe6e2; padding-top: 10px; } .code { font-size: 14px; font-weight: bold; letter-spacing: 1px; }
            .qr { float: right; width: 84px; height: 84px; } .muted { color: #5b6b63; font-size: 9px; }
        </style></head><body>
            <div class="issuer">Connect · administradora de condomínios</div><h1>Certidão de Débitos</h1>
            <table class="meta"><tr><td><span>Condomínio</span>' . $condo . '</td><td><span>Unidade</span>' . $unit . '</td><td><span>Data de referência</span>'
            . $date($doc['as_of']) . '</td><td><span>Nº</span>' . (int)$doc['id'] . '</td></tr></table>' . $text . $table . '
            <table class="sign"><tr><td><div class="line">Síndico(a)</div></td><td><div class="line">Connect · administradora de condomínios</div></td></tr></table>
            <div class="foot">' . $qrImg . '<span class="muted">Código de verificação</span><div class="code">' . $e($doc['verification_display']) . '</div>' . $verify
            . '<p class="muted">Emitida pela administradora em ' . $date($doc['issued_at']) . '. Documento gerado a partir dos registros da administradora na data de referência; pagamentos posteriores não aparecem.</p></div>
        </body></html>';
    }
}
