<?php
declare(strict_types=1);

namespace MovesOSTests\Unit\Talk;

use Source\Services\Talk\Gateway\CloudApiGateway;
use Source\Services\Talk\Media\MediaStore;
use Source\Services\Talk\Service\DomainError;
use MovesOSTests\Fakes\Talk\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class MediaStoreTest extends TestCase
{
    public function test_nome_limpo_e_caminho_fora_de_media_recusado(): void
    {
        $this->assertSame('boleto.pdf', MediaStore::cleanName("bo\0leto.pdf", 'pdf'));
        $this->assertSame('..boleto.pdf', MediaStore::cleanName('../boleto.pdf', 'pdf'), 'barras somem; não sobe de pasta');
        $this->assertStringStartsWith('arquivo-', MediaStore::cleanName('', 'jpg'));
        $store = new MediaStore(sys_get_temp_dir());
        foreach (['../../etc/passwd', 'media/2026/11/x.php', '/etc/passwd'] as $bad) {
            try {
                $store->absolute($bad);
                $this->fail("aceitou $bad");
            } catch (DomainError $e) {
                $this->assertSame(404, $e->getCode());
            }
        }
    }

    public function test_api_oficial_sobe_o_arquivo_e_envia_pelo_id(): void
    {
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 200, 'body' => '{"id":"MEDIA9"}'],
            ['status' => 200, 'body' => '{"messages":[{"id":"wamid.X"}]}'],
        ];
        $gw = new CloudApiGateway(['phone_number_id' => '123', 'access_token' => 't'], $http);
        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($tmp, '%PDF-1.4');
        $r = $gw->sendMedia('5531999998888', 'document', $tmp, 'application/pdf', 'boleto.pdf', 'Segue');
        unlink($tmp);
        $this->assertSame('wamid.X', $r->providerMessageId);
        $this->assertSame('https://graph.facebook.com/v21.0/123/media', $http->calls[0]['url']);
        $this->assertSame(['id' => 'MEDIA9', 'caption' => 'Segue', 'filename' => 'boleto.pdf'], $http->calls[1]['body']['document']);
    }

    public function test_api_oficial_baixa_pela_url_temporaria(): void
    {
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 200, 'body' => '{"url":"https://lookaside.fbsbx.com/x","mime_type":"image/jpeg"}'],
            ['status' => 200, 'body' => "\xFF\xD8\xFFbytes"],
        ];
        $got = (new CloudApiGateway(['phone_number_id' => '123', 'access_token' => 't'], $http))->downloadMedia('MID1');
        $this->assertSame("\xFF\xD8\xFFbytes", $got['bytes']);
        $this->assertSame('https://graph.facebook.com/v21.0/MID1', $http->calls[0]['url']);
        $this->assertSame('Bearer t', $http->calls[1]['headers']['Authorization']);
    }
}
