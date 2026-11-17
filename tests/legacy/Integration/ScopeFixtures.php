<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use PDO;

/**
 * Linhas de fixture para os testes de escopo (ADR 0340, 0341, 0342): preenche as colunas obrigatórias sem padrão com valores
 * neutros, a partir do information_schema, e aponta as chaves dadas em $refs para registros reais ("*sufixo" casa o fim do
 * nome da coluna). Chaves estrangeiras ficam desligadas só durante o INSERT.
 */
trait ScopeFixtures
{
    /**
     * @param array<string, mixed> $values valores fixos (ganham de tudo; coluna que a tabela não tem é ignorada)
     * @param array<string, int> $refs coluna (ou "*sufixo") => id real
     */
    private function scopeRow(string $table, array $values, array $refs = []): int
    {
        $cols = $this->pdo->prepare("SELECT column_name, data_type, column_type, character_maximum_length, is_nullable, column_default, extra
            FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position");
        $cols->execute([$table]);
        $row = [];
        $columns = $cols->fetchAll(PDO::FETCH_ASSOC);
        $values = array_intersect_key($values, array_flip(array_column($columns, 'column_name')));
        foreach ($columns as $c) {
            $name = (string)$c['column_name'];
            if (str_contains((string)$c['extra'], 'auto_increment') || str_contains((string)$c['extra'], 'GENERATED')) {
                continue;
            }
            $ref = $refs[$name] ?? null;
            foreach ($refs as $key => $id) {
                if ($ref === null && str_starts_with($key, '*') && str_ends_with($name, substr($key, 1))) {
                    $ref = $id;
                }
            }
            if ($ref !== null) {
                $row[$name] = $ref;
            } elseif ($c['is_nullable'] === 'NO' && $c['column_default'] === null) {
                $row[$name] = match ((string)$c['data_type']) {
                    'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double' => 1,
                    'date' => date('Y-m-d'),
                    'datetime', 'timestamp' => date('Y-m-d H:i:s'),
                    'time' => '10:00:00',
                    'year' => (int)date('Y'),
                    'enum' => preg_match("/^enum\\('([^']*)'/", (string)$c['column_type'], $m) ? $m[1] : '',
                    'json', 'longtext' => '{}',
                    default => $name === 'competence' ? date('Y-m') : self::scopeText($name, (int)($c['character_maximum_length'] ?? 20)),
                };
            }
        }
        $row = $values + $row;
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $names = array_keys($row);
        $this->pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')')
            ->execute(array_values($row));
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $id;
    }

    /** Texto curto e único: o sufixo aleatório nunca é cortado (colunas com UNIQUE). */
    private static function scopeText(string $name, int $max): string
    {
        $suffix = bin2hex(random_bytes(3));
        $max = max(1, min(20, $max));
        return $max <= 6 ? substr($suffix, 0, $max) : substr($name, 0, $max - 6) . $suffix;
    }
}
