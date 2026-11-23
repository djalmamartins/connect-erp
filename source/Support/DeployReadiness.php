<?php

declare(strict_types=1);

namespace Source\Support;

use PDO;

/**
 * Conferência de produção antes de publicar (ADR 0354), chamada pelo service/commands/deploy-check.php.
 *
 * - env(): o .env de produção. Erro quando algo deixa o sistema inseguro ou quebrado (debug ligado, dados de
 *   demonstração, endereço do laboratório, chave do 2FA ou do cofre dos bancos ausente, inválida ou repetida). Aviso
 *   quando falta algo que o roteiro da hospedagem pede (2FA ainda não obrigatório, e-mail de alerta, entrega de e-mail).
 * - database(): o banco de produção. Erro com conta ativa de teste (e-mail do laboratório ou de exemplo). Aviso com
 *   pessoa da equipe sem 2FA confirmado e credencial de banco em ambiente de teste.
 *
 * - php(): limites do PHP (ADR 0362). Aviso quando a memória ou o tamanho de envio ficam abaixo do que o sistema aceita
 *   (PDFs grandes, importações e o maior anexo: 50 MB), porque o envio falharia sem mensagem clara.
 *
 * Mostra só ids e quantidades, nunca e-mail ou nome (LGPD). Tabela que ainda não existe (antes da primeira migration)
 * é ignorada.
 */
final class DeployReadiness
{
    /** Domínios que só existem no laboratório e nos testes. */
    public const TEST_DOMAINS = ['connect.lab', 'exemplo.test', 'example.com', 'example.org', 'teste.com', 'test.com'];

    /** Maior arquivo que o sistema aceita (mídia do Studio, Upload::MEDIA_MAX_BYTES). */
    public const REQUIRED_UPLOAD_BYTES = 52428800;

    /**
     * Memória mínima por processo para os PDFs e as importações. 512M desde o ADR 0384: o livro-caixa (uma tabela só no
     * Dompdf) gasta ~135 MB com 400 lançamentos no mês e ~550 MB com 1.000.
     */
    public const REQUIRED_MEMORY_BYTES = 536870912;

    /**
     * @param array<string, string|false> $ini valores de ini_get (memory_limit, upload_max_filesize, post_max_size, file_uploads)
     * @return list<string> avisos
     */
    public static function php(array $ini): array
    {
        $warnings = [];
        $where = ' Ajustar no hPanel (Avançado > Configuração do PHP), que vale para o site.';
        $memory = self::bytes($ini['memory_limit'] ?? '');
        if ($memory !== -1 && $memory < self::REQUIRED_MEMORY_BYTES) {
            $warnings[] = 'memory_limit do PHP abaixo de 512M: o livro-caixa de um mês cheio, outros PDFs grandes e importações podem parar no meio.' . $where;
        }
        if (!filter_var($ini['file_uploads'] ?? '1', FILTER_VALIDATE_BOOL)) {
            $warnings[] = 'file_uploads do PHP está desligado: nenhum anexo ou foto é recebido.' . $where;
        }
        $upload = self::bytes($ini['upload_max_filesize'] ?? '');
        if ($upload < self::REQUIRED_UPLOAD_BYTES) {
            $warnings[] = 'upload_max_filesize do PHP abaixo de 50M: arquivos que o sistema aceita seriam recusados pelo PHP.' . $where;
        }
        $post = self::bytes($ini['post_max_size'] ?? '');
        if ($post !== 0 && $post < max($upload, self::REQUIRED_UPLOAD_BYTES)) {
            $warnings[] = 'post_max_size do PHP menor que o upload_max_filesize (ou que 50M): o formulário chega vazio.' . $where;
        }
        return $warnings;
    }

    /** "128M" → bytes; "-1" → -1; vazio ou inválido → 0. */
    public static function bytes(string|false $value): int
    {
        $value = strtoupper(trim((string)$value));
        if ($value === '-1') {
            return -1;
        }
        if (!preg_match('~^(\d+)([KMG]?)~', $value, $m)) {
            return 0;
        }
        return (int)$m[1] * match ($m[2]) { 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1 };
    }

