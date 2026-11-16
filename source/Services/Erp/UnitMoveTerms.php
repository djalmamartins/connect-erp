<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Termo de entrada e de saída da unidade (revisão de 15/11, etapa 2, ADR 0311; opções do modelo 2).
 *
 * - Texto: um por condomínio e tipo, ou o geral da Connect (condomínio vazio), usado por quem não tem texto próprio.
 *   Sem nenhum gravado, vale o texto padrão abaixo. Texto puro: linha em branco separa parágrafos.
 * - Palavras-chave (KEYWORDS) trocadas na hora de gerar o termo, sempre escapadas (o texto nunca vira HTML).
 * - Permissões: editar o texto do condomínio exige o condomínio no escopo; o geral, acesso a todos os condomínios.
 *   Gerar o termo exige a unidade no escopo (fora = 404). Gravar texto entra na auditoria.
 */
final class UnitMoveTerms
{
    public const KINDS = ['entrada' => 'Termo de entrada', 'saida' => 'Termo de saída'];
    public const MAX_LENGTH = 20000;
    public const KEYWORDS = [
        'condominio' => 'Nome do condomínio',
        'unidade' => 'Unidade',
        'bloco' => 'Bloco',
        'morador' => 'Nome do inquilino ou proprietário',
        'documento' => 'Documento (CPF/CNPJ)',
        'cidade' => 'Cidade',
        'uf' => 'Estado',
        'dataCriado' => 'Data em que o termo foi gerado',
        'linhaAssinatura' => 'Linha para assinatura',
    ];
    public const DEFAULTS = [
        'entrada' => "Eu, @morador, CPF/CNPJ @documento, declaro que a partir desta data passo a ocupar a unidade @unidade do @condominio e que recebi a convenção e o regimento interno do condomínio, comprometendo-me a cumpri-los e a fazê-los cumprir pelos demais ocupantes da unidade.\n\nDeclaro também que os dados informados à administração são verdadeiros e que comunicarei qualquer alteração.\n\n@cidade/@uf, @dataCriado.\n\n@linhaAssinatura\n@morador",
        'saida' => "Eu, @morador, CPF/CNPJ @documento, declaro que nesta data deixo de ocupar a unidade @unidade do @condominio e entrego as chaves e os dispositivos de acesso que estavam comigo.\n\nEstou ciente de que as contas da unidade até esta data seguem as regras do condomínio e do contrato, e que a saída não quita valores em aberto.\n\n@cidade/@uf, @dataCriado.\n\n@linhaAssinatura\n@morador",
    ];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Texto em vigor para editar: o do condomínio, senão o geral, senão o padrão.
     * @return array{condominium_id:?int, kind:string, body:string, source:string, updated_at:?string}
     */
    public function forEdit(User $user, ?int $condominium, string $kind): array
    {
        $kind = self::kind($kind);
        $this->assertEditable($user, $condominium);
        return ['condominium_id' => $condominium, 'kind' => $kind] + $this->effective($condominium, $kind);
    }

    /** @return array{condominium_id:?int, kind:string, body:string, source:string, updated_at:?string} */
    public function save(User $user, ?int $condominium, string $kind, mixed $body): array
    {
        $kind = self::kind($kind);
        $this->assertEditable($user, $condominium);
        $text = is_string($body) ? trim(str_replace("\r\n", "\n", $body)) : '';
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            throw new RegistryValidationException(['body' => 'Escreva o texto do termo (até ' . number_format(self::MAX_LENGTH, 0, ',', '.') . ' caracteres).']);
        }
        $current = $this->stored($condominium, $kind);
        if ($current === null) {
            $this->pdo->prepare('INSERT INTO erp_unit_move_terms (condominium_id, kind, body, created_by, updated_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$condominium, $kind, $text, (int)$user->id, (int)$user->id]);
            Audit::record('create', 'erp_unit_move_terms', (int)$this->pdo->lastInsertId(), null, ['condominium_id' => $condominium, 'kind' => $kind, 'length' => mb_strlen($text)]);
        } elseif ($current['body'] !== $text) {
            $this->pdo->prepare('UPDATE erp_unit_move_terms SET body = ?, updated_by = ? WHERE id = ?')->execute([$text, (int)$user->id, (int)$current['id']]);
            Audit::record('update', 'erp_unit_move_terms', (int)$current['id'], ['length' => mb_strlen((string)$current['body'])], ['condominium_id' => $condominium, 'kind' => $kind, 'length' => mb_strlen($text)]);
        }
        return ['condominium_id' => $condominium, 'kind' => $kind] + $this->effective($condominium, $kind);
    }

