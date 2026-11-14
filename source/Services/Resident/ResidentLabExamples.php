<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use PDO;
use Source\Models\User;
use Source\Services\Erp\AccountabilityLinks;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\VirtualDisk;

/**
 * Exemplos para ver o aplicativo do morador funcionando no lab (tools/lab-morador-teste.php, só com a trava do
 * ADR 0162). Tudo fictício e marcado com "(exemplo do app)": comunicados, encomendas, um pet, um documento visível
 * aos moradores e os links da prestação de contas dos meses fechados. Rodar de novo não duplica: cada item é
 * procurado pela marca antes de ser criado. Nada é enviado por e-mail.
 */
final class ResidentLabExamples
{
    public const MARK = '(exemplo do app)';
    public const TRACKING = ['APP000000001BR', 'APP000000002BR'];

    public function __construct(private PDO $pdo, private User $admin, private ?string $documentsRoot = null)
    {
    }

    /**
     * @param array{condominium_id:int, unit_id:int, name:string} $resident
     * @return array<string, string> o que foi feito, por item (para a tela)
     */
    public function create(array $resident): array
    {
        $condo = (int)$resident['condominium_id'];
        $unit = (int)$resident['unit_id'];
        $done = [];
        $done['Comunicados'] = $this->announcements($condo);
        $done['Encomendas'] = $this->deliveries($condo, $unit, (string)$resident['name']);
        $done['Pet'] = $this->pet($condo, $unit);
        $done['Documento'] = $this->document($condo);
        $done['Prestação de contas'] = $this->accountability($condo);
        return $done;
    }

    private function announcements(int $condo): string
    {
        $exists = $this->pdo->prepare("SELECT COUNT(*) FROM erp_announcements WHERE condominium_id = ? AND subject LIKE ?");
        $exists->execute([$condo, '%' . self::MARK]);
        if ((int)$exists->fetchColumn() > 0) {
            return 'já existiam';
        }
        $insert = $this->pdo->prepare("INSERT INTO erp_announcements (condominium_id, kind, subject, body, audience, send_at, status, created_by)
            VALUES (?, 'general', ?, ?, 'all', ?, 'active', ?)");
        $insert->execute([$condo, 'Limpeza da caixa d’água ' . self::MARK,
            "Na próxima terça-feira, das 8h às 12h, a água será interrompida para a limpeza semestral da caixa d’água.\n\nDeixe água separada para o período. Obrigado pela compreensão.",
            date('Y-m-d H:i:s', strtotime('-2 days')), (int)$this->admin->id]);
        $insert->execute([$condo, 'Novo horário da academia ' . self::MARK,
            "A partir deste mês a academia funciona todos os dias, das 6h às 22h.\n\nLembre-se de levar toalha e de limpar os aparelhos depois do uso.",
            date('Y-m-d H:i:s', strtotime('-6 days')), (int)$this->admin->id]);
        return '2 criados (sem envio de e-mail)';
    }

    private function deliveries(int $condo, int $unit, string $name): string
    {
        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM erp_deliveries WHERE unit_id = ? AND tracking_code IN (?, ?) AND deleted_at IS NULL');
        $exists->execute([$unit, ...self::TRACKING]);
        if ((int)$exists->fetchColumn() > 0) {
            return 'já existiam';
        }
        $registry = new RegistryService($this->pdo);
        $registry->save($this->admin, 'deliveries', ['condominium_id' => $condo, 'unit_id' => $unit, 'recipient' => $name, 'carrier' => 'correios',
            'tracking_code' => self::TRACKING[0], 'received_on' => date('Y-m-d')]);
        $picked = (int)$registry->save($this->admin, 'deliveries', ['condominium_id' => $condo, 'unit_id' => $unit, 'recipient' => $name, 'carrier' => 'mercado_livre',
            'tracking_code' => self::TRACKING[1], 'received_on' => date('Y-m-d', strtotime('-5 days'))])['id'];
        $this->pdo->prepare("UPDATE erp_deliveries SET status = 'picked_up', picked_up_on = ?, picked_up_by = ? WHERE id = ?")
            ->execute([date('Y-m-d', strtotime('-4 days')), $name, $picked]);
        return '2 criadas (1 esperando retirada)';
    }

    private function pet(int $condo, int $unit): string
    {
        $exists = $this->pdo->prepare("SELECT COUNT(*) FROM erp_pets WHERE unit_id = ? AND deleted_at IS NULL");
        $exists->execute([$unit]);
        if ((int)$exists->fetchColumn() > 0) {
            return 'a unidade já tem';
        }
        (new RegistryService($this->pdo))->save($this->admin, 'pets', ['condominium_id' => $condo, 'unit_id' => $unit, 'name' => 'Thor', 'species' => 'cao',
            'size' => 'medio', 'breed' => 'SRD', 'vaccinated' => '1']);
        return '1 criado';
    }

    private function document(int $condo): string
    {
        $title = 'Regulamento interno ' . self::MARK;
        $exists = $this->pdo->prepare('SELECT id FROM erp_documents WHERE condominium_id = ? AND title = ? AND deleted_at IS NULL LIMIT 1');
        $exists->execute([$condo, $title]);
        $id = (int)$exists->fetchColumn();
        $disk = new VirtualDisk($this->pdo, null, $this->documentsRoot, true);
        if ($id === 0) {
            $file = tempnam(sys_get_temp_dir(), 'regulamento');
            file_put_contents($file, self::pdf(['Regulamento interno (exemplo do aplicativo do morador)', '',
                '1. Silêncio das 22h às 7h.', '2. Áreas comuns com reserva pelo aplicativo.', '3. Encomendas ficam na portaria por até 15 dias.',
                '4. Animais circulam no colo ou na guia nas áreas comuns.', '', 'Documento fictício, gerado só para teste.']));
            try {
                $id = (int)$disk->upload($this->admin, ['name' => 'regulamento-interno-exemplo.pdf', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK],
                    ['condominium_id' => $condo, 'folder' => 'outros', 'title' => $title])['id'];
            } catch (\Throwable $error) {
                return 'não foi possível criar: ' . $error->getMessage();
            } finally {
                @unlink($file);
            }
        }
        $disk->share($this->admin, $id, true);
        return 'visível aos moradores (Disco Virtual › Outros)';
    }

    private function accountability(int $condo): string
    {
        $stmt = $this->pdo->prepare("SELECT competence FROM erp_month_closings WHERE condominium_id = ? AND status = 'closed' ORDER BY competence DESC LIMIT 3");
        $stmt->execute([$condo]);
        $months = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($months === []) {
            return 'nenhum mês fechado no condomínio';
        }
        $links = new AccountabilityLinks($this->pdo);
        $made = 0;
        foreach ($months as $competence) {
            try {
                $links->generate($this->admin, $condo, 'monthly', substr((string)$competence, 0, 7));
                $made++;
            } catch (\Throwable) {
                // mês sem dados suficientes: segue para o próximo
            }
        }
        return $made . ' mês(es) publicados (sem envio de e-mail)';
    }

    /**
     * PDF de uma página só com texto (regra pura), para o documento de exemplo.
     *
     * @param list<string> $lines
     */
    public static function pdf(array $lines): string
    {
        $text = "BT /F1 12 Tf 56 780 Td 16 TL\n";
        foreach ($lines as $line) {
            $latin = (string)@iconv('UTF-8', 'Windows-1252//TRANSLIT', $line);
            $text .= '(' . strtr($latin, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ") Tj T*\n";
        }
        $text .= 'ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length ' . strlen($text) . " >>\nstream\n" . $text . "\nendstream"];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
