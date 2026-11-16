<?php
declare(strict_types=1);

namespace Source\Services\Talk\Erp;

use Source\Services\Talk\Contracts\ContactDirectory;
use Source\Services\Talk\Support\Db;

/**
 * Busca configurável por SQL: basta informar a consulta do ERP que recebe os
 * telefones e devolve person_id, name e colunas extras (viram "details").
 *
 * Exemplo (ajustar aos nomes reais das tabelas do ERP):
 *   SELECT p.id AS person_id, p.nome AS name, c.nome AS condominio, u.descricao AS unidade
 *   FROM pessoas p JOIN unidades u ON u.id = p.unidade_id JOIN condominios c ON c.id = u.condominio_id
 *   WHERE REGEXP_REPLACE(p.celular, '[^0-9]', '') IN (:phones)
 */
final class SqlContactDirectory implements ContactDirectory
{
    public function __construct(private Db $db, private string $sql)
    {
    }

    public function findByPhone(array $phoneCandidates): ?array
    {
        if ($phoneCandidates === []) {
            return null;
        }
        $marks = implode(',', array_fill(0, count($phoneCandidates), '?'));
        $row = $this->db->one(str_replace(':phones', $marks, $this->sql) . ' LIMIT 1', $phoneCandidates);
        if (!$row) {
            return null;
        }
        $id = (int) $row['person_id'];
        $name = (string) $row['name'];
        unset($row['person_id'], $row['name']);
        return ['person_id' => $id, 'name' => $name, 'details' => $row];
    }
}
