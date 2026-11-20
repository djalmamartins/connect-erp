<?php

namespace Source\Support\Proposal;

use Source\Core\Connect;
use Source\Models\Notification\Notification;
use Source\Models\Notification\NotificationCategory;
use Source\Models\Notification\NotificationMessage;
use Source\Models\Proposal\Proposal;
use Source\Models\User;
use Source\Services\Privacy\ConsentLog;
use Source\Services\Privacy\LegalDocuments;
use Source\Support\AppLogger;
use Source\Support\Email;

final class ProposalService
{
    public function submit(array $data): array
    {
        $clean = [
            'name' => trim(strip_tags((string)($data['name'] ?? ''))),
            'email' => strtolower(trim((string)($data['email'] ?? ''))),
            'whatsapp' => trim(strip_tags((string)($data['whatsapp'] ?? ''))),
            'condominium' => trim(strip_tags((string)($data['condominio'] ?? ''))),
            'units' => filter_var($data['units'] ?? null, FILTER_VALIDATE_INT),
            'profile' => trim(strip_tags((string)($data['profile'] ?? ''))),
            'message' => trim(strip_tags((string)($data['message'] ?? ''))),
        ];
        $profiles = ['sindico', 'conselheiro', 'morador', 'administrador', 'outro'];
        if (mb_strlen($clean['name']) < 3 || !is_email($clean['email']) || strlen(preg_replace('/\D+/', '', $clean['whatsapp'])) < 10 || mb_strlen($clean['condominium']) < 2 || !$clean['units'] || $clean['units'] < 1 || !in_array($clean['profile'], $profiles, true)) {
            return ['success' => false, 'message' => 'Confira os campos obrigatórios e tente novamente.'];
        }
        // ADR 0604: a ciência da política é marcada pela pessoa; sem ela, nada é gravado. Marketing é separado e opcional.
        $privacyAccepted = self::checked($data['privacy'] ?? null);
        $marketing = self::checked($data['marketing'] ?? null);
        if (!$privacyAccepted) {
            return ['success' => false, 'message' => 'Para enviar, marque que leu a Política de Privacidade.'];
        }
        if (!empty($data['website'])) {
            return ['success' => true, 'message' => 'Solicitação recebida.'];
        }

        $proposal = new Proposal();
        $proposal->protocol = 'PROP-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        foreach ($clean as $field => $value) $proposal->{$field} = $value ?: null;
        $proposal->status = 'new';
        $proposal->source_url = mb_substr((string)($data['source_url'] ?? url('/solicite-sua-proposta')), 0, 500);
        $proposal->request_hash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $proposal->consent_at = date('Y-m-d H:i:s');
        $proposal->marketing_consent_at = $marketing ? date('Y-m-d H:i:s') : null;
        if (!$proposal->save()) return ['success' => false, 'message' => 'Não foi possível registrar a solicitação agora.'];
        $this->recordConsents($proposal, $marketing);

        $this->queueRequesterEmail($proposal);
        $this->notifyTeam($proposal);
        AppLogger::log('info', 'Nova proposta recebida pelo site', ['event_type' => 'proposal_received', 'proposal_id' => $proposal->id, 'protocol' => $proposal->protocol, 'status' => 'resolved'], 'proposals');
        return ['success' => true, 'message' => 'Proposta solicitada com sucesso!', 'protocol' => $proposal->protocol];
    }

    private static function checked(mixed $value): bool
    {
        return in_array($value, ['1', 1, true, 'on', 'yes'], true);
    }

    /** Prova do que a pessoa marcou (ADR 0604). Falha aqui não perde a proposta: fica no log para conferir. */
    private function recordConsents(Proposal $proposal, bool $marketing): void
    {
        try {
            $log = new ConsentLog();
            $who = ['proposal_id' => (int)$proposal->id, 'email' => (string)$proposal->email];
            $log->record('privacy', true, LegalDocuments::PRIVACY, 'proposta', $who);
            $log->record('marketing', $marketing, LegalDocuments::PRIVACY, 'proposta', $who);
        } catch (\Throwable $e) {
            AppLogger::log('error', 'Aceite da proposta não registrado', ['event_type' => 'privacy_consent_failed', 'proposal_id' => $proposal->id, 'error' => $e->getMessage(), 'status' => 'open'], 'privacy');
        }
    }

    private function queueRequesterEmail(Proposal $proposal): void
    {
        (new Email())->bootstrap('Recebemos sua solicitação de proposta - ' . CONF_SITE_NAME, ProposalMailer::site($proposal), $proposal->email, $proposal->name)
            ->queue(CONF_MAIL_SENDER['address'], CONF_MAIL_SENDER['name']);
    }

    private function notifyTeam(Proposal $proposal): void
    {
        $pdo = Connect::getInstance();
        $category = (new NotificationCategory())->findByUri('studio');
        if (!$category) return;

        $message = new NotificationMessage();
        $message->title = 'Nova proposta: ' . $proposal->condominium;
        $message->body = $proposal->name . ' solicitou atendimento para um condomínio com ' . $proposal->units . ' unidades.';
        $message->audience = 'proposal_team';
        $message->severity = 'info';
        $message->delivery_channels = 'both';
        $message->link = url('/studio/proposals/' . $proposal->id);
        $message->status = 'sent';
        $message->delivered_at = date('Y-m-d H:i:s');
        $message->expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        if (!$message->save()) return;

        $stmt = $pdo->query("SELECT DISTINCT u.* FROM users u INNER JOIN access_user_roles ur ON ur.user_id=u.id INNER JOIN access_roles r ON r.id=ur.role_id WHERE u.status<>'trash' AND r.slug IN ('developer','super_admin','client_admin','manager')");
        foreach ($stmt->fetchAll() ?: [] as $record) {
            $recipient = (new User())->findById((int)$record->id);
            if (!$recipient) continue;
            $notification = new Notification();
            $notification->message_id = $message->id;
            $notification->users_id = $recipient->id;
            $notification->category = $category->id;
            $notification->image = 'images/default.svg';
            $notification->title = $message->title;
            $notification->body = $message->body;
            $notification->severity = 'info';
            $notification->link = $message->link;
            $notification->view = 0;
            $notification->expires_at = $message->expires_at;
            $notification->save();
            if (is_email((string)$recipient->email)) {
                (new Email())->bootstrap($message->title, ProposalMailer::system($proposal, $recipient), $recipient->email, $recipient->fullName())
                    ->queue(CONF_MAIL_SENDER['address'], CONF_MAIL_SENDER['name'], date('Y-m-d H:i:s'), (int)$message->id, (int)$recipient->id);
            }
        }
    }
}
