<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Resident\ResidentFeatureDisabled;
use Source\Services\Resident\ResidentPortal;
use Source\Services\Support\TicketError;
use Source\Services\Talk\TalkCatalog;
use Source\Services\Talk\TalkCharges;
use Source\Services\Talk\TalkConversation;
use Source\Services\Talk\TalkHelpdesk;
use Source\Services\Talk\TalkResidents;
use Source\Services\Talk\TalkStaffLogin;
use Source\Services\Talk\TalkTokens;
use Source\Support\AppLogger;

/**
 * API do Connect Talk, o atendimento por WhatsApp (docs/api/openapi.yaml, tag Talk; ADR 0308).
 * Token de integração no TalkApiAuthenticated; escopo de condomínios e regras no TalkHelpdesk.
 *
 *   GET  /api/v1/talk/people?phones=5531999998888,31999998888
 *   POST /api/v1/talk/tickets                 201 novo, 200 se o protocolo do Talk já tem chamado
 *   POST /api/v1/talk/tickets/{id}/transfer
 *   POST /api/v1/talk/tickets/{id}/close
 *   GET  /api/v1/talk/charges?resident_id=77&phone=5531999998888   2ª via do boleto (ADR 0386)
 *   GET  /api/v1/talk/me                      o próprio token: nome, escopos, validade, condomínios (ADR 0623)
 *   GET  /api/v1/talk/condominiums                    condomínios do escopo (ADR 0624, condominiums.read)
 *   GET  /api/v1/talk/condominiums/{id}               endereço e áreas comuns
 *   GET  /api/v1/talk/condominiums/{id}/units         unidades (só bloco e número)
 *   GET  /api/v1/talk/condominiums/{id}/announcements comunicados publicados para todos
 *   GET  /api/v1/talk/residents/{id}[/...]            o que o morador veria no app (ADR 0632, residents.read),
 *                                                     com o telefone dele no cabeçalho X-Resident-Phone
 *   GET  /api/v1/talk/tickets?after=N                 respostas da equipe nos chamados do Talk (ADR 0634, tickets.write)
 *   GET  /api/v1/talk/tickets/{id}                    o chamado com a conversa pública
 *   POST /api/v1/talk/tickets/{id}/messages           mensagem do WhatsApp no chamado (idempotente por talk_message_id)
 * Cada rota pede um escopo do token (TalkTokens::SCOPES); sem ele = 403 insufficient_scope.
 */
final class Talk
{
    public function me(): void
    {
        $token = TalkTokens::current();
        if ($this->agent(null) === null || $token === null) {
            return;
        }
        $this->run('talk_me_failed', static fn (): array => [200, (new TalkTokens())->describe($token)]);
    }

