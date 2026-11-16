<?php

declare(strict_types=1);

namespace Source\Services\Privacy;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Anonimização de quem saiu (LGPD art. 18, IV e VI), ADR 0330. Parte 2 do ADR 0328.
 *
 * Usa a mesma busca do relatório (PersonalDataExport::collect) e, nos condomínios do escopo:
 * - cadastro de morador: nome vira "Titular anonimizado #id"; documento, e-mail, telefone e anotações apagados; o vínculo com
 *   a conta de acesso é desfeito e o cadastro fica inativo (unidade, vínculo, datas e contas continuam, para a contabilidade);
 * - convites pendentes do portal: revogados;
 * - visitantes com o mesmo documento: nome anonimizado, documento, telefone e anotações apagados, inativos;
 * - veículos e animais ligados pelo nome: dono/tutor anonimizado e anotações apagadas (placa, modelo, espécie ficam);
 * - chamados: nome, e-mail e telefone de quem abriu apagados (o texto do chamado fica, é registro do atendimento).
 * Contas a receber, boletos, acordos e prestação de contas não mudam (guarda obrigatória da contabilidade e do condomínio).
 *
 * Recusa (DomainException, nada muda) quando algum cadastro ainda está ativo sem data de saída no passado (a pessoa ainda
 * mora ou paga) ou quando há conta a receber em aberto com ela como pagadora (cobrança é interesse legítimo do condomínio).
 * Sem confirm = prévia. Com confirm, tudo ou nada numa transação. A conta de acesso (users) não é tocada aqui: desativar
 * login é tarefa de Usuários do Sistema.
 */
final class PersonalDataAnonymizer
{
    public const LABEL = 'Titular anonimizado';

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @param array<string, mixed> $input */
    public function run(User $user, array $input): array
    {
        return $this->apply($this->scope->allowedIds($user), $input, date('Y-m-d'));
    }

    /**
     * @param list<int> $condominiumIds
     * @param array<string, mixed> $input document, email, reason, confirm
     * @return array<string, mixed>
     */
    public function apply(array $condominiumIds, array $input, string $today): array
    {
        [$document, $email] = PersonalDataExport::criteria($input['document'] ?? null, $input['email'] ?? null);
        $reason = trim((string)($input['reason'] ?? ''));
        $confirm = ($input['confirm'] ?? false) === true;
        if ($confirm && (mb_strlen($reason) < 10 || mb_strlen($reason) > 500)) {
            throw new InvalidArgumentException('Informe o motivo (de 10 a 500 caracteres), por exemplo o número do pedido do titular.');
        }
        $found = (new PersonalDataExport($this->pdo))->collect($condominiumIds, $document, $email);
        $residents = $found['residents']['rows'];
        if ($residents === [] && $found['visitors']['rows'] === [] && $found['tickets']['rows'] === []) {
            throw new DomainException('Nada encontrado para essa busca nos seus condomínios.');
        }
        $blockers = self::blockers($residents, $found['receivables']['rows'], $today);
        $plan = [
            'residents' => array_map('intval', array_column($residents, 'id')),
            'invitations' => array_map('intval', array_column(array_filter($found['invitations']['rows'], static fn (array $i): bool => (string)$i['status'] === 'pending'), 'id')),
            'visitors' => array_map('intval', array_column($found['visitors']['rows'], 'id')),
            'vehicles' => array_map('intval', array_column($found['vehicles']['rows'], 'id')),
            'pets' => array_map('intval', array_column($found['pets']['rows'], 'id')),
            'tickets' => array_map('intval', array_column($found['tickets']['rows'], 'id')),
            'kept' => ['receivables' => count($found['receivables']['rows'])],
        ];
        $result = ['confirmed' => false, 'blockers' => $blockers, 'plan' => $plan];
        if (!$confirm) {
            return $result;
        }
        if ($blockers !== []) {
            throw new DomainException('Não dá para anonimizar: ' . implode(' ', array_column($blockers, 'message')));
        }
        $this->pdo->beginTransaction();
        try {
            $this->write($plan);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        return ['confirmed' => true, 'blockers' => [], 'plan' => $plan];
    }

    /**
     * Regra pura: o que impede a anonimização.
     *
     * @param list<array<string, mixed>> $residents
     * @param list<array<string, mixed>> $receivables
     * @return list<array{code:string, id:int, message:string}>
     */
    public static function blockers(array $residents, array $receivables, string $today): array
    {
        $out = [];
        foreach ($residents as $r) {
            $endsOn = (string)($r['ends_on'] ?? '');
            $left = ($endsOn !== '' && $endsOn < $today) || (string)$r['status'] !== 'active' || !empty($r['deleted_at']);
            if (!$left) {
                $out[] = ['code' => 'still_linked', 'id' => (int)$r['id'],
                    'message' => sprintf('O cadastro #%d (%s) ainda está ativo sem data de saída no passado.', (int)$r['id'], (string)$r['unit_label'])];
            }
        }
        foreach ($receivables as $e) {
            if ((string)$e['status'] === 'active' && empty($e['paid_on'])) {
                $out[] = ['code' => 'open_receivable', 'id' => (int)$e['id'],
                    'message' => sprintf('A conta #%d ainda está em aberto com essa pessoa como pagadora.', (int)$e['id'])];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $plan */
    private function write(array $plan): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($plan['residents'] as $id) {
            $this->pdo->prepare("UPDATE erp_residents SET name = ?, document = NULL, email = NULL, phone = NULL, notes = NULL, user_id = NULL,
                    billing_by_email = 0, status = 'inactive', updated_at = ? WHERE id = ?")
                ->execute([self::LABEL . ' #' . $id, $now, $id]);
        }
        foreach ($plan['invitations'] as $id) {
            $this->pdo->prepare("UPDATE erp_resident_invitations SET status = 'revoked', revoked_at = ? WHERE id = ? AND status = 'pending'")->execute([$now, $id]);
        }
        foreach ($plan['visitors'] as $id) {
            $this->pdo->prepare("UPDATE erp_visitors SET name = ?, document = NULL, phone = NULL, notes = NULL, status = 'inactive', updated_at = ? WHERE id = ?")
                ->execute([self::LABEL, $now, $id]);
        }
        foreach ($plan['vehicles'] as $id) {
            $this->pdo->prepare('UPDATE erp_vehicles SET owner_name = ?, notes = NULL, updated_at = ? WHERE id = ?')->execute([self::LABEL, $now, $id]);
        }
        foreach ($plan['pets'] as $id) {
            $this->pdo->prepare('UPDATE erp_pets SET tutor_name = ?, notes = NULL, updated_at = ? WHERE id = ?')->execute([self::LABEL, $now, $id]);
        }
        foreach ($plan['tickets'] as $id) {
            $this->pdo->prepare('UPDATE studio_support_tickets SET requester_name = ?, requester_email = NULL, requester_phone = NULL WHERE id = ?')
                ->execute([self::LABEL, $id]);
        }
    }
}