    /**
     * @param array<string, string> $env
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public static function env(array $env, int $now): array
    {
        $errors = [];
        $warnings = [];
        $get = static fn (string $key): string => trim((string)($env[$key] ?? ''));
        $on = static fn (string $key): bool => filter_var($get($key), FILTER_VALIDATE_BOOL);

        if ($on('APP_DEBUG')) {
            $errors[] = 'APP_DEBUG precisa ser false em produção (mostra erros e caminhos para o visitante).';
        }
        if ($on('ERP_DEMO_DATA')) {
            $errors[] = 'ERP_DEMO_DATA precisa ser false em produção (as telas mostrariam dados de exemplo).';
        }
        $host = strtolower((string)(parse_url($get('APP_URL'), PHP_URL_HOST) ?: ''));
        if ($host === '' || self::isTestHost($host)) {
            $errors[] = 'APP_URL precisa ser o endereço de produção (está vazio ou é do laboratório).';
        }
        $keys = [];
        foreach (['CONNECT_2FA_KEY' => 'do 2FA', 'CONNECT_BANK_VAULT_KEY' => 'do cofre dos bancos'] as $key => $label) {
            $raw = $get($key);
            $decoded = base64_decode($raw, true);
            if ($raw === '' || $decoded === false || strlen($decoded) !== 32) {
                $errors[] = sprintf('%s (chave %s) ausente ou inválida: precisa de 32 bytes em base64.', $key, $label);
                continue;
            }
            $keys[$key] = $raw;
        }
        if (count($keys) === 2 && count(array_unique($keys)) === 1) {
            $errors[] = 'CONNECT_2FA_KEY e CONNECT_BANK_VAULT_KEY não podem ser a mesma chave.';
        }
        $storage = \Source\Support\Storage\DocumentStores::mode($env);
        if ($storage === '') {
            $errors[] = 'DOCUMENT_STORAGE precisa ser local ou onedrive (ADR 0374).';
        } elseif ($storage === 'onedrive' && ($missing = \Source\Support\Storage\DocumentStores::missing($env)) !== []) {
            $errors[] = 'DOCUMENT_STORAGE=onedrive sem ' . implode(', ', $missing) . ': os arquivos continuariam no servidor.';
        }
        $talk = $get('TALK_KEY');
        if ($talk !== '' && in_array($talk, $keys, true)) {
            $errors[] = 'TALK_KEY não pode repetir a chave do 2FA ou do cofre dos bancos.';
        }

        $from = $get('CONNECT_2FA_ENFORCE_FROM');
        $start = $from !== '' ? strtotime($from . ' 00:00:00') : false;
        if ($start === false || $start > $now) {
            $warnings[] = 'CONNECT_2FA_ENFORCE_FROM vazio ou no futuro: a equipe ainda entra sem 2FA.';
        }
        if (filter_var($get('ALERT_EMAIL'), FILTER_VALIDATE_EMAIL) === false) {
            $warnings[] = 'ALERT_EMAIL vazio ou inválido: o vigia do servidor não manda e-mail (HOSPEDAGEM.md §8).';
        }
        if ($get('ERP_MAIL_DELIVERY') !== 'production') {
            $warnings[] = 'ERP_MAIL_DELIVERY não é "production": os avisos e boletos por e-mail não saem para os moradores.';
        }
        if ($get('MOVESOS_SMTP_HOST') === '') {
            $warnings[] = 'MOVESOS_SMTP_HOST vazio: sem servidor de e-mail.';
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** @return array{errors: list<string>, warnings: list<string>} */
    public static function database(PDO $pdo): array
    {
        $errors = [];
        $warnings = [];
        $ids = self::ids($pdo, 'SELECT id, email FROM users ORDER BY id', static fn (array $r): bool => self::isTestEmail((string)$r['email']));
        if ($ids !== null && $ids !== []) {
            $errors[] = sprintf('%d conta(s) de teste ativa(s) no banco (ids %s). Exclua ou troque o e-mail antes de abrir.', count($ids), self::list($ids));
        }
        $staff = self::ids($pdo, 'SELECT DISTINCT r.user_id AS id FROM access_user_roles r
                LEFT JOIN user_two_factor t ON t.user_id = r.user_id AND t.confirmed_at IS NOT NULL
            WHERE t.user_id IS NULL ORDER BY r.user_id');
        if ($staff !== null && $staff !== []) {
            $warnings[] = sprintf('%d pessoa(s) da equipe sem 2FA confirmado (ids %s).', count($staff), self::list($staff));
        }
        $sandbox = self::ids($pdo, "SELECT id FROM erp_bank_credentials WHERE environment <> 'production' ORDER BY id");
        if ($sandbox !== null && $sandbox !== []) {
            $warnings[] = sprintf('%d credencial(is) de banco em ambiente de teste (ids %s): boletos não valem de verdade.', count($sandbox), self::list($sandbox));
        }
        $missing = self::missingSiteContact($pdo);
        if ($missing !== []) {
            $warnings[] = 'Dados da Connect vazios no Studio > Configurações (CDC: o site mostra CNPJ, endereço e contato): '
                . implode(', ', $missing) . '.';
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Campos do site que precisam estar preenchidos antes de abrir (revisão de 23/11): sem eles o site mostra
     * rodapé sem CNPJ e cartões de contato vazios.
     *
     * @return list<string>
     */
    public static function missingSiteContact(PDO $pdo): array
    {
        $labels = ['site_company_name' => 'razão social', 'site_cnpj' => 'CNPJ', 'site_phone' => 'telefone',
            'mail_suport' => 'e-mail de atendimento', 'site_street' => 'endereço', 'site_city' => 'cidade'];
        try {
            $row = $pdo->query('SELECT * FROM settings ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($row)) {
            return array_values($labels);
        }
        $missing = [];
        foreach ($labels as $column => $label) {
            if (array_key_exists($column, $row) && trim((string)$row[$column]) === '') {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    public static function isTestEmail(string $email): bool
    {
        $at = strrpos($email, '@');
        return $at !== false && self::isTestHost(strtolower(trim(substr($email, $at + 1))));
    }

    private static function isTestHost(string $host): bool
    {
        foreach (array_merge(self::TEST_DOMAINS, ['localhost']) as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return str_ends_with($host, '.lab') || str_ends_with($host, '.test') || str_ends_with($host, '.local')
            || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /** @return list<int>|null null quando a tabela ainda não existe */
    private static function ids(PDO $pdo, string $sql, ?callable $keep = null): ?array
    {
        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $error) {
            return null;
        }
        return array_values(array_map(static fn (array $r): int => (int)$r['id'], $keep !== null ? array_filter($rows, $keep) : $rows));
    }

    /** @param list<int> $ids */
    private static function list(array $ids): string
    {
        return implode(', ', array_slice($ids, 0, 10)) . (count($ids) > 10 ? '…' : '');
    }
}
