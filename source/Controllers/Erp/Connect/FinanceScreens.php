<?php

declare(strict_types=1);

namespace Source\Controllers\Erp\Connect;

use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\BillingRules;
use Source\Services\Erp\FinancialReports;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\MonthClosing;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas do financeiro com dados reais que eram montadas dentro do Panel: fechamento mensal (AP-030),
 * faturas (AP-032), prestação de contas (AP-079 e AP-051), régua de cobrança (AP-165), inadimplência e
 * acordos (AP-033/034) e cobrança bancária (AP-035 a AP-038). Cada bloco devolve null sem permissão ou
 * sem banco, e a tela segue com a demonstração. As ações usam a API /api/v1.
 *
 * O Panel::pageView() chama data() quando a página está em PAGES (a mesma regra das outras *Screens).
 */
final class FinanceScreens
{
    /** Página => carregador dos dados reais. */
    public const PAGES = [
        'AP-030' => 'closingData',
        'AP-032' => 'invoiceData',
        'AP-079' => 'reportData',
        'AP-051' => 'reportData',
        'AP-165' => 'billingData',
        'AP-033' => 'agreementData',
        'AP-034' => 'agreementData',
        'AP-035' => 'bankingData',
        'AP-036' => 'bankingData',
        'AP-037' => 'bankingData',
        'AP-038' => 'bankingData',
    ];

    /** @return array<string, mixed> */
    public function data(User $user, string $id): array
    {
        return match (self::PAGES[$id] ?? null) {
            'closingData' => $this->closingData($user),
            'invoiceData' => $this->invoiceData($user),
            'reportData' => $this->reportData($user, $id),
            'billingData' => $this->billingData($user),
            'agreementData' => $this->agreementData($user, $id),
            'bankingData' => $this->bankingData($user, $id),
            default => [],
        };
    }

    /** Páginas da prestação de contas com dados reais (ADR 0037): balancete e demonstrativo. */
    public const REPORT_PAGES = ['AP-079', 'AP-051'];

