<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\App\Resident\AppScreens;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\ResidentPortalPermissions;
use Source\Services\Privacy\PrivacyCenter;

/**
 * XSS no app web do morador (ADR 0347): grava um texto de teste com HTML em toda coluna de texto das tabelas que o app mostra
 * (condomínio, unidade, morador, boletos, comunicados, reservas, chamados, encomendas, visitas, assembleias, documentos...) e
 * renderiza no servidor todas as telas de leitura do app. O texto só pode aparecer escapado; a marca crua é falha.
 */
final class ResidentAppXssCanaryIntegrationTest extends TestCase
{
    use ScopeFixtures;

    private const CANARY = '"\'></script><i data-xss>x';
    private const MARK = '<i data-xss>';

    /** Tabela => valores para a linha aparecer para a moradora (ganham do texto de teste). */
    private const ROWS = [
        'erp_entries' => ['nature' => 'receita', 'status' => 'active', 'agreement_role' => 'original', 'agreement_id' => null, 'deleted_at' => null],
        'erp_bank_charges' => ['status' => 'registered', 'deleted_at' => null],
        'erp_announcements' => ['status' => 'active', 'kind' => 'general', 'audience' => 'all', 'deleted_at' => null],
        'erp_common_areas' => ['status' => 'active', 'booking_mode' => 'free', 'deleted_at' => null],
        'erp_reservations' => ['status' => 'confirmed', 'deleted_at' => null],
        'erp_deliveries' => ['status' => 'active', 'carrier' => 'correios', 'deleted_at' => null],
        'erp_visit_forecasts' => ['status' => 'active', 'deleted_at' => null],
        'erp_assemblies' => ['status' => 'convened', 'kind' => 'ordinaria', 'mode' => 'presencial', 'deleted_at' => null],
        'erp_assembly_items' => ['status' => 'pending', 'vote_basis' => 'unit', 'quorum' => 'simple'],
        'erp_documents' => ['shared_with_residents' => 1, 'folder' => 'atas', 'scan_status' => 'clean', 'deleted_at' => null],
        'erp_unit_documents' => ['kind' => 'debt_certificate', 'status' => 'issued'],
        'erp_pets' => ['status' => 'active', 'deleted_at' => null],
        'erp_vehicles' => ['status' => 'active', 'deleted_at' => null],
        'erp_occurrences' => ['status' => 'active', 'priority' => 'media', 'deleted_at' => null],
        'erp_extra_charges' => ['status' => 'active', 'kind' => 'extra', 'method' => 'fixed', 'deleted_at' => null],
        'erp_accountability_links' => ['kind' => 'monthly', 'status' => 'active'],
        'erp_waitlist' => ['status' => 'active'],
    ];

    /** Telas que mostram as linhas gravadas. */
    private const WITH_DATA = ['início', 'perfil', 'boletos', 'boleto', 'avisos', 'comunicados', 'reservas', 'área', 'ocorrências', 'chamado',
        'encomendas', 'moradores', 'visitantes', 'assembleias', 'assembleia', 'documentos', 'minha unidade', 'certidões', 'prestação de contas'];

    protected function tearDown(): void
    {
        Auth::actingAs(null);
        http_response_code(200);
        $_GET = [];
        $this->pdo->exec("DELETE FROM erp_resident_portal_permissions WHERE feature = 'household'");
        parent::tearDown();
    }

    public function testResidentScreensEscapeStoredText(): void
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([self::CANARY, 'active']);
        $condominium = (int)$this->pdo->lastInsertId();
        foreach (array_keys(ResidentPortalPermissions::FEATURES) as $feature) {
            $this->pdo->prepare('INSERT INTO erp_resident_portal_permissions (condominium_id, feature, enabled) VALUES (?, ?, 1)')->execute([$condominium, $feature]);
        }
        $unit = $this->scopeRow('erp_units', ['type' => 'apartamento', 'status' => 'active', 'postage_payer' => 'ambos', 'billing_responsible' => 'nao_definido', 'bill_address' => 'predio', 'bill_email_to' => 'responsavel', 'deleted_at' => null] + $this->texts('erp_units'), ['condominium_id' => $condominium]);
        $userId = $this->createUser(['first_name' => self::CANARY, 'last_name' => self::CANARY]);
        $refs = ['condominium_id' => $condominium, 'unit_id' => $unit, 'user_id' => $userId, 'requester_id' => $userId, 'created_by' => $userId];
        $this->scopeRow('erp_residents', ['relationship' => 'owner', 'status' => 'active', 'deleted_at' => null, 'email' => 'canario@morador.test'] + $this->texts('erp_residents'), $refs);
        $ids = [];
        foreach (self::ROWS as $table => $values) {
            $rowRefs = $refs + ['entry_id' => $ids['erp_entries'] ?? 0, 'assembly_id' => $ids['erp_assemblies'] ?? 0, 'common_area_id' => $ids['erp_common_areas'] ?? 0, 'area_id' => $ids['erp_common_areas'] ?? 0];
            $ids[$table] = $this->scopeRow($table, $values + $this->texts($table), $rowRefs);
        }
        $ticket = $this->scopeRow('studio_support_tickets', ['status' => 'open', 'priority' => 'medium', 'area' => 'administrativo', 'origin' => 'residents', 'requester_email' => 'canario@morador.test']
            + $this->texts('studio_support_tickets'), $refs);
        $this->scopeRow('studio_support_ticket_messages', ['visibility' => 'public', 'is_internal' => 0] + $this->texts('studio_support_ticket_messages'), ['ticket_id' => $ticket, 'user_id' => $userId, 'author_id' => $userId]);
        $this->pdo->prepare('UPDATE settings SET access_app = 1')->execute();
        // Sem o aceite dos termos (ADR 0600, etapa 2), toda tela levaria a /termos.
        (new PrivacyCenter($this->pdo))->accept($userId, 'app');

