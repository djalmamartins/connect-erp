<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\Announcements;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Dados das telas de comunicação (ADR 0059, 0065 e 0075), lidos no servidor pelo mesmo serviço da API:
 * AP-111 comunicado geral, AP-137 aviso de cobrança (com as unidades em atraso e quem já foi avisado),
 * AP-138 envios agendados e AP-139 endereços de comunicação. O detalhe de um comunicado vem de
 * ?comunicado=ID. Enviar e cancelar são pela API REST (erp.js, data-erp-communication), com CSRF no
 * cabeçalho e a permissão conferida lá. Sem banco ou sem as permissões de ver, commReady false = a
 * tela segue com a demonstração.
 */
final class CommunicationScreens
{
    /** Página => tela própria. */
    public const PAGES = [
        'AP-111' => 'pages/communication/announcements',
        'AP-137' => 'pages/communication/announcements',
        'AP-138' => 'pages/communication/announcements',
        'AP-139' => 'pages/communication/addresses',
    ];

    /** Tipo dos comunicados de cada página; AP-138 mostra os dois tipos, só os agendados. */
    public const KINDS = ['AP-111' => 'general', 'AP-137' => 'collection', 'AP-138' => null];

    /** Rótulos do vínculo da pessoa com a unidade (os mesmos do cadastro de pessoas, AP-005). */
    public const RELATIONSHIPS = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro'];

    /** Permissão extra, além de erp.announcements.view, como na API. */
    public const EXTRA_PERMISSION = ['AP-137' => 'erp.agreements.view', 'AP-139' => 'erp.residents.view'];

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        $data = ['commReady' => false];
        $extra = self::EXTRA_PERMISSION[$id] ?? null;
        if (!isset(self::PAGES[$id]) || Connect::databaseDisabled() || !Access::can('erp.announcements.view', $user)
            || ($extra !== null && !Access::can($extra, $user))) {
            return $data;
        }
        try {
            $context = CondominiumContext::id();
            $data += ['commContext' => $context, 'commError' => null, 'commCanManage' => Access::can('erp.announcements.manage', $user)];
            // Modo de entrega (teste do Djalma, 09/11, item 10): fora de produção nada sai para o morador, e a tela
            // precisa dizer isso, senão "Enviado" parece e-mail entregue.
            $data['commMode'] = self::deliveryMode();
            $service = new Announcements();
            if ($id === 'AP-139') {
                // A tela recebe tudo pronto (unidades já filtradas e os rótulos do vínculo): não lê
                // cadastro nem banco ao renderizar.
                $addresses = $service->addresses($user, $context);
                $filter = in_array($_GET['sem'] ?? null, ['comunicado', 'cobranca'], true) ? $_GET['sem'] : null;
                $data['addresses'] = $addresses;
                $data['addressFilter'] = $filter;
                $data['addressUnits'] = self::filterAddresses($addresses['data'], $filter);
                $data['addressRelationships'] = self::RELATIONSHIPS;
            } else {
                $kind = self::KINDS[$id];
                $data['commKind'] = $kind;
                $data['announcements'] = $service->list($user, $context, $id === 'AP-138' ? ['scheduled' => 1] : ['kind' => $kind]);
                $data['announcementDetail'] = null;
                $detail = filter_var($_GET['comunicado'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($detail !== false && $detail !== null) {
                    try {
                        $found = $service->find($user, (int)$detail);
                        if ($kind === null || $found['kind'] === $kind) {
                            $data['announcementDetail'] = $found;
                        } else {
                            $data['commError'] = 'Este comunicado é de outra página.';
                        }
                    } catch (RegistryNotFound) {
                        $data['commError'] = 'Comunicado não encontrado no seu escopo.';
                    }
                }
                if ($id === 'AP-111') {
                    $data['commUnits'] = $context === null ? [] : array_values(array_filter((new RegistryService())->units($user),
                        static fn (array $u): bool => $u['condominium_id'] === $context));
                }
                if ($id === 'AP-137') {
                    $data += $this->collectionData($user, $service, $context);
                }
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'communication_screen_failed', 'page' => $id]);
            return ['commReady' => false];
        }
        $data['commReady'] = true;
        return $data;
    }

