<?php

require dirname(__DIR__, 2) . "/vendor/autoload.php";

use Source\Models\Certificate\AppCertificate;
$email = new \Source\Support\Email();
$view = new \Source\Core\View(dirname(__DIR__, 2) . "/container/mail/default");

$certificado = (new AppCertificate())->find("status = :status AND next_due = date(NOW()) AND last_charge != DATE(NOW())",
    "status=active")->fetch(true);

    if ($certificado) {
        foreach ($certificado as $subscribe) {
            $subscribe->status = "inactive";
            $subscribe->save();
        }
    }

$certificate = (new AppCertificate())->find("status = :status AND next_due = date(NOW()) AND last_charge != DATE(NOW())",
    "status=inactive")->fetch(true);

if ($certificate) {
    foreach ($certificate as $subscribes) {
            $user = (new \Source\Models\User())->findById($subscribes->user_id);
            $users = $subscribes->user_id;
            $loft = (new \Source\Models\Loft\AppLoft())->findById($subscribes->loft_id);
            $lofts = $subscribes->loft_id;

            $year = date('Y');
            list($d, $m, $y) = explode("/", date('d/m/Y', strtotime('+365 days')));
            $subscribes = (new AppCertificate());
            $subscribes->user_id = $users;
            $subscribes->loft_id = $lofts;
            $subscribes->register = $year . str_pad($loft->id, 4, '0', STR_PAD_LEFT) . str_pad($users, 4, '0', STR_PAD_LEFT);
            $subscribes->certificate_name = "{$loft->loft_name}";
            $subscribes->status = "active";
            $subscribes->started = date('Y-m-d');
            $subscribes->next_due = "{$y}-{$m}-{$d}";
            $subscribes->last_charge = date('Y-m-d');

            $subject = "Certificado CBC Renovado";
            $body = $view->render("mail", [
                "subject" => $subject,
                "message" => "<h2>Obrigado {$user->first_name}!</h2><p>Estamos passando apenas para agradecer por você ser um membro da CBC.</p>
                    <p>Seu certificado venceu hoje e já está renovado e disponivel para ser impresso. <br>Qualquer dúvida estamos a disposição.</p>"
            ]);

            $email->bootstrap(
                $subject,
                $body,
                $user->email,
                "{$user->first_name}"
            )->queue();

        $subscribes->save();
    }
}