    /** POST /talk/staff/login {code}: troca o código de /ambiente/talk pelo funcionário (escopo staff.login). */
    public function staffLogin(): void
    {
        if ($this->agent('staff.login') === null) {
            return;
        }
        if (request_limit_ip('talk_staff_login', 30, 60)) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_requests', 'message' => 'Muitas tentativas de login. Tente de novo em um minuto.']]);
            return;
        }
        $this->run('talk_staff_login_failed', fn (): array => [200, (new TalkStaffLogin())->exchange((string)($this->body()['code'] ?? ''))]);
    }

    /** GET /talk/staff/{id}: o funcionário ainda pode usar o Talk? (active = talk.access e conta não excluída) */
    public function staffStatus(array $data): void
    {
        if ($this->agent('staff.login') !== null) {
            $this->run('talk_staff_status_failed', static fn (): array => [200, (new TalkStaffLogin())->status((int)($data['id'] ?? 0))]);
        }
    }

    public function condominiums(): void
    {
        $agent = $this->agent('condominiums.read');
        if ($agent !== null) {
            $this->list('talk_condominiums_failed', static fn (): array => (new TalkCatalog())->condominiums($agent, $_GET['page'] ?? 1));
        }
    }

    public function condominium(array $data): void
    {
        $agent = $this->agent('condominiums.read');
        if ($agent !== null) {
            $this->run('talk_condominium_failed', static fn (): array => [200, (new TalkCatalog())->condominium($agent, (int)($data['id'] ?? 0))]);
        }
    }

    public function units(array $data): void
    {
        $agent = $this->agent('condominiums.read');
        if ($agent !== null) {
            $this->list('talk_units_failed', static fn (): array => (new TalkCatalog())->units($agent, (int)($data['id'] ?? 0), $_GET['page'] ?? 1));
        }
    }

    public function announcements(array $data): void
    {
        $agent = $this->agent('condominiums.read');
        if ($agent !== null) {
            $this->list('talk_announcements_failed', static fn (): array => (new TalkCatalog())->announcements($agent, (int)($data['id'] ?? 0), $_GET['page'] ?? 1));
        }
    }

    /** Vínculo do morador: primeiro nome, condomínio, unidade e funções ligadas. */
    public function resident(array $data): void
    {
        $this->forResident($data, 'profile', static fn (array $link): array => TalkResidents::present($link));
    }

    public function residentAnnouncements(array $data): void
    {
        $this->forResident($data, 'announcements', static fn (array $link, ResidentPortal $p): array => $p->announcements($link));
    }

    public function residentCommonAreas(array $data): void
    {
        $this->forResident($data, 'common_areas', static fn (array $link, ResidentPortal $p): array => $p->commonAreas($link));
    }

    public function residentAreaAvailability(array $data): void
    {
        $area = (int)($data['area'] ?? 0);
        $month = is_string($_GET['month'] ?? null) ? (string)$_GET['month'] : null;
        $this->forResident($data, 'area_availability', static fn (array $link, ResidentPortal $p): array => $p->areaAvailability($link, $area, $month));
    }

    public function residentReservations(array $data): void
    {
        $this->forResident($data, 'reservations', static fn (array $link, ResidentPortal $p): array => $p->reservations($link));
    }

    public function residentDeliveries(array $data): void
    {
        $this->forResident($data, 'deliveries', static fn (array $link, ResidentPortal $p): array => $p->deliveries($link));
    }

    public function residentVisits(array $data): void
    {
        $this->forResident($data, 'visits', static fn (array $link, ResidentPortal $p): array => $p->visits($link));
    }

    public function residentAssemblies(array $data): void
    {
        $this->forResident($data, 'assemblies', static fn (array $link, ResidentPortal $p): array => $p->assemblies($link));
    }

    public function residentAssembly(array $data): void
    {
        $assembly = (int)($data['assembly'] ?? 0);
        $this->forResident($data, 'assembly', static fn (array $link, ResidentPortal $p): array => $p->assembly($link, $assembly));
    }

    public function residentDocuments(array $data): void
    {
        $this->forResident($data, 'documents', static fn (array $link, ResidentPortal $p): array => $p->documents($link));
    }

    /**
     * Confere o morador e o telefone (TalkResidents::link) e roda a leitura do ResidentPortal no vínculo dele.
     * Função desligada no condomínio = 403 feature_disabled, como no app. @param callable(array, ResidentPortal):array $action
     */
    private function forResident(array $data, string $action, callable $read): void
    {
        $agent = $this->agent('residents.read');
        if ($agent === null) {
            return;
        }
        header('Cache-Control: no-store, private');
        try {
            $phone = mb_substr((string)($_SERVER['HTTP_X_RESIDENT_PHONE'] ?? ''), 0, 30);
            $link = (new TalkResidents())->link($agent, (int)($data['id'] ?? 0), $phone, $action, (int)(TalkTokens::current()['id'] ?? 0));
            JsonResponse::send(200, ['data' => $read($link, new ResidentPortal())]);
        } catch (TicketError $error) {
            $code = match ($error->status()) { 404 => 'not_found', 429 => 'too_many_requests', default => 'validation_failed' };
            JsonResponse::send($error->status(), ['error' => ['code' => $code, 'message' => $error->getMessage()]
                + ($code === 'validation_failed' ? ['fields' => [$error->field => $error->getMessage()]] : [])]);
        } catch (ResidentFeatureDisabled $error) {
            JsonResponse::send(403, ['error' => ['code' => 'feature_disabled', 'message' => $error->getMessage(), 'feature' => $error->feature]]);
        } catch (RegistryNotFound | \Source\Services\Erp\Registry\RegistryValidationException) {
            // Assembleia ou área comum de outro condomínio: o mesmo 404.
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Não encontrado.']]);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'integration', ['event_type' => 'talk_resident_' . $action . '_failed', 'origin' => 'talk']);
            JsonResponse::send(500, ['error' => ['code' => 'internal_error', 'message' => 'Não foi possível concluir o pedido do Talk.']]);
        }
    }

    public function people(): void
    {
        $agent = $this->agent('people.read');
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

    public function charges(): void
    {
        $agent = $this->agent('charges.read');
        if ($agent === null) {
            return;
        }
        // Dado de cobrança: limite próprio, além do limite de falhas do token (o Talk pede no máximo um por mensagem).
        if (request_limit_ip('talk_charges', 60, 60)) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_requests', 'message' => 'Muitas consultas de boleto. Tente de novo em um minuto.']]);
            return;
        }
        $resident = (int)($_GET['resident_id'] ?? 0);
        $phone = mb_substr((string)($_GET['phone'] ?? ''), 0, 30);
        $this->run('talk_charges_failed', static fn (): array => [200, (new TalkCharges())->open($agent, $resident, $phone)]);
    }

    public function open(): void
    {
        $agent = $this->agent('tickets.write');
        if ($agent === null) {
            return;
        }
        $this->run('talk_ticket_open_failed', function () use ($agent): array {
            $result = (new TalkHelpdesk())->open($agent, $this->body());
            return [$result['created'] ? 201 : 200, $result['ticket']];
        });
    }

    /** Respostas da equipe desde o cursor (after = último id de mensagem recebido). */
    public function feed(): void
    {
        $agent = $this->agent('tickets.write');
        if ($agent !== null) {
            $this->list('talk_ticket_feed_failed', static fn (): array => (new TalkConversation())->feed($agent, $_GET['after'] ?? 0));
        }
    }

    public function ticket(array $data): void
    {
        $agent = $this->agent('tickets.write');
        if ($agent !== null) {
            $this->run('talk_ticket_show_failed', static fn (): array => [200, (new TalkConversation())->show($agent, (int)($data['id'] ?? 0))]);
        }
    }

    public function message(array $data): void
    {
        $agent = $this->agent('tickets.write');
        if ($agent === null) {
            return;
        }
        $this->run('talk_ticket_message_failed', function () use ($agent, $data): array {
            $result = (new TalkConversation())->post($agent, (int)($data['id'] ?? 0), $this->body());
            return [$result['duplicate'] ? 200 : 201, $result];
        });
    }

    public function transfer(array $data): void
    {
        $agent = $this->agent('tickets.write');
        if ($agent === null) {
            return;
        }
        $this->run('talk_ticket_transfer_failed', fn (): array => [200, (new TalkHelpdesk())->transfer($agent, (int)($data['id'] ?? 0), $this->body())]);
    }

    public function close(array $data): void
    {
        $agent = $this->agent('tickets.write');
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

    /** Lista paginada: {data: [...], meta: {page, per_page, total}}. @param callable():array{data:list<array>, meta:array} $action */
    private function list(string $failure, callable $action): void
    {
        try {
            JsonResponse::send(200, $action());
        } catch (TicketError $error) {
            JsonResponse::send($error->status(), ['error' => ['code' => $error->status() === 404 ? 'not_found' : 'validation_failed', 'message' => $error->getMessage()]]);
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

    /** Usuário do token, se o token tiver o escopo da rota (null = sem escopo exigido). */
    private function agent(?string $scope): ?User
    {
        $user = Auth::user();
        if ($user === null || TalkTokens::current() === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Token de integração ausente ou inválido.']]);
            return null;
        }
        if ($scope !== null && !TalkTokens::allows($scope)) {
            JsonResponse::send(403, ['error' => ['code' => 'insufficient_scope', 'message' => 'Este token não tem o escopo ' . $scope . '.', 'scope' => $scope]]);
            return null;
        }
        return $user;
    }
}
