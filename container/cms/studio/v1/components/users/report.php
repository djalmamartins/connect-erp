<?php

use Dompdf\Dompdf;

$dompdf = new Dompdf(["enable_remote" => true]);

ob_start();
require __DIR__ . "/pages/listUsers.php";

$dompdf->loadHtml(ob_get_clean());

$dompdf->setPaper("A4");

$dompdf->render();
$dompdf->stream("certificado.pdf", ["Attachment" => false]);