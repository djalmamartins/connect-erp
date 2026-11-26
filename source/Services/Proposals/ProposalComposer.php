<?php

declare(strict_types=1);

namespace Source\Services\Proposals;

use PDO;
use Source\Core\Connect;
use Source\Models\Proposal\Proposal;
use Source\Models\Proposal\ProposalResponse;
use Source\Models\User;
use Source\Support\AppLogger;
use Source\Support\Audit;
use Source\Support\Email;
use Source\Support\Proposal\ProposalMailer;

/**
 * Criar, anexar e enviar propostas no ERP (ADR 0650). Antes o PDF era gerado e enviado no Studio (ADR 0639);
 * agora o Studio só consulta e tudo passa por aqui: tela CN-011 e API /api/v1/proposals.
 *
 * - create(): proposta cadastrada pela equipe (telefone, WhatsApp, indicação...), sem e-mail automático para a pessoa.
 * - attach(): guarda os PDFs prontos (até dois: administradora e síndico profissional) e, se pedido, envia num e-mail.
 * - resend(): manda de novo PDFs já anexados.
 * Quem pode: proposals.manage (conferido no controlador e na API). Tudo entra na auditoria.
 */
final class ProposalComposer
{
    /** Como a proposta chegou quando foi cadastrada no ERP. "site" é só do formulário público. */
    public const ORIGINS = [
        'telefone' => 'Telefone',
        'whatsapp' => 'WhatsApp',
        'email' => 'E-mail',
        'indicacao' => 'Indicação',
        'presencial' => 'Visita ou reunião',
        'outro' => 'Outro',
    ];

    /**
     * Os dois PDFs que podem ir na proposta (ADR 0650): o da administradora e o do síndico profissional.
     * A chave é o valor de proposal_responses.template_type (o enum antigo, reaproveitado).
     */
    public const KINDS = ['administrator' => 'Administradora', 'syndic' => 'Síndico profissional'];

