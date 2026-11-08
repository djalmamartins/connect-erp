<?php

declare(strict_types=1);

namespace Source\Services\Inspections;

use Dompdf\Dompdf;
use Dompdf\Options;
use Source\Core\View;
use Source\Models\User;
use Source\Support\AppLogger;
use Source\Support\Email;
use Source\Support\MailTemplate;

/**
 * Relatório da vistoria: o mesmo documento (pages/inspections/report-document no tema do ERP)
 * serve para a visualização/impressão no navegador e para o PDF anexado ao e-mail.
 * O PDF é gerado sem acesso remoto (dompdf isRemoteEnabled=false); as fotos entram como
 * data URI lidas da pasta privada.
 */
final class InspectionReport
{
    private InspectionService $service;
    private string $theme;

    public function __construct(InspectionService $service, ?string $theme = null)
    {
        $this->service = $service;
        $this->theme = rtrim($theme ?? moves_container_path('erp', CONF_VIEW_ERP), '/') . '/';
    }

    /**
     * @param array<string, mixed> $inspection resultado de InspectionService::get()
     * @param callable(int $photoId): string $photoSrc endereço de cada foto
     */
    public function html(array $inspection, callable $photoSrc, bool $forPdf = false): string
    {
        return (new View($this->theme))->render('pages/inspections/report-document', [
            'inspection' => $inspection,
            'photoSrc' => $photoSrc,
            'forPdf' => $forPdf,
            'siteName' => defined('CONF_SITE_NAME') ? CONF_SITE_NAME : 'Connect',
            'generatedAt' => date('d/m/Y H:i'),
        ]);
    }

    /** @param array<string, mixed> $inspection */
    public function pdf(User $user, array $inspection): string
    {
        $html = $this->html($inspection, function (int $photoId) use ($user, $inspection): string {
            try {
                $file = $this->service->photoFile($user, (int)$inspection['id'], $photoId);
                return 'data:' . $file['mime'] . ';base64,' . base64_encode((string)file_get_contents($file['path']));
            } catch (InspectionError) {
                return '';
            }
        }, true);
        $options = new Options();
        $options->set('tempDir', sys_get_temp_dir());
        $options->set('chroot', $this->theme);
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return (string)$dompdf->output();
    }

    public static function filename(array $inspection): string
    {
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$inspection['condominium'])), '-'));
        return sprintf('vistoria-%d-%s-%s.pdf', (int)$inspection['id'], $slug ?: 'condominio', substr((string)$inspection['checkin']['at'], 0, 10));
    }

    /**
     * Coloca o relatório na fila de e-mail (mail_queue) com o PDF anexado e registra cada envio.
     * Só para vistorias concluídas.
     *
     * @param list<string> $recipients e-mails já validados por InspectionService::recipients()
     * @return int quantidade de e-mails na fila
     */
    public function queue(User $user, array $inspection, array $recipients, string $message = ''): int
    {
        if ($inspection['status'] !== 'completed') {
            throw InspectionError::conflict('Conclua a vistoria antes de enviar o relatório.');
        }
        if (!$this->service->can(InspectionService::PERFORM, $user)) {
            throw InspectionError::forbidden();
        }
        $message = trim($message);
        if (mb_strlen($message) > 2000) {
            throw InspectionError::invalid(['message' => 'Use no máximo 2000 caracteres na mensagem.']);
        }
        $directory = $this->service->photos()->root() . '/reports/' . (int)$inspection['condominium_id'];
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new InspectionError('storage_failed', 'Não foi possível preparar a pasta dos relatórios.', 500);
        }
        $pdfPath = $directory . '/' . (int)$inspection['id'] . '-' . bin2hex(random_bytes(8)) . '.pdf';
        if (file_put_contents($pdfPath, $this->pdf($user, $inspection)) === false) {
            throw new InspectionError('storage_failed', 'Não foi possível gerar o PDF do relatório.', 500);
        }
        $subject = sprintf('Relatório de vistoria — %s — %s', $inspection['condominium'], date('d/m/Y', strtotime((string)$inspection['checkin']['at'])));
        $body = (new MailTemplate())->render('inspection-report', [
            'condominium' => $inspection['condominium'],
            'template' => $inspection['template'],
            'date' => date('d/m/Y H:i', strtotime((string)$inspection['checkin']['at'])),
            'inspector' => $inspection['inspector'],
            'counts' => $inspection['counts'],
            'issues' => array_values(array_filter($inspection['items'], static fn(array $item): bool => $item['result'] === 'issue')),
            'message' => $message,
            'sender' => trim($user->first_name . ' ' . $user->last_name),
        ]);
        $queued = 0;
        foreach ($recipients as $recipient) {
            $email = (new Email())->bootstrap($subject, $body, $recipient, $recipient)->attach($pdfPath, self::filename($inspection));
            if ($email->queue(userId: (int)$user->id)) {
                $this->service->recordDelivery($user, (int)$inspection['id'], $recipient);
                $queued++;
            }
        }
        AppLogger::log('info', 'Relatório de vistoria na fila de e-mail', [
            'event_type' => 'inspection_report_queued', 'inspection_id' => $inspection['id'], 'recipients' => $queued, 'user_id' => $user->id,
        ], 'erp');
        return $queued;
    }
}