        $user = (new User())->findById($userId);
        $screens = [
            'início' => ['home', [], ['pular' => '1']], 'resolva fácil' => ['resolve', []], 'perfil' => ['profile', []],
            'boletos' => ['charges', []], 'boletos (rolagem)' => ['charges', [], ['parcial' => '1']], 'boleto' => ['charge', ['id' => (string)$ids['erp_entries']]],
            'recibo' => ['receipt', ['id' => (string)$ids['erp_entries']]], 'avisos' => ['notifications', []],
            'busca' => ['search', [], ['q' => 'x']], 'certidões' => ['certificates', []], 'prestação de contas' => ['accountability', []],
            'comunicados' => ['announcements', []], 'reservas' => ['reservations', []],
            'área' => ['reservationArea', ['id' => (string)$ids['erp_common_areas']]], 'ocorrências' => ['occurrences', []],
            'chamado' => ['ticket', ['id' => (string)$ticket]], 'encomendas' => ['deliveries', []], 'moradores' => ['household', []],
            'visitantes' => ['visits', []], 'assembleias' => ['assemblies', []], 'assembleia' => ['assembly', ['id' => (string)$ids['erp_assemblies']]],
            'edital' => ['notice', ['id' => (string)$ids['erp_assemblies']]], 'documentos' => ['documents', []], 'minha unidade' => ['unit', []],
            'segurança' => ['security', []], 'termos' => ['terms', []], 'privacidade' => ['privacy', []], 'boas-vindas' => ['welcome', []], 'sem internet' => ['offline', []],
        ];

        $raw = [];
        $broken = [];
        $redirected = [];
        $shown = [];
        $escaped = htmlspecialchars(self::MARK, ENT_QUOTES, 'UTF-8');
        foreach ($screens as $name => $screen) {
            [$action, $args] = $screen;
            Auth::actingAs($user);
            \Source\Support\Access::clear();
            http_response_code(200);
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET = $screen[2] ?? [];
            ob_start();
            try {
                (new AppScreens())->$action($args);
                $html = (string)ob_get_clean();
            } catch (\Source\Support\RedirectIntercepted $redirect) {
                ob_end_clean();
                $redirected[] = $name;
                continue;
            } catch (\Throwable $error) {
                ob_end_clean();
                $broken[] = $name . ': ' . get_class($error) . ' ' . $error->getMessage();
                continue;
            }
            if (str_contains($html, self::MARK)) {
                $raw[] = $name;
            }
            $shown[$name] = substr_count($html, $escaped);
        }
        self::assertSame([], $broken, 'Tela quebrou com o texto de teste.');
        self::assertSame(['recibo'], $redirected, 'Só o recibo volta para o boleto (a conta de teste não está paga).');
        // A moldura (nome e condomínio) mostra o texto em toda tela; as de dados precisam mostrar mais que ela, senão a linha
        // gravada nem chegou à tela e o teste não provaria nada.
        $frame = $shown['segurança'];
        $empty = array_keys(array_filter(array_intersect_key($shown, array_flip(self::WITH_DATA)), static fn (int $n): bool => $n <= $frame));
        self::assertSame([], $empty, 'Tela de dados sem a linha de teste.');
        self::assertGreaterThan(0, $shown['boletos (rolagem)']);
        self::assertSame([], $raw, 'Texto gravado saiu sem escapar nessas telas do app.');
    }

    /** @return array<string, string> */
    private function texts(string $table): array
    {
        $stmt = $this->pdo->prepare("SELECT column_name, character_maximum_length FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND extra NOT LIKE '%GENERATED%'
              AND data_type IN ('varchar', 'char', 'text', 'mediumtext', 'longtext', 'tinytext')");
        $stmt->execute([$table]);
        $values = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $max = (int)($c['character_maximum_length'] ?? 0);
            if ($max >= strlen(self::CANARY) || $max === 0) {
                $values[(string)$c['column_name']] = self::CANARY;
            }
        }
        return $values;
    }
}
