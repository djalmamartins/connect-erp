<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\AnnualBalance;
use Source\Services\Erp\ManagementReports;
use Source\Services\Erp\PaymentReports;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\RegistryReports;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Tela única dos relatórios que já tinham serviço e API (ADR 0143): gerenciais (ADR 0082, AP-045 a AP-070),
 * de pagamentos (ADR 0089, AP-074 a AP-076) e cadastrais (ADR 0084, AP-085 a AP-099). Cada página é uma
 * linha de PAGES: o serviço e o método que a API usa, os filtros, as permissões (as mesmas da API) e as
 * colunas. A tela lê no servidor pelo mesmo serviço da API, no condomínio da barra superior, e o
 * "Exportar CSV" baixa a mesma consulta na API (format=csv, CSV seguro do ADR 0081, auditado e com o
 * limite de 30 por hora do ADR 0087). Sem banco ou sem permissão, reportReady false = demonstração.
 */
final class ReportScreens
{
    /**
     * Página => definição:
     *  - api: caminho em /api/v1/reports; query: parâmetros fixos da API (visão);
     *  - filters: period (de/ate em datas), competences (de/ate em AAAA-MM), compare (duas faixas), competence
     *    (uma competência), until, as_of, bank_account, unit, compare_with e os selects de SELECTS;
     *  - condo: exige um condomínio na barra superior; perms: as mesmas da API;
     *  - columns: [rótulo, campo, tipo]; tipos em cell().
     */
    public const PAGES = [
        'AP-045' => ['api' => 'consumption', 'filters' => ['competences'], 'perms' => ['erp.meter_readings.view'],
            'lead' => 'Leituras, consumo e valor por tipo de medidor nas competências escolhidas.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Tipo', 'kind', 'label:meter'], ['Leituras', 'readings', 'int'], ['Consumo', 'consumption', 'number'],
                ['Valor', 'amount_cents', 'money'], ['Cobradas', 'billed', 'int']]],
        'AP-046' => ['api' => 'consumption', 'query' => ['by' => 'unit'], 'filters' => ['competences'], 'perms' => ['erp.meter_readings.view'],
            'lead' => 'Consumo de cada unidade por tipo de medidor nas competências escolhidas.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Unidade', 'unit_label', 'text'], ['Tipo', 'kind', 'label:meter'], ['Leituras', 'readings', 'int'],
                ['Consumo', 'consumption', 'number'], ['Valor', 'amount_cents', 'money'], ['Cobradas', 'billed', 'int']]],
        'AP-049' => ['api' => 'cost-centers', 'filters' => ['competences'], 'perms' => ['erp.entries.view', 'erp.cost_centers.view'],
            'lead' => 'Previsto (pela competência), realizado (pago) e em aberto de cada centro de custo.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Natureza', 'nature', 'label:nature'], ['Código', 'code', 'text'], ['Centro de custo', 'cost_center', 'text'],
                ['Previsto', 'planned_cents', 'money'], ['Realizado', 'realized_cents', 'money'], ['Em aberto', 'open_cents', 'money']]],
        'AP-050' => ['api' => 'comparison', 'filters' => ['compare'], 'perms' => ['erp.entries.view', 'erp.chart.view'],
            'lead' => 'Duas faixas de competência lado a lado, por conta do plano, com a diferença e a variação.',
            'columns' => [['Código', 'code', 'text'], ['Conta', 'name', 'text'], ['Natureza', 'nature', 'label:nature'], ['Período A', 'a_cents', 'money'],
                ['Período B', 'b_cents', 'money'], ['Diferença', 'difference_cents', 'money'], ['Variação', 'percent', 'percent']]],
        'AP-052' => ['api' => 'financial-statement', 'query' => ['view' => 'accounts'], 'filters' => ['competences'], 'perms' => ['erp.entries.view', 'erp.chart.view'],
            'lead' => 'Receitas e despesas por conta do plano: previsto, realizado e em aberto.',
            'columns' => [['Código', 'code', 'text'], ['Conta', 'account', 'text'], ['Natureza', 'nature', 'label:nature'], ['Lançamentos', 'entries', 'int'],
                ['Previsto', 'planned_cents', 'money'], ['Realizado', 'realized_cents', 'money'], ['Em aberto', 'open_cents', 'money']]],
        'AP-053' => ['api' => 'financial-statement', 'query' => ['view' => 'analytic'], 'filters' => ['competences'], 'perms' => ['erp.entries.view', 'erp.chart.view'],
            'lead' => 'Cada lançamento das competências, agrupado pela conta do plano.',
            'columns' => [['Código', 'code', 'text'], ['Conta', 'account', 'text'], ['Natureza', 'nature', 'label:nature'], ['Descrição', 'description', 'text'],
                ['Unidade', 'unit_label', 'text'], ['Competência', 'competence', 'text'], ['Vencimento', 'due_on', 'date'], ['Pagamento', 'paid_on', 'date'],
                ['Situação', 'situation', 'label:situation'], ['Valor', 'amount_cents', 'money']]],
        'AP-054' => ['api' => 'financial-statement', 'query' => ['view' => 'situation'], 'filters' => ['competences'], 'perms' => ['erp.entries.view', 'erp.chart.view'],
            'lead' => 'Receitas e despesas das competências por situação: pago, a vencer, vencido e cancelado.',
            'columns' => [['Natureza', 'nature', 'label:nature'], ['Situação', 'situation', 'label:situation'], ['Lançamentos', 'entries', 'int'], ['Valor', 'amount_cents', 'money']]],
        'AP-056' => ['api' => 'account-statement', 'filters' => ['bank_account', 'period'], 'perms' => ['erp.entries.view', 'erp.bank_accounts.view'],
            'lead' => 'Movimentos pagos e transferências da conta bancária, com o saldo anterior e o saldo corrido.',
            'columns' => [['Data', 'on_date', 'date'], ['Histórico', 'description', 'text'], ['Valor', 'amount_cents', 'signed'], ['Saldo', 'balance_cents', 'signed']]],
        'AP-062' => ['api' => 'apportionment', 'query' => ['view' => 'unit'], 'filters' => ['competence'], 'condo' => true, 'perms' => ['erp.entries.view', 'erp.units.view'],
            'lead' => 'Despesas da competência rateadas pela fração ideal de cada unidade, sem perder centavo.',
            'columns' => [['Unidade', 'unit_label', 'text'], ['Fração ideal', 'ideal_fraction', 'number'], ['Valor', 'amount_cents', 'money']]],
        'AP-063' => ['api' => 'apportionment', 'query' => ['view' => 'compare'], 'filters' => ['competence', 'compare_with'], 'condo' => true, 'perms' => ['erp.entries.view', 'erp.units.view'],
            'lead' => 'O rateio de duas competências lado a lado, por unidade.',
            'columns' => [['Unidade', 'unit_label', 'text'], ['Fração ideal', 'ideal_fraction', 'number'], ['Competência', 'a_cents', 'money'], ['Comparação', 'b_cents', 'money'],
                ['Diferença', 'difference_cents', 'money'], ['Variação', 'percent', 'percent']]],
        'AP-064' => ['api' => 'apportionment', 'query' => ['view' => 'unit_account'], 'filters' => ['competence'], 'condo' => true, 'perms' => ['erp.entries.view', 'erp.units.view'],
            'lead' => 'A parte de cada unidade em cada conta do plano.',
            'columns' => [['Unidade', 'unit_label', 'text'], ['Código', 'code', 'text'], ['Conta', 'account', 'text'], ['Valor', 'amount_cents', 'money']]],
        'AP-065' => ['api' => 'apportionment', 'query' => ['view' => 'unit_entries'], 'filters' => ['competence', 'unit'], 'condo' => true, 'perms' => ['erp.entries.view', 'erp.units.view'],
            'lead' => 'Cada despesa da competência e a parte de uma unidade nela.',
            'columns' => [['Unidade', 'unit_label', 'text'], ['Despesa', 'description', 'text'], ['Código', 'code', 'text'], ['Valor da despesa', 'expense_cents', 'money'],
                ['Parte da unidade', 'amount_cents', 'money']]],
        'AP-066' => ['api' => 'apportionment', 'query' => ['view' => 'entries'], 'filters' => ['competence'], 'condo' => true, 'perms' => ['erp.entries.view', 'erp.units.view'],
            'lead' => 'Cada despesa da competência dividida entre todas as unidades.',
            'columns' => [['Unidade', 'unit_label', 'text'], ['Despesa', 'description', 'text'], ['Código', 'code', 'text'], ['Valor da despesa', 'expense_cents', 'money'],
                ['Parte da unidade', 'amount_cents', 'money']]],
        'AP-067' => ['api' => 'apportionment', 'query' => ['view' => 'account'], 'filters' => ['competence'], 'condo' => true, 'perms' => ['erp.entries.view', 'erp.units.view'],
            'lead' => 'O rateio por conta do plano, com a maior e a menor parte de uma unidade.',
            'columns' => [['Código', 'code', 'text'], ['Conta', 'account', 'text'], ['Valor', 'amount_cents', 'money'], ['Maior parte', 'max_share_cents', 'money'],
                ['Menor parte', 'min_share_cents', 'money']]],
        'AP-068' => ['api' => 'entries', 'filters' => ['competences', 'natureza', 'situacao'], 'perms' => ['erp.entries.view'],
            'lead' => 'Lançamentos das competências, com a situação de hoje.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Natureza', 'nature', 'label:nature'], ['Descrição', 'description', 'text'], ['Código', 'code', 'text'],
                ['Unidade', 'unit_label', 'text'], ['Competência', 'competence', 'text'], ['Vencimento', 'due_on', 'date'], ['Pagamento', 'paid_on', 'date'],
                ['Situação', 'situation', 'label:situation'], ['Valor', 'amount_cents', 'money']]],
        'AP-069' => ['api' => 'entries', 'query' => ['detailed' => '1'], 'filters' => ['competences', 'natureza', 'situacao'], 'perms' => ['erp.entries.view'],
            'lead' => 'Lançamentos das competências com fornecedor, centro de custo, conta bancária, documento e origem.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Natureza', 'nature', 'label:nature'], ['Descrição', 'description', 'text'], ['Código', 'code', 'text'],
                ['Unidade', 'unit_label', 'text'], ['Fornecedor', 'supplier', 'text'], ['Centro de custo', 'cost_center', 'text'], ['Conta bancária', 'bank_account', 'text'],
                ['Documento', 'document_number', 'text'], ['Origem', 'origin', 'text'], ['Competência', 'competence', 'text'], ['Vencimento', 'due_on', 'date'],
                ['Pagamento', 'paid_on', 'date'], ['Situação', 'situation', 'label:situation'], ['Valor', 'amount_cents', 'money']]],
        'AP-070' => ['api' => 'bank-charges', 'filters' => ['period', 'boleto'], 'perms' => ['erp.bank_charges.view'],
            'lead' => 'Boletos com vencimento no período, com o valor emitido e o pago.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Nosso número', 'our_number', 'text'], ['Situação', 'status', 'label:boleto'], ['Unidade', 'unit_label', 'text'],
                ['Descrição', 'description', 'text'], ['Conta bancária', 'bank_account', 'text'], ['Vencimento', 'due_on', 'date'], ['Valor', 'amount_cents', 'money'],
                ['Pagamento', 'paid_on', 'date'], ['Valor pago', 'paid_cents', 'money']]],
        'AP-074' => ['api' => 'payments', 'filters' => ['period', 'pagamento'], 'perms' => ['erp.entries.view'],
            'lead' => 'Contas pagas no período, pela data do pagamento, com a conta bancária e o fornecedor.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Natureza', 'nature', 'label:nature'], ['Pago em', 'paid_on', 'date'], ['Vencimento', 'due_on', 'date'],
                ['Descrição', 'description', 'text'], ['Documento', 'document_number', 'text'], ['Conta', 'account', 'text'], ['Conta bancária', 'bank_account', 'text'],
                ['Fornecedor', 'supplier', 'text'], ['CPF/CNPJ', 'supplier_document', 'text'], ['Unidade', 'unit_label', 'text'], ['Valor', 'amount_cents', 'money']]],
        'AP-075' => ['api' => 'calculation', 'filters' => ['as_of', 'unit'], 'condo' => true, 'perms' => ['erp.entries.view', 'erp.agreements.view'],
            'lead' => 'Correção, multa e juros de cada conta vencida na data de referência, pela regra de cobrança do condomínio.',
            'columns' => [['Unidade', 'unit_label', 'text'], ['Descrição', 'description', 'text'], ['Competência', 'competence', 'text'], ['Vencimento', 'due_on', 'date'],
                ['Atraso', 'days_late', 'days'], ['Principal', 'principal_cents', 'money'], ['Correção', 'correction_cents', 'money'], ['Multa', 'fine_cents', 'money'],
                ['Juros', 'interest_cents', 'money'], ['Total', 'total_cents', 'money']]],
        'AP-076' => ['api' => 'transfers', 'filters' => ['period', 'ativas'], 'perms' => ['erp.entries.view', 'erp.bank_accounts.view'],
            'lead' => 'Transferências entre contas bancárias no período. As canceladas aparecem, mas ficam fora do total.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Data', 'transferred_on', 'date'], ['Origem', 'from_account', 'text'], ['Destino', 'to_account', 'text'],
                ['Descrição', 'description', 'text'], ['Valor', 'amount_cents', 'money'], ['Situação', 'status', 'label:transfer'], ['Motivo do cancelamento', 'cancel_reason', 'text']]],
        'AP-080' => ['api' => 'annual-balance', 'filters' => ['year', 'base'], 'perms' => ['erp.entries.view', 'erp.chart.view'], 'wide' => true,
            'lead' => 'Receitas e despesas de cada conta do plano mês a mês no ano, com as contas sintéticas somadas e o resultado de cada mês.',
            'columns' => [['Código', 'code', 'text'], ['Conta', 'name', 'tree'], ['Natureza', 'nature', 'label:nature'],
                ['Jan', 'months_cents.0', 'money'], ['Fev', 'months_cents.1', 'money'], ['Mar', 'months_cents.2', 'money'], ['Abr', 'months_cents.3', 'money'],
                ['Mai', 'months_cents.4', 'money'], ['Jun', 'months_cents.5', 'money'], ['Jul', 'months_cents.6', 'money'], ['Ago', 'months_cents.7', 'money'],
                ['Set', 'months_cents.8', 'money'], ['Out', 'months_cents.9', 'money'], ['Nov', 'months_cents.10', 'money'], ['Dez', 'months_cents.11', 'money'],
                ['Total', 'total_cents', 'money']]],
        'AP-085' => ['api' => 'reservations', 'filters' => ['period', 'reserva'], 'perms' => ['erp.reservations.view'],
            'lead' => 'Reservas das áreas comuns no período, com a taxa e a situação.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Área', 'area', 'text'], ['Unidade', 'unit_label', 'text'], ['Morador', 'resident_name', 'text'],
                ['Data', 'reserved_on', 'date'], ['Das', 'starts_time', 'text'], ['Até', 'ends_time', 'text'], ['Taxa', 'fee_cents', 'money'], ['Situação', 'status_label', 'text']]],
        'AP-086' => ['api' => 'units', 'filters' => [], 'perms' => ['erp.units.view'],
            'lead' => 'Unidades ativas com tipo, fração ideal, área privativa e quantas pessoas, medidores e contas vencidas têm.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Unidade', 'unit_label', 'text'], ['Tipo', 'type', 'text'], ['Andar', 'floor', 'text'],
                ['Fração ideal', 'ideal_fraction', 'number'], ['Área privativa', 'private_area', 'number'], ['Pessoas', 'people', 'int'], ['Medidores', 'meters', 'int'],
                ['Contas vencidas', 'overdue_entries', 'int']]],
        'AP-087' => ['api' => 'people', 'filters' => ['vinculo'], 'perms' => ['erp.residents.view'], 'personal' => true,
            'lead' => 'Pessoas ativas por unidade e vínculo.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Unidade', 'unit_label', 'text'], ['Vínculo', 'relationship_label', 'text'], ['Nome', 'name', 'text'],
                ['Documento', 'document', 'text'], ['E-mail', 'email', 'text'], ['Telefone', 'phone', 'text'], ['Cobrança por e-mail', 'billing_by_email', 'bool'],
                ['Comunicados', 'notices', 'bool']]],
        'AP-089' => ['api' => 'condominiums', 'filters' => [], 'scope' => 'all', 'perms' => ['erp.condominiums.view'],
            'lead' => 'Condomínios do seu escopo com unidades ativas, pessoas e contas bancárias.',
            'columns' => [['Condomínio', 'name', 'text'], ['CNPJ', 'document', 'text'], ['Cidade', 'city', 'text'], ['UF', 'state', 'text'], ['Situação', 'status', 'label:active'],
                ['Unidades', 'units', 'int'], ['Pessoas', 'people', 'int'], ['Contas bancárias', 'bank_accounts', 'int']]],
        'AP-090' => ['api' => 'occurrences', 'filters' => ['period', 'ocorrencia'], 'perms' => ['erp.occurrences.view'],
            'lead' => 'Ocorrências registradas no período, com a prioridade, a situação e o chamado.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Unidade', 'unit_label', 'text'], ['Assunto', 'subject', 'text'], ['Prioridade', 'priority', 'label:priority'],
                ['Data', 'occurred_on', 'date'], ['Situação', 'status', 'label:occurrence'], ['Chamado', 'ticket_id', 'text']]],
        'AP-093' => ['api' => 'pending-payments', 'filters' => ['until'], 'perms' => ['erp.entries.view'],
            'lead' => 'Contas a pagar em aberto até a data limite. As vencidas ficam marcadas.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Descrição', 'description', 'text'], ['Fornecedor', 'supplier', 'text'], ['Conta', 'account', 'text'],
                ['Documento', 'document_number', 'text'], ['Vencimento', 'due_on', 'date'], ['Vencida', 'overdue', 'bool'], ['Valor', 'amount_cents', 'money']]],
        'AP-094' => ['api' => 'closings', 'filters' => ['competences'], 'perms' => ['erp.closing.view'],
            'lead' => 'Fechamentos mensais (prestações de contas) nas competências, com o resultado e as reaberturas.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Competência', 'competence', 'text'], ['Situação', 'status', 'label:closing'], ['Receitas', 'revenue_cents', 'money'],
                ['Despesas', 'expense_cents', 'money'], ['Resultado', 'result_cents', 'signed'], ['Fechado em', 'closed_at', 'datetime'], ['Reaberto em', 'reopened_at', 'datetime']]],
        'AP-095' => ['api' => 'moves', 'filters' => ['period'], 'perms' => ['erp.moves.view'],
            'lead' => 'Mudanças agendadas no período.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Unidade', 'unit_label', 'text'], ['Morador', 'resident_name', 'text'], ['Tipo', 'kind', 'label:move'],
                ['Data', 'scheduled_on', 'date'], ['Período', 'period', 'label:shift'], ['Situação', 'status', 'label:move_status']]],
        'AP-096' => ['api' => 'addresses', 'filters' => [], 'scope' => 'all', 'perms' => ['erp.condominiums.view'],
            'lead' => 'Endereço e contato de cada condomínio do seu escopo.',
            'columns' => [['Condomínio', 'name', 'text'], ['CEP', 'zip_code', 'text'], ['Logradouro', 'street', 'text'], ['Número', 'number', 'text'], ['Complemento', 'complement', 'text'],
                ['Bairro', 'district', 'text'], ['Cidade', 'city', 'text'], ['UF', 'state', 'text'], ['Telefone', 'phone', 'text'], ['E-mail', 'email', 'text']]],
        'AP-097' => ['api' => 'logs', 'filters' => ['period', 'acao'], 'scope' => 'all', 'perms' => ['erp.audit.view'], 'personal' => true,
            'lead' => 'Registro de auditoria do ERP no período. Só aparece para quem acessa todos os condomínios.',
            'columns' => [['Data', 'created_at', 'datetime'], ['Usuário', 'user_name', 'text'], ['Ação', 'action', 'label:action'], ['Entidade', 'entity', 'text'],
                ['Registro', 'entity_id', 'text'], ['Severidade', 'severity', 'text'], ['IP', 'ip', 'text'], ['Contexto', 'context', 'text']]],
        'AP-098' => ['api' => 'access-people', 'filters' => [], 'perms' => ['erp.audit.view'], 'personal' => true,
            'lead' => 'Quem tem acesso a cada condomínio por concessão.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Nome', 'name', 'text'], ['E-mail', 'email', 'text'], ['Situação', 'status', 'text'],
                ['Concedido em', 'granted_at', 'datetime']]],
        'AP-099' => ['api' => 'sent-boletos', 'filters' => ['period'], 'perms' => ['erp.notifications.view', 'erp.bank_charges.view'],
            'lead' => 'Avisos de boleto emitido que entraram na fila de envio no período.',
            'columns' => [['Condomínio', 'condominium_name', 'text'], ['Unidade', 'unit_label', 'text'], ['Nosso número', 'our_number', 'text'], ['Valor', 'amount_cents', 'money'],
                ['Vencimento', 'due_on', 'date'], ['Situação', 'status', 'label:outbox'], ['Entrega', 'delivery_mode', 'text'], ['Destinatários', 'recipients', 'int'],
                ['Processado em', 'processed_at', 'datetime']]],
    ];

    /** Selects dos filtros: nome do parâmetro na tela => [rótulo, parâmetro da API, opções (vazio = todas)]. */
    public const SELECTS = [
        'natureza' => ['Natureza', 'nature', ['' => 'Todas', 'receita' => 'Receitas', 'despesa' => 'Despesas']],
        'situacao' => ['Situação', 'situation', ['' => 'Todas'] + ManagementReports::SITUATIONS],
        'boleto' => ['Situação', 'status', ['' => 'Todas', 'pending' => 'Pendente', 'registered' => 'Registrado', 'rejected' => 'Rejeitado', 'paid' => 'Pago', 'cancelled' => 'Cancelado']],
        'pagamento' => ['Natureza', 'nature', ['despesa' => 'Despesas', 'receita' => 'Receitas', 'all' => 'Todas']],
        'ativas' => ['Canceladas', 'active_only', ['' => 'Mostrar', '1' => 'Esconder']],
        'reserva' => ['Situação', 'status', ['' => 'Todas', 'active' => 'Aguardando aprovação', 'confirmed' => 'Confirmada', 'inactive' => 'Cancelada']],
        'vinculo' => ['Vínculo', 'relationship', ['' => 'Todos', 'owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro']],
        'ocorrencia' => ['Situação', 'status', ['' => 'Todas', 'active' => 'Aberta', 'running' => 'Em andamento', 'done' => 'Concluída']],
        'base' => ['Base', 'basis', ['competence' => 'Competência', 'cash' => 'Caixa (pagamento)']],
        'acao' => ['Ação', 'action', ['' => 'Todas', 'create' => 'Criação', 'update' => 'Alteração', 'delete' => 'Exclusão', 'export' => 'Exportação']],
    ];

    /** Rótulos dos códigos (tipo label:NOME); código sem rótulo aparece como veio. */
    public const LABELS = [
        'nature' => ['receita' => 'Receita', 'despesa' => 'Despesa'],
        'situation' => ManagementReports::SITUATIONS,
        'meter' => ['agua' => 'Água', 'gas' => 'Gás', 'energia' => 'Energia'],
        'boleto' => ['pending' => 'Pendente', 'registered' => 'Registrado', 'rejected' => 'Rejeitado', 'paid' => 'Pago', 'cancelled' => 'Cancelado'],
        'transfer' => ['active' => 'Ativa', 'cancelled' => 'Cancelada'],
        'active' => ['active' => 'Ativo', 'inactive' => 'Inativo'],
        'priority' => ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'],
        'occurrence' => ['active' => 'Aberta', 'running' => 'Em andamento', 'done' => 'Concluída'],
        'closing' => ['closed' => 'Fechado', 'reopened' => 'Reaberto'],
        'move' => ['entrada' => 'Entrada', 'saida' => 'Saída'],
        'shift' => ['manha' => 'Manhã', 'tarde' => 'Tarde'],
        'move_status' => ['active' => 'Agendada', 'done' => 'Concluída', 'inactive' => 'Cancelada'],
        'action' => ['create' => 'Criação', 'update' => 'Alteração', 'delete' => 'Exclusão', 'export' => 'Exportação'],
        'outbox' => ['pending' => 'Na fila', 'processing' => 'Enviando', 'sent' => 'Enviado', 'failed' => 'Falhou', 'cancelled' => 'Cancelado'],
    ];

    /** Tom do selo por código (o resto fica neutro). */
    public const TONES = ['paid' => 'success', 'confirmed' => 'success', 'done' => 'success', 'sent' => 'success', 'closed' => 'success', 'registered' => 'info',
        'overdue' => 'danger', 'rejected' => 'danger', 'failed' => 'danger', 'alta' => 'danger', 'cancelled' => 'plain', 'inactive' => 'plain', 'reopened' => 'warning',
        'open' => 'info', 'pending' => 'warning', 'running' => 'info'];

    /** Totais do serviço que viram cartões no topo, na ordem: chave => rótulo. */
    public const TOTALS = ['opening_cents' => 'Saldo anterior', 'revenue_cents' => 'Receitas', 'expense_cents' => 'Despesas', 'receita_cents' => 'Receitas', 'despesa_cents' => 'Despesas', 'result_cents' => 'Resultado',
        'planned_cents' => 'Previsto (receitas − despesas)', 'realized_cents' => 'Realizado (receitas − despesas)', 'in_cents' => 'Entradas', 'out_cents' => 'Saídas',
        'closing_cents' => 'Saldo final', 'principal_cents' => 'Principal', 'correction_cents' => 'Correção', 'fine_cents' => 'Multa', 'interest_cents' => 'Juros',
        'total_cents' => 'Total', 'amount_cents' => 'Valor total', 'paid_cents' => 'Valor pago', 'overdue_cents' => 'Vencido', 'fee_cents' => 'Taxas'];

    /** @return array<string, string> página => primeira permissão (o menu e a moldura usam Panel::pagePermissions) */
    public static function pagePermissions(): array
    {
        return array_map(static fn (array $page): string => $page['perms'][0], self::PAGES);
    }

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        $data = ['reportReady' => false];
        $page = self::PAGES[$id] ?? null;
        if ($page === null || Connect::databaseDisabled()) {
            return $data;
        }
        foreach ($page['perms'] as $permission) {
            if (!Access::can($permission, $user)) {
                return $data;
            }
        }
        $context = CondominiumContext::id();
        $filters = self::filters($id, $_GET, date('Y-m-d'));
        $data += ['reportPage' => $page, 'reportFilters' => $filters, 'reportError' => null, 'reportNotice' => null, 'report' => null, 'reportRows' => [],
            'reportCsv' => null, 'reportNeedsCondo' => false, 'reportOptions' => ['bank_account' => [], 'unit' => []]];
        try {
            if (!empty($page['condo']) && $context === null) {
                $data['reportNeedsCondo'] = true;
                $data['reportReady'] = true;
                return $data;
            }
            $data['reportOptions'] = $this->options($user, $page, $context);
            if (in_array('bank_account', $page['filters'], true) && $filters['conta'] === null) {
                $filters['conta'] = $data['reportOptions']['bank_account'][0]['id'] ?? null;
                $data['reportFilters'] = $filters;
            }
            if (in_array('unit', $page['filters'], true) && $id === 'AP-065' && $filters['unidade'] === null) {
                $filters['unidade'] = $data['reportOptions']['unit'][0]['id'] ?? null;
                $data['reportFilters'] = $filters;
            }
            $report = $this->run($user, $id, $context, $filters);
            $data['report'] = $report;
            $data['reportRows'] = $report['rows'] ?? [];
            if (($report['blocked'] ?? false) === true) {
                $data['reportNotice'] = (string)($report['message'] ?? '') . (($report['missing_fraction'] ?? []) !== [] ? ' Unidades sem fração: ' . implode(', ', $report['missing_fraction']) . '.' : '');
            }
            $data['reportCsv'] = self::csvUrl($id, $filters, ($page['scope'] ?? null) === 'all' ? null : $context);
        } catch (\InvalidArgumentException $error) {
            $data['reportError'] = $error->getMessage();
        } catch (RegistryForbidden) {
            $data['reportError'] = 'Este relatório exige acesso a todos os condomínios.';
        } catch (CondominiumAccessDenied | RegistryNotFound) {
            $data['reportError'] = 'Condomínio ou conta não encontrado no seu escopo.';
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'report_screen_failed', 'page' => $id]);
            return ['reportReady' => false];
        }
        $data['reportReady'] = true;
        return $data;
    }

    /** Chama o mesmo método do serviço que a API usa. */
    private function run(User $user, string $id, ?int $c, array $f): array
    {
        $m = fn (): ManagementReports => new ManagementReports();
        $r = fn (): RegistryReports => new RegistryReports();
        $p = fn (): PaymentReports => new PaymentReports();
        $view = self::PAGES[$id]['query']['view'] ?? '';
        return match ($id) {
            'AP-045', 'AP-046' => $m()->consumption($user, $c, $f['de'], $f['ate'], $id === 'AP-046'),
            'AP-049' => $m()->costCenters($user, $c, $f['de'], $f['ate']),
            'AP-050' => $m()->comparison($user, $c, $f['de'], $f['ate'], $f['de_b'], $f['ate_b']),
            'AP-052', 'AP-053', 'AP-054' => $m()->statement($user, $c, $f['de'], $f['ate'], $view),
            'AP-056' => $f['conta'] === null ? throw new \InvalidArgumentException('Cadastre uma conta bancária para ver o extrato.') : $m()->accountStatement($user, $f['conta'], $f['de'], $f['ate']),
            'AP-062', 'AP-063', 'AP-064', 'AP-065', 'AP-066', 'AP-067' => $m()->apportionment($user, (int)$c, $f['competencia'], $view, $f['comparar'], $f['unidade']),
            'AP-068', 'AP-069' => $m()->entries($user, $c, $f['de'], $f['ate'], ['nature' => $f['natureza'], 'situation' => $f['situacao']], $id === 'AP-069'),
            'AP-070' => $m()->bankCharges($user, $c, $f['de'], $f['ate'], $f['boleto'] ?: null),
            'AP-074' => $p()->payments($user, $c, $f['de'], $f['ate'], $f['pagamento'] ?: 'despesa'),
            'AP-075' => $p()->calculation($user, (int)$c, $f['unidade'], $f['referencia']),
            'AP-076' => $p()->transfers($user, $c, $f['de'], $f['ate'], $f['ativas'] !== '1'),
            'AP-080' => (new AnnualBalance())->report($user, $c, $f['ano'], $f['base']),
            'AP-085' => $r()->reservations($user, $c, $f['de'], $f['ate'], $f['reserva'] ?: null),
            'AP-086' => $r()->units($user, $c),
            'AP-087' => $r()->people($user, $c, $f['vinculo'] ?: null),
            'AP-089' => $r()->condominiums($user),
            'AP-090' => $r()->occurrences($user, $c, $f['de'], $f['ate'], $f['ocorrencia'] ?: null),
            'AP-093' => $r()->pendingPayments($user, $c, $f['limite']),
            'AP-094' => $r()->closings($user, $c, $f['de'], $f['ate']),
            'AP-095' => $r()->moves($user, $c, $f['de'], $f['ate']),
            'AP-096' => $r()->addresses($user),
            'AP-097' => $r()->logs($user, $f['de'], $f['ate'], null, $f['acao'] ?: null),
            'AP-098' => $r()->accessPeople($user, $c),
            'AP-099' => $r()->sentBoletos($user, $c, $f['de'], $f['ate']),
        };
    }

    /** @return array{bank_account: list<array{id:int,label:string}>, unit: list<array{id:int,label:string}>} opções dos filtros no condomínio em contexto */
    private function options(User $user, array $page, ?int $context): array
    {
        $options = ['bank_account' => [], 'unit' => []];
        $registry = new RegistryService();
        if (in_array('bank_account', $page['filters'], true)) {
            $list = $registry->list($user, 'bank_accounts', $context !== null ? ['condominium_id' => $context] : []);
            foreach ($list['data'] ?? [] as $row) {
                if (($row['status'] ?? 'active') === 'active') {
                    $options['bank_account'][] = ['id' => (int)$row['id'], 'label' => trim((string)($row['name'] ?? ('Conta ' . $row['id'])))];
                }
            }
        }
        if (in_array('unit', $page['filters'], true)) {
            foreach ($registry->units($user) as $unit) {
                if ($context === null || $unit['condominium_id'] === $context) {
                    $options['unit'][] = ['id' => $unit['id'], 'label' => $unit['label']];
                }
            }
        }
        return $options;
    }

    /**
     * Filtros da tela (regra pura), com padrões: datas de 1º de janeiro até hoje, competências de janeiro até
     * o mês atual, a competência do mês atual (comparada com a anterior), a data limite em hoje + 30 dias e a
     * referência em hoje. Valor inválido volta ao padrão; os selects só aceitam as opções de SELECTS.
     *
     * @return array<string, mixed>
     */
    public static function filters(string $id, array $query, string $today): array
    {
        $month = substr($today, 0, 7);
        $year = substr($today, 0, 4);
        $previous = (new \DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
        $page = self::PAGES[$id] ?? ['filters' => []];
        $competences = array_intersect(['competences', 'compare'], $page['filters']) !== [];
        $f = [
            'de' => $competences ? (self::month($query['de'] ?? null) ?? $year . '-01') : (self::date($query['de'] ?? null) ?? $year . '-01-01'),
            'ate' => $competences ? (self::month($query['ate'] ?? null) ?? $month) : (self::date($query['ate'] ?? null) ?? $today),
            'de_b' => self::month($query['de_b'] ?? null) ?? ((int)$year - 1) . '-01',
            'ate_b' => self::month($query['ate_b'] ?? null) ?? ((int)$year - 1) . substr($month, 4),
            'competencia' => self::month($query['competencia'] ?? null) ?? $month,
            'comparar' => in_array('compare_with', $page['filters'], true) ? (self::month($query['comparar'] ?? null) ?? $previous) : null,
            'limite' => self::date($query['limite'] ?? null) ?? date('Y-m-d', (int)strtotime($today . ' +30 days')),
            'referencia' => self::date($query['referencia'] ?? null) ?? $today,
            'conta' => self::id($query['conta'] ?? null),
            'unidade' => self::id($query['unidade'] ?? null),
            'ano' => preg_match('/^20\d{2}$/', (string)(is_string($query['ano'] ?? null) ? $query['ano'] : '')) ? (int)$query['ano'] : (int)$year,
        ];
        foreach (self::SELECTS as $name => [, , $options]) {
            $value = $query[$name] ?? '';
            $f[$name] = is_string($value) && isset($options[$value]) ? $value : (string)array_key_first($options);
        }
        return $f;
    }

    /** Endereço do CSV na API (regra pura): a mesma consulta da tela, com format=csv e o condomínio em contexto. */
    public static function csvUrl(string $id, array $f, ?int $context): string
    {
        $page = self::PAGES[$id];
        $query = ['format' => 'csv'];
        if ($context !== null) {
            $query['condominium_id'] = $context;
        }
        $query += $page['query'] ?? [];
        foreach ($page['filters'] as $filter) {
            $query += match ($filter) {
                'period', 'competences' => ['from' => $f['de'], 'to' => $f['ate']],
                'compare' => ['from_a' => $f['de'], 'to_a' => $f['ate'], 'from_b' => $f['de_b'], 'to_b' => $f['ate_b']],
                'competence' => ['competence' => $f['competencia']],
                'compare_with' => ['compare_with' => $f['comparar']],
                'until' => ['until' => $f['limite']],
                'as_of' => ['as_of' => $f['referencia']],
                'year' => ['year' => $f['ano']],
                'bank_account' => $f['conta'] !== null ? ['bank_account_id' => $f['conta']] : [],
                'unit' => $f['unidade'] !== null ? ['unit_id' => $f['unidade']] : [],
                default => isset(self::SELECTS[$filter]) && $f[$filter] !== '' ? [self::SELECTS[$filter][1] => $f[$filter]] : [],
            };
        }
        return '/api/v1/reports/' . $page['api'] . '?' . http_build_query($query);
    }

    /**
     * Cartões de total (regra pura): os totais em centavos que o serviço devolve, na ordem de TOTALS.
     *
     * @return list<array{0:string, 1:int}>
     */
    public static function totals(?array $report): array
    {
        $totals = (array)($report['totals'] ?? []) + (isset($report['opening_cents']) ? ['opening_cents' => $report['opening_cents']] : []);
        $out = [];
        foreach (self::TOTALS as $key => $label) {
            if (isset($totals[$key]) && is_int($totals[$key])) {
                $out[] = [$label, $totals[$key]];
            }
        }
        return $out;
    }

    /**
     * Célula formatada para HTML (regra pura, já escapada): money e signed em R$, date, datetime, int, days,
     * number (vírgula decimal), percent, bool (Sim/Não) e label:NOME (selo com o rótulo de LABELS).
     */
    public static function cell(array $row, array $column): string
    {
        [, $field, $type] = $column;
        $value = self::value($row, $field);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            return '<span class="erp-muted">—</span>';
        }
        if (str_starts_with($type, 'label:')) {
            $code = (string)$value;
            $label = self::LABELS[substr($type, 6)][$code] ?? $code;
            return '<span class="erp-badge erp-badge--' . (self::TONES[$code] ?? 'plain') . '">' . $e($label) . '</span>';
        }
        return match ($type) {
            'money', 'signed' => $e(self::brl((int)$value)),
            'date' => $e(self::brDate((string)$value)),
            'datetime' => $e(self::brDate((string)$value, true)),
            'int' => (string)(int)$value,
            'days' => (int)$value . ' dia(s)',
            'number' => $e(str_replace('.', ',', (string)$value)),
            'percent' => $e(number_format((float)$value, 2, ',', '.') . '%'),
            'bool' => $value ? 'Sim' : 'Não',
            'tree' => '<span class="erp-report-account" style="--erp-level: ' . max(0, (int)($row['level'] ?? 1) - 1) . '">' . $e((string)$value) . '</span>',
            default => $e(is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value),
        };
    }

    /** Valor de um caminho "a.b" no registro (regra pura). */
    public static function value(array $row, string $path): mixed
    {
        return CollectionReportScreens::value($row, $path);
    }

    /** Classe da linha (regra pura): conta sintética em negrito; cancelado/inativo esmaecido. */
    public static function rowClass(array $row): string
    {
        return match (true) {
            ($row['analytic'] ?? true) === false => 'erp-report-synthetic',
            in_array($row['status'] ?? $row['situation'] ?? null, ['cancelled', 'inactive'], true) => 'erp-muted',
            default => '',
        };
    }

    /** Tipos alinhados à direita. */
    public static function numeric(string $type): bool
    {
        return in_array($type, ['money', 'signed', 'int', 'days', 'number', 'percent'], true);
    }

    public static function brl(int $cents): string
    {
        return ($cents < 0 ? '−R$ ' : 'R$ ') . number_format(abs($cents) / 100, 2, ',', '.');
    }

    /** AAAA-MM-DD[ HH:MM...] → DD/MM/AAAA[ HH:MM] (regra pura); outro texto volta como veio. */
    public static function brDate(string $value, bool $time = false): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}))?/', $value, $m)) {
            return $value;
        }
        return "{$m[3]}/{$m[2]}/{$m[1]}" . ($time && isset($m[4]) ? ' ' . $m[4] : '');
    }

    /** Data AAAA-MM-DD válida ou null (regra pura). */
    public static function date(mixed $value): ?string
    {
        return CollectionReportScreens::date($value);
    }

    /** Mês AAAA-MM válido ou null (regra pura). */
    public static function month(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : null;
    }

    private static function id(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : (int)$id;
    }
}
