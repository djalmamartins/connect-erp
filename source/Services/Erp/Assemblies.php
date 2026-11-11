<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Audit;

/**
 * Assembleia e votação (ADR 0088).
 *
 * - create() (AP-018): rascunho com a pauta; cada item tem a base do voto (fração ideal ou uma unidade,
 *   um voto) e o quórum (simples, absoluto, dois terços ou unanimidade).
 * - convene(): edital pelo comunicado da outbox (ADR 0059), com pelo menos MIN_NOTICE_DAYS dias até a
 *   primeira convocação. Nada é enviado aqui: fora de produção a fila só registra em log ou caixa de teste.
 * - open(), checkIn() (AP-088): presença por unidade, com procuração no Disco Virtual. A unidade com conta
 *   vencida em aberto na abertura fica registrada como presente, mas não vota (Código Civil, art. 1.335, III).
 * - startVoting(), vote(), closeItem() (AP-017): um item em votação por vez; um voto por unidade e item, com
 *   o peso do momento; a apuração usa decide() sobre o total do condomínio.
 * - close() (AP-092): só com todos os itens apurados; gera a ata com o sha256. Depois de fechada, nada muda.
 * - cancel(): rascunho ou convocada; para o edital que ainda não saiu.
 *
 * Escopo por condomínio (ADR-002); toda mudança vai para a auditoria.
 */
final class Assemblies
{
    public const MIN_NOTICE_DAYS = 8;
    public const MAX_ITEMS = 20;
    public const MAX_LIST = 200;
    /** Teto da lista de presença (uma linha por unidade; ADR 0094). */
    public const MAX_ATTENDANCE = 5000;
    /** Pesos em milionésimos: a fração ideal tem 6 casas (decimal 9,6) e "uma unidade" vale 1.000000. */
    public const SCALE = 1000000;
    public const KINDS = ['ordinaria', 'extraordinaria'];
    public const MODES = ['presencial', 'virtual', 'hibrida'];
    public const BASES = ['fraction', 'unit'];
    public const QUORUMS = ['simple', 'absolute', 'two_thirds', 'unanimity'];
    public const CHOICES = ['yes', 'no', 'abstain'];
    public const QUORUM_LABELS = ['simple' => 'maioria simples dos votos', 'absolute' => 'maioria absoluta do condomínio',
        'two_thirds' => 'dois terços do condomínio', 'unanimity' => 'unanimidade'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Apuração (regra pura), com pesos em milionésimos:
     * - simple: mais sim do que não entre os votos (abstenção não conta);
     * - absolute: sim acima da metade do total do condomínio;
     * - two_thirds: sim em pelo menos dois terços do total;
     * - unanimity: sim igual ao total.
     *
     * @return array{approved:bool, yes:int, no:int, abstain:int, total:int, quorum:string}
     */
    public static function decide(int $yes, int $no, int $abstain, int $total, string $quorum): array
    {
        if (!in_array($quorum, self::QUORUMS, true)) {
            throw new InvalidArgumentException('Quórum inválido.');
        }
        if ($yes < 0 || $no < 0 || $abstain < 0 || $total < 0 || $yes + $no + $abstain > $total) {
            throw new InvalidArgumentException('Totais da apuração inconsistentes.');
        }
        $approved = $total > 0 && match ($quorum) {
            'simple' => $yes > $no,
            'absolute' => $yes * 2 > $total,
            'two_thirds' => $yes * 3 >= $total * 2,
            'unanimity' => $yes === $total,
        };
        return ['approved' => $approved, 'yes' => $yes, 'no' => $no, 'abstain' => $abstain, 'total' => $total, 'quorum' => $quorum];
    }

    /** Peso (regra pura) em milionésimos: a fração ideal da unidade, ou 1 por unidade. */
    public static function weight(string $basis, mixed $fraction): int
    {
        if ($basis === 'unit') {
            return self::SCALE;
        }
        $value = is_numeric($fraction) ? (int)round((float)$fraction * self::SCALE) : 0;
        if ($value <= 0 || $value > self::SCALE) {
            throw new DomainException('A unidade não tem fração ideal cadastrada: o item votado por fração não aceita o voto.');
        }
        return $value;
    }

    /** Data e hora (regra pura): "AAAA-MM-DD HH:MM" ou "AAAA-MM-DDTHH:MM". */
    public static function dateTime(mixed $value, string $what): \DateTimeImmutable
    {
        $text = is_string($value) ? substr(str_replace('T', ' ', trim($value)), 0, 16) : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $text);
        if ($date === false || $date->format('Y-m-d H:i') !== $text) {
            throw new InvalidArgumentException("Informe {$what} (AAAA-MM-DD HH:MM).");
        }
        return $date;
    }