    /**
     * Termo pronto para imprimir: texto em vigor do condomínio da unidade com as palavras-chave trocadas.
     * @return array{kind:string, title:string, source:string, html:string, condominium:string, unit:string, person:?string, created_on:string}
     */
    public function render(User $user, int $unitId, ?int $residentId, string $kind, ?\DateTimeImmutable $today = null): array
    {
        $kind = self::kind($kind);
        $stmt = $this->pdo->prepare('SELECT u.id, u.condominium_id, u.label, u.block, u.number, c.name AS condominium, c.city, c.state
            FROM erp_units u JOIN condominiums c ON c.id = u.condominium_id WHERE u.id = ? AND u.deleted_at IS NULL');
        $stmt->execute([$unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($unit === false || !$this->scope->canAccess($user, (int)$unit['condominium_id'])) {
            throw new RegistryNotFound();
        }
        $person = null;
        if ($residentId !== null) {
            $p = $this->pdo->prepare('SELECT name, document FROM erp_residents WHERE id = ? AND unit_id = ? AND deleted_at IS NULL');
            $p->execute([$residentId, $unitId]);
            $person = $p->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($person === null) {
                throw new RegistryValidationException(['resident_id' => 'Essa pessoa não está ligada a esta unidade.']);
            }
        }
        $today ??= new \DateTimeImmutable('today');
        $term = $this->effective((int)$unit['condominium_id'], $kind);
        $values = [
            'condominio' => (string)$unit['condominium'],
            'unidade' => (string)($unit['label'] ?: $unit['number']),
            'bloco' => (string)($unit['block'] ?? ''),
            'morador' => (string)($person['name'] ?? ''),
            'documento' => self::document($person['document'] ?? null),
            'cidade' => (string)($unit['city'] ?? ''),
            'uf' => (string)($unit['state'] ?? ''),
            'dataCriado' => self::longDate($today),
        ];
        return ['kind' => $kind, 'title' => self::KINDS[$kind], 'source' => $term['source'], 'html' => self::fill($term['body'], $values),
            'condominium' => $values['condominio'], 'unit' => $values['unidade'], 'person' => $person['name'] ?? null, 'created_on' => $today->format('Y-m-d')];
    }

    /**
     * Troca as palavras-chave e devolve HTML seguro: cada pedaço do texto é escapado; linha em branco = parágrafo,
     * quebra simples = <br>; @linhaAssinatura vira a linha de assinatura. Palavra-chave sem valor fica em branco.
     * @param array<string, string> $values
     */
    public static function fill(string $body, array $values): string
    {
        $paragraphs = preg_split('/\n\s*\n/', trim(str_replace("\r\n", "\n", $body))) ?: [];
        $out = [];
        foreach ($paragraphs as $paragraph) {
            $lines = array_map(static function (string $line) use ($values): string {
                $parts = preg_split('/@(' . implode('|', array_keys(self::KEYWORDS)) . ')\b/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
                $html = '';
                foreach ($parts as $i => $part) {
                    if ($i % 2 === 0) {
                        $html .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
                    } elseif ($part === 'linhaAssinatura') {
                        $html .= '<span class="erp-move-term__sign" aria-label="Linha para assinatura"></span>';
                    } else {
                        $html .= htmlspecialchars($values[$part] ?? '', ENT_QUOTES, 'UTF-8');
                    }
                }
                return $html;
            }, explode("\n", trim($paragraph)));
            $out[] = '<p>' . implode('<br>', $lines) . '</p>';
        }
        return implode("\n", $out);
    }

    public static function kind(mixed $kind): string
    {
        if (!is_string($kind) || !isset(self::KINDS[$kind])) {
            throw new RegistryValidationException(['kind' => 'Escolha o tipo: entrada ou saída.']);
        }
        return $kind;
    }

    /** @return array{body:string, source:string, updated_at:?string} */
    private function effective(?int $condominium, string $kind): array
    {
        if ($condominium !== null && ($own = $this->stored($condominium, $kind)) !== null) {
            return ['body' => (string)$own['body'], 'source' => 'condominium', 'updated_at' => (string)$own['updated_at']];
        }
        if (($general = $this->stored(null, $kind)) !== null) {
            return ['body' => (string)$general['body'], 'source' => 'general', 'updated_at' => (string)$general['updated_at']];
        }
        return ['body' => self::DEFAULTS[$kind], 'source' => 'default', 'updated_at' => null];
    }

    /** @return ?array{id:int|string, body:string, updated_at:string} */
    private function stored(?int $condominium, string $kind): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, body, updated_at FROM erp_unit_move_terms WHERE scope_key = ? AND kind = ?');
        $stmt->execute([$condominium ?? 0, $kind]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function assertEditable(User $user, ?int $condominium): void
    {
        if ($condominium === null ? !$this->scope->hasGlobalAccess($user) : !$this->scope->canAccess($user, $condominium)) {
            throw new RegistryNotFound();
        }
    }

    private static function document(?string $digits): string
    {
        $d = preg_replace('/\D/', '', (string)$digits) ?? '';
        if (strlen($d) === 11) {
            return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9);
        }
        if (strlen($d) === 14) {
            return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12);
        }
        return $d;
    }

    private static function longDate(\DateTimeImmutable $date): string
    {
        $months = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        return (int)$date->format('j') . ' de ' . $months[(int)$date->format('n') - 1] . ' de ' . $date->format('Y');
    }
}
