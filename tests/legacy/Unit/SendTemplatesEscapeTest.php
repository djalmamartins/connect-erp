<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Revisão dos templates de e-mail (ADR 0159, pendente fechado no ADR 0327): todo <?= dos templates do
 * container send precisa escapar o valor. Nome, assunto, texto de chamado e comunicado vêm de pessoas e
 * vão para a caixa de entrada de outras pessoas; um valor sem escape vira HTML injetado no e-mail.
 */
final class SendTemplatesEscapeTest extends TestCase
{
    /** Formas aceitas depois de "<?=": escape do Plates, número, seção da moldura ou escolha entre dois textos fixos. */
    private const SAFE = [
        '~^\$this->e\(~',
        '~^nl2br\(\$this->e\(~',
        '~^\(int\)~',
        '~^\$this->section\(~',
        "~^\\\$[a-zA-Z_]+ \\? '[^'<>]*' : '[^'<>]*' \\?>~",
    ];

    public function testEveryEchoInSendTemplatesIsEscaped(): void
    {
        $root = dirname(__DIR__, 3) . '/container/send';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            preg_match_all('~<\?=\s*(.*?)(?=\?>)\?>~s', $source, $matches);
            foreach ($matches[0] as $i => $whole) {
                $expression = ltrim(substr($whole, 3));
                $safe = false;
                foreach (self::SAFE as $pattern) {
                    if (preg_match($pattern, $expression)) {
                        $safe = true;
                        break;
                    }
                }
                self::assertTrue($safe, sprintf('%s: valor sem escape em "%s"', basename($file->getPathname()), trim($matches[1][$i])));
                $checked++;
            }
        }
        self::assertGreaterThan(50, $checked, 'Nenhum template de e-mail encontrado em container/send.');
    }
}
