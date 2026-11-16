<?php
declare(strict_types=1);

namespace Source\Services\Talk\Domain;

/** Mensagem recebida, já normalizada (independe do provedor). */
final class InboundMessage
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $fromPhone,
        public readonly string $type,          // text, image, audio, document, video, location, other
        public readonly ?string $body,
        public readonly ?string $contactName = null,
        public readonly ?string $mediaRef = null, // id/URL da mídia no provedor (download pelo worker)
        public readonly ?string $mediaMime = null,
    ) {
    }
}
