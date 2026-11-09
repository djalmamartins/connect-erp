<?php

declare(strict_types=1);

namespace Source\Services\Erp\Registry;

/**
 * Definições dos cadastros do ERP (grupo Cadastros e, desde o ADR 0015, as áreas e o
 * patrimônio do grupo Condomínios).
 *
 * Um único motor (RegistryService + RegistryValidator + controllers Registry) atende
 * todos os cadastros: cada entrada abaixo descreve tabela, rota, recurso da API,
 * permissões, escopo por condomínio e campos. Campos e regras são PROPOSTOS
 * (docs/specs/pages/ap-0xx-*.md seguem PENDENTES de confirmação) e estão registrados
 * em docs/Organic Code/decisions/0014-registry-engine.md.
 *
 * Escopo (ADR-002, unidade de segregação = condominium_id):
 *  - required: todo registro pertence a um condomínio; só aparece para quem tem acesso a ele.
 *  - optional: condomínio opcional; sem condomínio vale para todos (ex.: contatos úteis).
 *  - none: cadastro da administradora Connect, usado por todos os condomínios
 *    (fornecedores, advogados); controlado apenas pela permissão.
 *
 * Tipos de campo: condominium, unit (FK erp_units do mesmo condomínio), text, longtext, cpf,
 * cnpj, document (CPF ou CNPJ), id_document (CPF ou RG/outro), email, phone, date,
 * money (centavos), percent, decimal (scale/min/max_value), bool, select, plate, oab,
 * ref (registro de outro cadastro: 'ref' => slug, 'ref_where' => filtros, 'ref_label' => colunas;
 * ADR 0020 — quem é apontado não pode ser excluído enquanto houver registro ativo apontando).
 *
 * 'group' (padrão cadastros) é o grupo do catálogo; Unidades fica em condominios (ADR 0017).
 * 'base_where' + 'fixed' (ADR 0024): visão filtrada de outra tabela, que grava sempre os valores fixos.
 * 'closing_lock' => campo da competência (ADR 0031): competência fechada trava gravação e exclusão.
 * 'origin_lock' => ['field', 'columns', 'message'] (ADR 0033): registro gerado por outro módulo
 *   (field preenchido) não troca as colunas listadas nem é excluído; muda-se pela origem.
 * 'unique' aceita 'nullable' => [campos]: vazio conta como valor na comparação.
 */
final class Definitions
{
    private const STATUS = ['active' => 'Ativo', 'inactive' => 'Inativo'];

    /** Lançamento gerado por fatura (ADR 0033): só a baixa (pago em, conta bancária) e as notas mudam aqui. */
    public const INVOICE_LOCK = [
        'field' => 'invoice_item_id',
        'columns' => ['condominium_id', 'nature', 'chart_account_id', 'amount_cents', 'competence', 'due_on', 'unit_id', 'status', 'description'],
        'message' => 'Lançamento gerado por fatura: altere ou cancele pela fatura.',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::$cache ??= self::build();
    }

    /** @return array<string, mixed>|null */
    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** @return array<string, mixed>|null */
    public static function byApi(string $resource): ?array
    {
        foreach (self::all() as $definition) {
            if ($definition['api'] === $resource) {
                return $definition;
            }
        }
        return null;
    }

    /** @return array<string, string> página do catálogo => permissão de leitura */
    public static function pagePermissions(): array
    {
        $map = [];
        foreach (self::all() as $definition) {
            $map[$definition['page']] = $definition['permission'] . '.view';
        }
        return $map;
    }

