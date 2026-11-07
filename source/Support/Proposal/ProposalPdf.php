<?php

namespace Source\Support\Proposal;

use Dompdf\Dompdf;
use Dompdf\Options;
use Source\Models\Proposal\Proposal;
use Source\Models\Proposal\ProposalResponse;

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
        $options = new Options();
        $options->set('tempDir', is_dir('/tmp') && is_writable('/tmp') ? '/tmp' : sys_get_temp_dir());
        $options->set('chroot', dirname(__DIR__, 3));
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($proposal, $response), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        if (file_put_contents($absolute, $dompdf->output()) === false) {
            throw new \RuntimeException('Não foi possível salvar o PDF da proposta.');
        }
        return 'files/proposals/' . date('Y/m') . '/' . $filename;
    }

    private function html(Proposal $proposal, ProposalResponse $response): string
    {
        $template = $response->template_type === 'administrator' ? 'Proposta para Administradora' : 'Proposta para Síndico e Condomínio';
        $accent = $response->template_type === 'administrator' ? '#6E00B3' : '#c99a22';
        $address = trim(CONF_SITE_ADDR_STREET . ', ' . CONF_SITE_ADDR_NUMBER . (CONF_SITE_ADDR_COMPLEMENT ? ' - ' . CONF_SITE_ADDR_COMPLEMENT : '') . ' - ' . CONF_SITE_ADDR_DISTRICT . ', ' . CONF_SITE_ADDR_CITY . ' - ' . CONF_SITE_ADDR_STATE . ', CEP ' . CONF_SITE_ADDR_ZIPCODE, ' ,-');
        $paragraphs = static fn(string $text): string => implode('', array_map(static fn($line) => '<p>' . nl2br(htmlspecialchars(trim($line), ENT_QUOTES, 'UTF-8')) . '</p>', preg_split('/\n{2,}/', trim($text)) ?: []));
        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>@page{margin:17mm 18mm 18mm}*{box-sizing:border-box}body{margin:0;font-family:"DejaVu Sans",sans-serif;color:#24322e;font-size:9.2pt;line-height:1.42}.top{padding:14px 18px;background:#063d32;border-bottom:4px solid '.$accent.';color:#fff}.brand{font-size:18pt;font-weight:700;color:#fff}.brand span{color:'.$accent.'}.top small{display:block;margin-top:2px;color:#dbe7e3}.meta{width:100%;margin:13px 0;border-collapse:collapse}.meta td{width:50%;padding:7px 9px;border:1px solid #e4e7e5}.meta small{display:block;color:#75807c;font-size:7pt;text-transform:uppercase}.meta strong{display:block;margin-top:2px;color:#163d32}.label{margin:13px 0 5px;color:'.$accent.';font-size:7.6pt;font-weight:700;letter-spacing:.08em;text-transform:uppercase}h1{margin:13px 0 3px;color:#063d32;font-size:18pt;line-height:1.15}h2{margin:4px 0 8px;color:#52615c;font-size:9.5pt;font-weight:400}p{margin:0 0 6px}.terms{padding:10px 12px;background:#f5f7f6;border-left:4px solid '.$accent.'}.notes{padding:8px 10px;border:1px solid #e3e6e4}.signature{margin-top:16px;padding-top:9px;border-top:1px solid #b9c1be}.footer{position:fixed;left:0;right:0;bottom:-11mm;text-align:center;color:#7a837f;font-size:6.4pt}.page-number:after{content:counter(page)}</style></head><body><div class="footer">'.htmlspecialchars(CONF_SITE_NAME.' | '.CONF_SITE_PHONE.' | '.CONF_MAIL_SUPPORT.' | '.$address, ENT_QUOTES, 'UTF-8').' | Página <span class="page-number"></span></div><div class="top"><div class="brand">CONNECT <span>CONDOMÍNIOS</span></div><small>Gestão inteligente para condomínios de excelência</small></div><h1>'.htmlspecialchars($template, ENT_QUOTES, 'UTF-8').'</h1><h2>'.htmlspecialchars($response->subject, ENT_QUOTES, 'UTF-8').'</h2><table class="meta"><tr><td><small>Cliente</small><strong>'.htmlspecialchars($proposal->name, ENT_QUOTES, 'UTF-8').'</strong></td><td><small>Condomínio/Administradora</small><strong>'.htmlspecialchars($proposal->condominium, ENT_QUOTES, 'UTF-8').'</strong></td></tr><tr><td><small>Unidades</small><strong>'.(int)$proposal->units.'</strong></td><td><small>Protocolo</small><strong>'.htmlspecialchars($proposal->protocol, ENT_QUOTES, 'UTF-8').'</strong></td></tr></table><div class="label">Apresentação</div>'.$paragraphs($response->introduction).'<div class="label">Escopo proposto</div>'.$paragraphs($response->scope).'<div class="label">Condições comerciais</div><div class="terms">'.$paragraphs($response->commercial_terms).($response->payment_terms ? '<p><strong>Pagamento:</strong> '.htmlspecialchars($response->payment_terms, ENT_QUOTES, 'UTF-8').'</p>' : '').'<p><strong>Validade:</strong> '.date('d/m/Y', strtotime($response->valid_until)).'</p></div>'.($response->notes ? '<div class="label">Observações</div><div class="notes">'.$paragraphs($response->notes).'</div>' : '').'<div class="signature"><strong>'.htmlspecialchars(CONF_SITE_NAME, ENT_QUOTES, 'UTF-8').'</strong><br>'.htmlspecialchars(CONF_MAIL_SUPPORT.' | '.CONF_SITE_PHONE, ENT_QUOTES, 'UTF-8').'</div></body></html>';
    }
}
