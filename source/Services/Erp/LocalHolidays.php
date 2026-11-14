<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Support\Audit;

/**
 * Feriados do estado e da cidade (ADR 0306) que entram no prazo do 5º dia útil da prestação de contas (ADR 0301).
 *
 * O lugar vem do cadastro do condomínio (cidade e UF). Um feriado estadual vale para todos os condomínios daquela UF;
 * um municipal, para os daquela cidade (comparada sem acento, sem diferença de maiúsculas e de espaços). "Todo ano"
 * guarda só dia e mês; "só neste ano" guarda também o ano (ponto facultativo decretado, por exemplo).
 *
 * Ver e cadastrar exige alcançar o condomínio (CondominiumScope): fora do escopo = 404. A permissão (ver: livro caixa;
 * cadastrar e remover: erp.closing.manage) fica com quem chama, como no link da prestação de contas.
 */
final class LocalHolidays
{
    public const SCOPES = ['state', 'city'];
    public const STATES = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR',
        'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Cidade para comparar (regra pura): sem acento, minúsculas e um espaço entre as palavras. */
    public static function cityKey(?string $city): string
    {
        $city = trim((string)$city);
        if ($city === '') {
            return '';
        }
        $ascii = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $city);
        $ascii = (string)preg_replace('/[^a-z0-9]+/', ' ', strtolower($ascii));
        return substr(trim($ascii), 0, 100);
    }

    /** UF válida em maiúsculas ou null (regra pura). */
    public static function state(?string $state): ?string
    {
        $state = strtoupper(trim((string)$state));
        return in_array($state, self::STATES, true) ? $state : null;
    }

    /**
     * Datas AAAA-MM-DD dos feriados cadastrados no ano (regra pura). Dia que não existe no ano (29/02 fora do
     * bissexto) fica de fora.
     *
     * @param list<array{month:int|string, day:int|string, year:int|string}> $rows
     * @return list<string>
     */
    public static function datesFor(array $rows, int $year): array
    {
        $dates = [];
        foreach ($rows as $row) {
            $only = (int)$row['year'];
            if ($only !== 0 && $only !== $year) {
                continue;
            }
            if (checkdate((int)$row['month'], (int)$row['day'], $year)) {
                $dates[] = sprintf('%04d-%02d-%02d', $year, (int)$row['month'], (int)$row['day']);
            }
        }
        $dates = array_values(array_unique($dates));
        sort($dates);
        return $dates;
    }

    /**
     * Lê a data do formulário (regra pura): AAAA-MM-DD ou DD/MM/AAAA. @return array{month:int, day:int, year:int}
     */
    public static function parseDate(mixed $value): array
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m)) {
            [$day, $month, $year] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } else {
            throw new InvalidArgumentException('Informe a data do feriado (dd/mm/aaaa).');
        }
        if ($year < 2000 || $year > 2100 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('Data do feriado inválida.');
        }
        return ['month' => $month, 'day' => $day, 'year' => $year];
    }

    /** Feriados que valem no lugar (estado e cidade), de todo ano e de anos específicos. @return list<array> */
    public function forPlace(?string $state, ?string $city): array
    {
        $state = self::state($state);
        if ($state === null) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT * FROM erp_local_holidays WHERE state = :s AND (city_key = '' OR city_key = :c)
            ORDER BY month, day, year, city_key");
        $stmt->execute(['s' => $state, 'c' => self::cityKey($city)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lugar do condomínio (cidade e UF do cadastro), sem conferir escopo. @return array{city:?string, state:?string} */
    public function place(int $condominiumId): array
    {
        $stmt = $this->pdo->prepare('SELECT city, state FROM condominiums WHERE id = :id');
        $stmt->execute(['id' => $condominiumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new CondominiumAccessDenied($condominiumId);
        }
        $city = trim((string)($row['city'] ?? ''));
        return ['city' => $city !== '' ? $city : null, 'state' => self::state($row['state'] ?? null)];
    }

    /** Datas dos feriados locais do condomínio nos anos pedidos (para o prazo). @return list<string> */
    public function datesForCondominium(int $condominiumId, int ...$years): array
    {
        $place = $this->place($condominiumId);
        $rows = $this->forPlace($place['state'], $place['city']);
        $dates = [];
        foreach ($years as $year) {
            array_push($dates, ...self::datesFor($rows, $year));
        }
        return array_values(array_unique($dates));
    }

    /**
     * Feriados do condomínio para a tela e a API, com o prazo do livro do mês pedido.
     *
     * @return array{condominium_id:int, city:?string, state:?string, holidays:list<array>, deadline:?array}
     */
    public function list(User $user, ?int $condominiumId, ?string $competence = null): array
    {
        $condominiumId = $this->condominium($user, $condominiumId);
        $place = $this->place($condominiumId);
        $holidays = array_map(fn (array $row): array => self::present($row), $this->forPlace($place['state'], $place['city']));
        $deadline = null;
        if ($competence !== null && RecurringEntries::isCompetence($competence)) {
            $due = (new DateTimeImmutable($competence . '-01'))->modify('+1 month')->format('Y-m');
            $year = (int)substr($due, 0, 4);
            $local = $this->datesForCondominium($condominiumId, $year);
            $day = AccountabilityDeadline::businessDay($due, AccountabilityDeadline::BUSINESS_DAY, $local);
            $national = AccountabilityDeadline::businessDay($due);
            $deadline = ['competence' => $competence, 'date' => $day->format('Y-m-d'), 'national_date' => $national->format('Y-m-d'),
                'moved_by_local' => $day != $national];
        }
        return ['condominium_id' => $condominiumId, 'city' => $place['city'], 'state' => $place['state'], 'holidays' => $holidays, 'deadline' => $deadline];
    }

    /**
     * Cadastra um feriado do estado ou da cidade do condomínio.
     *
     * @param array{scope?:mixed, date?:mixed, every_year?:mixed, name?:mixed} $input
     */
    public function add(User $user, ?int $condominiumId, array $input): array
    {
        $condominiumId = $this->condominium($user, $condominiumId);
        $place = $this->place($condominiumId);
        $scope = is_string($input['scope'] ?? null) ? $input['scope'] : 'city';
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Escolha se o feriado é do estado ou da cidade.');
        }
        if ($place['state'] === null) {
            throw new InvalidArgumentException('Cadastre a UF do condomínio antes de incluir feriados locais.');
        }
        if ($scope === 'city' && self::cityKey($place['city']) === '') {
            throw new InvalidArgumentException('Cadastre a cidade do condomínio antes de incluir feriados municipais.');
        }
        $name = trim(is_string($input['name'] ?? null) ? (string)$input['name'] : '');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Informe o nome do feriado (3 a 120 caracteres).');
        }
        $date = self::parseDate($input['date'] ?? null);
        $everyYear = filter_var($input['every_year'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        if ($everyYear && $date['month'] === 2 && $date['day'] === 29) {
            throw new InvalidArgumentException('29/02 só existe em ano bissexto: marque "só neste ano".');
        }
        $row = [
            'state' => $place['state'],
            'city' => $scope === 'city' ? $place['city'] : null,
            'city_key' => $scope === 'city' ? self::cityKey($place['city']) : '',
            'month' => $date['month'], 'day' => $date['day'], 'year' => $everyYear ? 0 : $date['year'],
            'name' => $name, 'created_by' => (int)$user->id,
        ];
        try {
            $this->pdo->prepare('INSERT INTO erp_local_holidays (state, city, city_key, month, day, year, name, created_by)
                VALUES (:state, :city, :city_key, :month, :day, :year, :name, :created_by)')->execute($row);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                throw new DomainException('Esse feriado já está cadastrado para este lugar.');
            }
            throw $error;
        }
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'erp_local_holidays', $id, null, $row + ['condominium_id' => $condominiumId]);
        return self::present(['id' => $id] + $row);
    }

    /** Remove um feriado que vale para o lugar do condomínio (o de outro lugar = 404). */
    public function remove(User $user, ?int $condominiumId, int $id): array
    {
        $condominiumId = $this->condominium($user, $condominiumId);
        $place = $this->place($condominiumId);
        $stmt = $this->pdo->prepare('SELECT * FROM erp_local_holidays WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['state'] !== $place['state']
            || ($row['city_key'] !== '' && $row['city_key'] !== self::cityKey($place['city']))) {
            throw new CondominiumAccessDenied($condominiumId);
        }
        $this->pdo->prepare('DELETE FROM erp_local_holidays WHERE id = :id')->execute(['id' => $id]);
        Audit::record('delete', 'erp_local_holidays', $id, $row + ['condominium_id' => $condominiumId], null);
        return ['id' => $id, 'removed' => true];
    }

    public static function present(array $row): array
    {
        $year = (int)$row['year'];
        return [
            'id' => (int)$row['id'], 'scope' => (string)$row['city_key'] === '' ? 'state' : 'city',
            'state' => (string)$row['state'], 'city' => $row['city'] !== null ? (string)$row['city'] : null,
            'month' => (int)$row['month'], 'day' => (int)$row['day'], 'year' => $year === 0 ? null : $year,
            'every_year' => $year === 0, 'name' => (string)$row['name'],
            'label' => sprintf('%02d/%02d', (int)$row['day'], (int)$row['month']) . ($year === 0 ? '' : '/' . $year),
        ];
    }

    private function condominium(User $user, ?int $condominiumId): int
    {
        if ($condominiumId === null || $condominiumId < 1) {
            throw new InvalidArgumentException('Informe o condomínio.');
        }
        $this->scope->assertAccess($user, $condominiumId);
        return $condominiumId;
    }
}
