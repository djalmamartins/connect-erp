<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use DomainException;
use InvalidArgumentException;
use Source\Core\Connect;
use Source\Core\Controller;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Privacy\PersonalDataAnonymizer;
use Source\Services\Privacy\PersonalDataExport;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Audit;
use Source\Support\Message;

/**
 * ERP v1 — Dados pessoais (Configurações › Acesso, CN-009, ADR 0332): a tela dos pedidos do titular (LGPD).
 *
 * GET  /configuracoes/dados-pessoais             formulário de busca (CPF/CNPJ e/ou e-mail)
 * POST /configuracoes/dados-pessoais             busca: mostra o que o sistema guarda e a prévia da anonimização
 * POST /configuracoes/dados-pessoais/baixar      o mesmo relatório em JSON, para entregar ao titular
 * POST /configuracoes/dados-pessoais/anonimizar  anonimiza, com motivo e confirmação (não tem volta)
 *
 * A busca vai por POST para o CPF e o e-mail não ficarem na URL, no histórico do navegador nem nos logs do servidor.
 * Usa os mesmos serviços da API (/api/v1/privacy/personal-data e /privacy/anonymize, ADR 0328 e 0330), com o mesmo
 * limite de pedidos e a mesma auditoria (só o hash da busca). Permissões: erp.access + erp.privacy.export; anonimizar
 * exige também erp.users.manage.
 */
final class PrivacyScreens extends Controller
{
    public const PAGE_ID = 'CN-009';
    public const ROUTE = '/configuracoes/dados-pessoais';

    public function __construct()
    {
        parent::__construct(moves_container_path('erp', CONF_VIEW_ERP) . '/');
    }

    public function index(): void
    {
        $user = $this->guard(false);
        if ($user === null) {
            return;
        }
        $this->show($user, $this->blank($user));
    }

    public function search(array $data): void
    {
        $user = $this->guard(false);
        if ($user === null) {
            return;
        }
        $page = $this->blank($user);
        $page['privacyDocument'] = is_scalar($data['document'] ?? null) ? mb_substr(trim((string)$data['document']), 0, 32) : '';
        $page['privacyEmail'] = is_scalar($data['email'] ?? null) ? mb_substr(trim((string)$data['email']), 0, 190) : '';
        if (!csrf_verify($data)) {
            $page['privacyError'] = 'Sua sessão expirou. Tente de novo.';
        } elseif (request_limit('erp_privacy_export', 20, 600)) {
            $page['privacyError'] = 'Muitas buscas seguidas. Tente de novo em alguns minutos.';
        } elseif (Connect::databaseDisabled()) {
            $page['privacyError'] = 'Sem conexão com o banco de dados, a busca não pode ser feita.';
        } else {
            try {
                [$document, $email] = PersonalDataExport::criteria($data['document'] ?? null, $data['email'] ?? null);
                $report = (new PersonalDataExport())->export($user, $document, $email);
                $page['privacyReport'] = $report;
                $this->audit('export', $document, $email, ['records' => (int)$report['total']]);
                if ($page['canAnonymize'] && (int)$report['total'] > 0) {
                    try {
                        $page['privacyPreview'] = (new PersonalDataAnonymizer())->run($user, ['document' => $document, 'email' => $email]);
                    } catch (DomainException) {
                        $page['privacyPreview'] = null;
                    }
                }
            } catch (InvalidArgumentException $exception) {
                $page['privacyError'] = $exception->getMessage();
            } catch (\Throwable $error) {
                AppLogger::exception($error, 'erp', ['event_type' => 'privacy_screen_failed']);
                $page['privacyError'] = 'Não foi possível buscar agora. Tente de novo em instantes.';
            }
        }
        $this->show($user, $page);
    }

