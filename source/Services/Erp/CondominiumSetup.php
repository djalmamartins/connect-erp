<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;

/**
 * Roteiro de cadastro de um condomínio novo (ADR 0151).
 *
 * Ordem pedida pelo proprietário em 10/11/2026: dados do condomínio → unidades →
 * proprietários de cada unidade → síndico e conselho → áreas vinculadas → checklist
 * de chegada por último (onde ficam os prazos). Cada passo aponta para a tela que já
 * existe; este serviço só conta o que já foi cadastrado no condomínio para marcar o
 * passo como feito. Não grava nada: o guia em si fica na sessão (GUIDE_KEY).
 *
 * A autorização continua nas telas de cada passo; aqui um passo só aparece para quem
 * tem a permissão de ver aquela tela.
 */
final class CondominiumSetup
{
    /** Sessão: id do condomínio cujo guia de cadastro está aberto. */
    public const GUIDE_KEY = 'erpCondominiumSetup';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /**
     * Passos na ordem do cadastro. `paths` são os caminhos (sem o slug) em que o passo
     * é o atual; `route` é para onde o botão leva.
     *
     * @return list<array{key:string,label:string,hint:string,route:string,paths:list<string>,permission:string}>
     */
    public static function definitions(int $condominiumId): array
    {
        return [
            ['key' => 'condominium', 'label' => 'Condomínio', 'hint' => 'Dados, endereço e contrato', 'route' => "/condominios/{$condominiumId}/editar",
                'paths' => ["/condominios/{$condominiumId}/editar", '/condominios/novo'], 'permission' => 'erp.condominiums.view'],
            ['key' => 'units', 'label' => 'Unidades', 'hint' => 'Blocos, andares e unidades', 'route' => '/condominios/unidades/cadastro-rapido',
                'paths' => ['/condominios/unidades', '/configuracoes/fracao-ideal'], 'permission' => 'erp.units.view'],
            ['key' => 'owners', 'label' => 'Proprietários', 'hint' => 'O proprietário de cada unidade', 'route' => '/cadastros/proprietarios-e-inquilinos',
                'paths' => ['/cadastros/proprietarios-e-inquilinos'], 'permission' => 'erp.residents.view'],
            ['key' => 'board', 'label' => 'Síndico e conselho', 'hint' => 'Síndico, subsíndico e conselho', 'route' => '/cadastros/sindico-e-conselho',
                'paths' => ['/cadastros/sindico-e-conselho'], 'permission' => 'erp.board.view'],
            ['key' => 'areas', 'label' => 'Áreas vinculadas', 'hint' => 'Vagas, depósitos e boxes', 'route' => '/condominios/areas-vinculaveis',
                'paths' => ['/condominios/areas-vinculaveis'], 'permission' => 'erp.linkable_areas.view'],
            ['key' => 'checklist', 'label' => 'Checklist', 'hint' => 'O que falta e os prazos', 'route' => "/condominios/{$condominiumId}/checklist",
                'paths' => ["/condominios/{$condominiumId}/checklist"], 'permission' => 'erp.condominiums.view'],
        ];
    }

    /**
     * Passos visíveis para o usuário, com o que já foi cadastrado.
     *
     * @return list<array{key:string,label:string,hint:string,route:string,paths:list<string>,permission:string,count:int,total:?int,done:bool,meta:string}>
     */
    public function steps(User $user, int $condominiumId): array
    {
        $counts = $this->counts($condominiumId);
        $steps = [];
        foreach (self::definitions($condominiumId) as $step) {
            if (!$user->can($step['permission'])) {
                continue;
            }
            $count = $counts[$step['key']] ?? 0;
            $total = null;
            $meta = '';
            switch ($step['key']) {
                case 'condominium':
                    $done = true;
                    $meta = 'Cadastrado';
                    break;
                case 'units':
                    $done = $count > 0;
                    $meta = $count === 1 ? '1 unidade' : "{$count} unidades";
                    break;
                case 'owners':
                    $total = $counts['units'] ?? 0;
                    $done = $total > 0 && $count >= $total;
                    $meta = $total > 0 ? "{$count} de {$total} unidades" : 'Cadastre as unidades antes';
                    break;
                case 'board':
                    $done = $count > 0;
                    $meta = $count === 0 ? 'Ninguém cadastrado' : ($count === 1 ? '1 pessoa' : "{$count} pessoas");
                    break;
                case 'areas':
                    $done = $count > 0;
                    $meta = $count === 0 ? 'Opcional' : ($count === 1 ? '1 área' : "{$count} áreas");
                    break;
                default: // checklist
                    $total = $counts['checklist_total'] ?? 0;
                    $done = $total > 0 && $count >= $total;
                    $meta = $total > 0 ? "{$count} de {$total} itens resolvidos" : 'Sem itens';
            }
            $steps[] = $step + ['count' => $count, 'total' => $total, 'done' => $done, 'meta' => $meta];
        }
        return $steps;
    }

    /**
     * Índice do passo atual pelo caminho da tela (sem o slug), ou null fora do roteiro.
     *
     * @param list<array{paths:list<string>}> $steps
     */
    public static function currentIndex(array $steps, string $path): ?int
    {
        $path = '/' . trim($path, '/');
        foreach ($steps as $index => $step) {
            foreach ($step['paths'] as $prefix) {
                if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                    return $index;
                }
            }
        }
        return null;
    }

    /**
     * Primeiro passo ainda não feito depois do condomínio (ou o checklist, se tudo estiver feito).
     *
     * @param list<array{key:string,done:bool}> $steps
     */
    public static function nextPending(array $steps): ?array
    {
        foreach ($steps as $step) {
            if ($step['key'] !== 'condominium' && !$step['done']) {
                return $step;
            }
        }
        return $steps === [] ? null : $steps[count($steps) - 1];
    }

    /** @return array<string, int> */
    private function counts(int $condominiumId): array
    {
        $queries = [
            'units' => 'SELECT COUNT(*) FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL',
            // Unidades com pelo menos um proprietário ativo (o roteiro pede o proprietário de cada unidade).
            'owners' => "SELECT COUNT(DISTINCT r.unit_id) FROM erp_residents r JOIN erp_units u ON u.id = r.unit_id AND u.deleted_at IS NULL
                WHERE r.condominium_id = :c AND r.relationship = 'owner' AND r.deleted_at IS NULL",
            'board' => 'SELECT COUNT(*) FROM erp_board_members WHERE condominium_id = :c AND deleted_at IS NULL',
            'areas' => 'SELECT COUNT(*) FROM erp_linkable_areas WHERE condominium_id = :c AND deleted_at IS NULL',
            'checklist' => "SELECT COUNT(*) FROM condominium_onboarding_items WHERE condominium_id = :c AND status IN ('regular', 'not_applicable')",
            'checklist_total' => 'SELECT COUNT(*) FROM condominium_onboarding_items WHERE condominium_id = :c',
        ];
        $counts = [];
        foreach ($queries as $key => $sql) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['c' => $condominiumId]);
                $counts[$key] = (int)$stmt->fetchColumn();
            } catch (\PDOException) {
                // Tabela ainda não migrada neste ambiente: o passo aparece como não feito.
                $counts[$key] = 0;
            }
        }
        return $counts;
    }
}
