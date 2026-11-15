<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Support\TicketError;
use Source\Services\Talk\TalkHelpdesk;
use Source\Support\AppLogger;

/**
 * API do Connect Talk, o atendimento por WhatsApp (docs/api/openapi.yaml, tag Talk; ADR 0308).
 * Token de integração no TalkApiAuthenticated; escopo de condomínios e regras no TalkHelpdesk.
 *
 *   GET  /api/v1/talk/people?phones=5531999998888,31999998888
 *   POST /api/v1/talk/tickets                 201 novo, 200 se o protocolo do Talk já tem chamado
 *   POST /api/v1/talk/tickets/{id}/transfer
 *   POST /api/v1/talk/tickets/{id}/close
 */
final class Talk
{
    public function people(): void
    {
        $agent = $this->agent();
        if ($agent === null) {
            return;
        }
        $phones = mb_substr((string)($_GET['phones'] ?? ''), 0, 200);
        if (TalkHelpdesk::phoneVariants($phones) === []) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Informe o telefone com DDD.', 'fields' => ['phones' => 'Informe o telefone com DDD.']]]);
            return;
        }
        $this->run('talk_people_failed', static fn (): array => [200, (new TalkHelpdesk())->people($agent, $phones)]);
    }

    public function open(): void
    {
        $agent = $this->agent();
        if ($agent === null) {
            return;
        }
        $this->run('talk_ticket_open_failed', function () use ($agent): array {
            $result = (new TalkHelpdesk())->open($agent, $this->body());
            return [$result['created'] ? 201 : 200, $result['ticket']];
        });
    }

    public function transfer(array $data): void
    {
        $agent = $this->agent();
        if ($agent === null) {
            return;
        }
        $this->run('talk_ticket_transfer_failed', fn (): array => [200, (new TalkHelpdesk())->transfer($agent, (int)($data['id'] ?? 0), $this->body())]);
    }

    public function close(array $data): void
    {
        $agent = $this->agent();
        if ($agent === null) {
            return;
        }
        $this->run('talk_ticket_close_failed', fn (): array => [200, (new TalkHelpdesk())->close($agent, (int)($data['id'] ?? 0), $this->body())]);
    }

    /** @param callable():array{0:int,1:mixed} $action */
    private function run(string $failure, callable $action): void
    {
        try {
            [$status, $data] = $action();
            JsonResponse::send($status, ['data' => $data]);
        } catch (TicketError $error) {
            $code = match ($error->status()) { 404 => 'not_found', 403 => 'forbidden', default => 'validation_failed' };
            JsonResponse::send($error->status(), ['error' => ['code' => $code, 'message' => $error->getMessage(), 'fields' => $code === 'validation_failed' ? [$error->field => $error->getMessage()] : (object)[]]]);
        } catch (\JsonException) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'integration', ['event_type' => $failure, 'origin' => 'talk']);
            JsonResponse::send(500, ['error' => ['code' => 'internal_error', 'message' => 'Não foi possível concluir o pedido do Talk.']]);
        }
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        $body = trim($raw) === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        return is_array($body) ? $body : [];
    }

    private function agent(): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Token de integração ausente ou inválido.']]);
        }
        return $user;
    }
}
