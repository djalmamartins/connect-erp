<?php

declare(strict_types=1);

/**
 * Credenciais bancárias no cofre (ADR 0156). Só pelo terminal do servidor: client_secret, certificado
 * e chave nunca passam pelo navegador, pela API nem pelo Git.
 *
 *   php tools/bank-credentials.php gerar-chave
 *       Gera a CONNECT_BANK_VAULT_KEY para colar no .env (fora do Git). Gere uma vez por ambiente.
 *
 *   php tools/bank-credentials.php cadastrar --conta=ID --banco=inter --client-id=XXXX --certificado=/caminho/Inter.crt --chave=/caminho/Inter.key
 *       Pede o client_secret sem mostrar na tela (ou lê de BANK_CLIENT_SECRET). Valida o par certificado/chave,
 *       grava tudo cifrado, liga a conta ao Inter (sandbox) e mostra UMA vez o endereço do webhook.
 *       Depois de cadastrar, apague os arquivos .crt/.key da pasta de downloads.
 *
 *   php tools/bank-credentials.php cadastrar --conta=ID --banco=sicoob --client-id=XXXX --numero-cliente=NNNN
 *       Sicoob (ADR 0633), só sandbox: pede o access token do portal no lugar do client_secret; sem certificado.
 *
 *   php tools/bank-credentials.php cadastrar --conta=ID --banco=condoconta --client-id=XXXX
 *       CondoConta (ADR 0649), só sandbox: pede o client_secret; sem certificado e sem número do cliente.
 *
 *   php tools/bank-credentials.php testar --conta=ID
 *       Só pede um token ao banco da conta (sandbox do Inter, do Sicoob ou da CondoConta, stage da Cora; não emite nada) para conferir credencial
 *       e certificado. Saída 0 se o banco respondeu, 1 se não (com o motivo).
 *
 *   php tools/bank-credentials.php situacao --conta=ID
 *   php tools/bank-credentials.php remover --conta=ID      volta a conta para o sandbox interno
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Source\Services\Erp\Banking\BankCredentialSetup;
use Source\Services\Erp\Banking\BankCredentials;
use Source\Services\Erp\Banking\BankVault;

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
$account = static function () use ($options, $fail): int {
    $id = filter_var($options['conta'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false || $id === null ? $fail('Informe --conta=ID (número da conta bancária no ERP).') : (int)$id;
};

try {
    switch ($command) {
        case 'gerar-chave':
            fwrite(STDOUT, BankVault::ENV_KEY . '=' . base64_encode(random_bytes(32)) . PHP_EOL);
            fwrite(STDOUT, "Cole a linha no .env do servidor (nunca no Git) e guarde uma cópia no cofre de senhas. Sem ela, as credenciais gravadas não abrem." . PHP_EOL);
            break;

        case 'cadastrar':
            $id = $account();
            $bank = $options['banco'] ?? 'inter';
            $noCertificate = in_array($bank, BankCredentials::WITHOUT_CERTIFICATE, true);
            $needsNumber = in_array($bank, BankCredentials::NEEDS_CLIENT_NUMBER, true);
            foreach ($noCertificate ? ($needsNumber ? ['client-id', 'numero-cliente'] : ['client-id']) : ['client-id', 'certificado', 'chave'] as $required) {
                if (trim((string)($options[$required] ?? '')) === '') {
                    $fail("Falta --{$required}.");
                }
            }
            $read = static function (string $path) use ($fail): string {
                if (!is_file($path) || !is_readable($path) || filesize($path) > 65536) {
                    $fail("Não consegui ler {$path}.");
                }
                return (string)file_get_contents($path);
            };
            $secret = getenv('BANK_CLIENT_SECRET') ?: '';
            if ($secret === '') {
                fwrite(STDOUT, ($needsNumber ? 'access token do sandbox' : 'client_secret') . ' (não aparece na tela): ');
                if (DIRECTORY_SEPARATOR === '/') {
                    shell_exec('stty -echo');
                }
                $secret = trim((string)fgets(STDIN));
                if (DIRECTORY_SEPARATOR === '/') {
                    shell_exec('stty echo');
                }
                fwrite(STDOUT, PHP_EOL);
            }
            $result = (new BankCredentials())->save($id, $bank, 'sandbox', [
                'client_id' => (string)$options['client-id'], 'client_secret' => $secret,
                'certificate' => ($options['certificado'] ?? '') !== '' ? $read((string)$options['certificado']) : '',
                'private_key' => ($options['chave'] ?? '') !== '' ? $read((string)$options['chave']) : '',
                'client_number' => (string)($options['numero-cliente'] ?? ''),
            ]);
            $base = rtrim((string)(getenv('BANK_WEBHOOK_BASE_URL') ?: (defined('CONF_URL_BASE') ? CONF_URL_BASE : '')), '/');
            fwrite(STDOUT, "Credencial gravada no cofre. Conta {$id} agora usa o {$bank} (sandbox). " . ($result['certificate_expires_on'] !== null ? "Certificado vence em {$result['certificate_expires_on']}." : 'Sem certificado (sandbox).') . PHP_EOL);
            fwrite(STDOUT, "Endereço do webhook (aparece só agora; cadastre no banco e não compartilhe):" . PHP_EOL);
            fwrite(STDOUT, ($base !== '' ? $base : 'https://SEU-HOST') . "/api/v1/webhooks/bank/{$bank}/{$result['webhook_secret']}" . PHP_EOL);
            fwrite(STDOUT, "Apague os arquivos .crt e .key da pasta de onde vieram." . PHP_EOL);
            break;

        case 'testar':
            // ADR 0392: o banco sai do código da conta (Inter, Cora, Sicoob ou CondoConta); antes era sempre o Inter, e a Cora falhava sem motivo.
            $id = $account();
            $result = (new BankCredentialSetup())->testFromTerminal($id);
            fwrite($result['connected'] ? STDOUT : STDERR, $result['message'] . PHP_EOL);
            exit($result['connected'] ? 0 : 1);

        case 'situacao':
            $id = $account();
            $status = (new BankCredentials())->status($id);
            fwrite(STDOUT, ($status === null ? "Conta {$id} sem credencial (usa o sandbox interno)." : json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . PHP_EOL);
            break;

        case 'remover':
            $id = $account();
            (new BankCredentials())->remove($id);
            fwrite(STDOUT, "Credencial apagada. Conta {$id} voltou para o sandbox interno." . PHP_EOL);
            break;

        default:
            $fail("Uso: php tools/bank-credentials.php [gerar-chave|cadastrar|testar|situacao|remover] --conta=ID ...\nVeja o cabeçalho deste arquivo.");
    }
} catch (InvalidArgumentException|RuntimeException|DomainException $error) {
    $fail('Erro: ' . $error->getMessage());
}
