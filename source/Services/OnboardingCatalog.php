<?php

declare(strict_types=1);

namespace Source\Services;

use PDO;
use Source\Core\Connect;
use Source\Support\Audit;

/**
 * Catálogo do checklist de chegada (ADR 0013): itens que todo condomínio deve verificar.
 * Mudanças valem para os próximos checklists e para "Atualizar com o catálogo"; itens já
 * criados nos condomínios mantêm o título copiado. Excluir não existe: desative o item.
 */
final class OnboardingCatalog
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->query("SELECT t.*, (SELECT COUNT(*) FROM condominium_onboarding_items i WHERE i.template_id = t.id) AS usage_count
            FROM onboarding_item_templates t
            ORDER BY FIELD(t.category, 'documentacao','seguranca','manutencao','seguros','financeiro','pessoas'), t.position, t.id");
        return array_map(fn(array $row): array => $this->present($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT t.*, (SELECT COUNT(*) FROM condominium_onboarding_items i WHERE i.template_id = t.id) AS usage_count
            FROM onboarding_item_templates t WHERE t.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new OnboardingItemNotFound($id);
        }
        return $this->present($row);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws OnboardingValidationError
     */
    public function create(array $input): array
    {
        $data = $this->validate($input, null);
        $stmt = $this->pdo->prepare('INSERT INTO onboarding_item_templates
            (code, category, title, description, default_due_days, renewal_months, position, active)
            VALUES (:code, :category, :title, :description, :default_due_days, :renewal_months, :position, :active)');
        $stmt->execute($data);
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'onboarding_item_templates', $id, null, $data);
        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $input campos ausentes mantêm o valor atual
     * @return array<string, mixed>
     * @throws OnboardingValidationError|OnboardingItemNotFound
     */
    public function update(int $id, array $input): array
    {
        $before = $this->find($id);
        $data = $this->validate($input + $before, $id);
        unset($data['code']);
        $stmt = $this->pdo->prepare('UPDATE onboarding_item_templates SET category = :category, title = :title,
                description = :description, default_due_days = :default_due_days, renewal_months = :renewal_months,
                position = :position, active = :active
            WHERE id = :id');
        $stmt->execute($data + ['id' => $id]);
        $after = $this->find($id);
        Audit::record('update', 'onboarding_item_templates', $id, $before, $after);
        return $after;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, ?int $id): array
    {
        $errors = [];
        $code = strtolower(trim((string)($input['code'] ?? '')));
        if ($id === null) {
            if (!preg_match('/^[a-z0-9_]{2,60}$/', $code)) {
                $errors['code'] = 'Código com 2 a 60 letras minúsculas, números ou _.';
            } else {
                $exists = $this->pdo->prepare('SELECT 1 FROM onboarding_item_templates WHERE code = :code');
                $exists->execute(['code' => $code]);
                if ($exists->fetchColumn()) {
                    $errors['code'] = 'Já existe um item com este código.';
                }
            }
        }
        $category = (string)($input['category'] ?? '');
        if (!in_array($category, OnboardingChecklist::CATEGORIES, true)) {
            $errors['category'] = 'Categoria inválida.';
        }
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'] = 'Informe o nome do item (até 160 caracteres).';
        }
        $description = trim((string)($input['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            $errors['description'] = 'Descrição com no máximo 500 caracteres.';
        }
        $due = filter_var($input['default_due_days'] ?? 30, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        if ($due === false) {
            $errors['default_due_days'] = 'Prazo entre 1 e 365 dias.';
        }
        $renewalRaw = $input['renewal_months'] ?? null;
        $renewal = null;
        if ($renewalRaw !== null && $renewalRaw !== '') {
            $renewal = filter_var($renewalRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
            if ($renewal === false) {
                $errors['renewal_months'] = 'Renovação entre 1 e 120 meses, ou em branco se não vence.';
            }
        }
        $position = filter_var($input['position'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999]]);
        if ($position === false) {
            $errors['position'] = 'Ordem entre 0 e 999.';
        }
        if ($errors !== []) {
            throw new OnboardingValidationError($errors);
        }
        return [
            'code' => $code, 'category' => $category, 'title' => $title, 'description' => $description !== '' ? $description : null,
            'default_due_days' => (int)$due, 'renewal_months' => $renewal !== null ? (int)$renewal : null,
            'position' => (int)$position, 'active' => filter_var($input['active'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
        ];
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'code' => (string)$row['code'], 'category' => (string)$row['category'],
            'title' => (string)$row['title'], 'description' => $row['description'] !== null ? (string)$row['description'] : null,
            'default_due_days' => (int)$row['default_due_days'],
            'renewal_months' => $row['renewal_months'] !== null ? (int)$row['renewal_months'] : null,
            'position' => (int)$row['position'], 'active' => (bool)$row['active'], 'usage_count' => (int)$row['usage_count'],
        ];
    }
}
