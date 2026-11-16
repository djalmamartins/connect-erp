<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * Lançamento para várias unidades num único cadastro (revisão de 15/11, item 15, ADR 0313). Ex.: controle do portão
 * para as unidades 01 e 303. Lançamentos, contas a pagar e contas a receber aceitam, ao criar, "outras unidades"
 * (`extra_unit_ids`): grava um lançamento por unidade, com os mesmos dados, pelo motor de cadastros (cada um confere
 * condomínio, unidade, fechamento e auditoria como no cadastro normal). Tudo ou nada.
 *
 * Valor (`unit_amount_mode`): `each` = o valor informado para cada unidade (padrão); `split` = o valor informado é o
 * total, dividido igualmente, e os centavos que sobram ficam com as primeiras unidades.
 * INFERIDO: o Djalma não disse se o valor é por unidade ou total; o padrão é por unidade, como no exemplo do controle.
 */
final class EntryUnits
{
    public const SLUGS = ['entries', 'payables', 'receivables'];
    public const MODES = ['each' => 'Valor para cada unidade', 'split' => 'Dividir o valor entre as unidades'];
    /** Unidades por cadastro: limita a transação (o rateio do condomínio inteiro é a fatura). */
    public const MAX_UNITS = 100;

    private PDO $pdo;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?RegistryService $registry = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->registry = $registry ?? new RegistryService($this->pdo, $scope ?? new CondominiumScope($this->pdo));
    }

    /** O pedido traz outras unidades? (regra pura) */
    public static function applies(string $slug, array $input, ?int $id): bool
    {
        return $id === null && in_array($slug, self::SLUGS, true) && self::extraIds($input['extra_unit_ids'] ?? null, null) !== [];
    }

    /**
     * Outras unidades pedidas (regra pura): inteiros positivos, sem repetir e sem a unidade principal.
     *
     * @return list<int>
     */
    public static function extraIds(mixed $raw, mixed $main): array
    {
        $raw = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? explode(',', $raw) : []);
        $main = (int)(filter_var($main, FILTER_VALIDATE_INT) ?: 0);
        $ids = [];
        foreach ($raw as $value) {
            $id = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && $id !== $main && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Valores por unidade (regra pura). `split` divide o total; os centavos que sobram vão para as primeiras.
     *
     * @return list<int>
     */
    public static function amounts(int $cents, int $units, string $mode): array
    {
        if ($units < 1) {
            return [];
        }
        if ($mode !== 'split') {
            return array_fill(0, $units, $cents);
        }
        $base = intdiv($cents, $units);
        $rest = $cents - $base * $units;
        $out = [];
        for ($i = 0; $i < $units; $i++) {
            $out[] = $base + ($i < $rest ? 1 : 0);
        }
        return $out;
    }

    /**
     * Grava um lançamento por unidade (a principal primeiro). Erro em qualquer um desfaz todos.
     *
     * @return list<array> registros gravados, na ordem das unidades
     * @throws RegistryValidationException
     */
    public function create(User $user, string $slug, array $input): array
    {
        $extra = self::extraIds($input['extra_unit_ids'] ?? null, $input['unit_id'] ?? null);
        $mode = is_string($input['unit_amount_mode'] ?? null) && isset(self::MODES[$input['unit_amount_mode']]) ? $input['unit_amount_mode'] : 'each';
        $errors = [];
        if ((int)(filter_var($input['unit_id'] ?? null, FILTER_VALIDATE_INT) ?: 0) < 1) {
            $errors['unit_id'] = 'Escolha a primeira unidade para lançar em várias.';
        }
        if (count($extra) + 1 > self::MAX_UNITS) {
            $errors['extra_unit_ids'] = 'Escolha até ' . self::MAX_UNITS . ' unidades por cadastro. Para o condomínio inteiro, use a fatura.';
        }
        $cents = self::cents($input['amount_cents'] ?? null);
        if ($mode === 'split' && $cents !== null && $cents < count($extra) + 1) {
            $errors['amount_cents'] = 'O total precisa dar pelo menos R$ 0,01 para cada unidade.';
        }
        if ($errors !== []) {
            throw new RegistryValidationException($errors);
        }
        $units = array_merge([(int)$input['unit_id']], $extra);
        $amounts = $cents === null ? array_fill(0, count($units), $input['amount_cents'] ?? null) : array_map(
            static fn (int $c): string => intdiv($c, 100) . '.' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT),
            self::amounts($cents, count($units), $mode));
        unset($input['extra_unit_ids'], $input['unit_amount_mode']);

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        $saved = [];
        try {
            foreach ($units as $i => $unit) {
                try {
                    $saved[] = $this->registry->save($user, $slug, ['unit_id' => (string)$unit, 'amount_cents' => $amounts[$i]] + $input);
                } catch (RegistryValidationException $exception) {
                    // Erro da primeira unidade aparece nos campos; o das outras, no campo das outras unidades.
                    if ($i === 0) {
                        throw $exception;
                    }
                    $first = (string)(array_values($exception->errors)[0] ?? 'Dado inválido.');
                    $label = array_column($this->registry->units($user), 'label', 'id')[$unit] ?? ('código ' . $unit);
                    throw new RegistryValidationException(['extra_unit_ids' => 'Unidade ' . $label . ': ' . $first]);
                }
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $saved;
    }

    /** Valor em centavos pela mesma regra do campo de dinheiro; null = inválido (o cadastro mostra o erro). */
    private static function cents(mixed $value): ?int
    {
        $text = str_replace(['R$', ' '], '', trim((string)$value));
        if (preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/', $text)) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $text)) {
            return null;
        }
        $cents = (int)round((float)$text * 100);
        return $cents > 0 ? $cents : null;
    }
}
