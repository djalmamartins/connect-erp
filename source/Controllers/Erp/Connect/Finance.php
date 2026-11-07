<?php
namespace Source\Controllers\Erp\Connect;

use IntlDateFormatter;
use Source\Models\Corporation\AppCondominium;
use Source\Models\Erp\AppInvoice;
use Source\Controllers\App\V1\App;

/**
 * ERP | Class Finance
 *
 * @author Djalma Martins
 * @package Source\App\Erp\Connect
 */
class Finance extends Erp
{
    /**
     * Finance constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function dash(?array $data): void
    {
        redirect("/erp/finance/home");
    }

    public function home(?array $data): void
    {

        $head = $this->seo->render(
            CONF_SITE_NAME . " | Financeiro",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        //CHART
        $dateChart = [];
        for ($month = -11; $month <= 0; $month++){
            $dateChart[] = date("m/y", strtotime("{$month}month"));
        }

        $chartData = new \stdClass();
        $chartData->categories = "'" . implode("','", $dateChart) . "'";
        $chartData->expense = "0,0,0,0,0,0,0,0,0,0,0,0";
        $chartData->income = "0,0,0,0,0,0,0,0,0,0,0,0";
        $chartData->owing = "0,0,0,0,0,0,0,0,0,0,0,0";

        $chart = (new AppInvoice())
            ->find("condominium_id = :condo AND due_at >= DATE(now() - INTERVAL 11 MONTH) GROUP BY year(due_at) ASC, month(due_at) ASC", "condo={$this->condo->id}",
            "

            year(due_at) AS due_year,
            month(due_at) AS due_month,
            DATE_FORMAT(due_at, '%m/%Y') AS due_date,
            (SELECT SUM(value) FROM app_invoices WHERE condominium_id = :condo AND status = 'paid' AND type = 'income' AND  year(due_at) = due_year AND month(due_at) = due_month) AS income,
            (SELECT SUM(value) FROM app_invoices WHERE condominium_id = :condo AND status = 'paid' AND type = 'expense' AND  year(due_at) = due_year AND month(due_at) = due_month) AS expense,
            (SELECT SUM(value) FROM app_invoices WHERE condominium_id = :condo AND status = 'unpaid' AND type = 'income' AND year(due_at) = due_year AND month(due_at) = due_month) AS owing,
            (SELECT SUM(value) FROM app_invoices WHERE condominium_id = :condo AND status = 'unpaid' AND type = 'income' AND  year(due_at) = due_year AND month(due_at) = due_month) AS receive

            "
            )
            ->limit(12)
            ->fetch( true);

        if($chart){
            $chartCategories = [];
            $chartExpense = [];
            $chartIncome = [];
            $chartOwing = [];
            $chartReceive = [];

            foreach ($chart as $chartItem) {
                $chartCategories[] = $chartItem->due_date;
                $chartExpense[] = $chartItem->expense;
                $chartIncome[] = $chartItem->income;
                $chartOwing[] = $chartItem->owing;
                $chartReceive[] = $chartItem->receive;
            }

            $chartData->categories = "'" . implode("','", $chartCategories) . "'";
            $chartData->expense = implode(",", array_map("abs", $chartExpense));
            $chartData->income = implode(",", array_map("abs", $chartIncome));
            $chartData->owing = implode(",", array_map("abs", $chartOwing));
            $chartData->receive = implode(",", array_map("abs", $chartReceive));
        }

//        var_dump($chartCategories, $chartExpense,$chartIncome, $chartOwing);
        //END CHART

        //INCOME && EXPENSE

        $income = (new AppInvoice())
            ->find("condominium_id = :condo AND status = 'unpaid' AND type = 'income' AND date(due_at) <= date(now() + INTERVAL 1 MONTH)", "condo={$this->condo->id}")
            ->order("due_at")
            ->fetch(true);

        $expense = (new AppInvoice())
            ->find("condominium_id = :condo AND status = 'unpaid' AND type = 'expense' AND date(due_at) <= date(now() + INTERVAL 1 MONTH)", "condo={$this->condo->id}")
            ->order("due_at")
            ->fetch(true);

        //END INCOME && EXPENSE

        //COUNT

        $count = (new AppInvoice())
            ->find("condominium_id = :condo AND YEAR(due_at) = YEAR(NOW()) AND MONTH(due_at) = MONTH(NOW())", "condo={$this->condo->id}",
                "
                (SELECT SUM(value) FROM app_invoices WHERE condominium_id = :condo AND status = 'paid' AND type = 'income' AND YEAR(due_at) = YEAR(NOW()) AND MONTH(due_at) = MONTH(NOW())) AS cIncome,
                (SELECT SUM(value) FROM app_invoices WHERE condominium_id = :condo AND status = 'paid' AND type = 'expense' AND YEAR(due_at) = YEAR(NOW()) AND MONTH(due_at) = MONTH(NOW())) AS cExpense,
                (SELECT SUM(value) FROM app_invoices WHERE condominium_id = :condo AND type = 'expense' AND YEAR(due_at) = YEAR(NOW()) AND MONTH(due_at) = MONTH(NOW())) AS ctExpense,
                (SELECT SUM(value) FROM app_invoices WHERE condominium_id = :condo AND status = 'unpaid' AND type = 'income' AND YEAR(due_at) = YEAR(NOW()) AND MONTH(due_at) = MONTH(NOW())) AS cReceive
                "
            )->fetch();

        //END COUNT

        $wallet = (new AppInvoice())->find("condominium_id = :condo", "condo={$this->condo->id}",
        "
                (SELECT SUM(value) FROM app_invoices WHERE condominium_id = :condo AND status = 'paid' AND type = 'income') AS income,
                (SELECT SUM(value) FROM app_invoices WHERE condominium_id = :condo AND status = 'paid' AND type = 'expense') AS expense,
                (SELECT SUM(value) FROM app_invoices WHERE condominium_id = :condo AND status = 'unpaid' AND type = 'income' AND due_at <= CURDATE()) AS owing
        ")->fetch();

        if($wallet){
            $wallet->wallet = $wallet->income - $wallet->expense;
        }

        echo $this->view->render("components/finance/home", [
            "app" => "finance/home",
            "head" => $head,
            "chart" => $chartData,
            "income" => $income,
            "expense" => $expense,
            "wallet" => $wallet,

            "count" => (object)[
                "income" => $count->cIncome,
                "ctExpense" => $count->ctExpense,
                "cfExpense" => $count->ctExpense - $count->cExpense,
                "cReceive" => $count->cReceive,
                "cash" => $count->cIncome - $count->cExpense,
            ],

            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],
        ]);
    }

    public function income(?array $data): void
    {
        $head = $this->seo->render(
            CONF_SITE_NAME . " | Financeiro",
            CONF_SITE_DESC,
            url("/erp"),
            url("/erp/assets/images/image.jpg"),
            false
        );

        echo $this->view->render("components/finance/invoices", [
            "app" => "finance/income",
            "head" => $head,
            "search" => null,


            "condo" => (object)[
                "select" => $this->condo,
                "list" => (new AppCondominium())->find()->fetch(true),
            ],
        ]);
    }



//    public function home(?array $data): void
//    {
//        $head = $this->seo->render(
//            CONF_SITE_NAME . " | Usuários",
//            CONF_SITE_DESC,
//            url("/erp"),
//            url("/erp/assets/images/image.jpg"),
//            false
//        );
//
//        echo $this->view->render("components/users/home", [
//            "app" => "users/home",
//            "head" => $head,
//            "search" => $search,
//            "users" => $users->order("id DESC")->limit($pager->limit())->offset($pager->offset())->fetch(true),
//            "paginator" => $pager->render(),
//
//            "user" => $this->user,
//
//        ]);
//    }
}