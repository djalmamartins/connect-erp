<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use InvalidArgumentException;

/**
 * Adaptador de sandbox (ADR 0035): NÃO fala com banco nenhum, não usa credencial e não imita CNAB.
 * Serve para exercitar o fluxo inteiro (emitir, consultar, cancelar, retorno e conciliação) com
 * dados sintéticos. A linha digitável e o código de barras começam com "SANDBOX" e não são pagáveis.
 *
 * Formato do retorno (próprio do sandbox, texto UTF-8):
 *   SANDBOX-RETORNO;v1
 *   <event_key>;<kind>;<our_number>;<amount_cents>;<occurred_on AAAA-MM-DD>
 *   ...
 *   TOTAL;<quantidade de eventos>;<soma de amount_cents>
 *
 * Simulação determinística por descrição do título: "[sandbox:rejeitar]" é recusado e
 * "[sandbox:pendente]" fica pendente (como um timeout) até ser consultado.
 */
final class SandboxBankAdapter implements BankAdapter
{
    public const HEADER = 'SANDBOX-RETORNO;v1';
    private const KINDS = ['registered', 'rejected', 'paid', 'cancelled'];
    private const MAX_LINES = 5000;

    public function name(): string
    {
        return 'sandbox';
    }

    public function capabilities(): array
    {
        return ['register', 'query', 'cancel', 'return_file'];
    }

    public function registerCharge(array $charge): array
    {
        $description = (string)($charge['description'] ?? '');
        if (str_contains($description, '[sandbox:rejeitar]')) {
            return ['status' => 'rejected', 'external_id' => null, 'digitable_line' => null, 'barcode' => null, 'error' => 'Sandbox: título recusado (simulação).'];
        }
        if (str_contains($description, '[sandbox:pendente]')) {
            return ['status' => 'pending', 'external_id' => 'SBX-' . $charge['our_number'], 'digitable_line' => null, 'barcode' => null, 'error' => 'Sandbox: sem confirmação do banco (simulação de timeout).'];
        }
        return $this->registered((string)$charge['our_number'], (int)$charge['amount_cents'], (string)$charge['due_on']);
    }

    public function getCharge(string $ourNumber, ?string $externalId): array
    {
        // No sandbox, a consulta de um título pendente confirma o registro.
        return ['external_id' => $externalId ?? 'SBX-' . $ourNumber] + $this->registered($ourNumber, 0, date('Y-m-d'));
    }

    public function cancelCharge(string $ourNumber, ?string $externalId): array
    {
        return ['status' => 'cancelled', 'error' => null];
    }

    public function parseReturn(string $content): array
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidArgumentException('Arquivo de retorno com codificação inválida (use UTF-8).');
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $content) ?: []), static fn (string $l): bool => $l !== ''));
        if (($lines[0] ?? '') !== self::HEADER) {
            throw new InvalidArgumentException('Layout de retorno desconhecido para o adaptador sandbox.');
        }
        if (count($lines) < 2 || count($lines) > self::MAX_LINES) {
            throw new InvalidArgumentException('Arquivo de retorno vazio ou grande demais.');
        }
        $trailer = explode(';', (string)array_pop($lines));
        array_shift($lines);
        if (count($trailer) !== 3 || $trailer[0] !== 'TOTAL' || !ctype_digit($trailer[1]) || !ctype_digit($trailer[2])) {
            throw new InvalidArgumentException('Trailer do retorno ausente ou inválido.');
        }
        $events = [];
        $sum = 0;
        $keys = [];
        foreach ($lines as $index => $line) {
            $row = explode(';', $line);
            $n = $index + 2;
            if (count($row) !== 5) {
                throw new InvalidArgumentException("Linha {$n} do retorno com número de campos inválido.");
            }
            [$key, $kind, $ourNumber, $amount, $date] = $row;
            if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $key) || isset($keys[$key])) {
                throw new InvalidArgumentException("Linha {$n}: identificador do evento inválido ou repetido no arquivo.");
            }
            if (!in_array($kind, self::KINDS, true) || !preg_match('/^\d{1,20}$/', $ourNumber)) {
                throw new InvalidArgumentException("Linha {$n}: ocorrência ou nosso número inválido.");
            }
            if ($amount !== '' && !ctype_digit($amount)) {
                throw new InvalidArgumentException("Linha {$n}: valor inválido (centavos).");
            }
            $parsed = $date === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($date !== '' && ($parsed === false || $parsed->format('Y-m-d') !== $date)) {
                throw new InvalidArgumentException("Linha {$n}: data inválida.");
            }
            $keys[$key] = true;
            $cents = $amount === '' ? null : (int)$amount;
            $sum += $cents ?? 0;
            $events[] = ['event_key' => $key, 'kind' => $kind, 'our_number' => $ourNumber, 'amount_cents' => $cents, 'occurred_on' => $date === '' ? null : $date];
        }
        if ((int)$trailer[1] !== count($events) || (int)$trailer[2] !== $sum) {
            throw new InvalidArgumentException('Trailer do retorno não confere com os eventos (quantidade ou soma).');
        }
        return $events;
    }

    /** Monta um retorno no formato do sandbox (testes e homologação manual). */
    public static function buildReturn(array $events): string
    {
        $lines = [self::HEADER];
        $sum = 0;
        foreach ($events as $event) {
            $lines[] = implode(';', [$event['event_key'], $event['kind'], $event['our_number'], (string)($event['amount_cents'] ?? ''), (string)($event['occurred_on'] ?? '')]);
            $sum += (int)($event['amount_cents'] ?? 0);
        }
        $lines[] = 'TOTAL;' . count($events) . ';' . $sum;
        return implode("\n", $lines) . "\n";
    }

    private function registered(string $ourNumber, int $amountCents, string $dueOn): array
    {
        $digits = str_pad(substr($ourNumber, -11), 11, '0', STR_PAD_LEFT);
        return [
            'status' => 'registered', 'external_id' => 'SBX-' . $ourNumber,
            'digitable_line' => 'SANDBOX ' . $digits . ' ' . str_pad((string)$amountCents, 10, '0', STR_PAD_LEFT) . ' ' . str_replace('-', '', $dueOn),
            'barcode' => 'SANDBOX' . $digits, 'error' => null,
        ];
    }
}