    /**
     * Dados reais do fechamento mensal (AP-030, ADR 0032) no contexto da barra superior: o
     * condomínio escolhido ou, em "Todos", cada condomínio do escopo. A tela lê $closing
     * (null = sem permissão ou banco indisponível, e a tela mostra a demonstração) e
     * $canManageClosing para mostrar "Fechar" e "Reabrir" (só com um condomínio escolhido).
     *
     * @return array{closing: ?array, closingCompetence: string, canManageClosing: bool}
     */
    private function closingData(User $user): array
    {
        $competence = MonthClosing::competenceOrCurrent($_GET['competencia'] ?? null);
        $data = ['closing' => null, 'closingCompetence' => $competence, 'canManageClosing' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.closing.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        try {
            $data['closing'] = (new MonthClosing())->overview($user, $context, $competence);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'month_closing_overview_failed', 'condominium_id' => $context]);
            return $data;
        }
        $data['canManageClosing'] = $context !== null && Access::can('erp.closing.manage', $user);
        return $data;
    }

    /**
     * Faturas reais (AP-032, ADR 0033) no contexto da barra superior ("Todos" = escopo do
     * usuário), filtradas por ?competencia= e ?situacao=. $invoices null = sem permissão ou sem
     * banco (a tela segue com a demonstração). As ações usam a API /api/v1/invoices.
     *
     * @return array{invoices: ?array, canManageInvoices: bool}
     */
    private function invoiceData(User $user): array
    {
        $data = ['invoices' => null, 'canManageInvoices' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.invoices.view', $user)) {
            return $data;
        }
        try {
            $data['invoices'] = (new Invoices())->list($user, CondominiumContext::id(), [
                'competence' => $_GET['competencia'] ?? null, 'status' => $_GET['situacao'] ?? null, 'page' => $_GET['pagina'] ?? 1,
            ]);
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'invoice_list_failed']);
            return $data;
        }
        $data['canManageInvoices'] = Access::can('erp.invoices.manage', $user);
        $data['canManageCharges'] = Access::can('erp.bank_charges.view', $user) && Access::can('erp.bank_charges.manage', $user);
        return $data;
    }

    /**
     * Relatórios financeiros reais (ADR 0037) no contexto da barra superior ("Todos" = escopo):
     *  - AP-079 balancete de verificação: ?de=AAAA-MM&ate=AAAA-MM (padrão: mês atual) → $trialBalance;
     *  - AP-051 demonstrativo (prestação de contas por caixa, com conciliação bancária):
     *    ?de=AAAA-MM-DD&ate=AAAA-MM-DD (padrão: do dia 1 até hoje) → $statement.
     * null = sem permissão, sem banco ou período inválido ($reportError diz qual).
     *
     * @return array{trialBalance?: ?array, statement?: ?array, reportError: ?string, reportPeriod: array{from:string, to:string}}
     */
    private function reportData(User $user, string $id): array
    {
        $trial = $id === 'AP-079';
        $from = is_string($_GET['de'] ?? null) ? $_GET['de'] : ($trial ? date('Y-m') : date('Y-m-01'));
        $to = is_string($_GET['ate'] ?? null) ? $_GET['ate'] : ($trial ? date('Y-m') : date('Y-m-d'));
        $key = $trial ? 'trialBalance' : 'statement';
        $data = [$key => null, 'reportError' => null, 'reportPeriod' => ['from' => mb_substr($from, 0, 10), 'to' => mb_substr($to, 0, 10)]];
        if (Connect::databaseDisabled() || !Access::can('erp.entries.view', $user) || !Access::can('erp.chart.view', $user)) {
            return $data;
        }
        try {
            $reports = new FinancialReports();
            $data[$key] = $trial ? $reports->trialBalance($user, CondominiumContext::id(), $from, $to) : $reports->statement($user, CondominiumContext::id(), $from, $to);
        } catch (\InvalidArgumentException $error) {
            $data['reportError'] = $error->getMessage();
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'financial_report_failed', 'page' => $id]);
            $data['reportError'] = 'Não foi possível gerar o relatório agora.';
        }
        return $data;
    }

    /**
     * Regra de cobrança e índices (AP-165, ADR 0040): regra que vale para o condomínio da barra
     * superior ("Todos" = padrão da Connect) e os meses do IPCA. null = sem permissão ou sem banco.
     *
     * @return array{billingRule: ?array, priceIndexes: ?array, canManageBilling: bool}
     */
    private function billingData(User $user): array
    {
        $data = ['billingRule' => null, 'priceIndexes' => null, 'canManageBilling' => false];
        if (Connect::databaseDisabled() || !Access::can('erp.billing_rules.view', $user)) {
            return $data;
        }
        try {
            $service = new BillingRules();
            $data['billingRule'] = $service->ruleFor($user, CondominiumContext::id());
            $data['priceIndexes'] = $service->listRates('IPCA');
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'billing_rules_screen_failed']);
            return ['billingRule' => null, 'priceIndexes' => null, 'canManageBilling' => false];
        }
        $data['canManageBilling'] = Access::can('erp.billing_rules.manage', $user);
        return $data;
    }

    /**
     * Inadimplência e acordos (AP-033/034, ADR 0039) no contexto da barra superior. AP-033: contas
     * vencidas por unidade ($delinquency) e a lista de acordos ($agreements, ?situacao=&pagina=).
     * AP-034: parcelas dos acordos ativos da página ($agreementParcels). null = sem permissão ou
     * sem banco (a tela segue com a demonstração). As ações usam a API /api/v1/agreements.
     *
     * @return array<string, mixed>
     */
    private function agreementData(User $user, string $id): array
    {
        $data = ['canManageAgreements' => false] + ($id === 'AP-033' ? ['delinquency' => null, 'agreements' => null] : ['agreements' => null, 'agreementParcels' => null]);
        if (Connect::databaseDisabled() || !Access::can('erp.agreements.view', $user)) {
            return $data;
        }
        $context = CondominiumContext::id();
        $service = new Agreements();
        try {
            if ($id === 'AP-033') {
                $data['delinquency'] = $service->delinquency($user, $context);
                $data['agreements'] = $service->list($user, $context, ['status' => $_GET['situacao'] ?? null, 'page' => $_GET['pagina'] ?? 1]);
            } else {
                $data['agreements'] = $service->list($user, $context, ['status' => 'active', 'page' => $_GET['pagina'] ?? 1]);
                $data['agreementParcels'] = self::flattenParcels(array_map(static fn (array $a): array => $service->find($user, (int)$a['id']), $data['agreements']['data']), date('Y-m-d'));
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'agreements_screen_failed', 'page' => $id]);
            return ['canManageAgreements' => false] + array_map(static fn (): mixed => null, array_diff_key($data, ['canManageAgreements' => 1]));
        }
        $data['canManageAgreements'] = Access::can('erp.agreements.manage', $user);
        return $data;
    }

    /**
     * Parcelas de vários acordos numa lista só (regra pura), com a situação de cada uma na data:
     * paga, vencida (em aberto e vencimento antes da data) ou a vencer.
     *
     * @param list<array> $agreements acordos de Agreements::find (com 'parcels')
     * @return list<array>
     */
    public static function flattenParcels(array $agreements, string $today): array
    {
        $rows = [];
        foreach ($agreements as $agreement) {
            foreach ($agreement['parcels'] ?? [] as $parcel) {
                $state = $parcel['paid_on'] !== null ? 'paid' : ((string)$parcel['due_on'] < $today ? 'late' : 'open');
                $rows[] = $parcel + ['agreement_id' => (int)$agreement['id'], 'installments' => (int)$agreement['installments'],
                    'unit_label' => $agreement['unit_label'] ?? null, 'condominium_name' => $agreement['condominium_name'] ?? null, 'state' => $state];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['state'] === 'paid', $a['due_on'], $a['agreement_id'], $a['number']] <=> [$b['state'] === 'paid', $b['due_on'], $b['agreement_id'], $b['number']]);
        return $rows;
    }

    /**
     * Cobrança bancária (AP-035 boletos, AP-036 importar retorno, AP-037 remessas, AP-038 retornos com
     * divergência; ADR 0035, 0038 e 0156), sandbox interno ou Inter (sandbox do banco). $bankCharges / $bankDivergences null = sem
     * permissão ou sem banco (a tela segue com a demonstração). As ações usam a API /api/v1.
     *
     * @return array{bankCharges?: ?array, bankDivergences?: ?array, bankReady: bool, canManageCharges: bool}
     */
    private function bankingData(User $user, string $id): array
    {
        $data = ['bankReady' => false, 'canManageCharges' => false] + ($id === 'AP-035' ? ['bankCharges' => null] : []) + ($id === 'AP-038' ? ['bankDivergences' => null, 'bankReturnFiles' => null] : [])
            + ($id === 'AP-037' ? ['bankRemittances' => null] : []);
        if (Connect::databaseDisabled() || !Access::can('erp.bank_charges.view', $user)) {
            return $data;
        }
        try {
            if ($id === 'AP-035') {
                $data['bankCharges'] = (new BankCharges())->list($user, CondominiumContext::id(), ['status' => $_GET['situacao'] ?? null, 'q' => $_GET['busca'] ?? null, 'page' => $_GET['pagina'] ?? 1]);
            } elseif ($id === 'AP-037') {
                $data['bankRemittances'] = (new \Source\Services\Erp\Banking\BankRemittances())->list($user, CondominiumContext::id(), [
                    'from' => $_GET['de'] ?? null, 'to' => $_GET['ate'] ?? null, 'status' => $_GET['situacao'] ?? null, 'page' => $_GET['pagina'] ?? 1,
                ]);
            } elseif ($id === 'AP-038') {
                $returns = new BankReturns();
                $data['bankDivergences'] = $returns->divergences($user, CondominiumContext::id());
                $data['bankReturnFiles'] = $returns->files($user, CondominiumContext::id(), ['page' => $_GET['pagina'] ?? 1]);
            }
        } catch (\Throwable $error) {
            \Source\Support\AppLogger::exception($error, 'erp', ['event_type' => 'banking_screen_failed', 'page' => $id]);
            return $data;
        }
        $data['bankReady'] = true;
        $data['canManageCharges'] = Access::can('erp.bank_charges.manage', $user);
        return $data;
    }
}