    /**
     * Aviso de cobrança (AP-137): as unidades com conta vencida na data de referência (?referencia=,
     * padrão hoje), as avisadas nos últimos dias (ficam de fora) e quantas pessoas autorizaram a cobrança
     * por e-mail em cada unidade (quando a conta pode ver o cadastro de pessoas).
     *
     * @return array<string, mixed>
     */
    private function collectionData(User $user, Announcements $service, ?int $context): array
    {
        $asOf = self::referenceDate($_GET['referencia'] ?? null);
        $out = ['collectionAsOf' => $asOf ?? date('Y-m-d'), 'collectionUnits' => null, 'collectionReminded' => [], 'collectionBilling' => null];
        if ($asOf === null) {
            $out['commError'] = 'Data de referência inválida ou futura. Mostrando a de hoje.';
        }
        if ($context === null) {
            return $out;
        }
        $out['collectionUnits'] = array_values(array_filter((new Agreements())->delinquency($user, $context, $out['collectionAsOf'])['units'],
            static fn (array $u): bool => $u['unit_id'] !== null));
        $out['collectionReminded'] = $service->remindedUnits($user, $context);
        if (Access::can('erp.residents.view', $user)) {
            $out['collectionBilling'] = array_column(array_map(static fn (array $u): array => ['id' => $u['unit_id'], 'n' => $u['billing']],
                $service->addresses($user, $context)['data']), 'n', 'id');
        }
        return $out;
    }

    /** Data de referência do aviso de cobrança (regra pura): vazio = hoje; inválida ou futura = null. */
    public static function referenceDate(mixed $value, ?string $today = null): ?string
    {
        $today ??= date('Y-m-d');
        if ($value === null || $value === '') {
            return $today;
        }
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value || $value > $today) {
            return null;
        }
        return $value;
    }

    /** Modo de entrega efetivo (o mesmo do NotificationDispatcher), sem abrir conexão: log, sandbox ou production. */
    public static function deliveryMode(): string
    {
        return NotificationDispatcher::deliveryMode(defined('CONF_APP_ENV') ? (string)CONF_APP_ENV : 'development',
            getenv('ERP_MAIL_DELIVERY') ?: 'log', getenv('ERP_MAIL_SANDBOX_TO') ?: null);
    }

    /** Texto do aviso de modo de entrega (regra pura). Em produção, null (sem aviso). */
    public static function deliveryNotice(string $mode): ?string
    {
        return match ($mode) {
            'production' => null,
            'sandbox' => 'Modo de teste: os e-mails vão só para a caixa de teste, com "[TESTE]" no assunto. Nenhum morador recebe.',
            default => 'Modo só registro: os avisos entram na fila e ficam registrados, mas nenhum e-mail sai. "Enviado" aqui quer dizer processado, não entregue.',
        };
    }

    /**
     * Situação de um comunicado para a tela (regra pura): cancelado, agendado, enviando (avisos na
     * fila), com falha ou enviado.
     *
     * @return array{0: string, 1: string} rótulo e tom do selo
     */
    public static function statusOf(array $announcement): array
    {
        $d = $announcement['delivery'] ?? [];
        return match (true) {
            ($announcement['status'] ?? '') === 'cancelled' => ['Cancelado', 'plain'],
            (bool)($announcement['scheduled'] ?? false) => ['Agendado', 'info'],
            ($d['pending'] ?? 0) + ($d['processing'] ?? 0) > 0 => ['Enviando', 'warning'],
            ($d['failed'] ?? 0) > 0 => ['Com falha', 'danger'],
            default => ['Enviado', 'success'],
        };
    }

    /** Pode cancelar (regra pura): ativo e com aviso que ainda não saiu. */
    public static function cancellable(array $announcement): bool
    {
        return ($announcement['status'] ?? '') === 'active' && (int)($announcement['delivery']['pending'] ?? 0) > 0;
    }

    /**
     * Endereços filtrados (regra pura): 'comunicado' = unidades sem ninguém que receba comunicado,
     * 'cobranca' = sem ninguém que receba a cobrança por e-mail.
     *
     * @param list<array> $units
     * @return list<array>
     */
    public static function filterAddresses(array $units, ?string $filter): array
    {
        $key = ['comunicado' => 'notices', 'cobranca' => 'billing'][$filter ?? ''] ?? null;
        if ($key === null) {
            return $units;
        }
        return array_values(array_filter($units, static fn (array $u): bool => (int)$u[$key] === 0));
    }
}
