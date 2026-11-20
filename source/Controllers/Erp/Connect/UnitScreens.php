<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\MeterReadings;
use Source\Services\Erp\RecurringEntries;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\UnitDocuments;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas da unidade com dados reais que eram montadas dentro do Panel: documentos da unidade e recibo
 * (AP-123/124/125/130 e AP-040), leituras do mês (AP-042), fração ideal e área (AP-167) e painel da
 * unidade (AP-003), mais as regras puras de lista de unidades usadas por outras telas.
 *
 * O Panel::pageView() chama data() quando a página está em PAGES (a mesma regra das outras *Screens).
 */
final class UnitScreens
{
    /** Página => carregador dos dados reais. */
    public const PAGES = [
        'AP-003' => 'unitPanelData',
        'AP-042' => 'meterReadingData',
        'AP-167' => 'unitMeasuresData',
        'AP-123' => 'unitDocumentData',
        'AP-124' => 'unitDocumentData',
        'AP-125' => 'unitDocumentData',
        'AP-130' => 'unitDocumentData',
        'AP-040' => 'unitDocumentData',
    ];

    /** @var \Closure(): list<array{id:int,name:string}> condomínios do escopo, do cache do Panel (o smoke injeta uma lista fixa) */
    private \Closure $condominiumOptions;

    /** @return array<string, mixed> */
    public function data(User $user, string $id, ?\Closure $condominiumOptions = null): array
    {
        $this->condominiumOptions = $condominiumOptions ?? static fn (): array => CondominiumContext::scopeOptions($user);
        return match (self::PAGES[$id] ?? null) {
            'unitPanelData' => $this->unitPanelData($user),
            'meterReadingData' => $this->meterReadingData($user),
            'unitMeasuresData' => $this->unitMeasuresData($user),
            'unitDocumentData' => $this->unitDocumentData($user, $id),
            default => [],
        };
    }

    /** Documentos da unidade com tela própria (ADR 0044 e 0046): página => tipo do documento. */
    public const UNIT_DOCUMENT_PAGES = ['AP-123' => 'debt_certificate', 'AP-124' => 'delinquency_declaration', 'AP-125' => 'quittance', 'AP-130' => 'consent_letter'];

    /** Recibo (AP-040, ADR 0057 e 0073): a mesma tela dos documentos, com as contas pagas da unidade para escolher. */
    public const RECEIPT_PAGE = 'AP-040';

    /** Tipos de relógio das leituras (ADR 0045). */
    public const METER_KINDS = ['agua' => 'Água', 'gas' => 'Gás', 'energia' => 'Energia'];

