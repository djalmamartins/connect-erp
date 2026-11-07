<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/users/sidebar.php"); ?>

<section class="dash_content_app">

        <?php $dataPrint = (!empty($filter->date) ? $filter->date : date("m/Y"));
            $dataP =  explode("/", $dataPrint);
            $print = $dataP[0]."-".$dataP[1];
        ?>

        <header class="dash_content_app_header">
            <h2 class="icon-print">Imprimir</h2>
            <a class="icon-print btn btn-blue" target="_blank" href="<?= url($adminBase . "/users/report/{$print}"); ?>">Imprimir relatório</a>
        </header>

        <div class="dash_content_app_box_print">
            <form class="app_launch_form_filter" action="<?= url($adminBase . "/users/filter"); ?>" method="post">
                 <input list="datelist" type="text" class="radius mask-month" name="date"
                       placeholder="<?= (!empty($filter->date) ? $filter->date : date("m/Y")); ?>">
                <datalist id="datelist">
                    <?php for ($range = -3; $range <= 0; $range++):
                        $dateRange = date("m/Y", strtotime(date("Y-m-01") . "+{$range}month"));
                        ?>
                        <option value="<?= $dateRange; ?>"/>
                    <?php endfor; ?>
                </datalist>

                <button class="btn btn-green radius transition icon-filter icon-notext"></button>
            </form>
            <?php $v->insert("components/users/list.php"); ?>
        </div>
</section>