<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Resident\ResidentFeatureDisabled;
use Source\Services\Resident\ResidentPortal;
use Source\Services\Resident\ResidentSession;
use Source\Services\Resident\ResidentTwoFactor;

/**
 * API do aplicativo do morador (ADR 0302), a mesma do app web em app.<domínio> e do app nativo.
 * Todas as rotas aceitam ?resident_id= para escolher o vínculo (quem tem mais de uma unidade); sem ele vale
 * o vínculo escolhido na sessão ou o primeiro. Conta sem vínculo válido recebe 403 not_resident.
 *
 *  GET  /api/v1/resident/me                       pessoa, vínculos, vínculo atual e funções ligadas
 *  GET  /api/v1/resident/summary                  resumo da tela inicial
 *  GET  /api/v1/resident/charges?from=&to=        extrato da unidade com o boleto de cada conta
 *  GET  /api/v1/resident/charges/{id}/pdf         PDF do boleto (Inter)
 *  GET  /api/v1/resident/accountability           links da prestação de contas
 *  GET  /api/v1/resident/announcements            comunicados
 *  GET  /api/v1/resident/common-areas             áreas comuns e regras
 *  GET  /api/v1/resident/reservations             reservas e lista de espera da unidade
 *  POST /api/v1/resident/reservations             pedir reserva {common_area_id, reserved_on, starts_time?, ends_time?, notes?}
 *  POST /api/v1/resident/reservations/{id}/cancel cancelar a própria reserva
 *  POST /api/v1/resident/waitlist                 entrar na lista de espera {common_area_id, desired_on, notes?}
 *  GET  /api/v1/resident/occurrences              ocorrências da unidade
 *  POST /api/v1/resident/occurrences              registrar {subject, description, priority?, occurred_on?}
 *  GET  /api/v1/resident/deliveries               encomendas
 *  GET  /api/v1/resident/visits                   visitas avisadas
 *  POST /api/v1/resident/visits                   avisar visita {visitor_name, visitor_document?, expected_on, expected_until?, notes?}
 *  POST /api/v1/resident/visits/{id}/cancel       cancelar visita prevista
 *  GET  /api/v1/resident/assemblies               assembleias
 *  GET  /api/v1/resident/assemblies/{id}          edital, pauta, resultado e ata
 *  GET  /api/v1/resident/documents                documentos liberados ao morador
 *  GET  /api/v1/resident/documents/{id}/file      arquivo
 *  GET  /api/v1/resident/pets-vehicles            pets e veículos da unidade
 *  GET  /api/v1/resident/2fa                      situação da verificação em duas etapas (opcional, ADR 0304)
 *  POST /api/v1/resident/2fa/confirm              ligar {code}; devolve os códigos de recuperação
 *  POST /api/v1/resident/2fa/disable              desligar {code}
 *  POST /api/v1/resident/2fa/recovery             novos códigos de recuperação {code}
 */
final class ResidentApp
{
    public function me(): void
    {
        $this->read(function (User $user, array $link, array $all): array {
            return ['user' => ['id' => (int)$user->id, 'name' => trim((string)($user->first_name ?? '') . ' ' . (string)($user->last_name ?? '')), 'email' => (string)($user->email ?? '')],
                'current' => $link, 'links' => $all];
        });
    }

