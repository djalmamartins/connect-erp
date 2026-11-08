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
 * Tipos de campo: condominium, text, longtext, cpf, cnpj, document (CPF ou CNPJ),
 * id_document (CPF ou RG/outro), email, phone, date, money (centavos), percent, bool,
 * select, plate, oab.
 */
final class Definitions
{
    private const STATUS = ['active' => 'Ativo', 'inactive' => 'Inativo'];

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
        $definitions = [
            'residents' => [
                'page' => 'AP-005', 'table' => 'erp_residents', 'route' => '/cadastros/proprietarios-e-inquilinos',
                'api' => 'residents', 'permission' => 'erp.residents', 'label' => 'Proprietários e inquilinos',
                'entity' => 'pessoa', 'gender' => 'a', 'scope' => 'required',
                'lead' => 'Proprietários, inquilinos, moradores e responsáveis financeiros de cada unidade.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'unit_label' => ['label' => 'Unidade', 'type' => 'text', 'required' => true, 'max' => 40, 'span' => 3, 'list' => true, 'search' => true, 'hint' => 'Ex.: Bloco A - 101'],
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
                'requires' => [['field' => 'email', 'when' => 'billing_by_email', 'message' => 'Informe o e-mail para enviar o boleto.']],
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
                    'unit_label' => ['label' => 'Unidade', 'type' => 'text', 'max' => 40, 'span' => 4, 'hint' => 'Vazio para síndico profissional'],
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
                    'unit_label' => ['label' => 'Unidade', 'type' => 'text', 'required' => true, 'max' => 40, 'span' => 6, 'list' => true, 'search' => true, 'hint' => 'Ex.: Bloco A - 101'],
                    'plate' => ['label' => 'Placa', 'type' => 'plate', 'required' => true, 'span' => 4, 'list' => true, 'search' => true],
                    'kind' => ['label' => 'Tipo', 'type' => 'select', 'required' => true, 'span' => 4, 'options' => ['carro' => 'Carro', 'moto' => 'Moto', 'utilitario' => 'Utilitário', 'outro' => 'Outro']],
                    'model' => ['label' => 'Marca e modelo', 'type' => 'text', 'max' => 80, 'span' => 4, 'list' => true, 'search' => true],
                    'color' => ['label' => 'Cor', 'type' => 'text', 'max' => 30, 'span' => 4, 'list' => true],
                    'parking_spot' => ['label' => 'Vaga', 'type' => 'text', 'max' => 20, 'span' => 4],
                    'owner_name' => ['label' => 'Responsável', 'type' => 'text', 'max' => 160, 'span' => 4, 'search' => true],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['condominium_id', 'plate'], 'message' => 'Esta placa já está cadastrada neste condomínio.']],
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
                    'unit_label' => ['label' => 'Unidade visitada', 'type' => 'text', 'required' => true, 'max' => 40, 'span' => 6, 'list' => true, 'search' => true],
                    'name' => ['label' => 'Nome', 'type' => 'text', 'required' => true, 'max' => 160, 'span' => 8, 'list' => true, 'search' => true],
                    'document' => ['label' => 'Documento (CPF ou RG)', 'type' => 'id_document', 'span' => 4, 'list' => true, 'search' => true],
                    'phone' => ['label' => 'Telefone', 'type' => 'phone', 'span' => 4],
                    'permanent_access' => ['label' => 'Acesso permanente', 'type' => 'bool', 'span' => 4],
                    'valid_until' => ['label' => 'Autorizado até', 'type' => 'date', 'span' => 4, 'list' => true, 'hint' => 'Vazio quando o acesso é permanente'],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'requires' => [['field' => 'valid_until', 'unless' => 'permanent_access', 'message' => 'Informe até quando o acesso vale ou marque acesso permanente.']],
                'order' => 'name',
            ],
            'pets' => [
                'page' => 'AP-013', 'table' => 'erp_pets', 'route' => '/cadastros/animais-de-estimacao',
                'api' => 'pets', 'permission' => 'erp.pets', 'label' => 'Animais de estimação',
                'entity' => 'animal', 'gender' => 'o', 'scope' => 'required',
                'lead' => 'Animais de estimação por unidade.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'unit_label' => ['label' => 'Unidade', 'type' => 'text', 'required' => true, 'max' => 40, 'span' => 6, 'list' => true, 'search' => true],
                    'name' => ['label' => 'Nome', 'type' => 'text', 'required' => true, 'max' => 80, 'span' => 6, 'list' => true, 'search' => true],
                    'species' => ['label' => 'Espécie', 'type' => 'select', 'required' => true, 'span' => 3, 'list' => true, 'filter' => true, 'options' => ['cao' => 'Cão', 'gato' => 'Gato', 'ave' => 'Ave', 'outro' => 'Outro']],
                    'size' => ['label' => 'Porte', 'type' => 'select', 'span' => 3, 'options' => ['pequeno' => 'Pequeno', 'medio' => 'Médio', 'grande' => 'Grande']],
                    'breed' => ['label' => 'Raça', 'type' => 'text', 'max' => 80, 'span' => 6, 'list' => true],
                    'tutor_name' => ['label' => 'Tutor', 'type' => 'text', 'max' => 160, 'span' => 6, 'search' => true],
                    'vaccinated' => ['label' => 'Vacinação em dia', 'type' => 'bool', 'span' => 6],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
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
                'api' => 'common-areas', 'permission' => 'erp.common_areas', 'label' => 'Áreas comuns',
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
                'api' => 'linkable-areas', 'permission' => 'erp.linkable_areas', 'label' => 'Áreas vinculáveis',
                'entity' => 'área vinculável', 'gender' => 'a', 'scope' => 'required',
                'lead' => 'Vagas de garagem, depósitos e boxes que podem ser vinculados a uma unidade.',
                'fields' => [
                    'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'required' => true, 'span' => 6, 'list' => true],
                    'kind' => ['label' => 'Tipo', 'type' => 'select', 'required' => true, 'span' => 3, 'list' => true, 'filter' => true, 'options' => [
                        'vaga' => 'Vaga de garagem', 'deposito' => 'Depósito', 'box' => 'Box', 'outro' => 'Outro',
                    ]],
                    'identifier' => ['label' => 'Identificação', 'type' => 'text', 'required' => true, 'max' => 40, 'span' => 3, 'list' => true, 'search' => true, 'hint' => 'Ex.: G1-023'],
                    'unit_label' => ['label' => 'Unidade vinculada', 'type' => 'text', 'max' => 40, 'span' => 4, 'list' => true, 'search' => true, 'hint' => 'Vazio: livre'],
                    'location' => ['label' => 'Localização', 'type' => 'text', 'max' => 120, 'span' => 4, 'hint' => 'Ex.: Subsolo 1'],
                    'fraction' => ['label' => 'Fração ideal (%)', 'type' => 'percent', 'span' => 4],
                    'notes' => ['label' => 'Observações', 'type' => 'longtext', 'max' => 2000, 'span' => 12],
                ],
                'unique' => [['fields' => ['condominium_id', 'kind', 'identifier'], 'message' => 'Já existe uma área deste tipo com esta identificação.']],
                'order' => 'identifier',
            ],
            'assets' => [
                'page' => 'AP-019', 'table' => 'erp_assets', 'route' => '/condominios/patrimonio',
                'api' => 'assets', 'permission' => 'erp.assets', 'label' => 'Patrimônio',
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
        ];

        foreach ($definitions as $slug => &$definition) {
            $definition['slug'] = $slug;
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