    /**
     * Pauta (regra pura): de 1 a MAX_ITEMS itens, cada um com título, descrição opcional, base e quórum.
     *
     * @return list<array{title:string, description:?string, vote_basis:string, quorum:string}>
     */
    public static function agenda(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > self::MAX_ITEMS) {
            throw new InvalidArgumentException(sprintf('A pauta precisa de 1 a %d itens.', self::MAX_ITEMS));
        }
        $items = [];
        foreach (array_values($raw) as $i => $item) {
            $item = is_array($item) ? $item : [];
            $n = $i + 1;
            $basis = $item['vote_basis'] ?? null;
            $quorum = $item['quorum'] ?? null;
            if (!in_array($basis, self::BASES, true)) {
                throw new InvalidArgumentException("Item {$n}: escolha a base do voto (fraction ou unit).");
            }
            if (!in_array($quorum, self::QUORUMS, true)) {
                throw new InvalidArgumentException("Item {$n}: escolha o quórum (simple, absolute, two_thirds ou unanimity).");
            }
            $description = $item['description'] ?? null;
            $items[] = [
                'title' => Announcements::line($item['title'] ?? null, 3, 160, "o título do item {$n}"),
                'description' => $description === null || $description === '' ? null : Announcements::text($description, 1, 2000, "a descrição do item {$n}"),
                'vote_basis' => (string)$basis, 'quorum' => (string)$quorum,
            ];
        }
        return $items;
    }

    /** Texto do edital (regra pura). */
    public static function noticeText(array $assembly, array $items): string
    {
        $lines = [
            sprintf('Ficam convocados os condôminos para a assembleia geral %s, %s.', $assembly['kind'], $assembly['mode']),
            'Local: ' . $assembly['location'],
            'Primeira convocação: ' . substr((string)$assembly['first_call_at'], 0, 16),
        ];
        if (!empty($assembly['second_call_at'])) {
            $lines[] = 'Segunda convocação: ' . substr((string)$assembly['second_call_at'], 0, 16);
        }
        $lines[] = '';
        $lines[] = 'Ordem do dia:';
        foreach ($items as $i => $item) {
            $lines[] = sprintf('%d. %s (%s, %s)', $i + 1, $item['title'], $item['vote_basis'] === 'fraction' ? 'voto por fração ideal' : 'um voto por unidade',
                self::QUORUM_LABELS[$item['quorum']]);
        }
        $lines[] = '';
        $lines[] = 'Unidade com conta vencida em aberto pode participar, mas não vota (Código Civil, art. 1.335, III). Procuração deve ser apresentada no início.';
        return implode("\n", $lines);
    }

    /**
     * Ata (regra pura): o que aconteceu, com presença por unidade e o resultado de cada item. Sem nome nem
     * contato de pessoa: os nomes ficam só no relatório de presença (AP-088).
     */
    public static function minutesText(array $assembly, array $attendance, array $items): string
    {
        $proxies = count(array_filter($attendance, static fn (array $a): bool => (bool)$a['is_proxy']));
        $blocked = array_values(array_filter($attendance, static fn (array $a): bool => !(bool)$a['eligible']));
        $lines = [
            sprintf('Ata da assembleia geral %s "%s" (%s).', $assembly['kind'], $assembly['title'], $assembly['mode']),
            'Local: ' . $assembly['location'],
            'Aberta em ' . substr((string)$assembly['opened_at'], 0, 16) . ' e encerrada em ' . substr((string)$assembly['closed_at'], 0, 16) . '.',
            sprintf('Unidades presentes: %d (%d por procuração). Sem direito a voto por conta vencida: %d%s.', count($attendance), $proxies, count($blocked),
                $blocked === [] ? '' : ' (' . implode(', ', array_map(static fn (array $a): string => (string)$a['unit_label'], $blocked)) . ')'),
            '',
        ];
        foreach ($items as $i => $item) {
            $result = json_decode((string)($item['result_json'] ?? ''), true) ?: [];
            $lines[] = sprintf('%d. %s', $i + 1, $item['title']);
            $lines[] = sprintf('   Base: %s. Quórum: %s.', $item['vote_basis'] === 'fraction' ? 'fração ideal' : 'uma unidade, um voto', self::QUORUM_LABELS[$item['quorum']]);
            $lines[] = sprintf('   Sim: %s. Não: %s. Abstenção: %s. Total do condomínio: %s. Votaram %d unidades.', self::share((int)($result['yes'] ?? 0), $item['vote_basis']),
                self::share((int)($result['no'] ?? 0), $item['vote_basis']), self::share((int)($result['abstain'] ?? 0), $item['vote_basis']),
                self::share((int)($result['total'] ?? 0), $item['vote_basis']), (int)($result['votes'] ?? 0));
            $lines[] = '   Resultado: ' . (!empty($result['approved']) ? 'APROVADO' : 'NÃO APROVADO') . '.';
        }
        return implode("\n", $lines);
    }

    /** Peso legível (regra pura): fração com 6 casas, ou número de unidades. */
    public static function share(int $weight, string $basis): string
    {
        return $basis === 'unit' ? (string)intdiv($weight, self::SCALE) : number_format($weight / self::SCALE, 6, ',', '.');
    }

    /**
     * @param array{condominium_id?:mixed, kind?:mixed, mode?:mixed, title?:mixed, location?:mixed, first_call_at?:mixed, second_call_at?:mixed, items?:mixed} $input
     */
    public function create(User $user, array $input): array
    {
        $condominium = $this->condominium($user, $input['condominium_id'] ?? null);
        $kind = $input['kind'] ?? null;
        $mode = $input['mode'] ?? null;
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Escolha o tipo: ordinaria ou extraordinaria.');
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Escolha o formato: presencial, virtual ou hibrida.');
        }
        $title = Announcements::line($input['title'] ?? null, 5, 160, 'o título');
        $location = Announcements::line($input['location'] ?? null, 3, 255, 'o local ou o link da sala');
        $first = self::dateTime($input['first_call_at'] ?? null, 'a primeira convocação');
        $second = null;
        if (($input['second_call_at'] ?? null) !== null && $input['second_call_at'] !== '') {
            $second = self::dateTime($input['second_call_at'], 'a segunda convocação');
            if ($second < $first->modify('+30 minutes')) {
                throw new InvalidArgumentException('A segunda convocação é pelo menos 30 minutos depois da primeira.');
            }
        }
        $items = self::agenda($input['items'] ?? null);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO erp_assemblies (condominium_id, kind, mode, title, location, first_call_at, second_call_at, created_by, updated_by)
                VALUES (:c, :k, :m, :t, :l, :f, :s, :u, :u2)')->execute(['c' => $condominium, 'k' => $kind, 'm' => $mode, 't' => $title, 'l' => $location,
                'f' => $first->format('Y-m-d H:i:s'), 's' => $second?->format('Y-m-d H:i:s'), 'u' => (int)$user->id, 'u2' => (int)$user->id]);
            $id = (int)$this->pdo->lastInsertId();
            $insert = $this->pdo->prepare('INSERT INTO erp_assembly_items (assembly_id, position, title, description, vote_basis, quorum) VALUES (:a, :p, :t, :d, :b, :q)');
            foreach ($items as $i => $item) {
                $insert->execute(['a' => $id, 'p' => $i + 1, 't' => $item['title'], 'd' => $item['description'], 'b' => $item['vote_basis'], 'q' => $item['quorum']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('create', 'erp_assemblies', $id, null, ['kind' => $kind, 'mode' => $mode, 'title' => $title, 'first_call_at' => $first->format('Y-m-d H:i'), 'items' => count($items)]);
        return $this->find($user, $id);
    }

    /**
     * Convoca: reserva a assembleia (draft → convened) e depois publica o edital pela outbox. Se o edital
     * falhar, a assembleia volta a rascunho. O comunicado abre a própria transação (ADR 0059).
     */
    public function convene(User $user, int $id, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $assembly = $this->row($user, $id);
        if ($assembly['status'] !== 'draft') {
            throw new DomainException('Só o rascunho pode ser convocado.');
        }
        if (new \DateTimeImmutable((string)$assembly['first_call_at']) < $now->modify('+' . self::MIN_NOTICE_DAYS . ' days')) {
            throw new DomainException(sprintf('O edital sai com pelo menos %d dias de antecedência: mude a data da primeira convocação.', self::MIN_NOTICE_DAYS));
        }
        if (!$this->transition($id, 'draft', 'convened', $user, 'convened_at')) {
            throw new DomainException('A assembleia mudou enquanto você convocava. Recarregue.');
        }
        try {
            $notice = (new Announcements($this->pdo, $this->scope))->announce($user, [
                'condominium_id' => (int)$assembly['condominium_id'],
                'subject' => mb_substr('Edital de convocação: ' . $assembly['title'], 0, 120),
                'body' => self::noticeText($assembly, $this->items($id)),
            ]);
        } catch (\Throwable $error) {
            $this->pdo->prepare("UPDATE erp_assemblies SET status = 'draft', convened_at = NULL WHERE id = :id AND status = 'convened' AND announcement_id IS NULL")->execute(['id' => $id]);
            throw $error;
        }
        $this->pdo->prepare('UPDATE erp_assemblies SET announcement_id = :n WHERE id = :id')->execute(['n' => (int)$notice['id'], 'id' => $id]);
        Audit::record('update', 'erp_assemblies', $id, ['status' => 'draft'], ['status' => 'convened', 'announcement_id' => (int)$notice['id']]);
        return $this->find($user, $id);
    }

    /** Abre a assembleia, a partir do horário da primeira convocação. */
    public function open(User $user, int $id, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $assembly = $this->row($user, $id);
        if ($assembly['status'] !== 'convened') {
            throw new DomainException('Só a assembleia convocada pode ser aberta.');
        }
        if ($now < new \DateTimeImmutable((string)$assembly['first_call_at'])) {
            throw new DomainException('A assembleia abre a partir do horário da primeira convocação.');
        }
        if (!$this->transition($id, 'convened', 'open', $user, 'opened_at', $now)) {
            throw new DomainException('A assembleia mudou enquanto você abria. Recarregue.');
        }
        Audit::record('update', 'erp_assemblies', $id, ['status' => 'convened'], ['status' => 'open']);
        return $this->find($user, $id);
    }

    /**
     * Presença (AP-088) de uma unidade ativa do condomínio. A procuração é um arquivo do Disco Virtual do
     * mesmo condomínio. A elegibilidade é conferida aqui: conta de receita vencida e não paga bloqueia o voto.
     *
     * @param array{unit_id?:mixed, attendee_name?:mixed, is_proxy?:mixed, proxy_document_id?:mixed} $input
     * @param bool $withDebt ADR 0095: o valor vencido só volta para quem tem erp.agreements.view; o direito a voto
     *                       e o motivo ("inadimplente") voltam sempre.
     */
    public function checkIn(User $user, int $id, array $input, ?\DateTimeImmutable $now = null, bool $withDebt = false): array
    {
        $now ??= new \DateTimeImmutable();
        $assembly = $this->openRow($user, $id);
        $condominium = (int)$assembly['condominium_id'];
        $unit = $this->unit($condominium, $input['unit_id'] ?? null);
        $name = Announcements::line($input['attendee_name'] ?? null, 3, 160, 'o nome de quem está presente');
        $proxy = filter_var($input['is_proxy'] ?? false, FILTER_VALIDATE_BOOL);
        $document = null;
        if ($proxy) {
            $document = filter_var($input['proxy_document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $stmt = $this->pdo->prepare('SELECT id FROM erp_documents WHERE id = :d AND condominium_id = :c AND deleted_at IS NULL');
            $stmt->execute(['d' => $document === false ? 0 : (int)$document, 'c' => $condominium]);
            if ($document === false || $stmt->fetchColumn() === false) {
                throw new InvalidArgumentException('Anexe a procuração no Disco Virtual do condomínio e informe o arquivo.');
            }
        }
        $overdue = $this->overdueCents($condominium, (int)$unit['id'], $now->format('Y-m-d'));
        try {
            $this->pdo->prepare('INSERT INTO erp_assembly_attendance (assembly_id, unit_id, attendee_name, is_proxy, proxy_document_id, eligible, overdue_cents, checked_in_at, created_by)
                VALUES (:a, :u, :n, :p, :d, :e, :o, :t, :by)')->execute(['a' => $id, 'u' => (int)$unit['id'], 'n' => $name, 'p' => $proxy ? 1 : 0,
                'd' => $document === null ? null : (int)$document, 'e' => $overdue === 0 ? 1 : 0, 'o' => $overdue, 't' => $now->format('Y-m-d H:i:s'), 'by' => (int)$user->id]);
        } catch (\PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new DomainException('Esta unidade já tem presença registrada.');
            }
            throw $error;
        }
        $attendance = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'erp_assembly_attendance', $attendance, null, ['assembly_id' => $id, 'unit_id' => (int)$unit['id'], 'is_proxy' => $proxy, 'eligible' => $overdue === 0]);
        return ['id' => $attendance, 'unit_id' => (int)$unit['id'], 'unit_label' => (string)$unit['label'], 'is_proxy' => $proxy, 'eligible' => $overdue === 0,
            'reason' => $overdue === 0 ? null : 'inadimplente', 'overdue_cents' => $withDebt ? $overdue : null];
    }

    /** Põe um item em votação; só um por vez. */
    public function startVoting(User $user, int $id, int $itemId): array
    {
        $this->openRow($user, $id);
        $item = $this->item($id, $itemId);
        if ($item['status'] !== 'pending') {
            throw new DomainException('Este item já foi votado ou está em votação.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT id FROM erp_assemblies WHERE id = :id FOR UPDATE')->execute(['id' => $id]);
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM erp_assembly_items WHERE assembly_id = :a AND status = 'voting'");
            $stmt->execute(['a' => $id]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new DomainException('Já há um item em votação: apure-o antes de abrir outro.');
            }
            $this->pdo->prepare("UPDATE erp_assembly_items SET status = 'voting' WHERE id = :i AND status = 'pending'")->execute(['i' => $itemId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_assembly_items', $itemId, ['status' => 'pending'], ['status' => 'voting']);
        return $this->find($user, $id);
    }

    /** Voto de uma unidade presente e quite no item em votação. */
    public function vote(User $user, int $id, int $itemId, mixed $unitId, mixed $choice): array
    {
        $assembly = $this->openRow($user, $id);
        $item = $this->item($id, $itemId);
        if ($item['status'] !== 'voting') {
            throw new DomainException('Este item não está em votação.');
        }
        if (!in_array($choice, self::CHOICES, true)) {
            throw new InvalidArgumentException('Escolha o voto: yes, no ou abstain.');
        }
        $unit = $this->unit((int)$assembly['condominium_id'], $unitId);
        $stmt = $this->pdo->prepare('SELECT eligible FROM erp_assembly_attendance WHERE assembly_id = :a AND unit_id = :u');
        $stmt->execute(['a' => $id, 'u' => (int)$unit['id']]);
        $eligible = $stmt->fetchColumn();
        if ($eligible === false) {
            throw new DomainException('A unidade não tem presença registrada.');
        }
        if ((int)$eligible !== 1) {
            throw new DomainException('Unidade com conta vencida em aberto não vota (Código Civil, art. 1.335, III).');
        }
        $weight = self::weight((string)$item['vote_basis'], $unit['ideal_fraction']);
        // ADR 0107: o item fica travado (compartilhado) até o voto gravar. A apuração trava o item com
        // exclusividade, então espera os votos em andamento e um voto que chega depois vê o item apurado.
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status FROM erp_assembly_items WHERE id = :i LOCK IN SHARE MODE');
            $lock->execute(['i' => $itemId]);
            if ($lock->fetchColumn() !== 'voting') {
                throw new DomainException('Este item não está em votação.');
            }
            $this->pdo->prepare('INSERT INTO erp_assembly_votes (assembly_id, item_id, unit_id, choice, weight, created_by) VALUES (:a, :i, :u, :c, :w, :by)')
                ->execute(['a' => $id, 'i' => $itemId, 'u' => (int)$unit['id'], 'c' => $choice, 'w' => number_format($weight / self::SCALE, 6, '.', ''), 'by' => (int)$user->id]);
            $vote = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof \PDOException && $error->getCode() === '23000') {
                throw new DomainException('Esta unidade já votou neste item.');
            }
            throw $error;
        }
        Audit::record('create', 'erp_assembly_votes', $vote, null, ['assembly_id' => $id, 'item_id' => $itemId, 'unit_id' => (int)$unit['id'], 'choice' => $choice]);
        return ['id' => $vote, 'item_id' => $itemId, 'unit_id' => (int)$unit['id'], 'choice' => $choice, 'weight' => self::share($weight, (string)$item['vote_basis'])];
    }

    /** Apura o item em votação contra o total do condomínio (soma das frações ou número de unidades ativas). */
    public function closeItem(User $user, int $id, int $itemId): array
    {
        $assembly = $this->openRow($user, $id);
        $item = $this->item($id, $itemId);
        if ($item['status'] !== 'voting') {
            throw new DomainException('Este item não está em votação.');
        }
        // ADR 0107: trava o item antes de somar, para nenhum voto entrar entre a soma e a apuração.
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status FROM erp_assembly_items WHERE id = :i FOR UPDATE');
            $lock->execute(['i' => $itemId]);
            if ($lock->fetchColumn() !== 'voting') {
                throw new DomainException('O item mudou enquanto você apurava. Recarregue.');
            }
            $stmt = $this->pdo->prepare('SELECT choice, ROUND(SUM(weight) * ' . self::SCALE . ') AS w, COUNT(*) AS n FROM erp_assembly_votes WHERE item_id = :i GROUP BY choice');
            $stmt->execute(['i' => $itemId]);
            $sum = ['yes' => 0, 'no' => 0, 'abstain' => 0];
            $votes = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sum[(string)$row['choice']] = (int)$row['w'];
                $votes += (int)$row['n'];
            }
            $total = $this->totalWeight((int)$assembly['condominium_id'], (string)$item['vote_basis']);
            $result = self::decide($sum['yes'], $sum['no'], $sum['abstain'], max($total, $sum['yes'] + $sum['no'] + $sum['abstain']), (string)$item['quorum']) + ['votes' => $votes];
            $this->pdo->prepare("UPDATE erp_assembly_items SET status = 'decided', result_json = :r, decided_at = NOW() WHERE id = :i AND status = 'voting'")
                ->execute(['r' => json_encode($result, JSON_THROW_ON_ERROR), 'i' => $itemId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_assembly_items', $itemId, ['status' => 'voting'], ['status' => 'decided', 'result' => $result]);
        return $this->find($user, $id);
    }

    /** Encerra: todos os itens apurados, ata gerada com sha256. A partir daqui a assembleia não muda mais. */
    public function close(User $user, int $id, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $assembly = $this->openRow($user, $id);
        $items = $this->items($id);
        if (array_filter($items, static fn (array $i): bool => $i['status'] !== 'decided') !== []) {
            throw new DomainException('Apure todos os itens da pauta antes de encerrar.');
        }
        $assembly['closed_at'] = $now->format('Y-m-d H:i:s');
        $minutes = self::minutesText($assembly, $this->attendance($id), $items);
        $hash = hash('sha256', $minutes);
        $stmt = $this->pdo->prepare("UPDATE erp_assemblies SET status = 'closed', closed_at = :t, minutes = :m, minutes_sha256 = :h, updated_by = :u WHERE id = :id AND status = 'open'");
        $stmt->execute(['t' => $assembly['closed_at'], 'm' => $minutes, 'h' => $hash, 'u' => (int)$user->id, 'id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('A assembleia mudou enquanto você encerrava. Recarregue.');
        }
        Audit::record('update', 'erp_assemblies', $id, ['status' => 'open'], ['status' => 'closed', 'minutes_sha256' => $hash]);
        return $this->find($user, $id);
    }

    /** Cancela o rascunho ou a assembleia convocada; o edital que ainda não saiu é parado. */
    public function cancel(User $user, int $id, mixed $reason): array
    {
        $reason = Announcements::line($reason, 5, 255, 'o motivo do cancelamento');
        $assembly = $this->row($user, $id);
        if (!in_array($assembly['status'], ['draft', 'convened'], true)) {
            throw new DomainException('Só o rascunho ou a assembleia convocada podem ser cancelados.');
        }
        $stmt = $this->pdo->prepare("UPDATE erp_assemblies SET status = 'cancelled', cancel_reason = :r, updated_by = :u WHERE id = :id AND status = :s");
        $stmt->execute(['r' => $reason, 'u' => (int)$user->id, 'id' => $id, 's' => $assembly['status']]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('A assembleia mudou enquanto você cancelava. Recarregue.');
        }
        $stopped = 0;
        if ($assembly['announcement_id'] !== null) {
            try {
                $stopped = (int)((new Announcements($this->pdo, $this->scope))->cancel($user, (int)$assembly['announcement_id'], $reason)['stopped'] ?? 0);
            } catch (DomainException) {
                // o edital já estava cancelado
            }
        }
        Audit::record('update', 'erp_assemblies', $id, ['status' => $assembly['status']], ['status' => 'cancelled', 'cancel_reason' => $reason, 'notice_stopped' => $stopped]);
        return $this->find($user, $id);
    }

    /** @return array{data:list<array>, total:int} */
    public function list(User $user, ?int $condominiumId = null): array
    {
        $ids = $this->ids($user, $condominiumId);
        if ($ids === []) {
            return ['data' => [], 'total' => 0];
        }
        $rows = $this->pdo->query('SELECT a.*, c.name AS condominium_name FROM erp_assemblies a JOIN condominiums c ON c.id = a.condominium_id
            WHERE a.condominium_id IN (' . implode(',', $ids) . ') ORDER BY a.first_call_at DESC, a.id DESC LIMIT ' . self::MAX_LIST)->fetchAll(PDO::FETCH_ASSOC);
        $data = array_map(fn (array $r): array => $this->present($r), $rows);
        return ['data' => $data, 'total' => count($data)];
    }

    public function find(User $user, int $id): array
    {
        $row = $this->row($user, $id);
        $out = $this->present($row) + ['items' => array_map(static fn (array $i): array => [
            'id' => (int)$i['id'], 'position' => (int)$i['position'], 'title' => (string)$i['title'], 'description' => $i['description'],
            'vote_basis' => (string)$i['vote_basis'], 'quorum' => (string)$i['quorum'], 'status' => (string)$i['status'],
            'result' => $i['result_json'] === null ? null : json_decode((string)$i['result_json'], true), 'decided_at' => $i['decided_at'],
        ], $this->items($id))];
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS units, COALESCE(SUM(is_proxy), 0) AS proxies, COALESCE(SUM(eligible = 0), 0) AS blocked FROM erp_assembly_attendance WHERE assembly_id = :a');
        $stmt->execute(['a' => $id]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['attendance'] = ['units' => (int)($count['units'] ?? 0), 'proxies' => (int)($count['proxies'] ?? 0), 'without_vote' => (int)($count['blocked'] ?? 0)];
        $out['minutes'] = $row['minutes'];
        $out['minutes_sha256'] = $row['minutes_sha256'];
        return $out;
    }

    /**
     * Relatório de presença (AP-088): unidade, quem está presente, procuração e direito a voto.
     *
     * @param bool $withDebt ADR 0094: o valor vencido por unidade só aparece para quem tem erp.agreements.view.
     */
    public function attendanceReport(User $user, int $id, bool $withDebt = false): array
    {
        $assembly = $this->row($user, $id);
        $rows = array_map(static fn (array $a): array => [
            'unit_label' => (string)$a['unit_label'], 'attendee_name' => (string)$a['attendee_name'], 'is_proxy' => (bool)$a['is_proxy'],
            'proxy_document_id' => $a['proxy_document_id'] === null ? null : (int)$a['proxy_document_id'], 'eligible' => (bool)$a['eligible'],
            'overdue_cents' => $withDebt ? (int)$a['overdue_cents'] : null, 'checked_in_at' => (string)$a['checked_in_at'],
        ], $this->attendance($id));
        return ['assembly' => $this->present($assembly), 'rows' => $rows, 'totals' => ['units' => count($rows),
            'proxies' => count(array_filter($rows, static fn (array $r): bool => $r['is_proxy'])),
            'without_vote' => count(array_filter($rows, static fn (array $r): bool => !$r['eligible']))]];
    }

    /** Relatório de resultados (AP-092): cada item com a apuração e a ata, se fechada. */
    public function resultsReport(User $user, int $id): array
    {
        $found = $this->find($user, $id);
        $rows = array_map(static fn (array $i): array => [
            'position' => $i['position'], 'title' => $i['title'], 'vote_basis' => $i['vote_basis'], 'quorum' => $i['quorum'], 'status' => $i['status'],
            'yes' => $i['result'] === null ? null : self::share((int)$i['result']['yes'], $i['vote_basis']),
            'no' => $i['result'] === null ? null : self::share((int)$i['result']['no'], $i['vote_basis']),
            'abstain' => $i['result'] === null ? null : self::share((int)$i['result']['abstain'], $i['vote_basis']),
            'total' => $i['result'] === null ? null : self::share((int)$i['result']['total'], $i['vote_basis']),
            'votes' => $i['result']['votes'] ?? null, 'approved' => $i['result']['approved'] ?? null,
        ], $found['items']);
        return ['assembly' => array_diff_key($found, ['items' => 1]), 'rows' => $rows];
    }

    /**
     * Tabela do CSV (regra pura); cada célula de texto passa por CollectionReports::csvCell (ADR 0081).
     *
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function csvTable(string $kind, array $report): array
    {
        $columns = match ($kind) {
            'attendance' => ['Unidade' => 'unit_label', 'Presente' => 'attendee_name', 'Procuração' => 'is_proxy', 'Vota' => 'eligible',
                'Vencido (centavos)' => 'overdue_cents', 'Entrada' => 'checked_in_at'],
            'results' => ['Item' => 'position', 'Título' => 'title', 'Base' => 'vote_basis', 'Quórum' => 'quorum', 'Situação' => 'status',
                'Sim' => 'yes', 'Não' => 'no', 'Abstenção' => 'abstain', 'Total' => 'total', 'Votos' => 'votes', 'Aprovado' => 'approved'],
            default => throw new InvalidArgumentException('Relatório inválido.'),
        };
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = array_map(static function (string $key) use ($row): string {
                $value = $row[$key] ?? null;
                return CollectionReports::csvCell(is_bool($value) ? ($value ? 'sim' : 'não') : (string)($value ?? ''));
            }, array_values($columns));
        }
        return ['header' => array_keys($columns), 'rows' => $rows];
    }

    /** Muda a situação só se ainda estiver na esperada (sem corrida entre duas abas). */
    private function transition(int $id, string $from, string $to, User $user, string $stamp, ?\DateTimeImmutable $at = null): bool
    {
        $stmt = $this->pdo->prepare("UPDATE erp_assemblies SET status = :to, {$stamp} = :t, updated_by = :u WHERE id = :id AND status = :from");
        $stmt->execute(['to' => $to, 't' => ($at ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'u' => (int)$user->id, 'id' => $id, 'from' => $from]);
        return $stmt->rowCount() === 1;
    }

    /** Soma, em centavos, das receitas da unidade vencidas antes de $asOf e não pagas (a mesma base do ADR 0039). */
    public function overdueCents(int $condominium, int $unit, string $asOf): int
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE condominium_id = :c AND unit_id = :u AND nature = 'receita'
            AND status = 'active' AND deleted_at IS NULL AND paid_on IS NULL AND due_on < :d");
        $stmt->execute(['c' => $condominium, 'u' => $unit, 'd' => $asOf]);
        return (int)$stmt->fetchColumn();
    }

    /** Total do condomínio em milionésimos: soma das frações ideais ou número de unidades ativas. */
    public function totalWeight(int $condominium, string $basis): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) AS n, ROUND(COALESCE(SUM(ideal_fraction), 0) * " . self::SCALE . ") AS f FROM erp_units
            WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['c' => $condominium]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return $basis === 'unit' ? (int)($row['n'] ?? 0) * self::SCALE : (int)($row['f'] ?? 0);
    }

    private function unit(int $condominium, mixed $value): array
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $stmt = $this->pdo->prepare("SELECT id, label, ideal_fraction FROM erp_units WHERE id = :id AND condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['id' => $id === false ? 0 : (int)$id, 'c' => $condominium]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($unit)) {
            throw new InvalidArgumentException('Unidade não encontrada ativa neste condomínio.');
        }
        return $unit;
    }

    private function item(int $assembly, int $itemId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_assembly_items WHERE id = :i AND assembly_id = :a');
        $stmt->execute(['i' => $itemId, 'a' => $assembly]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($item)) {
            throw new RegistryNotFound();
        }
        return $item;
    }

    /** @return list<array> */
    private function items(int $assembly): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_assembly_items WHERE assembly_id = :a ORDER BY position');
        $stmt->execute(['a' => $assembly]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array> */
    private function attendance(int $assembly): array
    {
        $stmt = $this->pdo->prepare('SELECT t.*, u.label AS unit_label FROM erp_assembly_attendance t JOIN erp_units u ON u.id = t.unit_id
            WHERE t.assembly_id = :a ORDER BY u.label, t.id LIMIT ' . self::MAX_ATTENDANCE);
        $stmt->execute(['a' => $assembly]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function openRow(User $user, int $id): array
    {
        $row = $this->row($user, $id);
        if ($row['status'] !== 'open') {
            throw new DomainException($row['status'] === 'closed' ? 'A assembleia está encerrada: a ata não muda mais.' : 'A assembleia não está aberta.');
        }
        return $row;
    }

    private function condominium(User $user, mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !$this->scope->canAccess($user, (int)$id)) {
            throw new RegistryNotFound();
        }
        return (int)$id;
    }

    private function row(User $user, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT a.*, c.name AS condominium_name FROM erp_assemblies a JOIN condominiums c ON c.id = a.condominium_id WHERE a.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    /** @return list<int> */
    private function ids(User $user, ?int $condominiumId): array
    {
        $ids = array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $i): bool => $i > 0));
        if ($condominiumId !== null) {
            return in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        return $ids;
    }

    private function present(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => $r['condominium_name'] ?? null,
            'kind' => (string)$r['kind'], 'mode' => (string)$r['mode'], 'title' => (string)$r['title'], 'location' => (string)$r['location'],
            'first_call_at' => (string)$r['first_call_at'], 'second_call_at' => $r['second_call_at'], 'status' => (string)$r['status'],
            'announcement_id' => $r['announcement_id'] === null ? null : (int)$r['announcement_id'], 'convened_at' => $r['convened_at'],
            'opened_at' => $r['opened_at'], 'closed_at' => $r['closed_at'], 'cancel_reason' => $r['cancel_reason'],
        ];
    }
}
