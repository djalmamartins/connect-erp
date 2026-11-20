<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Support\Thumb;

final class ThumbFallbackTest extends TestCase
{
    public function testMissingSourceReturnsNullInsteadOfThrowingTypeError(): void
    {
        self::assertNull((new Thumb())->make('images/arquivo-inexistente.jpg', 120, 120));
    }

    public function testImageHelperUsesAvatarForMissingLegacyImage(): void
    {
        self::assertStringEndsWith(
            '/container/web/default/v1/assets/images/avatar.jpg',
            image('images/arquivo-inexistente.jpg', 120, 120)
        );
    }

    public function testCorruptedImageFallsBackToAvatarInsteadOfBreakingThePage(): void
    {
        $relative = 'images/teste-corrompida-' . bin2hex(random_bytes(4)) . '.jpg';
        $path = dirname(__DIR__, 3) . '/' . CONF_UPLOAD_DIR . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, 'isto nao e uma imagem');
        try {
            self::assertStringEndsWith('/assets/images/avatar.jpg', image($relative, 120, 120));
        } finally {
            @unlink($path);
        }
    }
}
