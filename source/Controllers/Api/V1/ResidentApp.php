<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Source\Services\Privacy\PrivacyCenter;
use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Resident\ResidentFeatureDisabled;
use Source\Services\Resident\ResidentCertificates;
use Source\Services\Resident\ResidentFeed;
use Source\Services\Resident\ResidentHousehold;
use Source\Services\Resident\ResidentNotAllowed;
use Source\Services\Resident\ResidentPortal;
use Source\Services\Resident\ResidentProfile;
use Source\Services\Resident\ResidentSession;
use Source\Services\Resident\ResidentTicketClosed;
use Source\Services\Resident\ResidentTickets;
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
 *  GET  /api/v1/resident/common-areas/{id}/availability?month=AAAA-MM  horários já tomados no mês (sem dizer quem)
 *  GET  /api/v1/resident/reservations             reservas e lista de espera da unidade
 *  POST /api/v1/resident/reservations             pedir reserva {common_area_id, reserved_on, starts_time?, ends_time?, notes?}
 *  POST /api/v1/resident/reservations/{id}/cancel cancelar a própria reserva
 *  POST /api/v1/resident/waitlist                 entrar na lista de espera {common_area_id, desired_on, notes?}
 *  GET  /api/v1/resident/occurrences              ocorrências da unidade
 *  POST /api/v1/resident/occurrences              registrar {subject, description, priority?, occurred_on?}
 *  GET  /api/v1/resident/tickets?page=           chamados que o morador abriu (ADR 0305), 20 por página
 *  POST /api/v1/resident/tickets                  abrir {subject, description, priority?, occurred_on?}: registra a ocorrência e o chamado
 *  GET  /api/v1/resident/tickets/{id}             chamado com a conversa pública
 *  POST /api/v1/resident/tickets/{id}/messages    responder {body}; fechado = 409 ticket_closed
 *  GET  /api/v1/resident/deliveries               encomendas
 *  GET  /api/v1/resident/visits                   visitas avisadas
 *  POST /api/v1/resident/visits                   avisar visita {visitor_name, visitor_document?, expected_on, expected_until?, notes?}
 *  POST /api/v1/resident/visits/{id}/cancel       cancelar visita prevista
 *  GET  /api/v1/resident/assemblies               assembleias
 *  GET  /api/v1/resident/assemblies/{id}          edital, pauta, resultado e ata
 *  GET  /api/v1/resident/documents                documentos liberados ao morador
 *  GET  /api/v1/resident/documents/{id}/file      arquivo
 *  GET  /api/v1/resident/pets-vehicles            pets e veículos da unidade
 *  GET  /api/v1/resident/entries/{id}             uma conta da unidade com o boleto e, se paga, o recibo
 *  GET  /api/v1/resident/notifications            avisos do sininho (contas vencidas, encomendas, visitas, comunicados...)
 *  GET  /api/v1/resident/search?q=                busca no que o morador pode ver
 *  GET  /api/v1/resident/2fa                      situação da verificação em duas etapas (opcional, ADR 0304)
 *  POST /api/v1/resident/2fa/confirm              ligar {code}; devolve os códigos de recuperação
 *  POST /api/v1/resident/2fa/disable              desligar {code}
 *  POST /api/v1/resident/2fa/recovery             novos códigos de recuperação {code}
 *  GET  /api/v1/resident/onboarding               {completed, has_photo}: boas-vindas do primeiro acesso (revisão de 15/11)
 *  POST /api/v1/resident/onboarding/complete      marca as boas-vindas como vistas → 204
 *  GET  /api/v1/resident/profile/photo            foto da conta (image/jpeg 512×512) ou 404
 *  POST /api/v1/resident/profile/photo            multipart, campo photo (JPG, PNG ou WebP até 10 MB) → {has_photo: true}
 *  DELETE /api/v1/resident/profile/photo          tira a foto → 204
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

    public function entry(array $data): void
    {
        $id = $this->int($data['id'] ?? null) ?? 0;
        $this->read(function (User $u, array $link) use ($id): array {
            $entry = (new ResidentPortal())->entry($link, $id);
            if ($entry['receipt'] !== null) {
                \Source\Support\Audit::record('read', 'erp_entries', $id, null, ['channel' => 'resident_app', 'document' => 'receipt']);
            }
            return $entry;
        });
    }

    public function notifications(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentFeed())->notifications($link));
    }

    public function search(): void
    {
        $query = is_string($_GET['q'] ?? null) ? (string)$_GET['q'] : '';
        $this->read(fn (User $u, array $link): array => (new ResidentFeed())->search($link, $query,
            \Source\Controllers\App\Resident\AppScreens::menu($link['features'], '')));
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
        } catch (ResidentNotAllowed $exception) {
            JsonResponse::send(403, ['error' => ['code' => 'not_allowed', 'message' => $exception->getMessage()]]);
            return;
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Boleto não encontrado.']]);
            return;
        } catch (\Source\Services\Erp\DebtCertificateBlocked $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'debt_open', 'message' => $exception->getMessage(),
                'overdue_count' => $exception->overdueCount, 'overdue_cents' => $exception->overdueCents]]);
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

    /** CND: certidões de débitos da unidade (revisão de 15/11, etapa 5). */
    public function certificates(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentCertificates())->list($link));
    }

    /** Situação da CND (ADR 0635): débito que impede o pedido e o pedido aberto da unidade. */
    public function certificateStatus(): void
    {
        $this->read(fn (User $u, array $link): array => (new ResidentCertificates())->status($link));
    }

    /** Pede a CND à administradora (chamado do Help Desk). Com débito vencido: 409 debt_open, nada é gerado. */
    public function issueCertificate(): void
    {
        $this->write(202, fn (User $u, array $link): array => (new ResidentCertificates())->request($u, $link));
    }

    public function certificatePdf(array $data): void
    {
        [$user, $link] = $this->context();
        if ($link === null) {
            return;
        }
        $id = $this->int($data['id'] ?? null) ?? 0;
        try {
            $pdf = (new ResidentCertificates())->pdf($link, $id);
        } catch (ResidentFeatureDisabled $error) {
            $this->disabled($error);
            return;
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Certidão não encontrada.']]);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="certidao-de-debitos-' . $id . '.pdf"');
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

    public function areaAvailability(array $data): void
    {
        $id = $this->int($data['id'] ?? null) ?? 0;
        $month = is_string($_GET['month'] ?? null) ? (string)$_GET['month'] : null;
        $this->read(fn (User $u, array $link): array => (new ResidentPortal())->areaAvailability($link, $id, $month));
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

    public function tickets(): void
    {
        $page = $this->int($_GET['page'] ?? null) ?? 1;
        $kind = in_array($_GET['kind'] ?? null, ['chamado', 'ocorrencia'], true) ? $_GET['kind'] : null;
        [$user, $link] = $this->context();
        if ($link !== null) {
            $this->run(200, fn () => (new ResidentTickets())->list($user, $link, $page, $kind), true);
        }
    }

    public function ticket(array $data): void
    {
        $id = $this->int($data['id'] ?? null) ?? 0;
        $this->read(fn (User $u, array $link): array => (new ResidentTickets())->show($u, $link, $id));
    }

    public function openTicket(): void
    {
        $this->write(201, fn (User $u, array $link, array $body): array => (new ResidentTickets())->open($u, $link, $body));
    }

    public function replyTicket(array $data): void
    {
        $id = $this->int($data['id'] ?? null) ?? 0;
        $this->write(201, fn (User $u, array $link, array $body): array => (new ResidentTickets())->reply($u, $link, $id, is_string($body['body'] ?? null) ? $body['body'] : ''));
    }

    /** Moradores da unidade (revisão de 15/11, etapa 4). */
    public function household(): void
    {
        [$user, $link] = $this->context();
        if ($link !== null) {
            $this->run(200, fn () => (new ResidentHousehold())->list($link), true);
        }
    }

    public function addHousehold(): void
    {
        $this->write(201, fn (User $u, array $link, array $body): array => (new ResidentHousehold())->add($u, $link, $body));
    }

    public function blockHousehold(array $data): void
    {
        $id = $this->int($data['id'] ?? null) ?? 0;
        $this->write(200, fn (User $u, array $link): array => (new ResidentHousehold())->block($u, $link, $id));
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
        } catch (ResidentNotAllowed $exception) {
            JsonResponse::send(403, ['error' => ['code' => 'not_allowed', 'message' => $exception->getMessage()]]);
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

    /** Privacidade da conta (LGPD, ADR 0604): situação do aceite, novidades e tipos de pedido. */
    public function privacy(): void
    {
        $this->read(fn (User $u): array => (new PrivacyCenter())->status((int)$u->id));
    }

    /** POST {accept_terms: true, marketing?: bool} — aceite dos Termos e da Política na versão atual. */
    public function privacyAccept(): void
    {
        $this->write(200, function (User $u, array $link, array $body): array {
            if (($body['accept_terms'] ?? null) !== true) {
                throw new InvalidArgumentException('Marque que leu e aceita os Termos de Uso e a Política de Privacidade.');
            }
            $marketing = array_key_exists('marketing', $body) ? $body['marketing'] === true : null;
            return (new PrivacyCenter())->accept((int)$u->id, 'celular', $marketing);
        });
    }

    /** POST {marketing: bool} — dar ou retirar o consentimento de novidades. */
    public function privacyMarketing(): void
    {
        $this->write(200, function (User $u, array $link, array $body): array {
            if (!is_bool($body['marketing'] ?? null)) {
                throw new InvalidArgumentException('Informe marketing como verdadeiro ou falso.');
            }
            return (new PrivacyCenter())->setMarketing((int)$u->id, $body['marketing'], 'celular');
        });
    }

    /** POST {type, details?} — pedido do titular (art. 18), vira chamado LGPD no Help Desk. */
    public function privacyRequest(): void
    {
        $this->write(201, fn (User $u, array $link, array $body): array => (new PrivacyCenter())->request($u, $link,
            is_string($body['type'] ?? null) ? $body['type'] : '', is_string($body['details'] ?? null) ? $body['details'] : ''));
    }

    public function onboarding(): void
    {
        $this->read(fn (User $u): array => (new ResidentProfile())->status((int)$u->id));
    }

    public function onboardingComplete(): void
    {
        [$user, $link] = $this->context();
        if ($link !== null) {
            (new ResidentProfile())->complete((int)$user->id);
            http_response_code(204);
        }
    }

    public function photo(): void
    {
        [$user, $link] = $this->context();
        if ($link === null) {
            return;
        }
        $file = (new ResidentProfile())->photo((int)$user->id);
        if ($file === null) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Sem foto.']]);
            return;
        }
        self::sendPhoto($file);
    }

    public function uploadPhoto(): void
    {
        [$user, $link] = $this->context();
        if ($link !== null) {
            $this->run(200, function () use ($user): array {
                (new ResidentProfile())->storePhoto((int)$user->id, is_array($_FILES['photo'] ?? null) ? $_FILES['photo'] : []);
                return ['has_photo' => true];
            });
        }
    }

    public function deletePhoto(): void
    {
        [$user, $link] = $this->context();
        if ($link !== null) {
            (new ResidentProfile())->deletePhoto((int)$user->id);
            http_response_code(204);
        }
    }

    /** Foto só para a própria pessoa: nunca em cache compartilhado. Usado também pelo app web (/perfil/foto). */
    public static function sendPhoto(string $file): void
    {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string)filesize($file));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
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
        header("Content-Security-Policy: default-src 'none'; sandbox"); // ADR 0393: arquivo enviado nunca roda como página
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

    /** @param bool $paged a ação já devolve {data, meta} (lista paginada) */
    private function run(int $status, callable $action, bool $paged = false): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, $paged ? $result : ['data' => $result]);
        } catch (ResidentTicketClosed $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'ticket_closed', 'message' => $exception->getMessage()]]);
        } catch (ResidentFeatureDisabled $error) {
            $this->disabled($error);
        } catch (ResidentNotAllowed $exception) {
            JsonResponse::send(403, ['error' => ['code' => 'not_allowed', 'message' => $exception->getMessage()]]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Não encontrado.']]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Confira os campos.', 'fields' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (\Source\Services\Erp\DebtCertificateBlocked $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'debt_open', 'message' => $exception->getMessage(),
                'overdue_count' => $exception->overdueCount, 'overdue_cents' => $exception->overdueCents]]);
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
