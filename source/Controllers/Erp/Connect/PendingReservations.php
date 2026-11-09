<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Models\Auth;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Message;

/**
 * ERP v1 — ações da tela Agendamentos Pendentes (AP-114, ADR 0051 e 0070). A tela (GET) é a página
 * do catálogo, com as reservas aguardando aprovação em Panel::pendingReservationData().
 *
 * POST /operacional/agendamentos-pendentes/{id}/confirmar e /recusar, com CSRF e padrão PRG.
 * Muda só a situação da reserva, pelo mesmo RegistryService do cadastro (AP-113): as regras da
 * área comum, o horário sem sobreposição, o escopo de condomínios e a auditoria continuam valendo.
 * Permissão: erp.access + erp.reservations.manage.
 */
final class PendingReservations
{
    public const ROUTE = '/operacional/agendamentos-pendentes';
    public const DECISIONS = ['confirmar' => 'confirmed', 'recusar' => 'inactive'];

    public function confirm(array $data): void
    {
        $this->decide($data, 'confirmar');
    }

    public function refuse(array $data): void
    {
        $this->decide($data, 'recusar');
    }

    private function decide(array $data, string $decision): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return;
        }
        $back = erp_url(self::ROUTE);
        if (!Access::can('erp.access', $user) || !Access::can('erp.reservations.manage', $user)) {
            http_response_code(403);
            (new Message())->error('Você não tem permissão para aprovar reservas.')->flash();
            redirect($back);
            return;
        }
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Tente de novo.')->flash();
            redirect($back);
            return;
        }
        $id = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $service = new RegistryService();
        $definition = RegistryService::definition('reservations');
        try {
            $record = $service->find($user, 'reservations', $id);
            if ($record['status'] !== 'active') {
                (new Message())->warning('Esta reserva não está mais aguardando aprovação.')->flash();
                redirect($back);
                return;
            }
            $service->save($user, 'reservations', ['status' => self::DECISIONS[$decision]] + self::formInput($definition, $record), $id);
            (new Message())->success($decision === 'confirmar' ? 'Reserva confirmada.' : 'Reserva recusada. O horário ficou livre.')->flash();
        } catch (RegistryNotFound) {
            (new Message())->error('Reserva não encontrada no seu escopo de condomínios.')->flash();
        } catch (RegistryValidationException $exception) {
            (new Message())->error('Não foi possível ' . $decision . ' a reserva: ' . implode(' ', array_unique(array_values($exception->errors))))->flash();
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'pending_reservation_failed']);
            (new Message())->error('Não foi possível concluir agora. Tente de novo em instantes.')->flash();
        }
        redirect($back);
    }

    /**
     * Registro como o formulário o enviaria (regra pura): o validador do motor lê valor em reais
     * ("1.250,00"), sim/não como 1/0 e vazio como nulo. Assim a troca de situação regrava os mesmos
     * dados, sem multiplicar a taxa nem apagar campos.
     *
     * @return array<string, string>
     */
    public static function formInput(array $definition, array $record): array
    {
        $input = [];
        foreach ($definition['fields'] as $name => $field) {
            $value = $record[$name] ?? null;
            $input[$name] = match (true) {
                $field['type'] === 'bool' => $value ? '1' : '0',
                $value === null => '',
                $field['type'] === 'money' => number_format((int)$value / 100, 2, ',', '.'),
                is_float($value) => str_replace('.', ',', (string)$value),
                default => (string)$value,
            };
        }
        return $input;
    }
}
