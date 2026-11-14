<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Antivirus\ScanStatus;
use Source\Support\AppLogger;
use Source\Support\Audit;

/**
 * Link público da prestação de contas para os moradores (ADR 0301).
 *
 * O livro caixa do mês (CN-007, CashBook) é agrupado por categoria (conta do plano) e por fornecedor, e cada
 * pagamento vem seguido dos documentos anexados, na ordem: comprovante, conta ou fatura, nota fiscal, recibo e outros
 * (pedido do Djalma, 14/11/2026: "pagamento da Copasa, conta da Copasa, nota fiscal"). O livro anual junta os doze
 * meses do ano com o resumo por categoria.
 *
 * - generate(): só com o mês fechado (no anual, os doze meses). Grava o livro agrupado em JSON canônico com sha256 e
 *   versão. Gerar de novo com o mesmo conteúdo não muda nada; conteúdo diferente (o mês foi reaberto e corrigido)
 *   sobe a versão e mantém o mesmo endereço, para o link que os moradores já receberam mostrar a versão corrigida.
 *   Link revogado e gerado de novo ganha endereço novo: o antigo não volta a funcionar.
 * - afterClose(): chamado depois de fechar o mês (tela, API e lote). Gera o link do mês e o envia aos moradores;
 *   quando fecha dezembro, gera e envia também o livro do ano. Falha aqui nunca desfaz o fechamento.
 * - notifyResidents(): um aviso por unidade na fila do ERP (ADR 0054/0055, público "notices"), só do condomínio do
 *   link. Fora de produção nada sai para morador (NotificationDispatcher).
 * - publicView() e publicFile(): o que o link mostra e baixa, sem login. Só os documentos que estão no livro
 *   congelado, do mesmo condomínio, ainda no Disco Virtual e liberados pelo antivírus (ADR 0160).
 *
 * Dados pessoais: os recebimentos das unidades saem somados por categoria ("recebido de 42 unidades"), sem a
 * unidade, o nome nem os anexos. O caminho dos arquivos nunca vai no JSON. Escopo (ADR-002): fora = 404.
 */
final class AccountabilityLinks
{
    public const KINDS = ['monthly', 'annual'];
    public const EVENT = 'accountability.published';
    public const PATH = '/prestacao';
    /** Ordem dos documentos de cada lançamento no link. */
    public const DOCUMENT_ORDER = ['comprovante', 'conta', 'nota_fiscal', 'recibo', 'outro'];

