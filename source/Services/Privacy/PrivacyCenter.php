<?php

declare(strict_types=1);

namespace Source\Services\Privacy;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;

/**
 * Privacidade da conta do morador (ADR 0604, etapa 2): aceite dos Termos e da Política na versão atual, novidades
 * (marketing) com consentimento separado e pedidos do titular (LGPD art. 18) que viram chamado no Help Desk.
 * Usado pelo app web (AppScreens), pela API (/api/v1/resident/privacy) e pelo app de celular.
 */
final class PrivacyCenter
{
    /** Pedidos do titular (LGPD art. 18) e o texto do chamado. */
    public const REQUESTS = [
        'acesso' => 'Quero saber quais dados meus vocês guardam (acesso)',
        'correcao' => 'Quero corrigir dados meus',
        'exclusao' => 'Quero apagar ou anonimizar dados meus',
        'portabilidade' => 'Quero uma cópia dos meus dados para levar a outro serviço (portabilidade)',
        'compartilhamento' => 'Quero saber com quem meus dados foram compartilhados',
        'outro' => 'Outra dúvida sobre meus dados',
    ];
    public const CATEGORY = 'LGPD';
    /** Prazo de resposta (LGPD art. 19, II). */
    public const ANSWER_DAYS = 15;

    public function __construct(private ?PDO $pdo = null, private ?ConsentLog $log = null)
    {
    }

    /**
     * @return array{terms_version:string, privacy_version:string, accepted:bool, accepted_at:?string, marketing:bool,
     *     terms_url:string, privacy_url:string, requests:array<string,string>, answer_days:int}
     */
    public function status(int $userId): array
    {
        $terms = $this->log()->latestForUser($userId, 'terms');
        $privacy = $this->log()->latestForUser($userId, 'privacy');
        $marketing = $this->log()->latestForUser($userId, 'marketing');
        $accepted = self::current($terms, LegalDocuments::TERMS) && self::current($privacy, LegalDocuments::PRIVACY);
        return [
            'terms_version' => LegalDocuments::TERMS,
            'privacy_version' => LegalDocuments::PRIVACY,
            'accepted' => $accepted,
            'accepted_at' => $accepted ? (string)$terms['created_at'] : null,
            'marketing' => $marketing !== null && (int)$marketing['granted'] === 1,
            'terms_url' => self::siteUrl('/termos-de-uso'),
            'privacy_url' => self::siteUrl('/politica-de-privacidade'),
            'requests' => self::REQUESTS,
            'answer_days' => self::ANSWER_DAYS,
        ];
    }

    public function hasAccepted(int $userId): bool
    {
        return $this->status($userId)['accepted'];
    }

    /** Aceite dos Termos e da Política na versão atual; marketing só quando informado (null = não muda). */
    public function accept(int $userId, string $channel, ?bool $marketing = null): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Conta inválida.');
        }
        $who = ['user_id' => $userId];
        $this->log()->record('terms', true, LegalDocuments::TERMS, $channel, $who);
        $this->log()->record('privacy', true, LegalDocuments::PRIVACY, $channel, $who);
        if ($marketing !== null) {
            $this->log()->record('marketing', $marketing, LegalDocuments::PRIVACY, $channel, $who);
        }
        return $this->status($userId);
    }

    public function setMarketing(int $userId, bool $granted, string $channel): array
    {
        $this->log()->record('marketing', $granted, LegalDocuments::PRIVACY, $channel, ['user_id' => $userId]);
        return $this->status($userId);
    }

    /**
     * Pedido do titular: abre chamado no Help Desk (categoria LGPD, prioridade alta, origem do portal), com o condomínio e
     * a unidade do vínculo atual. Não depende da função "chamados" do portal: o direito vale sempre.
     *
     * @return array{id:int, protocol:string, answer_until:string}
     */
    public function request(User $user, array $link, string $type, string $details = ''): array
    {
        if (!isset(self::REQUESTS[$type])) {
            throw new InvalidArgumentException('Escolha o tipo do pedido.');
        }
        $details = mb_substr(trim(strip_tags($details)), 0, 2000);
        $message = self::REQUESTS[$type] . '.' . ($details !== '' ? "\n\nDetalhes: " . $details : '')
            . "\n\nPedido do titular pela LGPD (art. 18). Responder em até " . self::ANSWER_DAYS . ' dias (art. 19). '
            . 'Use Configurações > Dados pessoais para o relatório ou a anonimização.';
        try {
            $ticket = (new TicketService($this->pdo))->open([
                'subject' => 'Pedido LGPD: ' . mb_strtolower(self::REQUESTS[$type]),
                'message' => $message,
                'priority' => 'high',
                'category' => self::CATEGORY,
                'condominium_id' => (int)($link['condominium_id'] ?? 0) ?: null,
                'unit_id' => (int)($link['unit_id'] ?? 0) ?: null,
                'requester_id' => (int)$user->id,
            ], 'residents');
        } catch (TicketError $error) {
            throw new InvalidArgumentException($error->getMessage());
        }
        return [
            'id' => (int)$ticket['id'],
            'protocol' => (string)$ticket['protocol'],
            'answer_until' => date('Y-m-d', strtotime('+' . self::ANSWER_DAYS . ' days')),
        ];
    }

    private static function current(?array $row, string $version): bool
    {
        return $row !== null && (int)$row['granted'] === 1 && (string)$row['version'] === $version;
    }

    private static function siteUrl(string $path): string
    {
        $base = defined('CONF_URL_SSL') ? rtrim((string)CONF_URL_SSL, '/') : '';
        return $base . $path;
    }

    private function log(): ConsentLog
    {
        return $this->log ??= new ConsentLog($this->pdo ?? Connect::getInstance());
    }
}
