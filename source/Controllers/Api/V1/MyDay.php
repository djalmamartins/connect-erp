<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DateTimeImmutable;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\MyDay as MyDayService;
use Source\Services\Erp\MyDayError;
use Source\Support\Access;

/**
 * API REST do Meu Dia (CN-010, ADR 0630; docs/api/openapi.yaml, tag MyDay). As mesmas regras da tela:
 * cada pessoa vê o próprio dia; outra pessoa só com erp.myday.team. Sessão e CSRF no middleware ApiAuthenticated.
 */
final class MyDay
{
    /** GET /api/v1/my-day?date=AAAA-MM-DD&person_id= */
    public function show(): void
    {
        $this->run(function (User $user, MyDayService $service): array {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['date'] ?? ''));
            if (($_GET['date'] ?? '') !== '' && $date === false) {
                throw MyDayError::invalid(['date' => 'Use a data no formato AAAA-MM-DD.']);
            }
            $owner = $service->owner($user, (int)($_GET['person_id'] ?? 0) ?: null);
            return [200, ['data' => $service->day($owner, $date ?: new DateTimeImmutable('today'))]];
        });
    }

    /** GET /api/v1/my-day/visit/{condominium_id} */
    public function visit(array $data): void
    {
        $this->run(fn (User $user, MyDayService $service): array => [200, ['data' => $service->visitPanel($user, (int)($data['condominium_id'] ?? 0))]]);
    }

    /** POST /api/v1/my-day/events */
    public function store(): void
    {
        $this->run(fn (User $user, MyDayService $service): array => [201, ['data' => ['id' => $service->createEvent($user, $this->body())]]]);
    }

    /** POST /api/v1/my-day/events/{id}/status */
    public function status(array $data): void
    {
        $this->run(function (User $user, MyDayService $service) use ($data): array {
            $service->setEventStatus($user, (int)($data['id'] ?? 0), (string)($this->body()['status'] ?? ''));
            return [200, ['data' => ['id' => (int)($data['id'] ?? 0), 'status' => (string)($this->body()['status'] ?? '')]]];
        });
    }

    /** GET /api/v1/my-day/team — a equipe para escolher "Para quem" no app Meu Dia (ADR 0659). Exige erp.myday.team. */
    public function team(): void
    {
        $this->run(function (User $user, MyDayService $service): array {
            if (!$service->canSeeTeam($user)) {
                throw new MyDayError('person', 'Você só pode marcar tarefas para você.', 403);
            }
            return [200, ['data' => $service->team()]];
        });
    }

    private function run(callable $action): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem acesso ao ERP.']]);
            return;
        }
        try {
            [$status, $payload] = $action($user, new MyDayService());
            JsonResponse::send($status, $payload);
        } catch (MyDayError $error) {
            $body = ['code' => $error->errorCode, 'message' => $error->getMessage()];
            if ($error->errors !== []) {
                $body['fields'] = $error->errors;
            }
            JsonResponse::send($error->status, ['error' => $body]);
        } catch (\JsonException) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
        }
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        if (trim($raw) === '') {
            return [];
        }
        $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        return is_array($body) ? $body : [];
    }
}
