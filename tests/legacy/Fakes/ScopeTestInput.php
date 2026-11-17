<?php

declare(strict_types=1);

namespace MovesOSTests\Fakes;

/** php://input dos testes de escopo (ADR 0341): devolve o corpo combinado; os demais php:// não são usados pelas ações chamadas. */
final class ScopeTestInput
{
    public static string $body = '';
    /** @var resource|null */
    public $context;
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->position = 0;
        return $path === 'php://input';
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$body)];
    }
}