    private PDO $pdo;
    private CondominiumScope $scope;
    private CashBook $book;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?CashBook $book = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->book = $book ?? new CashBook($this->pdo, $this->scope);
    }

    /** Token do link (32 caracteres hexadecimais, 128 bits aleatórios). */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function validToken(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    /** Endereço público do link (o host vem de url(), como a verificação de documentos). */
    public static function url(string $token): string
    {
        return url(self::PATH . '/' . $token);
    }

    /**
     * Livro do mês agrupado para o link (regra pura), a partir de CashBook::build().
     *
     * @param array $book CashBook::build() sem caminhos
     * @return array{competence:string, from:string, to:string, totals:array, banks:list<array>, sections:list<array>, documents:int, missing_proof:int}
     */
    public static function group(array $book): array
    {
        $byLine = [];
        foreach ($book['annexes'] ?? [] as $annex) {
            $byLine[(int)$annex['line']][] = $annex;
        }
        $order = array_flip(self::DOCUMENT_ORDER);
        $sections = [];
        $documents = 0;
        foreach (['receita' => 'Receitas recebidas no mês', 'despesa' => 'Despesas pagas no mês'] as $nature => $label) {
            $sections[$nature] = ['nature' => $nature, 'label' => $label, 'total_cents' => 0, 'entries' => 0, 'categories' => []];
        }
        foreach ($book['lines'] ?? [] as $line) {
            $nature = $line['nature'] === 'receita' ? 'receita' : 'despesa';
            $amount = (int)$line['amount_cents'];
            $code = (string)$line['account_code'];
            $section = &$sections[$nature];
            $section['total_cents'] += $amount;
            $section['entries']++;
            $section['categories'][$code] ??= ['code' => $code, 'name' => (string)$line['account_name'], 'total_cents' => 0, 'entries' => 0, 'groups' => []];
            $category = &$section['categories'][$code];
            $category['total_cents'] += $amount;
            $category['entries']++;
            $counterpart = $line['counterpart'] ?? null;
            $fromUnit = $nature === 'receita' && is_string($counterpart) && str_starts_with($counterpart, 'Unidade ');
            if ($fromUnit) {
                // Recebimento de unidade: só a soma (sem unidade, nome nem anexo).
                $category['groups']['~units'] ??= ['name' => 'Unidades do condomínio', 'total_cents' => 0, 'units' => [], 'items' => []];
                $group = &$category['groups']['~units'];
                $group['total_cents'] += $amount;
                $group['units'][$counterpart] = true;
                unset($group);
                unset($category, $section);
                continue;
            }
            $name = is_string($counterpart) && trim($counterpart) !== '' ? $counterpart : ($nature === 'receita' ? 'Outras receitas' : 'Sem fornecedor informado');
            $key = mb_strtolower($name);
            $category['groups'][$key] ??= ['name' => $name, 'total_cents' => 0, 'items' => []];
            $docs = $byLine[(int)$line['seq']] ?? [];
            usort($docs, static fn (array $a, array $b): int => [$order[$a['kind']] ?? 99, (int)$a['number']] <=> [$order[$b['kind']] ?? 99, (int)$b['number']]);
            $docs = array_map(static fn (array $a): array => [
                'id' => (int)$a['id'], 'kind' => (string)$a['kind'], 'kind_label' => (string)($a['kind_label'] ?? $a['kind']),
                'name' => (string)$a['original_name'], 'mime' => (string)$a['mime'], 'size_bytes' => (int)$a['size_bytes'],
            ], $docs);
            $documents += count($docs);
            $category['groups'][$key]['total_cents'] += $amount;
            $category['groups'][$key]['items'][] = [
                'seq' => (int)$line['seq'], 'paid_on' => (string)$line['paid_on'], 'description' => (string)$line['description'],
                'document_number' => $line['document_number'] ?? null, 'amount_cents' => $amount,
                'proof' => array_intersect(array_column($docs, 'kind'), CashBook::PROOF_KINDS) !== [], 'documents' => $docs,
            ];
            unset($category, $section);
        }
        foreach ($sections as &$section) {
            ksort($section['categories'], SORT_STRING);
            foreach ($section['categories'] as &$category) {
                $groups = $category['groups'];
                $units = $groups['~units'] ?? null;
                unset($groups['~units']);
                ksort($groups, SORT_STRING);
                if ($units !== null) {
                    $count = count($units['units']);
                    $groups[] = ['name' => $units['name'], 'total_cents' => $units['total_cents'], 'items' => [], 'units' => $count,
                        'summary' => sprintf('Recebido de %d unidade%s', $count, $count === 1 ? '' : 's')];
                }
                $category['groups'] = array_values($groups);
            }
            unset($category);
            $section['categories'] = array_values($section['categories']);
        }
        unset($section);
        return [
            'competence' => (string)$book['competence'], 'from' => (string)$book['from'], 'to' => (string)$book['to'],
            'totals' => [
                'opening_cents' => (int)$book['opening_cents'], 'in_cents' => (int)$book['in_cents'], 'out_cents' => (int)$book['out_cents'],
                'closing_cents' => (int)$book['closing_cents'], 'banks_closing_cents' => (int)$book['banks_closing_cents'], 'reconciled' => (bool)$book['reconciled'],
            ],
            'banks' => array_map(static fn (array $b): array => ['name' => (string)$b['name'], 'opening_cents' => (int)$b['opening_cents'],
                'received_cents' => (int)$b['received_cents'], 'paid_cents' => (int)$b['paid_cents'], 'closing_cents' => (int)$b['closing_cents']], $book['banks'] ?? []),
            'sections' => array_values($sections),
            'documents' => $documents,
            'missing_proof' => count($book['missing_proof'] ?? []),
        ];
    }

    /**
     * Livro do ano (regra pura): os meses agrupados e o resumo do ano por categoria.
     *
     * @param list<array> $months group() de cada mês, em ordem
     */
    public static function annual(string $year, array $months): array
    {
        $summary = [];
        $in = $out = $documents = $missing = 0;
        foreach ($months as $month) {
            $in += (int)$month['totals']['in_cents'];
            $out += (int)$month['totals']['out_cents'];
            $documents += (int)$month['documents'];
            $missing += (int)$month['missing_proof'];
            foreach ($month['sections'] as $section) {
                foreach ($section['categories'] as $category) {
                    $key = $section['nature'] . '|' . $category['code'];
                    $summary[$key] ??= ['nature' => $section['nature'], 'code' => $category['code'], 'name' => $category['name'], 'total_cents' => 0, 'entries' => 0];
                    $summary[$key]['total_cents'] += (int)$category['total_cents'];
                    $summary[$key]['entries'] += (int)$category['entries'];
                }
            }
        }
        uasort($summary, static fn (array $a, array $b): int => [$a['nature'] === 'receita' ? 0 : 1, $a['code']] <=> [$b['nature'] === 'receita' ? 0 : 1, $b['code']]);
        $first = $months[0]['totals'] ?? null;
        $last = $months !== [] ? $months[count($months) - 1]['totals'] : null;
        return [
            'year' => $year,
            'totals' => ['opening_cents' => (int)($first['opening_cents'] ?? 0), 'in_cents' => $in, 'out_cents' => $out,
                'closing_cents' => (int)($last['closing_cents'] ?? 0), 'reconciled' => $months !== [] && !in_array(false, array_map(static fn (array $m): bool => (bool)$m['totals']['reconciled'], $months), true)],
            'categories' => array_values($summary),
            'documents' => $documents,
            'missing_proof' => $missing,
            'months' => $months,
        ];
    }

    /** Ids dos documentos que o livro congelado mostra (regra pura). @return list<int> */
    public static function documentIds(array $payload): array
    {
        $ids = [];
        foreach ($payload['kind'] === 'annual' ? $payload['book']['months'] : [$payload['book']] as $month) {
            foreach ($month['sections'] as $section) {
                foreach ($section['categories'] as $category) {
                    foreach ($category['groups'] as $group) {
                        foreach ($group['items'] as $item) {
                            foreach ($item['documents'] as $doc) {
                                $ids[] = (int)$doc['id'];
                            }
                        }
                    }
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /** Período normalizado (regra pura): AAAA-MM no mensal, AAAA no anual. */
    public static function period(string $kind, mixed $value): string
    {
        $value = is_string($value) || is_int($value) ? trim((string)$value) : '';
        if ($kind === 'annual') {
            if (!preg_match('/^(19|20)\d{2}$/', $value)) {
                throw new InvalidArgumentException('Informe o ano do livro (AAAA).');
            }
            return $value;
        }
        if ($kind !== 'monthly') {
            throw new InvalidArgumentException('Tipo inválido: use monthly ou annual.');
        }
        if (!RecurringEntries::isCompetence($value)) {
            throw new InvalidArgumentException('Informe o mês do livro (AAAA-MM).');
        }
        return $value;
    }

    /**
     * Gera (ou atualiza) o link do mês ou do ano. Devolve o link com 'created' e 'changed'.
     */
    public function generate(User $user, ?int $condominiumId, mixed $kind, mixed $period): array
    {
        $kind = is_string($kind) ? $kind : 'monthly';
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Tipo inválido: use monthly ou annual.');
        }
        $period = self::period($kind, $period);
        if ($condominiumId === null || $condominiumId < 1) {
            throw new InvalidArgumentException('Informe o condomínio.');
        }
        $this->scope->assertAccess($user, $condominiumId);
        $payload = $kind === 'annual' ? $this->annualPayload($user, $condominiumId, $period) : $this->monthlyPayload($user, $condominiumId, $period);
        $json = AccountabilityPublications::canonical($payload);
        $sha = hash('sha256', $json);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM erp_accountability_links WHERE condominium_id = :c AND kind = :k AND competence = :p FOR UPDATE');
            $stmt->execute(['c' => $condominiumId, 'k' => $kind, 'p' => $period]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $created = $before === null;
            $changed = true;
            if ($before === null) {
                $this->pdo->prepare('INSERT INTO erp_accountability_links (condominium_id, kind, competence, token, status, version, sha256, payload_json, generated_by, generated_at)
                    VALUES (:c, :k, :p, :t, \'active\', 1, :s, :j, :u, NOW())')
                    ->execute(['c' => $condominiumId, 'k' => $kind, 'p' => $period, 't' => self::newToken(), 's' => $sha, 'j' => $json, 'u' => (int)$user->id]);
                $id = (int)$this->pdo->lastInsertId();
            } elseif ($before['status'] === 'revoked') {
                // Revogado: endereço novo, o antigo continua desligado.
                $id = (int)$before['id'];
                $this->pdo->prepare('UPDATE erp_accountability_links SET status = \'active\', token = :t, version = version + 1, sha256 = :s, payload_json = :j,
                        generated_by = :u, generated_at = NOW(), sent_version = NULL, sent_at = NULL WHERE id = :id')
                    ->execute(['t' => self::newToken(), 's' => $sha, 'j' => $json, 'u' => (int)$user->id, 'id' => $id]);
            } elseif (hash_equals((string)$before['sha256'], $sha)) {
                $id = (int)$before['id'];
                $changed = false;
            } else {
                $id = (int)$before['id'];
                $this->pdo->prepare('UPDATE erp_accountability_links SET version = version + 1, sha256 = :s, payload_json = :j, generated_by = :u, generated_at = NOW() WHERE id = :id')
                    ->execute(['s' => $sha, 'j' => $json, 'u' => (int)$user->id, 'id' => $id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        $after = $this->row($id);
        if ($changed) {
            Audit::record($created ? 'create' : 'update', 'erp_accountability_links', $id,
                $created ? null : ['status' => $before['status'], 'version' => (int)$before['version']],
                ['kind' => $kind, 'competence' => $period, 'status' => 'active', 'version' => (int)$after['version'], 'sha256' => $sha]);
        }
        return ['created' => $created, 'changed' => $changed] + self::present($after);
    }

    /**
     * Depois de fechar o mês: gera o link do mês e envia aos moradores; em dezembro, também o do ano. Nunca lança:
     * o fechamento já foi gravado. Devolve o que foi feito (para a mensagem da tela).
     *
     * @return array{monthly:?array, annual:?array, errors:list<string>}
     */
    public function afterClose(User $user, int $condominiumId, string $competence): array
    {
        $done = ['monthly' => null, 'annual' => null, 'errors' => []];
        $jobs = [['monthly', $competence]];
        if (str_ends_with($competence, '-12')) {
            $jobs[] = ['annual', substr($competence, 0, 4)];
        }
        foreach ($jobs as [$kind, $period]) {
            try {
                $link = $this->generate($user, $condominiumId, $kind, $period);
                $sent = $this->notifyResidents($user, (int)$link['id']);
                $done[$kind] = $link + ['notified_units' => $sent['units']];
            } catch (DomainException | InvalidArgumentException $error) {
                $done['errors'][] = $error->getMessage();   // ex.: o ano ainda tem mês aberto
            } catch (\Throwable $error) {
                AppLogger::exception($error, 'erp', ['event_type' => 'accountability_link_after_close_failed', 'condominium_id' => $condominiumId, 'kind' => $kind]);
                $done['errors'][] = 'Não foi possível gerar o link da prestação de contas agora. Gere pelo livro caixa.';
            }
        }
        return $done;
    }

    /** Frase para a tela depois de fechar o mês (regra pura). */
    public static function closeMessage(array $done): string
    {
        $parts = [];
        foreach (['monthly' => 'O link da prestação de contas do mês', 'annual' => 'O livro anual'] as $kind => $label) {
            $link = $done[$kind] ?? null;
            if ($link === null) {
                continue;
            }
            $units = (int)($link['notified_units'] ?? 0);
            $parts[] = $label . ' está pronto' . ($units > 0 ? sprintf(' e foi para a fila de e-mails de %d unidade%s.', $units, $units === 1 ? '' : 's') : '.');
        }
        foreach ($done['errors'] ?? [] as $error) {
            $parts[] = (string)$error;
        }
        return implode(' ', $parts);
    }

    /**
     * Envia o link aos moradores: um aviso por unidade ativa do condomínio do link. A mesma versão não é enviada
     * duas vezes; uma versão corrigida pode ser enviada de novo.
     *
     * @return array{units:int, version:int, already_sent:bool}
     */
    public function notifyResidents(User $user, int $linkId): array
    {
        $link = $this->scoped($user, $linkId);
        if ($link['status'] !== 'active') {
            throw new DomainException('O link foi revogado. Gere de novo antes de enviar.');
        }
        $version = (int)$link['version'];
        if ((int)($link['sent_version'] ?? 0) === $version) {
            return ['units' => 0, 'version' => $version, 'already_sent' => true];
        }
        $condominium = (int)$link['condominium_id'];
        $stmt = $this->pdo->prepare("SELECT id, label FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active' ORDER BY id");
        $stmt->execute(['c' => $condominium]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $outbox = new NotificationOutbox($this->pdo);
        $count = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($units as $unit) {
                $count += $outbox->record(self::EVENT, "accountability:{$linkId}:v{$version}:{$unit['id']}", $condominium, 'erp_accountability_links', $linkId,
                    ['accountability_link_id' => $linkId, 'unit_id' => (int)$unit['id'], 'kind' => (string)$link['kind'], 'period' => (string)$link['competence'],
                        'version' => $version], (int)$user->id) ? 1 : 0;
            }
            $this->pdo->prepare('UPDATE erp_accountability_links SET sent_version = :v, sent_at = NOW() WHERE id = :id')->execute(['v' => $version, 'id' => $linkId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('notify', 'erp_accountability_links', $linkId, null, ['version' => $version, 'units' => $count]);
        return ['units' => $count, 'version' => $version, 'already_sent' => false];
    }

    /** Links de um condomínio (sem o conteúdo), do mais novo para o mais antigo. */
    public function list(User $user, ?int $condominiumId, mixed $kind = null, mixed $period = null): array
    {
        if ($condominiumId === null || $condominiumId < 1) {
            throw new InvalidArgumentException('Informe o condomínio.');
        }
        $this->scope->assertAccess($user, $condominiumId);
        $sql = 'SELECT * FROM erp_accountability_links WHERE condominium_id = :c';
        $params = ['c' => $condominiumId];
        if ($kind !== null && $kind !== '') {
            if (!in_array($kind, self::KINDS, true)) {
                throw new InvalidArgumentException('Tipo inválido: use monthly ou annual.');
            }
            $sql .= ' AND kind = :k';
            $params['k'] = $kind;
            if ($period !== null && $period !== '') {
                $sql .= ' AND competence = :p';
                $params['p'] = self::period((string)$kind, $period);
            }
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY competence DESC, kind, id DESC LIMIT 200');
        $stmt->execute($params);
        return ['rows' => array_map([self::class, 'present'], $stmt->fetchAll(PDO::FETCH_ASSOC))];
    }

    /** Link de um período, ou null (tela do livro caixa). */
    public function forPeriod(User $user, int $condominiumId, string $kind, string $period): ?array
    {
        $rows = $this->list($user, $condominiumId, $kind, $period)['rows'];
        return $rows[0] ?? null;
    }

    public function revoke(User $user, int $linkId, mixed $reason): array
    {
        $reason = is_string($reason) ? trim((string)preg_replace('/\s+/u', ' ', $reason)) : '';
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (3 a 255 caracteres).');
        }
        $link = $this->scoped($user, $linkId);
        if ($link['status'] !== 'active') {
            throw new DomainException('O link já está revogado.');
        }
        $this->pdo->prepare("UPDATE erp_accountability_links SET status = 'revoked', revoked_by = :u, revoked_at = NOW(), revoke_reason = :r WHERE id = :id AND status = 'active'")
            ->execute(['u' => (int)$user->id, 'r' => $reason, 'id' => $linkId]);
        Audit::record('update', 'erp_accountability_links', $linkId, ['status' => 'active'], ['status' => 'revoked', 'revoke_reason' => $reason]);
        return self::present($this->row($linkId));
    }

    /**
     * O que o link público mostra, ou null (token inválido, revogado ou conteúdo alterado no banco). Conta a visita.
     */
    public function publicView(mixed $token): ?array
    {
        $link = $this->byToken($token);
        if ($link === null) {
            return null;
        }
        $this->pdo->prepare('UPDATE erp_accountability_links SET view_count = view_count + 1, last_viewed_at = NOW() WHERE id = :id')->execute(['id' => (int)$link['id']]);
        $payload = json_decode((string)$link['payload_json'], true);
        return [
            'kind' => (string)$link['kind'], 'period' => (string)$link['competence'], 'version' => (int)$link['version'],
            'generated_at' => (string)$link['generated_at'], 'under_review' => $this->underReview($link),
            'condominium' => $payload['condominium'] ?? [], 'book' => $payload['book'] ?? [],
        ];
    }

    /**
     * Documento do link público para download. Só os que estão no livro congelado.
     *
     * @return array{path:string, mime:string, name:string, size:int}|null
     */
    public function publicFile(mixed $token, int $attachmentId, ?VirtualDisk $disk = null): ?array
    {
        $link = $this->byToken($token);
        if ($link === null) {
            return null;
        }
        $payload = json_decode((string)$link['payload_json'], true);
        if (!is_array($payload) || !in_array($attachmentId, self::documentIds($payload), true)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT d.path, d.mime, d.original_name, d.size_bytes, d.scan_status FROM erp_entry_attachments a
            JOIN erp_documents d ON d.id = a.document_id
            WHERE a.id = :id AND a.condominium_id = :c AND a.deleted_at IS NULL AND d.deleted_at IS NULL AND d.condominium_id = :c2');
        $stmt->execute(['id' => $attachmentId, 'c' => (int)$link['condominium_id'], 'c2' => (int)$link['condominium_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !VirtualDisk::downloadable((string)($row['scan_status'] ?? ScanStatus::UNSCANNED))) {
            return null;
        }
        $path = ($disk ?? new VirtualDisk($this->pdo, $this->scope))->absolute((string)$row['path']);
        if ($path === null) {
            return null;
        }
        return ['path' => $path, 'mime' => (string)$row['mime'], 'name' => (string)$row['original_name'], 'size' => (int)$row['size_bytes']];
    }

    private function monthlyPayload(User $user, int $condominiumId, string $competence): array
    {
        $book = $this->book->build($user, $condominiumId, $competence);
        if (!$book['closed']) {
            throw new DomainException("Feche o mês {$competence} antes de gerar o link da prestação de contas.");
        }
        return ['kind' => 'monthly', 'period' => $competence, 'condominium' => self::publicCondominium($book['condominium']), 'book' => self::group($book)];
    }

    private function annualPayload(User $user, int $condominiumId, string $year): array
    {
        $open = $this->openMonths($condominiumId, $year);
        if ($open !== []) {
            throw new DomainException('Para gerar o livro do ano, feche todos os meses de ' . $year . '. Ainda abertos: '
                . implode(', ', array_map(static fn (string $m): string => substr($m, 5, 2) . '/' . $year, $open)) . '.');
        }
        $months = [];
        $condominium = [];
        for ($m = 1; $m <= 12; $m++) {
            $book = $this->book->build($user, $condominiumId, sprintf('%s-%02d', $year, $m));
            $condominium = $book['condominium'];
            $months[] = self::group($book);
        }
        return ['kind' => 'annual', 'period' => $year, 'condominium' => self::publicCondominium($condominium), 'book' => self::annual($year, $months)];
    }

    /** Meses do ano ainda não fechados. @return list<string> */
    private function openMonths(int $condominiumId, string $year): array
    {
        $stmt = $this->pdo->prepare("SELECT competence FROM erp_month_closings WHERE condominium_id = :c AND competence LIKE :y AND status = 'closed'");
        $stmt->execute(['c' => $condominiumId, 'y' => $year . '-%']);
        $closed = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        $open = [];
        for ($m = 1; $m <= 12; $m++) {
            $competence = sprintf('%s-%02d', $year, $m);
            if (!isset($closed[$competence])) {
                $open[] = $competence;
            }
        }
        return $open;
    }

    /** Mês (ou algum mês do ano) reaberto para correção depois da geração. */
    private function underReview(array $link): bool
    {
        if ($link['kind'] === 'annual') {
            return $this->openMonths((int)$link['condominium_id'], (string)$link['competence']) !== [];
        }
        $stmt = $this->pdo->prepare('SELECT status FROM erp_month_closings WHERE condominium_id = :c AND competence = :m');
        $stmt->execute(['c' => (int)$link['condominium_id'], 'm' => (string)$link['competence']]);
        return $stmt->fetchColumn() !== 'closed';
    }

    private function byToken(mixed $token): ?array
    {
        if (!self::validToken($token)) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM erp_accountability_links WHERE token = :t AND status = 'active'");
        $stmt->execute(['t' => $token]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($link) || !hash_equals((string)$link['token'], (string)$token)) {
            return null;
        }
        if (!hash_equals((string)$link['sha256'], hash('sha256', (string)$link['payload_json']))) {
            AppLogger::log('warning', 'Link da prestação de contas com conteúdo alterado fora do sistema', ['event_type' => 'accountability_link_tampered',
                'link_id' => (int)$link['id']], 'erp');
            return null;
        }
        return $link;
    }

    private function scoped(User $user, int $linkId): array
    {
        $row = $this->row($linkId, false);
        if ($row === null || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new CondominiumAccessDenied();
        }
        return $row;
    }

    private function row(int $id, bool $required = true): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_accountability_links WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            if ($required) {
                throw new CondominiumAccessDenied();
            }
            return null;
        }
        return $row;
    }

    /** Dados do condomínio que o link mostra (nome, razão social, CNPJ e endereço do condomínio). */
    private static function publicCondominium(array $condominium): array
    {
        return array_intersect_key($condominium, array_flip(['name', 'legal_name', 'document', 'address']));
    }

    public static function present(array $row): array
    {
        $active = $row['status'] === 'active';
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'kind' => (string)$row['kind'], 'period' => (string)$row['competence'],
            'status' => (string)$row['status'], 'version' => (int)$row['version'], 'sha256' => (string)$row['sha256'],
            'url' => $active ? self::url((string)$row['token']) : null,
            'generated_by' => $row['generated_by'] !== null ? (int)$row['generated_by'] : null, 'generated_at' => $row['generated_at'],
            'sent_version' => $row['sent_version'] !== null ? (int)$row['sent_version'] : null, 'sent_at' => $row['sent_at'],
            'view_count' => (int)$row['view_count'], 'last_viewed_at' => $row['last_viewed_at'],
            'revoked_by' => $row['revoked_by'] !== null ? (int)$row['revoked_by'] : null, 'revoked_at' => $row['revoked_at'], 'revoke_reason' => $row['revoke_reason'],
        ];
    }
}
