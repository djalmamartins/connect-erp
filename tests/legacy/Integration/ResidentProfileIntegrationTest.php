<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\LoginThrottle;
use Source\Services\Resident\ResidentProfile;

/**
 * Revisão de 15/11 (ADR 0302): boas-vindas do primeiro acesso e foto da conta do morador, e o limite do pedido de
 * recuperação de senha pela API do app.
 */
final class ResidentProfileIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/resident-photos-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->root . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function profiles(): ResidentProfile
    {
        return new ResidentProfile($this->pdo, $this->root);
    }

    private function upload(int $width, int $height, string $type = 'png'): array
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int)imagecolorallocate($image, 10, 120, 70));
        $file = tempnam(sys_get_temp_dir(), 'photo');
        $type === 'png' ? imagepng($image, $file) : imagejpeg($image, $file);
        return ['tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];
    }

    public function testWelcomeIsShownUntilCompletedAndCompletingTwiceKeepsTheDate(): void
    {
        $user = $this->createUser();
        self::assertSame(['completed' => false, 'has_photo' => false], $this->profiles()->status($user));
        $this->profiles()->complete($user);
        $first = $this->pdo->query('SELECT onboarded_at FROM erp_resident_app_profiles WHERE user_id = ' . $user)->fetchColumn();
        $this->pdo->exec("UPDATE erp_resident_app_profiles SET onboarded_at = '2026-01-01 08:00:00' WHERE user_id = " . $user);
        $this->profiles()->complete($user);
        self::assertNotFalse($first);
        self::assertSame('2026-01-01 08:00:00', $this->pdo->query('SELECT onboarded_at FROM erp_resident_app_profiles WHERE user_id = ' . $user)->fetchColumn());
        self::assertTrue($this->profiles()->status($user)['completed']);
    }

    public function testPhotoIsCroppedToASquareJpegOutsideThePublicFolderAndReplacingDeletesTheOldOne(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $this->profiles()->storePhoto($user, $this->upload(900, 600));
        $first = $this->profiles()->photo($user);
        self::assertNotNull($first);
        self::assertStringStartsWith($this->root . '/' . $user . '/', $first);
        [$width, $height, $type] = getimagesize($first);
        self::assertSame([ResidentProfile::SIDE, ResidentProfile::SIDE, IMAGETYPE_JPEG], [$width, $height, $type]);
        self::assertNull($this->profiles()->photo($other), 'Cada conta só tem a própria foto.');
        self::assertTrue($this->profiles()->status($user)['has_photo']);
        self::assertFalse($this->profiles()->status($user)['completed'], 'Pôr a foto não conclui as boas-vindas.');

        $this->profiles()->storePhoto($user, $this->upload(300, 400, 'jpg'));
        $second = $this->profiles()->photo($user);
        self::assertNotSame($first, $second);
        self::assertFileDoesNotExist($first);
        self::assertSame([300, 300], array_slice(getimagesize((string)$second), 0, 2), 'Imagem pequena não é ampliada.');

        $this->profiles()->deletePhoto($user);
        self::assertNull($this->profiles()->photo($user));
        self::assertFileDoesNotExist((string)$second);
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_resident_app_profiles' AND entity_id = " . $user . " AND action = 'resident_app.photo'")->fetchColumn(), 'Enviar, trocar e tirar ficam na auditoria.');
    }

    public function testAFileThatIsNotAnImageIsRefused(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($file, "<?php echo 'oi';");
        try {
            $this->profiles()->storePhoto($this->createUser(), ['tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)]);
            self::fail('Arquivo que não é imagem deveria ser recusado.');
        } catch (RegistryValidationException $error) {
            self::assertArrayHasKey('photo', $error->errors);
        }
        try {
            $this->profiles()->storePhoto($this->createUser(), ['error' => UPLOAD_ERR_NO_FILE]);
            self::fail('Sem arquivo deveria ser recusado.');
        } catch (RegistryValidationException $error) {
            self::assertSame('Escolha uma foto.', $error->errors['photo']);
        }
    }

    public function testForgotPasswordRequestsAreLimitedPerAccount(): void
    {
        $throttle = new LoginThrottle($this->pdo);
        $subjects = LoginThrottle::subjects('limite' . bin2hex(random_bytes(3)) . '@test.local', null);
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($throttle->blocked(LoginThrottle::FORGET, $subjects));
            $throttle->fail(LoginThrottle::FORGET, $subjects);
        }
        self::assertTrue($throttle->blocked(LoginThrottle::FORGET, $subjects), 'O 6º pedido em 15 minutos é recusado.');
    }
}
