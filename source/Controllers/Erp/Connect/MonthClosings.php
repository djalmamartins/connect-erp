<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\RecurringEntries;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Erp\CondominiumContext;
use Source\Support\Message;

/**
 * ERP v1 — ações da tela Fechamento Mensal (AP-030, ADR 0031/0032). A tela (GET) é a página
 * do catálogo, que recebe os dados reais em Panel::closingData().
 *
 * POST /financeiro/fechamento-mensal/fechar e /reabrir, com CSRF e padrão PRG. O condomínio
 * vem do contexto da barra superior (ADR 0030), nunca do formulário: em "Todos" a ação é
 * recusada e pede para escolher um condomínio. O serviço confere o escopo de novo.
 * Permissão: erp.access + erp.closing.manage.
 */
final class MonthClosings
{
    public const ROUTE = '/financeiro/fechamento-mensal';

    public function close(array $data): void
    {
        $this->handle($data, static function (MonthClosing $service, $user, int $condominium, string $competence): string {
            $service->close($user, $condominium, $competence);
            return "Competência {$competence} fechada. Os lançamentos dela ficam travados até a reabertura.";
        });
    }

    public function reopen(array $data): void
    {
        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';
        $this->handle($data, static function (MonthClosing $service, $user, int $condominium, string $competence) use ($reason): string {
            $service->reopen($user, $condominium, $competence, $reason);
            return "Competência {$competence} reaberta. Os lançamentos voltam a aceitar alterações.";
        });
    }

    /** @param callable(MonthClosing, \Source\Models\User, int, string): string $action */
    private function handle(array $data, callable $action): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return;
        }
        $competence = is_string($data['competence'] ?? null) ? trim($data['competence']) : '';
        $back = erp_url(self::ROUTE) . (RecurringEntries::isCompetence($competence) ? '?competencia=' . $competence : '');
        if (!Access::can('erp.access', $user) || !Access::can('erp.closing.manage', $user)) {
            http_response_code(403);
            (new Message())->error('Você não tem permissão para fechar ou reabrir competências.')->flash();
            redirect($back);
            return;
        }
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Tente de novo.')->flash();
            redirect($back);
            return;
        }
        $condominium = CondominiumContext::id();
        if ($condominium === null) {
            (new Message())->warning('Escolha um condomínio na barra superior para fechar ou reabrir a competência.')->flash();
            redirect($back);
            return;
        }
        try {
            (new Message())->success($action(new MonthClosing(), $user, $condominium, $competence))->flash();
        } catch (InvalidArgumentException | DomainException $error) {
            (new Message())->error($error->getMessage())->flash();
        } catch (CondominiumAccessDenied) {
            (new Message())->error('Condomínio fora do seu escopo de acesso.')->flash();
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'month_closing_action_failed', 'condominium_id' => $condominium]);
            (new Message())->error('Não foi possível concluir. Tente de novo em instantes.')->flash();
        }
        redirect($back);
    }
}