    /** Limite por arquivo: o PDF do Canva da Connect tem ~7 MB; 20 MB ainda cabe no e-mail (2 arquivos). */
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private ?PDO $pdo = null)
    {
    }

    public static function originLabel(string $origin): string
    {
        return $origin === 'site' ? 'Site (Solicite uma proposta)' : (self::ORIGINS[$origin] ?? ucfirst($origin));
    }

    /**
     * Cadastra uma proposta pela equipe. Base legal (LGPD art. 7º, V): a pessoa pediu a proposta, por isso
     * "requested" é obrigatório. Não marca aceite de novidades (só a própria pessoa pode, ADR 0604).
     *
     * @param array<string,mixed> $input name, email, whatsapp, condominium, units, profile, message, origin, requested
     * @return array<string,mixed> a proposta (ProposalDesk::present)
     * @throws ProposalDeskError
     */
    public function create(array $input, User $by): array
    {
        $clean = [
            'name' => mb_substr(trim(strip_tags((string)($input['name'] ?? ''))), 0, 180),
            'email' => mb_substr(strtolower(trim((string)($input['email'] ?? ''))), 0, 180),
            'whatsapp' => mb_substr(trim(strip_tags((string)($input['whatsapp'] ?? ''))), 0, 30),
            'condominium' => mb_substr(trim(strip_tags((string)($input['condominium'] ?? ''))), 0, 180),
            'units' => filter_var($input['units'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]),
            'profile' => (string)($input['profile'] ?? ''),
            'message' => mb_substr(trim(strip_tags((string)($input['message'] ?? ''))), 0, 5000),
            'origin' => (string)($input['origin'] ?? ''),
        ];
        $fields = [];
        if (mb_strlen($clean['name']) < 3) $fields['name'] = 'Informe o nome de quem pediu a proposta.';
        if (!filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) $fields['email'] = 'Informe um e-mail válido (a proposta vai por ele).';
        if (strlen((string)preg_replace('/\D+/', '', $clean['whatsapp'])) < 10) $fields['whatsapp'] = 'Informe o telefone com DDD.';
        if (mb_strlen($clean['condominium']) < 2) $fields['condominium'] = 'Informe o condomínio ou a administradora.';
        if ($clean['units'] === false) $fields['units'] = 'Informe o número de unidades.';
        if (!isset(ProposalDesk::PROFILES[$clean['profile']])) $fields['profile'] = 'Escolha o perfil de quem pediu.';
        if (!isset(self::ORIGINS[$clean['origin']])) $fields['origin'] = 'Escolha como o pedido chegou.';
        if (!in_array($input['requested'] ?? null, ['1', 1, true, 'on'], true)) $fields['requested'] = 'Confirme que a pessoa pediu a proposta e aceitou receber o contato.';
        if ($fields !== []) {
            throw new ProposalDeskError('validation_failed', 'Confira os campos da proposta.', 422, $fields);
        }
        $team = array_column((new ProposalDesk($this->pdo))->team(), 'id');
        $now = date('Y-m-d H:i:s');
        $protocol = 'PROP-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $this->pdo()->prepare('INSERT INTO proposals (protocol, name, email, whatsapp, condominium, units, profile, message, status, assigned_to, origin, created_by, contacted_at)
            VALUES (:protocol, :name, :email, :whatsapp, :condominium, :units, :profile, :message, :status, :assigned, :origin, :created_by, :contacted)');
        $stmt->execute([
            ':protocol' => $protocol, ':name' => $clean['name'], ':email' => $clean['email'], ':whatsapp' => $clean['whatsapp'],
            ':condominium' => $clean['condominium'], ':units' => $clean['units'], ':profile' => $clean['profile'],
            ':message' => $clean['message'] !== '' ? $clean['message'] : null,
            // Quem cadastra já falou com a pessoa: entra como Contatada e sem o alerta de proposta nova.
            ':status' => 'contacted', ':assigned' => in_array((int)$by->id, $team, true) ? (int)$by->id : null,
            ':origin' => $clean['origin'], ':created_by' => (int)$by->id ?: null, ':contacted' => $now,
        ]);
        $id = (int)$this->pdo()->lastInsertId();
        Audit::record('create', 'proposals', $id, null, ['protocol' => $protocol, 'origin' => $clean['origin'], 'condominium' => $clean['condominium']]);
        AppLogger::log('info', 'Proposta cadastrada no ERP', ['event_type' => 'proposal_created', 'proposal_id' => $id, 'protocol' => $protocol, 'status' => 'resolved'], 'proposals');
        return (new ProposalDesk($this->pdo))->find($id) ?? [];
    }

    /**
     * Valores iniciais do formulário de envio.
     *
     * @param array<string,mixed> $proposal ProposalDesk::present()
     * @return array{subject:string,introduction:string,valid_until:string}
     */
    public static function defaults(array $proposal): array
    {
        return [
            'subject' => mb_substr('Proposta comercial Connect Condomínios - ' . (string)($proposal['condominium'] ?? ''), 0, 255),
            'introduction' => '',
            'valid_until' => date('Y-m-d', strtotime('+20 days')),
        ];
    }

    /**
     * Anexa os PDFs prontos (feitos fora do sistema, por exemplo no Canva): até dois, um da administradora e um do
     * síndico profissional. Cada arquivo vira uma versão em proposal_responses. Com $send, um único e-mail leva os
     * dois anexos e a situação passa a Proposta enviada.
     *
     * @param array<string,mixed> $input subject, introduction (mensagem opcional no e-mail), valid_until
     * @param array<string,array{tmp_name?:string,name?:string,size?:int,error?:int}|null> $files por tipo (KINDS)
     * @return array{ids:list<int>,status:string,queued:bool}
     * @throws ProposalDeskError
     */
    public function attach(int $proposalId, array $input, array $files, User $by, bool $send, ?\Source\Services\Erp\Antivirus\UploadScanPolicy $antivirus = null): array
    {
        $proposal = $proposalId > 0 ? (new Proposal())->findById($proposalId) : null;
        if (!$proposal) {
            throw new ProposalDeskError('not_found', 'Proposta não encontrada.', 404);
        }
        $subject = mb_substr(trim(strip_tags((string)($input['subject'] ?? ''))), 0, 255);
        $message = mb_substr(trim(strip_tags((string)($input['introduction'] ?? ''))), 0, 5000);
        $validUntil = (string)($input['valid_until'] ?? '');
        $fields = [];
        if ($subject === '') $fields['subject'] = 'Preencha o assunto do e-mail.';
        $valid = \DateTimeImmutable::createFromFormat('!Y-m-d', $validUntil);
        if (!$valid || $valid->format('Y-m-d') !== $validUntil) {
            $fields['valid_until'] = 'Informe a validade da proposta.';
        } elseif ($validUntil < date('Y-m-d')) {
            $fields['valid_until'] = 'A validade não pode ser antes de hoje.';
        }
        if ($send && !filter_var((string)$proposal->email, FILTER_VALIDATE_EMAIL)) {
            $fields['email'] = 'A proposta não tem um e-mail válido para envio.';
        }
        $uploads = [];
        foreach (self::KINDS as $kind => $label) {
            $file = $files[$kind] ?? null;
            if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $problem = self::checkPdf($file);
            if ($problem !== null) {
                $fields['pdf_' . $kind] = $label . ': ' . $problem;
                continue;
            }
            $uploads[$kind] = $file;
        }
        if ($uploads === [] && !isset($fields['pdf_administrator']) && !isset($fields['pdf_syndic'])) {
            $fields['pdf'] = 'Anexe pelo menos um PDF: o da administradora ou o do síndico profissional.';
        }
        if ($fields !== []) {
            throw new ProposalDeskError('validation_failed', 'Confira os campos da proposta.', 422, $fields);
        }
        $antivirus ??= new \Source\Services\Erp\Antivirus\UploadScanPolicy();
        foreach ($uploads as $kind => $file) {
            try {
                $antivirus->check((string)$file['tmp_name'], ['event_source' => 'proposals', 'proposal_id' => (int)$proposal->id]);
            } catch (\InvalidArgumentException $error) {
                throw new ProposalDeskError('validation_failed', $error->getMessage(), 422, ['pdf_' . $kind => self::KINDS[$kind] . ': ' . $error->getMessage()]);
            } catch (\Source\Services\Erp\Antivirus\UploadScanUnavailable $error) {
                throw new ProposalDeskError('scan_unavailable', $error->getMessage(), 503);
            }
        }

        $saved = [];
        foreach ($uploads as $kind => $file) {
            $response = new ProposalResponse();
            $response->proposal_id = $proposal->id;
            $response->created_by = $by->id;
            $response->template_type = $kind;
            $response->subject = $subject;
            $response->introduction = $message;
            $response->scope = '';
            $response->commercial_terms = '';
            $response->valid_until = $validUntil;
            $response->status = 'draft';
            if (!$response->save()) {
                throw new ProposalDeskError('save_failed', 'Não foi possível gravar a proposta.', 500);
            }
            $relative = 'files/proposals/' . date('Y/m') . '/' . strtolower((string)$proposal->protocol) . '-' . ($kind === 'syndic' ? 'sindico' : 'administradora') . '-v' . (int)$response->id . '.pdf';
            $absolute = dirname(__DIR__, 3) . '/storage/' . $relative;
            if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0755, true) && !is_dir(dirname($absolute))) {
                throw new ProposalDeskError('save_failed', 'Não foi possível preparar a pasta de propostas.', 500);
            }
            $tmp = (string)$file['tmp_name'];
            $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $absolute) : rename($tmp, $absolute);
            if (!$moved) {
                $response->status = 'failed';
                $response->save();
                throw new ProposalDeskError('save_failed', 'Não foi possível guardar o PDF.', 500);
            }
            @chmod($absolute, 0640);
            $response->pdf_path = $relative;
            $response->status = 'generated';
            $response->save();
            $saved[$kind] = $response;
        }

        $status = 'generated';
        $queued = false;
        if ($send) {
            $email = (new Email())->bootstrap($subject, ProposalMailer::response($proposal, $message), (string)$proposal->email, (string)$proposal->name);
            foreach ($saved as $kind => $response) {
                $email->attach(dirname(__DIR__, 3) . '/storage/' . $response->pdf_path,
                    'proposta-' . ($kind === 'syndic' ? 'sindico-profissional' : 'administradora') . '-' . strtolower((string)$proposal->protocol) . '.pdf');
            }
            $first = reset($saved);
            $queued = (bool)$email->queue(CONF_MAIL_SENDER['address'], CONF_MAIL_SENDER['name'], date('Y-m-d H:i:s'), null, null, (int)$first->id);
            $status = $queued ? 'queued' : 'failed';
            foreach ($saved as $response) {
                $response->status = $status;
                $response->queued_at = $queued ? date('Y-m-d H:i:s') : null;
                $response->save();
            }
            if ($queued && !in_array((string)$proposal->status, ['won', 'lost', 'archived'], true)) {
                $this->pdo()->prepare("UPDATE proposals SET status = 'proposal_sent' WHERE id = :id")->execute([':id' => (int)$proposal->id]);
            }
        }
        $ids = array_values(array_map(static fn (ProposalResponse $r): int => (int)$r->id, $saved));
        Audit::record($send ? 'send' : 'attach', 'proposal_responses', $ids[0], null,
            ['proposal_id' => (int)$proposal->id, 'files' => array_keys($saved), 'status' => $status, 'to' => $send ? (string)$proposal->email : null]);
        return ['ids' => $ids, 'status' => $status, 'queued' => $queued];
    }

    /**
     * Envia de novo versões já anexadas (um e-mail com os PDFs escolhidos).
     *
     * @param list<int> $responseIds
     * @return array{ids:list<int>,status:string,queued:bool}
     * @throws ProposalDeskError
     */
    public function resend(int $proposalId, array $responseIds, User $by): array
    {
        $proposal = $proposalId > 0 ? (new Proposal())->findById($proposalId) : null;
        if (!$proposal) {
            throw new ProposalDeskError('not_found', 'Proposta não encontrada.', 404);
        }
        $responses = [];
        foreach (array_unique(array_map('intval', $responseIds)) as $id) {
            $response = (new ProposalResponse())->findById($id);
            if ($response && (int)$response->proposal_id === (int)$proposal->id && $this->pdfFile((int)$proposal->id, $id) !== null) {
                $responses[] = $response;
            }
        }
        if ($responses === []) {
            throw new ProposalDeskError('validation_failed', 'Escolha um PDF desta proposta.', 422, ['pdf' => 'Escolha um PDF desta proposta.']);
        }
        if (!filter_var((string)$proposal->email, FILTER_VALIDATE_EMAIL)) {
            throw new ProposalDeskError('validation_failed', 'A proposta não tem um e-mail válido para envio.', 422, ['email' => 'A proposta não tem um e-mail válido para envio.']);
        }
        $email = (new Email())->bootstrap((string)$responses[0]->subject, ProposalMailer::response($proposal, (string)$responses[0]->introduction), (string)$proposal->email, (string)$proposal->name);
        foreach ($responses as $response) {
            $kind = (string)$response->template_type === 'syndic' ? 'sindico-profissional' : 'administradora';
            $email->attach((string)$this->pdfFile((int)$proposal->id, (int)$response->id), 'proposta-' . $kind . '-' . strtolower((string)$proposal->protocol) . '.pdf');
        }
        $queued = (bool)$email->queue(CONF_MAIL_SENDER['address'], CONF_MAIL_SENDER['name'], date('Y-m-d H:i:s'), null, null, (int)$responses[0]->id);
        foreach ($responses as $response) {
            $response->status = $queued ? 'queued' : 'failed';
            $response->queued_at = $queued ? date('Y-m-d H:i:s') : $response->queued_at;
            $response->save();
        }
        if ($queued && !in_array((string)$proposal->status, ['won', 'lost', 'archived'], true)) {
            $this->pdo()->prepare("UPDATE proposals SET status = 'proposal_sent' WHERE id = :id")->execute([':id' => (int)$proposal->id]);
        }
        $ids = array_map(static fn (ProposalResponse $r): int => (int)$r->id, $responses);
        Audit::record('send', 'proposal_responses', $ids[0], null, ['proposal_id' => (int)$proposal->id, 'responses' => $ids, 'status' => $queued ? 'queued' : 'failed', 'to' => (string)$proposal->email]);
        return ['ids' => $ids, 'status' => $queued ? 'queued' : 'failed', 'queued' => $queued];
    }

    /**
     * Confere o arquivo enviado (regra pura sobre o arquivo temporário): sem erro de envio, até MAX_BYTES,
     * começa com %PDF- e o tipo detectado é PDF. Devolve o problema ou null.
     *
     * @param array{tmp_name?:string,size?:int,error?:int} $file
     */
    public static function checkPdf(array $file): ?string
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return 'o arquivo passa do tamanho máximo de 20 MB.';
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            return 'o arquivo não chegou. Tente anexar de novo.';
        }
        $size = (int)filesize($tmp);
        if ($size < 1 || $size > self::MAX_BYTES) {
            return $size < 1 ? 'o arquivo está vazio.' : 'o arquivo passa do tamanho máximo de 20 MB.';
        }
        $head = (string)file_get_contents($tmp, false, null, 0, 5);
        $mime = function_exists('finfo_open') ? (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmp) : 'application/pdf';
        if ($head !== '%PDF-' || $mime !== 'application/pdf') {
            return 'anexe um arquivo PDF.';
        }
        return null;
    }

    /**
     * Conta da proposta (regra pura): unidades × preço por unidade de cada serviço, total do mês e do ano e se passa
     * do mínimo mensal que compensa. Só para conferência da equipe.
     *
     * @return array{units:int,admin_unit:?int,syndic_unit:?int,min_monthly:?int,admin_monthly:?int,syndic_monthly:?int,monthly:?int,yearly:?int,worth:?bool}
     */
    public static function quote(int $units, ?int $adminUnit, ?int $syndicUnit, ?int $minMonthly): array
    {
        $units = max(0, $units);
        $admin = $adminUnit !== null ? $adminUnit * $units : null;
        $syndic = $syndicUnit !== null ? $syndicUnit * $units : null;
        $monthly = $admin === null && $syndic === null ? null : (int)$admin + (int)$syndic;
        return [
            'units' => $units, 'admin_unit' => $adminUnit, 'syndic_unit' => $syndicUnit, 'min_monthly' => $minMonthly,
            'admin_monthly' => $admin, 'syndic_monthly' => $syndic, 'monthly' => $monthly, 'yearly' => $monthly !== null ? $monthly * 12 : null,
            'worth' => $monthly !== null && $minMonthly !== null ? $monthly >= $minMonthly : null,
        ];
    }

    /**
     * Grava os preços da calculadora (texto em reais, "12,50"; vazio = sem valor). Entra na auditoria.
     *
     * @param array<string,mixed> $input price_admin_unit, price_syndic_unit, min_monthly
     * @return array<string,mixed> a proposta atualizada
     * @throws ProposalDeskError
     */
    public function saveQuote(int $proposalId, array $input): array
    {
        $desk = new ProposalDesk($this->pdo);
        $before = $desk->find($proposalId);
        if ($before === null) {
            throw new ProposalDeskError('not_found', 'Proposta não encontrada.', 404);
        }
        $values = [];
        $fields = [];
        foreach (['price_admin_unit' => 'o preço da administradora', 'price_syndic_unit' => 'o preço do síndico profissional', 'min_monthly' => 'o mínimo mensal'] as $key => $label) {
            $raw = trim((string)($input[$key] ?? ''));
            if ($raw === '') {
                $values[$key] = null;
                continue;
            }
            $cents = \Source\Services\Erp\Invoices::cents($raw);
            if ($cents === null || $cents > 4_294_967_295) {
                $fields[$key] = 'Confira ' . $label . ' (ex.: 12,50).';
                continue;
            }
            $values[$key] = $cents;
        }
        if ($fields !== []) {
            throw new ProposalDeskError('validation_failed', 'Confira os valores da conta.', 422, $fields);
        }
        $this->pdo()->prepare('UPDATE proposals SET price_admin_unit_cents = :a, price_syndic_unit_cents = :s, min_monthly_cents = :m WHERE id = :id')
            ->execute([':a' => $values['price_admin_unit'], ':s' => $values['price_syndic_unit'], ':m' => $values['min_monthly'], ':id' => $proposalId]);
        $q = $before['quote'];
        Audit::record('quote', 'proposals', $proposalId, ['admin_unit' => $q['admin_unit'], 'syndic_unit' => $q['syndic_unit'], 'min_monthly' => $q['min_monthly']],
            ['admin_unit' => $values['price_admin_unit'], 'syndic_unit' => $values['price_syndic_unit'], 'min_monthly' => $values['min_monthly']]);
        return $desk->find($proposalId) ?? $before;
    }

    /** Caminho absoluto do PDF de uma versão desta proposta, só dentro de storage/files/proposals. */
    public function pdfFile(int $proposalId, int $responseId): ?string
    {
        $stmt = $this->pdo()->prepare('SELECT pdf_path FROM proposal_responses WHERE id = :id AND proposal_id = :proposal');
        $stmt->execute([':id' => $responseId, ':proposal' => $proposalId]);
        $path = (string)($stmt->fetchColumn() ?: '');
        if ($path === '') {
            return null;
        }
        $root = realpath(dirname(__DIR__, 3) . '/storage/files/proposals');
        $file = realpath(dirname(__DIR__, 3) . '/storage/' . $path);
        return $root && $file && is_file($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR) ? $file : null;
    }

    private function pdo(): PDO
    {
        $this->pdo ??= Connect::getInstance();
        if ($this->pdo === null) {
            throw new \RuntimeException('Sem conexão com o banco de dados.');
        }
        return $this->pdo;
    }
}