    public function summary(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->summary($link));
    }

    public function charges(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->charges($link, $_GET['from'] ?? null, $_GET['to'] ?? null));
    }

    public function chargePdf(array $data): void
    {
        [$user, $link] = $this->context();
        if ($link === null) {
            return;
        }
        $id = $this->int($data['id'] ?? null) ?? 0;
        try {
            $pdf = (new ResidentPortal())->chargePdf($link, $id);
        } catch (ResidentFeatureDisabled $error) {
            $this->disabled($error);
            return;
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Boleto não encontrado.']]);
            return;
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
            return;
        } catch (\RuntimeException $exception) {
            \Source\Support\AppLogger::exception($exception, 'erp', ['event_type' => 'resident_charge_pdf_failed', 'charge_id' => $id]);
            JsonResponse::send(502, ['error' => ['code' => 'bank_unavailable', 'message' => 'O banco não devolveu o PDF agora. Tente de novo.']]);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="boleto-' . $id . '.pdf"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
    }

    public function accountability(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->accountability($link));
    }

    public function announcements(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->announcements($link));
    }

    public function commonAreas(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->commonAreas($link));
    }

    public function reservations(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->reservations($link));
    }

    public function requestReservation(): void
    {
        $this->write(201, fn (User $u, array $link, array $body): array => (new ResidentPortal())->requestReservation($u, $link, $body));
    }

    public function cancelReservation(array $data): void
    {
        $id = $this->int($data['id'] ?? null) ?? 0;
        $this->write(200, function (User $u, array $link) use ($id): array {
            (new ResidentPortal())->cancelReservation($u, $link, $id);
            return ['id' => $id, 'status' => 'inactive'];
        });
    }

    public function joinWaitlist(): void
    {
        $this->write(201, fn (User $u, array $link, array $body): array => (new ResidentPortal())->joinWaitlist($u, $link, $body));
    }

    public function occurrences(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->occurrences($link));
    }

    public function reportOccurrence(): void
    {
        $this->write(201, fn (User $u, array $link, array $body): array => (new ResidentPortal())->reportOccurrence($u, $link, $body));
    }

    public function deliveries(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->deliveries($link));
    }

    public function visits(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->visits($link));
    }

    public function authorizeVisit(): void
    {
        $this->write(201, fn (User $u, array $link, array $body): array => (new ResidentPortal())->authorizeVisit($u, $link, $body));
    }

    public function cancelVisit(array $data): void
    {
        $id = $this->int($data['id'] ?? null) ?? 0;
        $this->write(200, function (User $u, array $link) use ($id): array {
            (new ResidentPortal())->cancelVisit($u, $link, $id);
            return ['id' => $id, 'status' => 'inactive'];
        });
    }

    public function assemblies(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->assemblies($link));
    }

    public function assembly(array $data): void
    {
        $id = $this->int($data['id'] ?? null) ?? 0;
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->assembly($link, $id));
    }

    public function documents(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->documents($link));
    }

    public function documentFile(array $data): void
    {
        [, $link] = $this->context();
        if ($link === null) {
            return;
        }
        try {
            $file = (new ResidentPortal())->documentFile($link, $this->int($data['id'] ?? null) ?? 0);
        } catch (ResidentFeatureDisabled $error) {
            $this->disabled($error);
            return;
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Documento não encontrado.']]);
            return;
        }
        self::sendFile($file);
    }

    public function petsVehicles(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->petsVehicles($link));
    }

    public function twoFactor(): void
    {
        [$user, $link] = $this->context();
        if ($link !== null) {
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => (new ResidentTwoFactor())->status($user)]);
        }
    }

    public function twoFactorConfirm(): void
    {
        $this->twoFactorAct('confirm');
    }

    public function twoFactorDisable(): void
    {
        $this->twoFactorAct('disable');
    }

    public function twoFactorRecovery(): void
    {
        $this->twoFactorAct('recovery');
    }

    private function twoFactorAct(string $action): void
    {
        [$user, $link] = $this->context();
        if ($link === null) {
            return;
        }
        $raw = (string)file_get_contents('php://input');
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $result = (new ResidentTwoFactor())->act($user, $action, $body['code'] ?? null);
        header('Cache-Control: no-store, private');
        if (!$result['ok']) {
            JsonResponse::send($result['status'], ['error' => ['code' => $result['code'], 'message' => $result['message']]]);
            return;
        }
        unset($result['ok']);
        JsonResponse::send(200, ['data' => $result + ['enabled' => $action !== 'disable']]);
    }

    /** @param array{path:string, mime:string, name:string, size:int} $file */
    public static function sendFile(array $file): void
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $file['name']) ?: 'documento';
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . $file['size']);
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($file['path']);
    }

    /** @return array{0: ?User, 1: ?array, 2: list<array>} */
    private function context(): array
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return [null, null, []];
        }
        [$all, $link] = ResidentSession::resolve($user, $_GET['resident_id'] ?? null);
        if ($link === null) {
            JsonResponse::send(403, ['error' => ['code' => 'not_resident', 'message' => 'Sua conta não está ligada a nenhuma unidade ativa.']]);
            return [$user, null, []];
        }
        return [$user, $link, $all];
    }

    private function read(callable $action): void
    {
        [$user, $link, $all] = $this->context();
        if ($link !== null) {
            $this->run(200, fn () => $action($user, $link, $all));
        }
    }

    private function write(int $status, callable $action): void
    {
        [$user, $link] = $this->context();
        if ($link === null) {
            return;
        }
        $raw = (string)file_get_contents('php://input');
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $this->run($status, fn () => $action($user, $link, $body));
    }

    private function run(int $status, callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $result]);
        } catch (ResidentFeatureDisabled $error) {
            $this->disabled($error);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Não encontrado.']]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Confira os campos.', 'fields' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function disabled(ResidentFeatureDisabled $error): void
    {
        JsonResponse::send(403, ['error' => ['code' => 'feature_disabled', 'message' => $error->getMessage(), 'feature' => $error->feature]]);
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