    public function download(array $data): void
    {
        $user = $this->guard(false);
        if ($user === null) {
            return;
        }
        if (!csrf_verify($data) || request_limit('erp_privacy_export', 20, 600) || Connect::databaseDisabled()) {
            (new Message())->error('Não foi possível gerar o arquivo agora. Faça a busca de novo.')->flash();
            redirect(erp_url(self::ROUTE));
            return;
        }
        try {
            [$document, $email] = PersonalDataExport::criteria($data['document'] ?? null, $data['email'] ?? null);
            $report = (new PersonalDataExport())->export($user, $document, $email);
        } catch (InvalidArgumentException $exception) {
            (new Message())->error($exception->getMessage())->flash();
            redirect(erp_url(self::ROUTE));
            return;
        }
        $this->audit('export', $document, $email, ['records' => (int)$report['total'], 'format' => 'json']);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="dados-pessoais-' . date('Y-m-d') . '.json"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['generated_at' => date('c'), 'data' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function anonymize(array $data): void
    {
        $user = $this->guard(true);
        if ($user === null) {
            return;
        }
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Tente de novo.')->flash();
            redirect(erp_url(self::ROUTE));
            return;
        }
        if (request_limit('erp_privacy_anonymize', 10, 600)) {
            (new Message())->warning('Muitos pedidos seguidos. Tente de novo em alguns minutos.')->flash();
            redirect(erp_url(self::ROUTE));
            return;
        }
        try {
            [$document, $email] = PersonalDataExport::criteria($data['document'] ?? null, $data['email'] ?? null);
            $reason = is_scalar($data['reason'] ?? null) ? (string)$data['reason'] : '';
            $result = (new PersonalDataAnonymizer())->run($user, ['document' => $document, 'email' => $email, 'reason' => $reason, 'confirm' => true]);
            $counts = array_map(static fn ($ids): int => is_array($ids) ? count($ids) : 0, array_diff_key($result['plan'], ['kept' => true]));
            $this->audit('anonymize', $document, $email, ['reason' => mb_substr(trim($reason), 0, 500), 'counts' => $counts]);
            (new Message())->success(sprintf('Dados anonimizados: %d cadastro(s), %d visitante(s), %d chamado(s). As contas a receber continuam guardadas.',
                $counts['residents'] ?? 0, $counts['visitors'] ?? 0, $counts['tickets'] ?? 0))->flash();
        } catch (InvalidArgumentException $exception) {
            (new Message())->error($exception->getMessage())->flash();
        } catch (DomainException $exception) {
            (new Message())->warning($exception->getMessage())->flash();
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'privacy_anonymize_failed']);
            (new Message())->error('Não foi possível anonimizar agora. Nada foi alterado.')->flash();
        }
        redirect(erp_url(self::ROUTE));
    }

    /** @return array<string, mixed> */
    private function blank(User $user): array
    {
        return ['privacyDocument' => '', 'privacyEmail' => '', 'privacyReport' => null, 'privacyPreview' => null, 'privacyError' => null,
            'canAnonymize' => Access::can('erp.users.manage', $user), 'privacyDatabase' => !Connect::databaseDisabled(), 'flash' => (new Session())->flash()];
    }

    /** @param array<string, mixed> $data */
    private function show(User $user, array $data): void
    {
        header('Cache-Control: no-store, private');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        echo $this->view->render('pages/settings/privacy', (new Panel())->layoutData($user, self::PAGE_ID, $data));
    }

    /** @param array<string, mixed> $extra */
    private function audit(string $action, ?string $document, ?string $email, array $extra): void
    {
        Audit::record($action, 'privacy_personal_data', null, [], ['search' => hash('sha256', (string)$document . '|' . (string)$email), 'origin' => 'erp'] + $extra);
    }

    private function guard(bool $anonymize): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.privacy.export', $user) || ($anonymize && !Access::can('erp.users.manage', $user))) {
            http_response_code(403);
            echo $this->view->render('pages/system/state', (new Panel())->layoutData($user, self::PAGE_ID, [
                'stateCode' => 403, 'stateTitle' => 'Acesso negado', 'withNavigation' => true,
                'stateMessage' => $anonymize ? 'Você pode consultar os dados pessoais, mas não anonimizar.' : 'Você não tem permissão para consultar dados pessoais.',
            ], 'Acesso negado'));
            return null;
        }
        return $user;
    }
}
