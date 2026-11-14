<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use DomainException;
use InvalidArgumentException;
use Source\Core\Connect;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Antivirus\UploadScanUnavailable;
use Source\Services\Erp\EntryAttachments;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Message;

/**
 * Anexos do lançamento na tela de edição (ADR 0174): nota fiscal, comprovante, recibo ou outro documento.
 * Mesmo serviço da API /api/v1/entries/{id}/attachments.
 *
 *  POST /financeiro/lancamentos/{id}/anexos                       envia (multipart: kind, file), CSRF
 *  GET  /financeiro/lancamentos/{id}/anexos/{attachment}          baixa (ou abre, com ?ver=1, se for PDF/imagem)
 *  POST /financeiro/lancamentos/{id}/anexos/{attachment}/excluir  remove o vínculo, CSRF
 *
 * Permissões: erp.access + erp.entries.view (baixar) e erp.entries.manage (enviar e remover). O retorno vai para
 * a tela de onde o usuário veio (lançamentos, contas a pagar ou a receber), conferida contra uma lista fechada.
 */
final class EntryAttachmentScreens
{
    public const ROUTE = '/financeiro/lancamentos';
    /** Telas que mostram o cartão de anexos (cadastros do motor sobre erp_entries). */
    public const SLUGS = ['entries' => '/financeiro/lancamentos', 'payables' => '/financeiro/contas-a-pagar', 'receivables' => '/financeiro/contas-a-receber'];
    /** Tipos que o navegador pode abrir direto (com CSP sandbox); o resto sempre baixa. */
    public const INLINE = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** Dados do cartão de anexos para a tela de edição (null = cadastro sem anexos ou lançamento novo). */
    public static function cardData(User $user, string $slug, ?int $entryId): ?array
    {
        if ($entryId === null || !isset(self::SLUGS[$slug]) || Connect::databaseDisabled()) {
            return null;
        }
        $data = ['entryId' => $entryId, 'back' => self::SLUGS[$slug], 'items' => [], 'canManage' => Access::can('erp.entries.manage', $user),
            'kinds' => EntryAttachments::KINDS, 'error' => null];
        try {
            $data['items'] = (new EntryAttachments())->list($user, $entryId);
        } catch (RegistryNotFound) {
            return null;
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'entry_attachments_card_failed']);
            $data['error'] = 'Não foi possível carregar os anexos agora.';
        }
        return $data;
    }

    /** Tela de retorno (regra pura): uma das rotas de SLUGS, com o id; qualquer outra coisa volta para Lançamentos. */
    public static function back(mixed $value, int $entryId): string
    {
        $route = is_string($value) && in_array($value, self::SLUGS, true) ? $value : self::ROUTE;
        return $route . '/' . $entryId;
    }

    public function store(array $data): void
    {
        $id = $this->id($data, 'id');
        $back = erp_url(self::back($data['voltar'] ?? null, $id)) . '#anexos';
        $user = $this->guard('manage', $back);
        if ($user === null) {
            return;
        }
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Envie o arquivo de novo.')->flash();
            redirect($back);
            return;
        }
        if (request_limit('erp_entry_attachment_upload', 60, 600)) {
            (new Message())->error('Muitos envios em pouco tempo. Aguarde alguns minutos.')->flash();
            redirect($back);
            return;
        }
        try {
            $upload = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
            $item = (new EntryAttachments())->attach($user, $id, $upload, $data['kind'] ?? null);
            (new Message())->success($item['kind_label'] . ' anexad' . (in_array($item['kind'], ['nota_fiscal', 'conta'], true) ? 'a' : 'o') . ' ao lançamento.')->flash();
        } catch (InvalidArgumentException | DomainException $error) {
            (new Message())->error($error->getMessage())->flash();
        } catch (RegistryNotFound) {
            (new Message())->error('Lançamento não encontrado no seu escopo.')->flash();
        } catch (UploadScanUnavailable $error) {
            (new Message())->error($error->getMessage())->flash();
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'erp', ['event_type' => 'entry_attachment_upload_failed']);
            (new Message())->error('Não foi possível guardar o arquivo. Tente de novo.')->flash();
        }
        redirect($back);
    }

    public function remove(array $data): void
    {
        $id = $this->id($data, 'id');
        $back = erp_url(self::back($data['voltar'] ?? null, $id)) . '#anexos';
        $user = $this->guard('manage', $back);
        if ($user === null) {
            return;
        }
        if (!csrf_verify($data)) {
            (new Message())->error('Sua sessão expirou. Tente de novo.')->flash();
            redirect($back);
            return;
        }
        try {
            (new EntryAttachments())->remove($user, $id, $this->id($data, 'attachment'));
            (new Message())->success('Anexo removido do lançamento. O arquivo continua no Disco Virtual.')->flash();
        } catch (DomainException $error) {
            (new Message())->error($error->getMessage())->flash();
        } catch (RegistryNotFound) {
            (new Message())->error('Anexo não encontrado.')->flash();
        }
        redirect($back);
    }

    public function download(array $data): void
    {
        $id = $this->id($data, 'id');
        $user = $this->guard('view', erp_url(self::ROUTE));
        if ($user === null) {
            return;
        }
        try {
            $file = (new EntryAttachments())->file($user, $id, $this->id($data, 'attachment'));
        } catch (RegistryNotFound) {
            http_response_code(404);
            echo 'Anexo não encontrado.';
            return;
        } catch (DomainException $error) {   // ADR 0160: bloqueado pelo antivírus
            http_response_code(403);
            echo htmlspecialchars($error->getMessage(), ENT_QUOTES);
            return;
        }
        $inline = !empty($_GET['ver']) && in_array($file['mime'], self::INLINE, true);
        $ascii = (string)preg_replace('/[^A-Za-z0-9._ -]+/', '_', $file['name']);
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . $file['size']);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; object-src 'self'; plugin-types application/pdf; sandbox");
        readfile($file['path']);
    }

    private function guard(string $action, string $back): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            redirect(erp_url('/login'));
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user) || ($action === 'manage' && !Access::can('erp.entries.manage', $user))) {
            http_response_code(403);
            (new Message())->error($action === 'manage' ? 'Você pode consultar, mas não alterar os anexos.' : 'Você não tem permissão para ver os lançamentos.')->flash();
            redirect($back);
            return null;
        }
        return $user;
    }

    private function id(array $data, string $key): int
    {
        return (int)(filter_var($data[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }
}
