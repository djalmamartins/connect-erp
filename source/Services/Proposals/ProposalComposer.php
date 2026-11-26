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
use Source\Support\Proposal\ProposalPdf;

/**
 * Criar, gerar e enviar propostas no ERP (ADR 0650). Antes a geração e o envio do PDF ficavam no Studio
 * (ADR 0639); agora o Studio só consulta e tudo passa por aqui: tela CN-011 e API /api/v1/proposals.
 *
 * - create(): proposta cadastrada pela equipe (telefone, WhatsApp, indicação...), sem e-mail automático para a pessoa.
 * - respond(): grava uma versão (proposal_responses), gera o PDF e, se pedido, põe o e-mail com o PDF na fila.
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

    public const TEMPLATES = ['syndic' => 'Síndico e condomínio', 'administrator' => 'Administradora'];

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
     * Textos iniciais do formulário da resposta, pelo modelo escolhido.
     *
     * @param array<string,mixed> $proposal ProposalDesk::present()
     * @return array{template_type:string,subject:string,introduction:string,scope:string,commercial_terms:string,payment_terms:string,notes:string,valid_until:string}
     */
    public static function defaults(array $proposal, string $template = 'syndic'): array
    {
        $template = isset(self::TEMPLATES[$template]) ? $template : 'syndic';
        $condominium = (string)($proposal['condominium'] ?? '');
        // Textos do modelo de proposta da Connect (ADR 0650): a apresentação e as atividades vão para o "Mapa de serviços".
        if ($template === 'administrator') {
            $intro = "Apresentamos uma proposta de parceria para apoiar a operação da {$condominium}, combinando processos, tecnologia e atendimento especializado para ampliar a capacidade da administradora e padronizar a experiência dos condomínios atendidos.";
            $scope = "Plataforma centralizada para gestão da carteira\nAutomação de rotinas administrativas e financeiras\nIndicadores, relatórios e acompanhamento operacional\nApoio à implantação e treinamento das equipes\nComunicação integrada com síndicos e condomínios";
            $value = "Valor da parceria: R$ 0,00/mês (valor por extenso), reajustado anualmente.";
        } else {
            $intro = "Cuidamos de toda a gestão administrativa, financeira e operacional do condomínio, garantindo organização, controle e tranquilidade no dia a dia.";
            $scope = "Emissão de boletos e controle de inadimplência\nGestão de contas a pagar e receber\nPrestação de contas mensal e anual\nRelatórios financeiros\nGestão fiscal e tributária\nCadastro e controle de fornecedores\nOrganização de documentos\nEmissão de notificações\nApoio na elaboração do orçamento anual\nAtendimento aos condôminos";
            $value = "Valor da proposta para serviço de administradora: R$ 0,00/mês (valor por extenso), reajustado anualmente.";
        }
        return [
            'template_type' => $template,
            'subject' => mb_substr('Proposta de gestão para ' . $condominium, 0, 255),
            'introduction' => $intro,
            'scope' => $scope,
            'commercial_terms' => $value,
            'payment_terms' => 'Vencimento mensal conforme data definida em contrato.',
            'notes' => '',
            'valid_until' => date('Y-m-d', strtotime('+20 days')),
        ];
    }

    /**
     * Grava uma versão da resposta, gera o PDF e, com $send, põe o e-mail com o PDF anexado na fila.
     *
     * @param array<string,mixed> $input template_type, subject, introduction, scope, commercial_terms, payment_terms, notes, valid_until
     * @return array{id:int,status:string,pdf_path:string,queued:bool}
     * @throws ProposalDeskError
     */
    public function respond(int $proposalId, array $input, User $by, bool $send): array
    {
        $proposal = $proposalId > 0 ? (new Proposal())->findById($proposalId) : null;
        if (!$proposal) {
            throw new ProposalDeskError('not_found', 'Proposta não encontrada.', 404);
        }
        $text = static fn (string $key, int $max): string => mb_substr(trim(strip_tags((string)($input[$key] ?? ''))), 0, $max);
        $clean = [
            'template_type' => isset(self::TEMPLATES[(string)($input['template_type'] ?? '')]) ? (string)$input['template_type'] : 'syndic',
            'subject' => $text('subject', 255), 'introduction' => $text('introduction', 10000), 'scope' => $text('scope', 10000),
            'commercial_terms' => $text('commercial_terms', 10000), 'payment_terms' => $text('payment_terms', 500), 'notes' => $text('notes', 5000),
            'valid_until' => (string)($input['valid_until'] ?? ''),
        ];
        $fields = [];
        foreach (['subject' => 'o assunto', 'introduction' => 'a apresentação', 'scope' => 'o escopo', 'commercial_terms' => 'as condições comerciais'] as $key => $label) {
            if ($clean[$key] === '') $fields[$key] = 'Preencha ' . $label . '.';
        }
        $valid = \DateTimeImmutable::createFromFormat('!Y-m-d', $clean['valid_until']);
        if (!$valid || $valid->format('Y-m-d') !== $clean['valid_until']) {
            $fields['valid_until'] = 'Informe a validade da proposta.';
        } elseif ($clean['valid_until'] < date('Y-m-d')) {
            $fields['valid_until'] = 'A validade não pode ser antes de hoje.';
        }
        if ($send && !filter_var((string)$proposal->email, FILTER_VALIDATE_EMAIL)) {
            $fields['email'] = 'A proposta não tem um e-mail válido para envio.';
        }
        if ($fields !== []) {
            throw new ProposalDeskError('validation_failed', 'Confira os campos da proposta.', 422, $fields);
        }

        $response = new ProposalResponse();
        $response->proposal_id = $proposal->id;
        $response->created_by = $by->id;
        foreach ($clean as $key => $value) {
            $response->{$key} = $value;
        }
        $response->payment_terms = $clean['payment_terms'] !== '' ? $clean['payment_terms'] : null;
        $response->notes = $clean['notes'] !== '' ? $clean['notes'] : null;
        $response->status = 'draft';
        if (!$response->save()) {
            throw new ProposalDeskError('save_failed', 'Não foi possível gravar a proposta.', 500);
        }
        try {
            $response->pdf_path = (new ProposalPdf())->generate($proposal, $response);
        } catch (\Throwable $exception) {
            AppLogger::exception($exception, 'proposals', ['event_type' => 'proposal_pdf_failed', 'proposal_id' => $proposal->id]);
            $response->status = 'failed';
            $response->save();
            throw new ProposalDeskError('pdf_failed', 'Não foi possível gerar o PDF.', 500);
        }
        $response->status = 'generated';
        $response->save();
        $queued = false;
        if ($send) {
            $absolute = dirname(__DIR__, 3) . '/storage/' . $response->pdf_path;
            $queued = (bool)(new Email())->bootstrap($clean['subject'], ProposalMailer::response($proposal), (string)$proposal->email, (string)$proposal->name)
                ->attach($absolute, 'proposta-' . strtolower((string)$proposal->protocol) . '.pdf')
                ->queue(CONF_MAIL_SENDER['address'], CONF_MAIL_SENDER['name'], date('Y-m-d H:i:s'), null, null, (int)$response->id);
            if ($queued) {
                $response->status = 'queued';
                $response->queued_at = date('Y-m-d H:i:s');
                $response->save();
                $before = (string)$proposal->status;
                if (!in_array($before, ['won', 'lost', 'archived'], true)) {
                    $this->pdo()->prepare("UPDATE proposals SET status = 'proposal_sent' WHERE id = :id")->execute([':id' => (int)$proposal->id]);
                }
            } else {
                $response->status = 'failed';
                $response->save();
            }
        }
        Audit::record($send ? 'send' : 'generate', 'proposal_responses', (int)$response->id, null,
            ['proposal_id' => (int)$proposal->id, 'template_type' => $clean['template_type'], 'status' => (string)$response->status, 'to' => $send ? (string)$proposal->email : null]);
        return ['id' => (int)$response->id, 'status' => (string)$response->status, 'pdf_path' => (string)$response->pdf_path, 'queued' => $queued];
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
