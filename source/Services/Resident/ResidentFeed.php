<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use PDO;
use Source\Core\Connect;

/**
 * Sininho e busca do aplicativo do morador (ADR 0302, ajustes de 14/11/2026). Só junta o que o ResidentPortal já
 * mostra para o vínculo, respeitando as funções ligadas no condomínio; não cria dado novo nem tabela.
 *
 * - Avisos: conta vencida, encomenda esperando, visita de hoje, comunicado dos últimos 30 dias, assembleia
 *   convocada ou em andamento, reserva dos próximos 7 dias e o andamento dos chamados (ADR 0641). "Novo" = mais recente que a última vez que o morador
 *   abriu os avisos (guardado no navegador; ver AppScreens::notifications).
 * - Busca: funções do menu, comunicados, assembleias, documentos e encomendas, sem diferenciar acentos e maiúsculas.
 */
final class ResidentFeed
{
    public const MAX_ITEMS = 30;
    public const MIN_QUERY = 2;

    private PDO $pdo;
    private ResidentPortal $portal;

    public function __construct(?PDO $pdo = null, ?ResidentPortal $portal = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->portal = $portal ?? new ResidentPortal($this->pdo);
    }

    /**
     * Avisos do vínculo, do mais recente para o mais antigo.
     *
     * @return list<array{kind:string, title:string, detail:string, at:string, path:string, tone:string}>
     */
    public function notifications(array $link, ?string $today = null, ?int $userId = null): array
    {
        $today ??= date('Y-m-d');
        $features = $link['features'] ?? [];
        $items = [];
        // Andamento dos chamados que o morador pediu (ADR 0641): cada movimentação da equipe.
        if ($userId !== null && $userId > 0) {
            array_push($items, ...(new ResidentTicketNotices($this->pdo))->feed($userId, (int)$link['condominium_id'], $today));
        }
        if (in_array('boletos', $features, true)) {
            $stmt = $this->pdo->prepare("SELECT id, description, due_on, amount_cents FROM erp_entries
                WHERE unit_id = :u AND condominium_id = :c AND nature = 'receita' AND status = 'active' AND deleted_at IS NULL
                  AND paid_on IS NULL AND due_on < :t ORDER BY due_on LIMIT 10");
            $stmt->execute(['u' => $link['unit_id'], 'c' => $link['condominium_id'], 't' => $today]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = ['kind' => 'charge', 'title' => 'Conta vencida', 'detail' => (string)$r['description'] . ' · R$ ' . number_format((int)$r['amount_cents'] / 100, 2, ',', '.'),
                    'at' => (string)$r['due_on'] . ' 00:00:00', 'path' => '/boletos/' . (int)$r['id'], 'tone' => 'danger'];
            }
        }
        if (in_array('deliveries', $features, true)) {
            foreach ($this->portal->deliveries($link) as $d) {
                if ($d['status'] === 'active') {
                    $items[] = ['kind' => 'delivery', 'title' => 'Encomenda na portaria', 'detail' => 'Para ' . $d['recipient'] . ($d['tracking_code'] ? ' · ' . $d['tracking_code'] : ''),
                        'at' => $d['received_on'] . ' ' . ($d['received_time'] ?? '00:00') . ':00', 'path' => '/encomendas', 'tone' => 'warning'];
                }
            }
        }
        if (in_array('visitors', $features, true)) {
            foreach ($this->portal->visits($link, $today) as $v) {
                if ($v['status'] === 'active' && $v['expected_on'] <= $today && ($v['expected_until'] ?? $v['expected_on']) >= $today) {
                    $items[] = ['kind' => 'visit', 'title' => 'Visita prevista para hoje', 'detail' => $v['visitor_name'],
                        'at' => $today . ' 00:00:00', 'path' => '/visitantes', 'tone' => 'info'];
                }
            }
        }
        if (in_array('announcements', $features, true)) {
            $since = (new \DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d');
            foreach ($this->portal->announcements($link) as $a) {
                if (substr($a['published_at'], 0, 10) >= $since) {
                    $items[] = ['kind' => 'announcement', 'title' => 'Novo comunicado', 'detail' => $a['subject'],
                        'at' => $a['published_at'], 'path' => '/comunicados#c' . $a['id'], 'tone' => 'info'];
                }
            }
        }
        if (in_array('assemblies', $features, true)) {
            foreach ($this->portal->assemblies($link) as $a) {
                if (in_array($a['status'], ['convened', 'open'], true)) {
                    $items[] = ['kind' => 'assembly', 'title' => $a['status'] === 'open' ? 'Assembleia em andamento' : 'Assembleia convocada', 'detail' => $a['title'],
                        'at' => $a['first_call_at'], 'path' => '/assembleias/' . $a['id'], 'tone' => 'info'];
                }
            }
        }
        if (in_array('reservations', $features, true)) {
            $limit = (new \DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
            foreach ($this->portal->reservations($link, $today)['reservations'] ?? [] as $r) {
                if (in_array($r['status'], ['active', 'confirmed'], true) && $r['reserved_on'] >= $today && $r['reserved_on'] <= $limit) {
                    $items[] = ['kind' => 'reservation', 'title' => 'Reserva chegando', 'detail' => $r['area'],
                        'at' => $r['reserved_on'] . ' ' . ($r['starts_time'] ?? '00:00') . ':00', 'path' => '/reservas', 'tone' => 'success'];
                }
            }
            // Aprovada pelo síndico nos últimos 30 dias: o morador pediu (created_by) e a equipe confirmou (updated_by).
            $stmt = $this->pdo->prepare("SELECT r.reserved_on, r.starts_time, r.updated_at, a.name AS area FROM erp_reservations r
                JOIN erp_common_areas a ON a.id = r.common_area_id
                WHERE r.unit_id = :u AND r.condominium_id = :c AND r.deleted_at IS NULL AND r.status = 'confirmed' AND r.reserved_on >= :t
                  AND r.updated_by IS NOT NULL AND (r.created_by IS NULL OR r.updated_by <> r.created_by) AND r.updated_at >= :since
                ORDER BY r.updated_at DESC LIMIT 5");
            $stmt->execute(['u' => $link['unit_id'], 'c' => $link['condominium_id'], 't' => $today,
                'since' => (new \DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d')]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = ['kind' => 'reservation_approved', 'title' => 'Reserva aprovada', 'detail' => (string)$r['area'] . ' · ' . date('d/m/Y', strtotime((string)$r['reserved_on'])),
                    'at' => (string)$r['updated_at'], 'path' => '/reservas', 'tone' => 'success'];
            }
        }
        usort($items, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        return array_slice($items, 0, self::MAX_ITEMS);
    }

    /**
     * O aviso é novo? (regra pura) Mais recente que a última visita aos avisos; data futura (assembleia, reserva)
     * conta como "agora", para não ficar novo para sempre.
     */
    public static function isNew(array $item, ?string $seenAt, ?string $now = null): bool
    {
        if ($seenAt === null || $seenAt === '') {
            return true;
        }
        $now ??= date('Y-m-d H:i:s');
        return strcmp(min($item['at'], $now), $seenAt) > 0;
    }

    /** Quantos avisos são novos (regra pura). */
    public static function unseen(array $items, ?string $seenAt, ?string $now = null): int
    {
        return count(array_filter($items, static fn (array $i): bool => self::isNew($i, $seenAt, $now)));
    }

    /** Texto para comparar sem acento e sem maiúscula (regra pura). */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii === false || $ascii === '') {
            $ascii = strtr($text, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
        }
        return trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^a-z0-9 ]+/', '', $ascii)));
    }

    /** Todas as palavras da busca aparecem no texto? (regra pura) */
    public static function matches(string $query, string ...$texts): bool
    {
        $haystack = self::fold(implode(' ', $texts));
        foreach (preg_split('/\s+/', self::fold($query)) ?: [] as $word) {
            if ($word !== '' && !str_contains($haystack, $word)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Busca no que o morador pode ver.
     *
     * @param list<array{key:string,label:string,path:string}> $menu funções do menu (AppScreens::menu)
     * @return list<array{kind:string, kind_label:string, title:string, detail:string, path:string}>
     */
    public function search(array $link, string $query, array $menu = []): array
    {
        $query = trim(mb_substr($query, 0, 80));
        if (mb_strlen(self::fold($query)) < self::MIN_QUERY) {
            return [];
        }
        $features = $link['features'] ?? [];
        $out = [];
        foreach ($menu as $item) {
            if (self::matches($query, $item['label'])) {
                $out[] = ['kind' => 'page', 'kind_label' => 'Página', 'title' => $item['label'], 'detail' => 'Abrir ' . mb_strtolower($item['label']), 'path' => $item['path']];
            }
        }
        if (in_array('announcements', $features, true)) {
            foreach ($this->portal->announcements($link) as $a) {
                if (self::matches($query, $a['subject'], $a['body'])) {
                    $out[] = ['kind' => 'announcement', 'kind_label' => 'Comunicado', 'title' => $a['subject'],
                        'detail' => mb_strimwidth(trim((string)preg_replace('/\s+/u', ' ', $a['body'])), 0, 120, '…'), 'path' => '/comunicados#c' . $a['id']];
                }
            }
        }
        if (in_array('assemblies', $features, true)) {
            foreach ($this->portal->assemblies($link) as $a) {
                if (self::matches($query, $a['title'], $a['location'], 'assembleia')) {
                    $out[] = ['kind' => 'assembly', 'kind_label' => 'Assembleia', 'title' => $a['title'], 'detail' => substr($a['first_call_at'], 0, 10), 'path' => '/assembleias/' . $a['id']];
                }
            }
        }
        if (in_array('documents', $features, true)) {
            foreach ($this->portal->documents($link) as $d) {
                if (self::matches($query, $d['title'], $d['folder_label'])) {
                    $out[] = ['kind' => 'document', 'kind_label' => 'Documento', 'title' => $d['title'], 'detail' => $d['folder_label'], 'path' => '/documentos/' . $d['id']];
                }
            }
        }
        if (in_array('deliveries', $features, true)) {
            foreach ($this->portal->deliveries($link) as $d) {
                if (self::matches($query, $d['recipient'], (string)$d['tracking_code'], $d['carrier'], 'encomenda')) {
                    $out[] = ['kind' => 'delivery', 'kind_label' => 'Encomenda', 'title' => 'Para ' . $d['recipient'], 'detail' => trim($d['carrier'] . ' ' . (string)$d['tracking_code']), 'path' => '/encomendas'];
                }
            }
        }
        return array_slice($out, 0, self::MAX_ITEMS);
    }
}
