<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Adesão ao portal do morador (ADR 0268), somente leitura. O convite do morador (AP-152, ADR 0090) lista convite por
 * convite, com nome e e-mail mascarado, e a matriz do portal (AP-153) mostra quais funções estão ligadas. Ninguém vê, de
 * uma vez, quantos moradores ativos já entraram no portal, quantos têm convite pendente, vencido ou revogado, quantos ainda
 * não foram convidados e o que falta para convidar (documento válido, ADR 0093, e e-mail), nem quantas unidades habitadas
 * têm alguém com acesso. A administradora usa isso para a campanha de adesão e para saber se vale ligar mais funções.
 *
 * Reusa o que já existe: a regra de morador ativo das listas do cadastro (vínculo ativo, sem excluído e sem fim de vínculo
 * passado, como no ADR 0265), os vínculos do cadastro (RegistryService::definition('residents')), a situação "vencido"
 * da lista dos convites (pendente com a validade passada), a regra do documento (InvitationRules::validDocument), a
 * ordem do convite (sem documento válido a lista inteira é recusada; sem e-mail o morador fica de fora) e as funções do
 * portal (ResidentPortalPermissions::FEATURES e enabled(), fail-closed).
 *
 * Regra pura classify() e summarize(), por morador ativo:
 * - accepted: já aceitou um convite (entrou no portal);
 * - pending: o convite mais recente está pendente e dentro da validade;
 * - expired: o convite mais recente está pendente e venceu;
 * - revoked: o convite mais recente foi revogado;
 * - ready: nunca convidado, com documento válido e e-mail (pode ser convidado);
 * - no_document: nunca convidado, sem CPF/CNPJ válido;
 * - no_email: nunca convidado, com documento válido e sem e-mail válido.
 * Uma linha por situação, sempre as sete, com residents e by_relationship (os vínculos do cadastro) somando residents;
 * totals.residents é a soma das linhas, totals.with_access = accepted e totals.to_invite = ready + expired + revoked.
 * units conta as unidades vinculadas com morador ativo (inhabited), separando with_access (alguém aceitou) e
 * without_access, que somam inhabited. features traz as funções do portal, ligadas ou não, e enabled as ligadas.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * situações, os vínculos, as funções e as contagens: nenhum nome, documento, e-mail, telefone, unidade ou token.
 */
final class ResidentPortalAdoption
{
    public const MAX_ROWS = 50000;
    public const SITUATIONS = [
        'accepted' => 'Entrou no portal',
        'pending' => 'Convite pendente',
        'expired' => 'Convite vencido',
        'revoked' => 'Convite revogado',
        'ready' => 'Pode ser convidado',
        'no_document' => 'Sem CPF/CNPJ válido',
        'no_email' => 'Sem e-mail válido',
    ];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Situação de um morador (regra pura).
     *
     * @param ?array{status:string, expires_at:string} $latest convite mais recente, ou null se nunca convidado
     */
    public static function classify(bool $accepted, ?array $latest, bool $validDocument, bool $validEmail, string $now): string
    {
        if ($accepted) {
            return 'accepted';
        }
        if ($latest !== null) {
            return match ($latest['status']) {
                'pending' => $latest['expires_at'] <= $now ? 'expired' : 'pending',
                'accepted' => 'accepted',
                default => 'revoked',
            };
        }
        return match (true) {
            !$validDocument => 'no_document',
            !$validEmail => 'no_email',
            default => 'ready',
        };
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{relationship:string, unit_id:?int, situation:string}> $residents
     * @param array<string, bool> $features
     */
    public static function summarize(array $residents, array $features): array
    {
        $relationships = RegistryService::definition('residents')['fields']['relationship']['options'];
        $counts = array_fill_keys(array_keys(self::SITUATIONS), 0);
        $byRelationship = array_fill_keys(array_keys(self::SITUATIONS), array_fill_keys(array_keys($relationships), 0));
        $units = [];
        foreach ($residents as $resident) {
            $situation = $resident['situation'];
            $counts[$situation]++;
            if (isset($byRelationship[$situation][$resident['relationship']])) {
                $byRelationship[$situation][$resident['relationship']]++;
            }
            if ($resident['unit_id'] !== null) {
                $units[$resident['unit_id']] = ($units[$resident['unit_id']] ?? false) || $situation === 'accepted';
            }
        }
        $rows = [];
        foreach (self::SITUATIONS as $situation => $label) {
            $rows[] = ['situation' => $situation, 'situation_label' => $label, 'residents' => $counts[$situation], 'by_relationship' => $byRelationship[$situation]];
        }
        $withAccess = count(array_filter($units));
        $list = [];
        foreach (ResidentPortalPermissions::FEATURES as $feature => $label) {
            $list[] = ['feature' => $feature, 'label' => $label, 'enabled' => (bool)($features[$feature] ?? false)];
        }
        return ['relationship_labels' => $relationships, 'rows' => $rows, 'totals' => ['residents' => array_sum($counts), 'with_access' => $counts['accepted'],
            'to_invite' => $counts['ready'] + $counts['expired'] + $counts['revoked']],
            'units' => ['inhabited' => count($units), 'with_access' => $withAccess, 'without_access' => count($units) - $withAccess],
            'features' => $list, 'enabled' => count(array_filter(array_column($list, 'enabled')))];
    }

    public function build(User $user, mixed $condominiumId): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare("SELECT id, unit_id, relationship, document, email FROM erp_residents WHERE condominium_id = ? AND deleted_at IS NULL
            AND status = 'active' AND (ends_on IS NULL OR ends_on >= ?) ORDER BY id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, date('Y-m-d')]);
        $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($residents) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d moradores ativos: fale com o suporte.', self::MAX_ROWS));
        }
        $stmt = $this->pdo->prepare('SELECT resident_id, status, expires_at FROM erp_resident_invitations WHERE condominium_id = ? ORDER BY id');
        $stmt->execute([(int)$id]);
        $latest = [];
        $accepted = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invitation) {
            $resident = (int)$invitation['resident_id'];
            $latest[$resident] = ['status' => (string)$invitation['status'], 'expires_at' => (string)$invitation['expires_at']];   // o último id fica
            if ($invitation['status'] === 'accepted') {
                $accepted[$resident] = true;
            }
        }
        $now = date('Y-m-d H:i:s');
        $features = [];
        foreach (array_keys(ResidentPortalPermissions::FEATURES) as $feature) {
            $features[$feature] = ResidentPortalPermissions::enabled($this->pdo, (int)$id, $feature);
        }
        return ['condominium_id' => (int)$id, 'on' => date('Y-m-d')] + self::summarize(array_map(static function (array $r) use ($latest, $accepted, $now): array {
            $resident = (int)$r['id'];
            return ['relationship' => (string)$r['relationship'], 'unit_id' => $r['unit_id'] === null ? null : (int)$r['unit_id'],
                'situation' => self::classify(isset($accepted[$resident]), $latest[$resident] ?? null, InvitationRules::validDocument($r['document'] === null ? null : (string)$r['document']),
                    filter_var((string)($r['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false, $now)];
        }, $residents), $features);
    }
}
