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
}