    /** @return array<string, array<string, mixed>> */
    private static function build(): array
    {
        // Unidade escolhida (unit_id, ADR 0017). unit_label continua gravado (texto da unidade)
        // até a troca ser validada; sem unit_id, o texto é obrigatório onde já era.
        $unit = static fn (int $span = 3): array => ['label' => 'Unidade', 'type' => 'unit', 'span' => $span, 'hint' => 'Unidade cadastrada em Condomínios › Unidades'];
        $unitText = static fn (string $label, bool $list, int $span = 3, ?string $hint = null): array => [
            'label' => $label, 'type' => 'text', 'max' => 40, 'span' => $span, 'list' => $list, 'search' => $list,
            'hint' => $hint ?? 'Preenchido pela unidade escolhida; digite só se ela ainda não estiver cadastrada',
        ];
        $unitRequired = static fn (string $message = 'Escolha a unidade ou digite o texto da unidade.'): array => ['field' => 'unit_label', 'unless' => 'unit_id', 'message' => $message];

        $definitions = [
            'units' => [
                'page' => 'AP-006', 'table' => 'erp_units', 'route' => '/condominios/unidades',
                'api' => 'units', 'permission' => 'erp.units', 'label' => 'Unidades',
                'entity' => 'unidade', 'gender' => 'a', 'scope' => 'required', 'group' => 'condominios',
                'lead' => 'Unidades de cada condomínio. Proprietários, inquilinos, conselho, veículos, visitantes e animais se vinculam a elas.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'block' => ['label' => 'Bloco ou torre', 'type' => 'text', 'max' => 20, 'span' => 3, 'list' => true, 'search' => true, 'hint' => 'Vazio se o condomínio não tem blocos'],
                    'number' => ['label' => 'Unidade', 'type' => 'text', 'required' => true, 'max' => 16, 'span' => 3, 'list' => true, 'search' => true, 'hint' => 'Ex.: 101, Casa 3, Loja 2'],
                    'type' => ['label' => 'Tipo', 'type' => 'select', 'required' => true, 'span' => 4, 'list' => true, 'filter' => true, 'options' => [
                        'apartamento' => 'Apartamento', 'casa' => 'Casa', 'sala' => 'Sala comercial', 'loja' => 'Loja', 'garagem' => 'Garagem', 'outro' => 'Outro',
                    ]],
                    'floor' => ['label' => 'Andar', 'type' => 'text', 'max' => 10, 'span' => 2],
                    'ideal_fraction' => ['label' => 'Fração ideal', 'type' => 'decimal', 'scale' => 6, 'min' => 0, 'max_value' => 1, 'span' => 3, 'hint' => 'Entre 0 e 1, ex.: 0,012500'],
                    'private_area' => ['label' => 'Área privativa (m²)', 'type' => 'decimal', 'scale' => 2, 'min' => 0, 'max_value' => 99999999.99, 'span' => 3],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                // Bloco vazio conta como valor: "101" sem bloco não pode repetir no mesmo condomínio.
                'unique' => [['fields' => ['condominium_id', 'block', 'number'], 'nullable' => ['block'], 'message' => 'Já existe esta unidade neste condomínio.']],
                'computed' => ['label'],
                // Não exclui unidade com cadastros ativos vinculados (RegistryInUse → 409).
                'referenced_by' => ['erp_residents', 'erp_board_members', 'erp_vehicles', 'erp_visitors', 'erp_pets', 'erp_linkable_areas', 'erp_entries'],
                'title' => 'number',
                'order' => 'label',
            ],
            'residents' => [
                'page' => 'AP-005', 'table' => 'erp_residents', 'route' => '/cadastros/proprietarios-e-inquilinos',
                'api' => 'residents', 'permission' => 'erp.residents', 'label' => 'Proprietários e inquilinos',
                'entity' => 'pessoa', 'gender' => 'a', 'scope' => 'required',
                'lead' => 'Proprietários, inquilinos, moradores e responsáveis financeiros de cada unidade.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'unit_id' => $unit(),
                    'unit_label' => $unitText('Unidade (texto)', true),
                    'relationship' => ['label' => 'Vínculo', 'type' => 'select', 'required' => true, 'span' => 3, 'list' => true, 'filter' => true, 'options' => [
                        'owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro',
                    ]],
                    'name' => ['label' => 'Nome completo ou razão social', 'type' => 'text', 'required' => true, 'max' => 160, 'span' => 8, 'list' => true, 'search' => true],
                    'document' => ['label' => 'CPF/CNPJ', 'type' => 'document', 'span' => 4, 'list' => true, 'search' => true],
                    'email' => ['label' => 'E-mail', 'type' => 'email', 'span' => 6],
                    'phone' => ['label' => 'Telefone', 'type' => 'phone', 'span' => 6, 'list' => true],
                    'starts_on' => ['label' => 'Início do vínculo', 'type' => 'date', 'span' => 4],
                    'ends_on' => ['label' => 'Fim do vínculo', 'type' => 'date', 'span' => 4, 'after' => 'starts_on'],
                    'billing_by_email' => ['label' => 'Recebe boleto por e-mail', 'type' => 'bool', 'span' => 4],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['condominium_id', 'unit_label', 'relationship', 'document'], 'message' => 'Esta pessoa já tem este vínculo com a unidade.']],
                'requires' => [['field' => 'email', 'when' => 'billing_by_email', 'message' => 'Informe o e-mail para enviar o boleto.'], $unitRequired()],
                'order' => 'name',
            ],
            'board' => [
                'page' => 'AP-014', 'table' => 'erp_board_members', 'route' => '/cadastros/sindico-e-conselho',
                'api' => 'board-members', 'permission' => 'erp.board', 'label' => 'Síndico e conselho',
                'entity' => 'membro', 'gender' => 'o', 'scope' => 'required',
                'lead' => 'Síndico, subsíndico e conselho fiscal e consultivo de cada condomínio, com o mandato.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'role' => ['label' => 'Cargo', 'type' => 'select', 'required' => true, 'span' => 6, 'list' => true, 'filter' => true, 'options' => [
                        'sindico' => 'Síndico', 'subsindico' => 'Subsíndico', 'fiscal' => 'Conselheiro fiscal',
                        'consultivo' => 'Conselheiro consultivo', 'suplente' => 'Suplente',
                    ]],
                    'name' => ['label' => 'Nome ou razão social', 'type' => 'text', 'required' => true, 'max' => 160, 'span' => 8, 'list' => true, 'search' => true],
                    'document' => ['label' => 'CPF/CNPJ', 'type' => 'document', 'span' => 4, 'search' => true, 'hint' => 'CNPJ para síndico profissional'],
                    'unit_id' => $unit(4),
                    'unit_label' => $unitText('Unidade (texto)', false, 4, 'Vazio para síndico profissional'),
                    'term_starts_on' => ['label' => 'Início do mandato', 'type' => 'date', 'required' => true, 'span' => 4],
                    'term_ends_on' => ['label' => 'Fim do mandato', 'type' => 'date', 'required' => true, 'span' => 4, 'list' => true, 'after' => 'term_starts_on'],
                    'email' => ['label' => 'E-mail', 'type' => 'email', 'span' => 6],
                    'phone' => ['label' => 'Telefone', 'type' => 'phone', 'span' => 6, 'list' => true],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12, 'hint' => 'Ex.: ata da assembleia que elegeu'],
                ],
                'single_active' => [['field' => 'role', 'value' => 'sindico', 'per' => 'condominium_id', 'message' => 'Este condomínio já tem um síndico ativo. Inative o atual antes de cadastrar outro.']],
                'order' => 'role',
                'title' => 'name',
            ],
            'employees' => [
                'page' => 'AP-009', 'table' => 'erp_employees', 'route' => '/cadastros/funcionarios',
                'api' => 'employees', 'permission' => 'erp.employees', 'label' => 'Funcionários',
                'entity' => 'funcionário', 'gender' => 'o', 'scope' => 'required',
                'lead' => 'Funcionários próprios de cada condomínio.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'job' => ['label' => 'Função', 'type' => 'select', 'required' => true, 'span' => 6, 'list' => true, 'filter' => true, 'options' => [
                        'zelador' => 'Zelador', 'porteiro' => 'Porteiro', 'faxineiro' => 'Auxiliar de limpeza', 'jardineiro' => 'Jardineiro',
                        'manutencao' => 'Manutenção', 'vigia' => 'Vigia', 'administrativo' => 'Administrativo', 'outro' => 'Outra',
                    ]],
                    'name' => ['label' => 'Nome completo', 'type' => 'text', 'required' => true, 'max' => 160, 'span' => 8, 'list' => true, 'search' => true],
                    'document' => ['label' => 'CPF', 'type' => 'cpf', 'required' => true, 'span' => 4, 'search' => true],
                    'hired_on' => ['label' => 'Admissão', 'type' => 'date', 'required' => true, 'span' => 4, 'list' => true],
                    'terminated_on' => ['label' => 'Desligamento', 'type' => 'date', 'span' => 4, 'after' => 'hired_on'],
                    'shift' => ['label' => 'Turno', 'type' => 'select', 'span' => 4, 'options' => [
                        'manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite', '12x36' => '12x36', 'comercial' => 'Comercial',
                    ]],
                    'salary_cents' => ['label' => 'Salário', 'type' => 'money', 'span' => 4],
                    'phone' => ['label' => 'Telefone', 'type' => 'phone', 'span' => 4, 'list' => true],
                    'email' => ['label' => 'E-mail', 'type' => 'email', 'span' => 4],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['condominium_id', 'document'], 'message' => 'Já existe um funcionário com este CPF neste condomínio.']],
                'order' => 'name',
            ],
            'suppliers' => [
                'page' => 'AP-010', 'table' => 'erp_suppliers', 'route' => '/cadastros/fornecedores',
                'api' => 'suppliers', 'permission' => 'erp.suppliers', 'label' => 'Fornecedores',
                'entity' => 'fornecedor', 'gender' => 'o', 'scope' => 'none',
                'lead' => 'Fornecedores e prestadores de serviço da Connect, usados por todos os condomínios.',
                'fields' => [
                    'legal_name' => ['label' => 'Razão social ou nome', 'type' => 'text', 'required' => true, 'max' => 160, 'span' => 8, 'list' => true, 'search' => true],
                    'document' => ['label' => 'CNPJ/CPF', 'type' => 'document', 'required' => true, 'span' => 4, 'list' => true, 'search' => true],
                    'trade_name' => ['label' => 'Nome fantasia', 'type' => 'text', 'max' => 160, 'span' => 6, 'search' => true],
                    'category' => ['label' => 'Categoria', 'type' => 'select', 'required' => true, 'span' => 6, 'list' => true, 'filter' => true, 'options' => [
                        'manutencao' => 'Manutenção', 'limpeza' => 'Limpeza', 'seguranca' => 'Segurança', 'elevadores' => 'Elevadores',
                        'jardinagem' => 'Jardinagem', 'portaria' => 'Portaria', 'concessionaria' => 'Concessionária (água, luz, gás)', 'outros' => 'Outros',
                    ]],
                    'email' => ['label' => 'E-mail', 'type' => 'email', 'span' => 6],
                    'phone' => ['label' => 'Telefone', 'type' => 'phone', 'span' => 6, 'list' => true],
                    'bank_code' => ['label' => 'Banco (código)', 'type' => 'text', 'max' => 3, 'pattern' => '/^\d{3}$/', 'pattern_message' => 'Use o código de 3 dígitos do banco.', 'span' => 4],
                    'bank_agency' => ['label' => 'Agência', 'type' => 'text', 'max' => 10, 'pattern' => '/^[0-9-]{1,10}$/', 'pattern_message' => 'Use apenas números e hífen.', 'span' => 4],
                    'bank_account' => ['label' => 'Conta', 'type' => 'text', 'max' => 20, 'pattern' => '/^[0-9Xx-]{1,20}$/', 'pattern_message' => 'Use apenas números, X e hífen.', 'span' => 4],
                    'pix_key' => ['label' => 'Chave Pix', 'type' => 'text', 'max' => 140, 'span' => 6],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['document'], 'message' => 'Já existe um fornecedor com este documento.']],
                'order' => 'legal_name',
            ],
            'lawyers' => [
                'page' => 'AP-016', 'table' => 'erp_lawyers', 'route' => '/cadastros/advogados',
                'api' => 'lawyers', 'permission' => 'erp.lawyers', 'label' => 'Advogados',
                'entity' => 'advogado', 'gender' => 'o', 'scope' => 'none',
                'lead' => 'Advogados e escritórios parceiros para cobrança judicial e assessoria.',
                'fields' => [
                    'name' => ['label' => 'Nome', 'type' => 'text', 'required' => true, 'max' => 160, 'span' => 8, 'list' => true, 'search' => true],
                    'oab' => ['label' => 'OAB', 'type' => 'oab', 'required' => true, 'span' => 4, 'list' => true, 'search' => true, 'hint' => 'UF e número, ex.: SP 123456'],
                    'document' => ['label' => 'CPF', 'type' => 'cpf', 'span' => 4],
                    'firm' => ['label' => 'Escritório', 'type' => 'text', 'max' => 160, 'span' => 8, 'list' => true, 'search' => true],
                    'email' => ['label' => 'E-mail', 'type' => 'email', 'span' => 6],
                    'phone' => ['label' => 'Telefone', 'type' => 'phone', 'span' => 6, 'list' => true],
                    'fee_percent' => ['label' => 'Honorários (%)', 'type' => 'percent', 'span' => 4],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['oab'], 'message' => 'Já existe um advogado com esta OAB.']],
                'order' => 'name',
            ],
            'vehicles' => [
                'page' => 'AP-011', 'table' => 'erp_vehicles', 'route' => '/cadastros/veiculos',
                'api' => 'vehicles', 'permission' => 'erp.vehicles', 'label' => 'Veículos',
                'entity' => 'veículo', 'gender' => 'o', 'scope' => 'required',
                'lead' => 'Veículos autorizados por unidade, para a portaria e o controle de vagas.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'unit_id' => $unit(),
                    'unit_label' => $unitText('Unidade (texto)', true),
                    'plate' => ['label' => 'Placa', 'type' => 'plate', 'required' => true, 'span' => 4, 'list' => true, 'search' => true],
                    'kind' => ['label' => 'Tipo', 'type' => 'select', 'required' => true, 'span' => 4, 'options' => ['carro' => 'Carro', 'moto' => 'Moto', 'utilitario' => 'Utilitário', 'outro' => 'Outro']],
                    'model' => ['label' => 'Marca e modelo', 'type' => 'text', 'max' => 80, 'span' => 4, 'list' => true, 'search' => true],
                    'color' => ['label' => 'Cor', 'type' => 'text', 'max' => 30, 'span' => 4, 'list' => true],
                    'parking_spot' => ['label' => 'Vaga', 'type' => 'text', 'max' => 20, 'span' => 4],
                    'owner_name' => ['label' => 'Responsável', 'type' => 'text', 'max' => 160, 'span' => 4, 'search' => true],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['condominium_id', 'plate'], 'message' => 'Esta placa já está cadastrada neste condomínio.']],
                'requires' => [$unitRequired()],
                'order' => 'plate',
            ],
            'visitors' => [
                'page' => 'AP-012', 'table' => 'erp_visitors', 'route' => '/cadastros/visitantes',
                'api' => 'visitors', 'permission' => 'erp.visitors', 'label' => 'Visitantes',
                'entity' => 'visitante', 'gender' => 'o', 'scope' => 'required',
                'lead' => 'Visitantes frequentes e prestadores autorizados por unidade.',
                'status' => ['active' => 'Liberado', 'inactive' => 'Bloqueado'],
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'unit_id' => ['label' => 'Unidade visitada'] + $unit(),
                    'unit_label' => $unitText('Unidade visitada (texto)', true),
                    'name' => ['label' => 'Nome', 'type' => 'text', 'required' => true, 'max' => 160, 'span' => 8, 'list' => true, 'search' => true],
                    'document' => ['label' => 'Documento (CPF ou RG)', 'type' => 'id_document', 'span' => 4, 'list' => true, 'search' => true],
                    'phone' => ['label' => 'Telefone', 'type' => 'phone', 'span' => 4],
                    'permanent_access' => ['label' => 'Acesso permanente', 'type' => 'bool', 'span' => 4],
                    'valid_until' => ['label' => 'Autorizado até', 'type' => 'date', 'span' => 4, 'list' => true, 'hint' => 'Vazio quando o acesso é permanente'],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'requires' => [['field' => 'valid_until', 'unless' => 'permanent_access', 'message' => 'Informe até quando o acesso vale ou marque acesso permanente.'], $unitRequired('Escolha a unidade visitada ou digite o texto da unidade.')],
                'order' => 'name',
            ],
            'pets' => [
                'page' => 'AP-013', 'table' => 'erp_pets', 'route' => '/cadastros/animais-de-estimacao',
                'api' => 'pets', 'permission' => 'erp.pets', 'label' => 'Animais de estimação',
                'entity' => 'animal', 'gender' => 'o', 'scope' => 'required',
                'lead' => 'Animais de estimação por unidade.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'unit_id' => $unit(),
                    'unit_label' => $unitText('Unidade (texto)', true),
                    'name' => ['label' => 'Nome', 'type' => 'text', 'required' => true, 'max' => 80, 'span' => 6, 'list' => true, 'search' => true],
                    'species' => ['label' => 'Espécie', 'type' => 'select', 'required' => true, 'span' => 3, 'list' => true, 'filter' => true, 'options' => ['cao' => 'Cão', 'gato' => 'Gato', 'ave' => 'Ave', 'outro' => 'Outro']],
                    'size' => ['label' => 'Porte', 'type' => 'select', 'span' => 3, 'options' => ['pequeno' => 'Pequeno', 'medio' => 'Médio', 'grande' => 'Grande']],
                    'breed' => ['label' => 'Raça', 'type' => 'text', 'max' => 80, 'span' => 6, 'list' => true],
                    'tutor_name' => ['label' => 'Tutor', 'type' => 'text', 'max' => 160, 'span' => 6, 'search' => true],
                    'vaccinated' => ['label' => 'Vacinação em dia', 'type' => 'bool', 'span' => 6],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'requires' => [$unitRequired()],
                'order' => 'name',
            ],
            'contacts' => [
                'page' => 'AP-015', 'table' => 'erp_useful_contacts', 'route' => '/cadastros/contatos-uteis',
                'api' => 'useful-contacts', 'permission' => 'erp.contacts', 'label' => 'Contatos úteis',
                'entity' => 'contato', 'gender' => 'o', 'scope' => 'optional',
                'lead' => 'Telefones úteis para moradores e portaria. Sem condomínio, o contato vale para todos.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'span' => 6, 'list' => true, 'hint' => 'Vazio: vale para todos os condomínios'],
                    'service' => ['label' => 'Serviço', 'type' => 'select', 'required' => true, 'span' => 6, 'list' => true, 'filter' => true, 'options' => [
                        'emergencia' => 'Emergência', 'encanador' => 'Encanador', 'eletricista' => 'Eletricista', 'chaveiro' => 'Chaveiro',
                        'dedetizacao' => 'Dedetização', 'gas' => 'Gás', 'concessionaria' => 'Concessionária', 'outro' => 'Outro',
                    ]],
                    'name' => ['label' => 'Nome', 'type' => 'text', 'required' => true, 'max' => 160, 'span' => 6, 'list' => true, 'search' => true],
                    'phone' => ['label' => 'Telefone', 'type' => 'phone', 'required' => true, 'span' => 6, 'list' => true, 'search' => true],
                    'email' => ['label' => 'E-mail', 'type' => 'email', 'span' => 6],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'order' => 'name',
            ],
            // Módulo Condomínios (ADR 0015): áreas e patrimônio de cada condomínio.
            'common_areas' => [
                'page' => 'AP-008', 'table' => 'erp_common_areas', 'route' => '/condominios/areas-comuns',
                'api' => 'common-areas', 'group' => 'condominios', 'permission' => 'erp.common_areas', 'label' => 'Áreas comuns',
                'entity' => 'área comum', 'gender' => 'a', 'scope' => 'required',
                'lead' => 'Salão de festas, churrasqueira, academia e demais áreas que os moradores podem reservar.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'name' => ['label' => 'Nome da área', 'type' => 'text', 'required' => true, 'max' => 120, 'span' => 6, 'list' => true, 'search' => true],
                    'booking_mode' => ['label' => 'Reserva', 'type' => 'select', 'required' => true, 'span' => 4, 'list' => true, 'filter' => true, 'options' => [
                        'approval' => 'Exige aprovação', 'free' => 'Livre', 'blocked' => 'Bloqueada', 'none' => 'Não reservável',
                    ]],
                    'capacity' => ['label' => 'Capacidade (pessoas)', 'type' => 'int', 'min' => 1, 'max_value' => 10000, 'span' => 4, 'list' => true],
                    'booking_fee_cents' => ['label' => 'Taxa de reserva', 'type' => 'money', 'span' => 4, 'list' => true],
                    'min_advance_days' => ['label' => 'Antecedência mínima (dias)', 'type' => 'int', 'min' => 0, 'max_value' => 365, 'span' => 4],
                    'max_advance_days' => ['label' => 'Antecedência máxima (dias)', 'type' => 'int', 'min' => 0, 'max_value' => 730, 'span' => 4, 'not_below' => 'min_advance_days'],
                    'opening_time' => ['label' => 'Abre às', 'type' => 'time', 'span' => 2],
                    'closing_time' => ['label' => 'Fecha às', 'type' => 'time', 'span' => 2],
                    'rules' => ['label' => 'Regras de uso', 'type' => 'longtext', 'max' => 4000, 'span' => 12],
                ],
                'unique' => [['fields' => ['condominium_id', 'name'], 'message' => 'Já existe uma área com este nome neste condomínio.']],
                'order' => 'name',
            ],
            'linkable_areas' => [
                'page' => 'AP-007', 'table' => 'erp_linkable_areas', 'route' => '/condominios/areas-vinculaveis',
                'api' => 'linkable-areas', 'group' => 'condominios', 'permission' => 'erp.linkable_areas', 'label' => 'Áreas vinculáveis',
                'entity' => 'área vinculável', 'gender' => 'a', 'scope' => 'required',
                'lead' => 'Vagas de garagem, depósitos e boxes que podem ser vinculados a uma unidade.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'kind' => ['label' => 'Tipo', 'type' => 'select', 'required' => true, 'span' => 3, 'list' => true, 'filter' => true, 'options' => [
                        'vaga' => 'Vaga de garagem', 'deposito' => 'Depósito', 'box' => 'Box', 'outro' => 'Outro',
                    ]],
                    'identifier' => ['label' => 'Identificação', 'type' => 'text', 'required' => true, 'max' => 40, 'span' => 3, 'list' => true, 'search' => true, 'hint' => 'Ex.: G1-023'],
                    'unit_id' => ['label' => 'Unidade vinculada', 'hint' => 'Vazio: livre'] + $unit(4),
                    'unit_label' => $unitText('Unidade vinculada (texto)', true, 4),
                    'location' => ['label' => 'Localização', 'type' => 'text', 'max' => 120, 'span' => 4, 'hint' => 'Ex.: Subsolo 1'],
                    'fraction' => ['label' => 'Fração ideal (%)', 'type' => 'percent', 'span' => 4],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['condominium_id', 'kind', 'identifier'], 'message' => 'Já existe uma área deste tipo com esta identificação.']],
                'order' => 'identifier',
            ],
            'assets' => [
                'page' => 'AP-019', 'table' => 'erp_assets', 'route' => '/condominios/patrimonio',
                'api' => 'assets', 'group' => 'condominios', 'permission' => 'erp.assets', 'label' => 'Patrimônio',
                'entity' => 'bem', 'gender' => 'o', 'scope' => 'required',
                'lead' => 'Bens do condomínio: móveis, equipamentos, eletrônicos e ferramentas.',
                'status' => ['active' => 'Em uso', 'inactive' => 'Baixado'],
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'description' => ['label' => 'Descrição', 'type' => 'text', 'required' => true, 'max' => 160, 'span' => 6, 'list' => true, 'search' => true],
                    'asset_number' => ['label' => 'Número de patrimônio', 'type' => 'text', 'max' => 30, 'span' => 4, 'list' => true, 'search' => true, 'pattern' => '/^[0-9A-Za-z.\/-]{1,30}$/', 'pattern_message' => 'Use letras, números, ponto, barra ou hífen.'],
                    'category' => ['label' => 'Categoria', 'type' => 'select', 'required' => true, 'span' => 4, 'list' => true, 'filter' => true, 'options' => [
                        'mobiliario' => 'Mobiliário', 'equipamento' => 'Equipamento', 'eletronico' => 'Eletrônico', 'ferramenta' => 'Ferramenta',
                        'seguranca' => 'Segurança', 'lazer' => 'Lazer', 'outro' => 'Outro',
                    ]],
                    'condition' => ['label' => 'Estado', 'type' => 'select', 'required' => true, 'span' => 4, 'list' => true, 'options' => [
                        'bom' => 'Bom', 'regular' => 'Regular', 'manutencao' => 'Em manutenção', 'inservivel' => 'Inservível',
                    ]],
                    'acquired_on' => ['label' => 'Data de aquisição', 'type' => 'date', 'span' => 4],
                    'value_cents' => ['label' => 'Valor de aquisição', 'type' => 'money', 'span' => 4, 'list' => true],
                    'location' => ['label' => 'Localização', 'type' => 'text', 'max' => 120, 'span' => 4],
                    'supplier_name' => ['label' => 'Fornecedor', 'type' => 'text', 'max' => 160, 'span' => 6],
                    'invoice_number' => ['label' => 'Nota fiscal nº', 'type' => 'text', 'max' => 30, 'span' => 6],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['condominium_id', 'asset_number'], 'message' => 'Este número de patrimônio já existe neste condomínio.']],
                'order' => 'description',
            ],
            // Plano de contas (AP-160, ADR 0019): base do núcleo financeiro. Sem condomínio é o plano
            // padrão da Connect; contas próprias de um condomínio completam o padrão.
            'chart' => [
                'page' => 'AP-160', 'table' => 'erp_chart_accounts', 'route' => '/configuracoes/plano-de-contas',
                'api' => 'chart-accounts', 'permission' => 'erp.chart', 'label' => 'Plano de contas',
                'entity' => 'conta', 'gender' => 'a', 'scope' => 'optional', 'group' => 'configuracoes',
                'lead' => 'Contas de receita e despesa. Sem condomínio, a conta faz parte do plano padrão da Connect e vale para todos.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'span' => 6, 'list' => true, 'hint' => 'Vazio: plano padrão da Connect (todos os condomínios)'],
                    'code' => ['label' => 'Código', 'type' => 'text', 'required' => true, 'max' => 30, 'span' => 3, 'list' => true, 'search' => true,
                        'pattern' => '/^\d{1,3}(\.\d{1,4}){0,5}$/', 'pattern_message' => 'Use números separados por ponto, ex.: 1, 1.01, 1.01.002.', 'hint' => 'Ex.: 2.01.003'],
                    'nature' => ['label' => 'Natureza', 'type' => 'select', 'required' => true, 'span' => 3, 'list' => true, 'filter' => true, 'options' => ['receita' => 'Receita', 'despesa' => 'Despesa']],
                    'name' => ['label' => 'Nome da conta', 'type' => 'text', 'required' => true, 'max' => 120, 'span' => 8, 'list' => true, 'search' => true],
                    'kind' => ['label' => 'Tipo', 'type' => 'select', 'required' => true, 'span' => 4, 'list' => true, 'options' => ['sintetica' => 'Sintética (agrupa)', 'analitica' => 'Analítica (recebe lançamentos)']],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                // Mesmo código não se repete no plano padrão nem no mesmo condomínio.
                'unique' => [['fields' => ['condominium_id', 'code'], 'nullable' => ['condominium_id'], 'message' => 'Já existe uma conta com este código neste plano.']],
                // Conta-pai = código sem o último segmento; precisa existir (no condomínio ou no padrão),
                // ser sintética e ter a mesma natureza. Conta com filhas não sai (RegistryInUse).
                'hierarchy' => ['field' => 'code', 'separator' => '.', 'parent_kind' => ['kind', 'sintetica'], 'same' => 'nature'],
                'title' => 'name',
                'order' => 'code',
            ],
            // Tipos de faturas (AP-168, ADR 0020): o que se cobra das unidades (taxa condominial,
            // fundo de reserva, rateio extra) e em qual conta de receita isso entra.
            'invoice_types' => [
                'page' => 'AP-168', 'table' => 'erp_invoice_types', 'route' => '/configuracoes/tipos-de-faturas',
                'api' => 'invoice-types', 'permission' => 'erp.invoice_types', 'label' => 'Tipos de faturas',
                'entity' => 'tipo de fatura', 'gender' => 'o', 'scope' => 'optional', 'group' => 'configuracoes',
                'lead' => 'O que é cobrado das unidades e a conta de receita de cada cobrança. Sem condomínio, o tipo vale para todos.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'span' => 6, 'list' => true, 'hint' => 'Vazio: tipo padrão da Connect (todos os condomínios)'],
                    'name' => ['label' => 'Nome', 'type' => 'text', 'required' => true, 'max' => 80, 'span' => 6, 'list' => true, 'search' => true, 'hint' => 'Ex.: Taxa condominial, Fundo de reserva'],
                    'chart_account_id' => ['label' => 'Conta de receita', 'type' => 'ref', 'required' => true, 'span' => 6,
                        'ref' => 'chart', 'ref_where' => ['kind' => 'analitica', 'nature' => 'receita'], 'ref_label' => ['code', 'name'],
                        'ref_message' => 'Escolha uma conta analítica de receita ativa do plano de contas.',
                        'hint' => 'Conta analítica de receita do plano de contas'],
                    'recurrence' => ['label' => 'Recorrência', 'type' => 'select', 'required' => true, 'span' => 3, 'list' => true, 'filter' => true, 'options' => ['mensal' => 'Mensal', 'avulsa' => 'Avulsa']],
                    'default_amount_cents' => ['label' => 'Valor padrão', 'type' => 'money', 'span' => 3, 'list' => true, 'hint' => 'Opcional'],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['condominium_id', 'name'], 'nullable' => ['condominium_id'], 'message' => 'Já existe um tipo de fatura com este nome neste plano.']],
                'order' => 'name',
            ],
            // Centros de custo (CN-002, ADR 0021): onde a despesa ou a receita acontece (portaria,
            // limpeza, obra do bloco B). Sem condomínio = padrão da Connect.
            'cost_centers' => [
                'page' => 'CN-002', 'table' => 'erp_cost_centers', 'route' => '/financeiro/centros-de-custo',
                'api' => 'cost-centers', 'permission' => 'erp.cost_centers', 'label' => 'Centros de custo',
                'entity' => 'centro de custo', 'gender' => 'o', 'scope' => 'optional', 'group' => 'financeiro',
                'lead' => 'Agrupam lançamentos por área ou projeto. Sem condomínio, o centro de custo vale para todos.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'span' => 6, 'list' => true, 'hint' => 'Vazio: centro de custo padrão da Connect (todos os condomínios)'],
                    'code' => ['label' => 'Código', 'type' => 'text', 'required' => true, 'max' => 20, 'span' => 2, 'list' => true, 'search' => true,
                        'pattern' => '/^[0-9A-Za-z][0-9A-Za-z._-]{0,19}$/', 'pattern_message' => 'Use letras, números, ponto, hífen ou sublinhado (até 20).'],
                    'name' => ['label' => 'Nome', 'type' => 'text', 'required' => true, 'max' => 120, 'span' => 4, 'list' => true, 'search' => true, 'hint' => 'Ex.: Portaria, Limpeza, Obra do bloco B'],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['condominium_id', 'code'], 'nullable' => ['condominium_id'], 'message' => 'Já existe um centro de custo com este código neste plano.']],
                'order' => 'code',
            ],
            // Contas bancárias (CN-003, ADR 0021): onde o dinheiro do condomínio fica. Sem segredos:
            // credenciais de API bancária ficam fora deste cadastro (BANKING_INTEGRATIONS.md).
            'bank_accounts' => [
                'page' => 'CN-003', 'table' => 'erp_bank_accounts', 'route' => '/financeiro/contas-bancarias',
                'api' => 'bank-accounts', 'permission' => 'erp.bank_accounts', 'label' => 'Contas bancárias',
                'entity' => 'conta bancária', 'gender' => 'a', 'scope' => 'required', 'group' => 'financeiro',
                'lead' => 'Contas correntes, poupanças e aplicações de cada condomínio. Uma delas é a principal.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'name' => ['label' => 'Apelido', 'type' => 'text', 'required' => true, 'max' => 80, 'span' => 6, 'list' => true, 'search' => true, 'hint' => 'Ex.: Conta movimento'],
                    'bank_code' => ['label' => 'Banco (código)', 'type' => 'text', 'required' => true, 'max' => 3, 'span' => 2, 'list' => true,
                        'pattern' => '/^\d{3}$/', 'pattern_message' => 'Use o código de 3 dígitos do banco (COMPE).'],
                    'branch' => ['label' => 'Agência', 'type' => 'text', 'required' => true, 'max' => 10, 'span' => 2, 'list' => true,
                        'pattern' => '/^\d{1,5}(-[\dXx])?$/', 'pattern_message' => 'Agência inválida (ex.: 1234 ou 1234-5).'],
                    'account_number' => ['label' => 'Conta', 'type' => 'text', 'required' => true, 'max' => 20, 'span' => 3, 'list' => true, 'search' => true,
                        'pattern' => '/^\d{1,15}-[\dXx]$/', 'pattern_message' => 'Conta com dígito (ex.: 123456-7).'],
                    'kind' => ['label' => 'Tipo', 'type' => 'select', 'required' => true, 'span' => 3, 'filter' => true, 'options' => ['corrente' => 'Conta corrente', 'poupanca' => 'Poupança', 'aplicacao' => 'Aplicação']],
                    'wallet' => ['label' => 'Carteira de cobrança', 'type' => 'text', 'max' => 20, 'span' => 2, 'hint' => 'Quando houver'],
                    'opening_balance_cents' => ['label' => 'Saldo inicial', 'type' => 'money', 'span' => 3],
                    'opened_on' => ['label' => 'Data do saldo inicial', 'type' => 'date', 'span' => 3],
                    'is_main' => ['label' => 'Conta principal', 'type' => 'bool', 'span' => 3, 'list' => true],
                    'cost_center_id' => ['label' => 'Centro de custo padrão', 'type' => 'ref', 'span' => 6,
                        'ref' => 'cost_centers', 'ref_label' => ['code', 'name'],
                        'ref_message' => 'Escolha um centro de custo ativo do padrão ou deste condomínio.', 'hint' => 'Opcional; sugerido nos lançamentos desta conta'],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['condominium_id', 'bank_code', 'branch', 'account_number'], 'message' => 'Esta conta já está cadastrada neste condomínio.']],
                'single_active' => [['field' => 'is_main', 'value' => 1, 'per' => 'condominium_id', 'message' => 'Este condomínio já tem uma conta principal ativa. Desmarque a atual antes.']],
                'requires' => [['field' => 'opened_on', 'when' => 'opening_balance_cents', 'message' => 'Informe a data do saldo inicial.']],
                'order' => 'name',
            ],
            // Lançamentos (AP-020, ADR 0022): receitas e despesas do condomínio. A conta do plano de
            // contas precisa ser analítica e da mesma natureza; pago exige a conta bancária.
            'entries' => [
                'page' => 'AP-020', 'table' => 'erp_entries', 'route' => '/financeiro/lancamentos',
                'api' => 'entries', 'permission' => 'erp.entries', 'label' => 'Lançamentos',
                'entity' => 'lançamento', 'gender' => 'o', 'scope' => 'required', 'group' => 'financeiro',
                'lead' => 'Receitas e despesas de cada condomínio, com conta, centro de custo, vencimento e pagamento.',
                'status' => ['active' => 'Válido', 'inactive' => 'Cancelado'],
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'nature' => ['label' => 'Natureza', 'type' => 'select', 'required' => true, 'span' => 3, 'list' => true, 'filter' => true, 'options' => ['receita' => 'Receita', 'despesa' => 'Despesa']],
                    'competence' => ['label' => 'Competência', 'type' => 'text', 'required' => true, 'max' => 7, 'span' => 3, 'list' => true, 'search' => true,
                        'pattern' => '/^\d{4}-(0[1-9]|1[0-2])$/', 'pattern_message' => 'Use ano e mês, ex.: 2026-11.', 'hint' => 'AAAA-MM'],
                    'description' => ['label' => 'Descrição', 'type' => 'text', 'required' => true, 'max' => 160, 'span' => 8, 'list' => true, 'search' => true],
                    'amount_cents' => ['label' => 'Valor', 'type' => 'money', 'required' => true, 'positive' => true, 'span' => 4, 'list' => true],
                    'chart_account_id' => ['label' => 'Conta do plano de contas', 'type' => 'ref', 'required' => true, 'span' => 6,
                        'ref' => 'chart', 'ref_where' => ['kind' => 'analitica'], 'ref_label' => ['code', 'name'],
                        'ref_match' => ['nature' => 'nature'], 'ref_match_message' => 'A conta precisa ter a mesma natureza do lançamento (receita ou despesa).',
                        'ref_message' => 'Escolha uma conta analítica ativa do plano de contas.'],
                    'cost_center_id' => ['label' => 'Centro de custo', 'type' => 'ref', 'span' => 6, 'ref' => 'cost_centers', 'ref_label' => ['code', 'name'],
                        'ref_message' => 'Escolha um centro de custo ativo do padrão ou deste condomínio.'],
                    'due_on' => ['label' => 'Vencimento', 'type' => 'date', 'required' => true, 'span' => 3, 'list' => true],
                    'paid_on' => ['label' => 'Pago em', 'type' => 'date', 'span' => 3, 'list' => true, 'hint' => 'Vazio enquanto estiver em aberto'],
                    'bank_account_id' => ['label' => 'Conta bancária', 'type' => 'ref', 'span' => 6, 'ref' => 'bank_accounts', 'ref_label' => ['name', 'bank_code', 'account_number'],
                        'ref_message' => 'Escolha uma conta bancária ativa deste condomínio.', 'hint' => 'Obrigatória quando o lançamento está pago'],
                    'supplier_id' => ['label' => 'Fornecedor', 'type' => 'ref', 'span' => 6, 'ref' => 'suppliers', 'ref_label' => ['legal_name'],
                        'ref_message' => 'Escolha um fornecedor ativo.'],
                    'unit_id' => ['hint' => 'Unidade que deve a receita (faturas preenchem sozinhas)'] + $unit(6),
                    'document_number' => ['label' => 'Nº do documento', 'type' => 'text', 'max' => 40, 'span' => 6, 'search' => true, 'hint' => 'Nota fiscal, recibo ou boleto'],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'requires' => [['field' => 'bank_account_id', 'when' => 'paid_on', 'message' => 'Informe a conta bancária do pagamento.']],
                // Competência fechada no fechamento mensal trava criação, alteração e exclusão (ADR 0031).
                'closing_lock' => 'competence',
                'origin_lock' => self::INVOICE_LOCK,
                'title' => 'description',
                'order' => 'due_on',
            ],
        ];

        // Lançamentos fixos (AP-021, ADR 0027): modelos que geram o lançamento de cada competência
        // (RecurringEntries::generate). Mesmas regras de conta e natureza dos lançamentos.
        $recurring = $definitions['entries']['fields'];
        unset($recurring['competence'], $recurring['due_on'], $recurring['paid_on'], $recurring['document_number'], $recurring['unit_id']);
        $recurring['bank_account_id']['hint'] = 'Conta sugerida; o lançamento gerado nasce em aberto';
        $recurring['day_of_month'] = ['label' => 'Dia do vencimento', 'type' => 'int', 'required' => true, 'min' => 1, 'max_value' => 28, 'span' => 3, 'list' => true, 'hint' => '1 a 28'];
        $recurring['starts_competence'] = ['label' => 'Primeira competência', 'type' => 'text', 'required' => true, 'max' => 7, 'span' => 3, 'list' => true,
            'pattern' => '/^\d{4}-(0[1-9]|1[0-2])$/', 'pattern_message' => 'Use ano e mês, ex.: 2026-11.', 'hint' => 'AAAA-MM'];
        $recurring['ends_competence'] = ['label' => 'Última competência', 'type' => 'text', 'max' => 7, 'span' => 3, 'list' => true, 'after' => 'starts_competence',
            'pattern' => '/^\d{4}-(0[1-9]|1[0-2])$/', 'pattern_message' => 'Use ano e mês, ex.: 2026-12.', 'hint' => 'Vazio = sem fim'];
        $notes = $recurring['notes'];
        unset($recurring['notes']);
        $recurring['notes'] = $notes;
        $definitions['recurring_entries'] = [
            'page' => 'AP-021', 'table' => 'erp_recurring_entries', 'route' => '/financeiro/lancamentos-fixos',
            'api' => 'recurring-entries', 'permission' => 'erp.recurring_entries', 'label' => 'Lançamentos fixos',
            'entity' => 'lançamento fixo', 'gender' => 'o', 'scope' => 'required', 'group' => 'financeiro',
            'lead' => 'Receitas e despesas que se repetem todo mês. Gere os lançamentos de uma competência sem duplicar os já gerados.',
            'fields' => $recurring,
            'title' => 'description',
            'order' => 'description',
        ];

        // Contas a pagar (AP-022) e a receber (AP-023), ADR 0024: visões dos lançamentos em aberto
        // (sem segunda tabela). Gravam sempre a natureza da visão; preencher "Pago em" dá baixa e o
        // lançamento sai da visão (continua em Lançamentos). Mesmas permissões de erp.entries.
        foreach ([
            'payables' => ['AP-022', 'despesa', '/financeiro/contas-a-pagar', 'payables', 'Contas a pagar', 'conta a pagar', 'Despesas em aberto, por vencimento. Informe "Pago em" e a conta bancária para dar baixa.'],
            'receivables' => ['AP-023', 'receita', '/financeiro/contas-a-receber', 'receivables', 'Contas a receber', 'conta a receber', 'Receitas em aberto, por vencimento. Informe "Pago em" e a conta bancária para dar baixa.'],
        ] as $slug => [$page, $nature, $route, $api, $label, $entity, $lead]) {
            $fields = $definitions['entries']['fields'];
            unset($fields['nature']);
            $fields['chart_account_id']['ref_where']['nature'] = $nature;
            $fields['paid_on']['hint'] = 'Preencha para dar baixa';
            $definitions[$slug] = [
                'page' => $page, 'table' => 'erp_entries', 'route' => $route, 'api' => $api,
                'permission' => 'erp.entries', 'label' => $label, 'entity' => $entity, 'gender' => 'a',
                'scope' => 'required', 'group' => 'financeiro', 'lead' => $lead,
                'status' => $definitions['entries']['status'],
                'fields' => $fields,
                'base_where' => ['nature' => $nature, 'paid_on' => null],
                'fixed' => ['nature' => $nature],
                'requires' => $definitions['entries']['requires'],
                'closing_lock' => 'competence',
                'origin_lock' => self::INVOICE_LOCK,
                'title' => 'description',
                'order' => 'due_on',
            ];
        }

        foreach ($definitions as $slug => &$definition) {
            $definition['slug'] = $slug;
            $definition['group'] ??= 'cadastros';
            $definition['status'] ??= self::STATUS;
            $definition['title'] ??= $definition['order'];
            $definition['unique'] ??= [];
            $definition['requires'] ??= [];
            $definition['single_active'] ??= [];
            foreach ($definition['fields'] as $name => &$field) {
                $field += ['name' => $name, 'required' => false, 'span' => 6, 'list' => false, 'search' => false, 'filter' => false, 'options' => [], 'hint' => null];
            }
            unset($field);
        }
        unset($definition);
        return $definitions;
    }
}
