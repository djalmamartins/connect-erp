<?php

declare(strict_types=1);

/**
 * Token de integração do Connect Talk (ADR 0308). Só pelo terminal do servidor: o token aparece uma vez, aqui,
 * e no banco fica só o sha256. Cole-o no .env do Talk como ERP_API_TOKEN (nunca no Git).
 *
 *   php tools/talk-token.php criar --usuario=ID_OU_EMAIL [--nome="Connect Talk"]
 *       O usuário precisa estar ativo, ter a permissão support.manage e ter acesso aos condomínios que o Talk vai
 *       atender (todos, com erp.condominiums.all, ou os liberados um a um). Recomendado: um usuário só para a
 *       integração, por exemplo "Connect Talk (integração)".
 *
 *       [--escopos=people.read,charges.read,tickets.write]  (padrão: os três, o que o Talk sempre usou; ADR 0623)
 *       Também dá para criar e revogar na tela Configurações › Integrações, com validade e IPs.
 *
 *   php tools/talk-token.php listar
 *   php tools/talk-token.php revogar --id=ID [--motivo=texto]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Talk\TalkTokens;
use Source\Support\Access;

$command = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    }
}
$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(2);
};

try {
    $tokens = new TalkTokens();
    switch ($command) {
        case 'criar':
            $key = trim((string)($options['usuario'] ?? ''));
            if ($key === '') {
                $fail('Informe --usuario=ID ou --usuario=email do usuário de integração.');
            }
            $user = ctype_digit($key) ? (new User())->findById((int)$key) : (new User())->find('email = :e', 'e=' . mb_strtolower($key))->fetch();
            if (!$user) {
                $fail('Usuário não encontrado.');
            }
            if ((string)$user->status !== 'confirmed') {
                $fail('O usuário não está ativo (confirmed).');
            }
            if (!Access::can(TalkTokens::PERMISSION, $user)) {
                $fail('O usuário não tem a permissão ' . TalkTokens::PERMISSION . ' (atender chamados). Dê um papel com ela antes.');
            }
            $condominiums = count((new CondominiumScope(Connect::getInstance()))->allowedIds($user));
            $scopes = isset($options['escopos']) ? TalkTokens::normalizeScopes($options['escopos']) : null;
            $created = $tokens->create((int)$user->id, (string)($options['nome'] ?? 'Connect Talk'), null, $scopes);
            fwrite(STDOUT, 'Token #' . $created['id'] . ' criado para ' . $user->fullName() . " ({$condominiums} condomínio(s) no escopo)." . PHP_EOL . PHP_EOL);
            fwrite(STDOUT, 'ERP_API_TOKEN=' . $created['token'] . PHP_EOL . PHP_EOL);
            fwrite(STDOUT, 'Cole a linha no .env do Connect Talk, junto com ERP_API_URL=https://<endereço do ERP>/api/v1.' . PHP_EOL);
            fwrite(STDOUT, 'Ele não aparece de novo. Perdeu? Revogue este e crie outro.' . PHP_EOL);
            if ($condominiums === 0) {
                fwrite(STDOUT, 'Atenção: o usuário não tem nenhum condomínio no escopo; o Talk não vai identificar ninguém.' . PHP_EOL);
            }
            break;

        case 'listar':
            foreach ($tokens->list() as $row) {
                fwrite(STDOUT, sprintf("#%d  %s  usuário %s (#%d)  final ...%s  escopos %s  criado %s  vence %s  último uso %s  %s\n", $row['id'], $row['name'], $row['user'], $row['user_id'],
                    $row['hint'], implode(',', $row['scopes']), $row['created_at'], $row['expires_at'] ?? 'nunca', $row['last_used_at'] ?? 'nunca',
                    $row['revoked_at'] !== null ? 'REVOGADO ' . $row['revoked_at'] : 'ativo'));
            }
            break;

        case 'revogar':
            $id = filter_var($options['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                $fail('Informe --id=ID (veja em "listar").');
            }
            fwrite(STDOUT, $tokens->revoke((int)$id, (string)($options['motivo'] ?? 'manual')) ? "Token #{$id} revogado." . PHP_EOL : "Não há token ativo #{$id}." . PHP_EOL);
            break;

        default:
            $fail("Uso: php tools/talk-token.php criar --usuario=ID_OU_EMAIL | listar | revogar --id=ID");
    }
} catch (\Throwable $error) {
    $fail('Erro: ' . $error->getMessage());
}
