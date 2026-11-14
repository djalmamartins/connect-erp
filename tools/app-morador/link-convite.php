<?php

declare(strict_types=1);

/*
 * Link do convite do morador para testar o app no laboratório (ADR 0303).
 * No lab os e-mails não saem (fila em modo sandbox/log), então o convite feito em
 * Configurações > Acesso > Criação de usuários para moradores não chega a ninguém.
 * Este comando pega o convite pendente mais recente (ou o id informado), gera um token
 * novo pelo mesmo serviço da entrega (ResidentInvitations::issueToken, que invalida o
 * anterior) e mostra o link. Recusa rodar em produção.
 *
 *   docker compose exec app php tools/app-morador/link-convite.php [id-do-convite]
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Source\Core\Connect;
use Source\Services\Erp\ResidentInvitations;

if (CONF_APP_ENV === 'production') {
    fwrite(STDERR, "Este comando é só para o laboratório (APP_ENV=production).\n");
    exit(1);
}

$pdo = Connect::getInstance();
$id = isset($argv[1]) ? (int)$argv[1] : 0;
$sql = "SELECT i.id, i.expires_at, r.name, c.name AS condominium
          FROM erp_resident_invitations i
          JOIN erp_residents r ON r.id = i.resident_id
          JOIN condominiums c ON c.id = i.condominium_id
         WHERE i.status = 'pending' AND i.expires_at > NOW()" . ($id > 0 ? ' AND i.id = :id' : '') . '
         ORDER BY i.id DESC LIMIT 1';
$stmt = $pdo->prepare($sql);
$stmt->execute($id > 0 ? ['id' => $id] : []);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "Nenhum convite pendente. Convide o morador em Configurações > Acesso > Criação de usuários para moradores e rode de novo.\n");
    exit(1);
}

$token = (new ResidentInvitations($pdo))->issueToken((int)$row['id']);
if ($token === null) {
    fwrite(STDERR, "O convite {$row['id']} venceu, foi revogado ou já foi aceito.\n");
    exit(1);
}

$host = getenv('CONNECT_ERP_HOST') ?: 'erp.connect.lab';
echo "Convite {$row['id']}: {$row['name']} ({$row['condominium']}), vale até {$row['expires_at']}.\n";
echo "Abra no navegador do Mac para criar a senha:\n\n";
echo "  https://{$host}/convite#{$token}\n\n";
echo "Esse link vale uma vez. Se rodar o comando de novo, o link anterior deixa de valer.\n";
