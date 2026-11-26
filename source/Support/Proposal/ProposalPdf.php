<?php

namespace Source\Support\Proposal;

use Dompdf\Dompdf;
use Dompdf\Options;
use Source\Models\Proposal\Proposal;
use Source\Models\Proposal\ProposalResponse;

/**
 * PDF da proposta comercial no modelo da Connect (ADR 0650): capa verde-escura com o logo dourado, conteúdo, sobre,
 * diferenciais, cronograma, mapa de serviços (com os textos e o valor da proposta), formalização com assinatura e
 * contato. Textos fixos da Connect em templates/connect.php; o que muda por proposta vem do ProposalResponse.
 * Fonte Montserrat (SIL OFL, fonts/OFL.txt). Sem fotos de banco de imagens (licença), só formas e o logo da Connect.
 */
final class ProposalPdf
{
    public function generate(Proposal $proposal, ProposalResponse $response): string
    {
        $directory = dirname(__DIR__, 3) . '/storage/files/proposals/' . date('Y/m');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível preparar a pasta de propostas.');
        }
        $filename = strtolower($proposal->protocol) . '-v' . $response->id . '.pdf';
        $absolute = $directory . '/' . $filename;
        if (file_put_contents($absolute, $this->render($proposal, $response)) === false) {
            throw new \RuntimeException('Não foi possível salvar o PDF da proposta.');
        }
        return 'files/proposals/' . date('Y/m') . '/' . $filename;
    }

    /** Bytes do PDF (também usado pela prévia de teste em tools/). */
    public function render(Proposal $proposal, ProposalResponse $response): string
    {
        $temp = is_dir('/tmp') && is_writable('/tmp') ? '/tmp' : sys_get_temp_dir();
        $fontCache = $temp . '/connect-proposal-fonts';
        if (!is_dir($fontCache)) {
            @mkdir($fontCache, 0775, true);
        }
        $options = new Options();
        $options->set('tempDir', $temp);
        $options->set('fontDir', $fontCache);
        $options->set('fontCache', $fontCache);
        $options->set('chroot', dirname(__DIR__, 3));
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', 'Montserrat');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($proposal, $response), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return (string)$dompdf->output();
    }

    public function html(Proposal $proposal, ProposalResponse $response): string
    {
        $constant = static fn (string $name, string $fallback): string => defined($name) && trim((string)constant($name)) !== '' ? trim((string)constant($name)) : $fallback;
        $data = [
            'proposal' => $proposal,
            'response' => $response,
            'fonts' => __DIR__ . '/fonts',
            'logo' => self::logo(),
            'phone' => $constant('CONF_SITE_PHONE', '31 97147-2258'),
            'email' => $constant('CONF_MAIL_SUPPORT', 'sac@connectcondominios.com.br'),
        ];
        return (static function (array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            require __DIR__ . '/templates/connect.php';
            return (string)ob_get_clean();
        })($data);
    }

    /** Logo empilhado (pino, CONNECT e CONDOMÍNIOS) em dourado, o mesmo do rodapé do site. */
    private static function logo(): string
    {
        $path = dirname(__DIR__, 3) . '/container/web/default/v1/assets/svg/logo-connect-footer.svg';
        return is_file($path) ? 'data:image/svg+xml;base64,' . base64_encode((string)file_get_contents($path)) : '';
    }
}