    /**
     * Documentos da unidade (AP-123/124/125/130, ADR 0044) no contexto da barra superior: os emitidos
     * do tipo da página ($unitDocuments, ?pagina=), as unidades para emitir ($documentUnits) e, com
     * ?documento=ID, o documento com o conteúdo para imprimir ($unitDocument). null = sem permissão
     * ou sem banco (a tela segue com a demonstração). Emitir e cancelar usam /api/v1/unit-documents.
     *
     * @return array<string, mixed>
     */
    private function unitDocumentData(User $user, string $id): array
    {
        $kind = self::UNIT_DOCUMENT_PAGES[$id] ?? 'receipt';
        $data = ['documentKind' => $kind, 'unitDocuments' => null, 'unitDocument' => null, 'documentUnits' => [], 'documentError' => null, 'canManageDocuments' => false,
            'receiptUnit' => null, 'receiptEntries' => []];
        if (Connect::databaseDisabled() || !Access::can('erp.unit_documents.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        try {
            $service = new UnitDocuments();
            $data['unitDocuments'] = $service->list($user, $context, ['kind' => $kind, 'page' => $_GET['pagina'] ?? 1]);
            $names = array_column($this->condominiumOptions($user), 'name', 'id');
            $data['documentUnits'] = $context === null ? [] : self::documentUnits((new RegistryService())->units($user), $names, $context);
            $receiptUnit = filter_var($_GET['unidade'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($kind === 'receipt' && $receiptUnit !== false && $receiptUnit !== null && in_array((int)$receiptUnit, array_column($data['documentUnits'], 'id'), true)) {
                // Contas pagas sem recibo válido da unidade escolhida (?unidade=); a emissão confere de novo.
                $data['receiptUnit'] = (int)$receiptUnit;
                $data['receiptEntries'] = (new \Source\Services\Erp\ReceiptCandidates())->forUnit($user, (int)$receiptUnit);
            }
            $documentId = filter_var($_GET['documento'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($documentId !== false && $documentId !== null) {
                try {
                    $data['unitDocument'] = $service->find($user, (int)$documentId);
                } catch (RegistryNotFound) {
                    $data['documentError'] = 'Documento não encontrado no seu escopo.';
                }
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'unit_documents_screen_failed', 'page' => $id]);
            return ['unitDocuments' => null, 'unitDocument' => null, 'canManageDocuments' => false] + $data;
        }
        $data['canManageDocuments'] = Access::can('erp.unit_documents.manage', $user);
        return $data;
    }

    /**
     * Unidades para o seletor dos documentos (regra pura): só as do condomínio em contexto, com o nome
     * do condomínio (condomínio sem nome no escopo fica de fora), ordenadas por condomínio e unidade.
     *
     * @param list<array{id:int, condominium_id:int, label:string}> $units
     * @param array<int, string> $names condomínio => nome, do escopo do usuário
     * @return list<array{id:int, condominium_id:int, label:string, condominium_name:string}>
     */
    /**
     * Unidades do condomínio da barra superior (revisão de 15/11): sem condomínio escolhido, nenhuma,
     * para que nenhum filtro de unidade misture condomínios.
     * @param list<array{condominium_id:int}> $units
     */
    public static function contextUnits(array $units, ?int $context): array
    {
        if ($context === null) {
            return [];
        }
        return array_values(array_filter($units, static fn (array $u): bool => (int)$u['condominium_id'] === $context));
    }

    public static function documentUnits(array $units, array $names, ?int $context): array
    {
        $rows = [];
        foreach ($units as $unit) {
            $condo = (int)$unit['condominium_id'];
            if (!isset($names[$condo]) || ($context !== null && $condo !== $context)) {
                continue;
            }
            $rows[] = $unit + ['condominium_name' => (string)$names[$condo]];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['condominium_name'], $a['label']] <=> [$b['condominium_name'], $b['label']]);
        return $rows;
    }

    /**
     * Leituras do mês (AP-042, ADR 0045) no contexto da barra superior: ?competencia=AAAA-MM (padrão:
     * mês atual) e ?tipo=agua|gas|energia. $meterSheet tem uma linha por relógio ativo do tipo, com a
     * leitura anterior e a da competência (consumo, valor e se já foi cobrada). null = sem permissão ou
     * sem banco (a tela segue com a demonstração). Lançar e faturar usam /api/v1/meter-readings.
     *
     * @return array<string, mixed>
     */
    private function meterReadingData(User $user): array
    {
        $competence = is_string($_GET['competencia'] ?? null) && RecurringEntries::isCompetence($_GET['competencia']) ? $_GET['competencia'] : date('Y-m');
        $kind = is_string($_GET['tipo'] ?? null) && isset(self::METER_KINDS[$_GET['tipo']]) ? $_GET['tipo'] : 'agua';
        $data = ['meterSheet' => null, 'meterCompetence' => $competence, 'meterKind' => $kind, 'canManageReadings' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.meter_readings.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        try {
            $registry = new RegistryService();
            $meters = [];
            for ($page = 1; $page <= 40; $page++) {
                $chunk = $registry->list($user, 'meters', ['status' => 'active', 'filter' => $kind, 'condominium_id' => $context, 'page' => $page]);
                $meters = array_merge($meters, $chunk['data']);
                if ($page >= $chunk['pages']) {
                    break;
                }
            }
            $labels = array_column($registry->units($user), 'label', 'id');
            $readings = (new MeterReadings())->list($user, $context, ['kind' => $kind])['data'];
            $data['meterSheet'] = self::readingSheet($meters, $labels, $readings, $competence);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'meter_readings_screen_failed']);
            return ['meterSheet' => null] + $data;
        }
        $data['canManageReadings'] = Access::can('erp.meter_readings.manage', $user);
        return $data;
    }

    /**
     * Folha de leituras da competência (regra pura): uma linha por relógio, com a leitura anterior (a
     * mais recente de competência anterior, senão a inicial do relógio) e a leitura da competência, se
     * houver. Ordenada por condomínio e unidade.
     *
     * @param list<array> $meters relógios (RegistryService 'meters')
     * @param array<int, string> $unitLabels unidade => rótulo
     * @param list<array> $readings leituras do tipo (MeterReadings::list), mais novas primeiro
     * @return list<array{meter_id:int, condominium_id:int, condominium_name:?string, unit_label:string, number:string, previous:float, reading:?array}>
     */
    public static function readingSheet(array $meters, array $unitLabels, array $readings, string $competence): array
    {
        $current = [];
        $previous = [];
        foreach ($readings as $r) {
            $meter = (int)$r['meter_id'];
            if ($r['competence'] === $competence) {
                $current[$meter] = $r;
            } elseif ($r['competence'] < $competence && (!isset($previous[$meter]) || $r['competence'] > $previous[$meter]['competence'])) {
                $previous[$meter] = $r;
            }
        }
        $rows = [];
        foreach ($meters as $m) {
            $id = (int)$m['id'];
            $rows[] = [
                'meter_id' => $id, 'condominium_id' => (int)$m['condominium_id'], 'condominium_name' => $m['condominium_name'] ?? null,
                'unit_label' => (string)($unitLabels[(int)$m['unit_id']] ?? '—'), 'number' => (string)$m['number'],
                'previous' => isset($current[$id]) ? (float)$current[$id]['previous_reading'] : (isset($previous[$id]) ? (float)$previous[$id]['reading'] : (float)($m['initial_reading'] ?? 0)),
                'reading' => $current[$id] ?? null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [(string)$a['condominium_name'], $a['unit_label'], $a['number']] <=> [(string)$b['condominium_name'], $b['unit_label'], $b['number']]);
        return $rows;
    }

    /**
     * Fração ideal e área em grade (AP-167, ADR 0140): unidades ativas do condomínio da barra superior,
     * pelo mesmo serviço de GET /api/v1/unit-measures. Gravar e dividir usam a API (erp.js).
     */
    private function unitMeasuresData(User $user): array
    {
        $data = ['measuresReady' => false, 'measures' => null, 'measuresError' => null, 'canManageMeasures' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.units.view', $user)) {
            return $data;
        }
        $data['measuresReady'] = true;
        $data['canManageMeasures'] = Access::can('erp.units.manage', $user);
        $context = CondominiumContext::id();
        if ($context === null) {
            return $data;
        }
        try {
            $data['measures'] = (new \Source\Services\Erp\UnitBatch())->measures($user, $context);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'unit_measures_screen_failed']);
            $data['measuresError'] = 'Não foi possível carregar as unidades agora. Tente de novo em instantes.';
        }
        return $data;
    }

    /**
     * Painel da unidade (AP-003, ADR 0054 e 0071): escolhe-se a unidade (?unidade=) entre as do
     * condomínio da barra superior; os dados vêm do PanelSummaries (GET /api/v1/panels/unit/{id}).
     */
    private function unitPanelData(User $user): array
    {
        $data = ['unitPanelReady' => false, 'unitPanel' => null, 'unitPanelUnits' => [], 'unitPanelSelected' => null, 'unitPanelError' => null];
        if (Connect::databaseDisabled() || !Access::can('erp.units.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        try {
            $units = (new RegistryService())->units($user);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'unit_panel_failed']);
            return $data;
        }
        $data['unitPanelReady'] = true;
        // Revisão 15/11: sem condomínio na barra superior não há filtro de unidade; com ele, só as unidades dele.
        $data['unitPanelUnits'] = self::contextUnits($units, $context);
        $selected = filter_var($_GET['unidade'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($selected === false || $selected === null) {
            return $data;
        }
        $data['unitPanelSelected'] = (int)$selected;
        try {
            $data['unitPanel'] = (new \Source\Services\Erp\PanelSummaries())->unit($user, (int)$selected);
        } catch (\Source\Services\Erp\Registry\RegistryForbidden) {
            $data['unitPanelError'] = 'Seu perfil não tem permissão para todos os dados do painel da unidade (pessoas, financeiro e documentos).';
        } catch (RegistryNotFound) {
            $data['unitPanelError'] = 'Unidade não encontrada no seu escopo de condomínios.';
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'unit_panel_failed']);
            $data['unitPanelError'] = 'Não foi possível montar o painel agora. Tente de novo em instantes.';
        }
        return $data;
    }

    /** @return list<array{id:int,name:string}> */
    private function condominiumOptions(User $user): array
    {
        return ($this->condominiumOptions)();
    }
}
