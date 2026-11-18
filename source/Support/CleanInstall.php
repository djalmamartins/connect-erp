<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Instalação limpa do banco da produção (ADR 0396, passo 2 do ADR 0379).
 *
 * Num banco vazio: estrutura base exportada do laboratório (`database/base/schema.sql`, sem dados), depois os catálogos
 * das migrations antigas (`database/legacy-migrations/migrations`: papéis, permissões, versões, páginas iniciais) e a linha
 * de configuração (`settings` id 1). As migrations atuais (`composer db:migrate`) rodam depois, pelo comando.
 *
 * As migrations antigas foram escritas para o banco antigo e algumas não repetem em cima da estrutura nova (tabela que já
 * existe, chave que não existe mais): cada comando roda sozinho e a falha é anotada e ignorada, como `mariadb --force`.
 */
final class CleanInstall
{
    /** @return array{tables: int, legacy_ok: int, legacy_skipped: list<string>, settings: bool} */
    public static function run(\PDO $pdo, string $root, string $siteUrl = ''): array
    {
        $existing = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchColumn();
        if ($existing > 0) {
            throw new \DomainException("O banco já tem {$existing} tabela(s). A instalação limpa só roda num banco vazio; para atualizar, use composer db:migrate.");
        }
        $schema = $root . '/database/base/schema.sql';
        if (!is_file($schema)) {
            throw new \DomainException('Falta database/base/schema.sql (gerado por tools/lab-exportar-esquema.sh).');
        }
        $sql = (string)file_get_contents($schema);
        if (preg_match('/^\s*INSERT\s+INTO/im', $sql)) {
            throw new \DomainException('database/base/schema.sql traz dados (INSERT): parado.');
        }
        foreach (self::statements($sql) as $statement) {
            $pdo->exec($statement);
        }
        $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchColumn();

        $ok = 0;
        $skipped = [];
        $files = glob($root . '/database/legacy-migrations/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            foreach (self::statements((string)file_get_contents($file)) as $index => $statement) {
                try {
                    $pdo->exec($statement);
                    $ok++;
                } catch (\PDOException $error) {
                    $skipped[] = basename($file) . ' #' . ($index + 1) . ': ' . strtok((string)$error->getMessage(), "\n");
                }
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        return ['tables' => $tables, 'legacy_ok' => $ok, 'legacy_skipped' => $skipped, 'settings' => self::seedSettings($pdo, $siteUrl)];
    }

    /** Linha de configuração com os temas que existem em container/; o resto se ajusta depois em Studio > Configurações. */
    public static function seedSettings(\PDO $pdo, string $siteUrl): bool
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM settings WHERE id = 1')->fetchColumn() > 0) {
            return false;
        }
        $columns = array_flip($pdo->query('SHOW COLUMNS FROM settings')->fetchAll(\PDO::FETCH_COLUMN));
        $url = rtrim($siteUrl !== '' ? $siteUrl : 'https://www.connectcondominios.com.br', '/');
        $values = [
            'id' => 1, 'mode' => 1, 'site_name' => 'Connect Condomínios', 'site_title' => 'Connect Condomínios',
            'site_desc' => 'Administração de condomínios', 'site_lang' => 'pt_BR', 'site_domain' => (string)parse_url($url, PHP_URL_HOST),
            'site_domain_ssl' => $url, 'view_theme' => 'default', 'view_support' => 'support', 'view_app' => 'connect',
            'view_erp' => 'connect', 'view_admin' => 'studio', 'view_mail' => 'default', 'view_upkeep' => 'default',
            'mail_name' => 'Connect Condomínios', 'mail_lang' => 'pt_BR', 'mail_html' => '1', 'mail_charset' => 'UTF-8',
            'pay_back' => '/', 'timezone_set' => 'America/Sao_Paulo',
            'access_studio' => 1, 'access_erp' => 1, 'access_app' => 1, 'access_site' => 1, 'access_support' => 1,
        ];
        $values = array_intersect_key($values, $columns);
        $names = array_keys($values);
        $pdo->prepare('INSERT INTO settings (`' . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')')
            ->execute(array_values($values));
        return true;
    }

    /**
     * Separa um arquivo .sql em comandos pelo ";" fora de aspas e comentários (os arquivos não usam DELIMITER).
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $statements = [];
        $current = '';
        $quote = null;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $current .= $sql[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '-' && substr($sql, $i, 3) === '-- ' || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= "\n";
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*' && ($sql[$i + 2] ?? '') !== '!') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            }
            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}
