<?php

declare(strict_types=1);

/**
 * Dados fictícios para o teste de carga (ADR 0611). Copia um condomínio de exemplo (padrão: o Lafayette de
 * tools/lab-lafayette.php) N vezes, com ids deslocados de 1.000.000 em 1.000.000, e cria uma conta de morador
 * (moradorN@carga.lab, senha Carga@2026) para cada morador ativo das cópias.
 *
 *   MOVESOS_ENV=lab MOVESOS_DB_NAME=connect_lab_carga ... php tools/hosting/carga/clonar-condominio.php \
 *       --origem=3 --copias=625 [--de=1] [--moradores=10000]
 *
 * Só roda num banco com "carga" no nome e fora da produção: o banco do laboratório e o de produção ficam de fora.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$environment = getenv('MOVESOS_ENV') ?: (getenv('APP_ENV') ?: 'production');
$pdo = \Source\Core\Connect::getInstance();
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($environment === 'production' || !str_contains($database, 'carga')) {
    fwrite(STDERR, "Recusado: use um banco só de teste, com \"carga\" no nome, fora da produção (banco atual: {$database}).\n");
    exit(1);
}
$options = getopt('', ['origem::', 'copias::', 'de::', 'moradores::']);
$src = (int)($options['origem'] ?? 3);
$n = (int)($options['copias'] ?? 187);
$from = max(1, (int)($options['de'] ?? 1));
$residents = (int)($options['moradores'] ?? 0);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = fn($s) => $pdo->query($s)->fetchAll(PDO::FETCH_ASSOC);
$condoTables = array_column($q("select distinct table_name t from information_schema.columns where table_schema=database() and column_name='condominium_id' and table_name in (select table_name from information_schema.tables where table_schema=database() and table_type='BASE TABLE')"), 't');
$condoTables = array_values(array_diff($condoTables, ['system_audit_logs', 'erp_notification_outbox', 'access_user_condominiums']));
$fks = $q("select table_name t, column_name c, referenced_table_name r from information_schema.key_column_usage where table_schema=database() and referenced_table_name is not null");
$set = array_merge(['condominiums'], $condoTables);
// filhos de 1 nível sem condominium_id
$children = [];
foreach ($fks as $f) if (!in_array($f['t'], $set, true) && in_array($f['r'], $condoTables, true) && !isset($children[$f['t']])) $children[$f['t']] = $f;
$all = array_merge($set, array_keys($children));
$fkMap = [];
foreach ($fks as $f) $fkMap[$f['t']][$f['c']] = $f['r'];
$cols = []; foreach ($q("select table_name t, column_name c, data_type d, column_key k, extra e from information_schema.columns where table_schema=database() and extra not like '%GENERATED%' order by ordinal_position") as $r) $cols[$r['t']][] = $r;
$uniq = []; foreach ($q("select table_name t, max(column_name) c from information_schema.statistics where table_schema=database() and non_unique=0 and index_name<>'PRIMARY' group by table_name, index_name having count(*)=1") as $r) $uniq[$r['t']][$r['c']] = true;
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
for ($k = $from; $k <= $n; $k++) {
  $off = $k * 1000000;
  foreach ($all as $t) {
    if (!isset($cols[$t])) continue;
    $hasId = false; $sel = []; $names = [];
    foreach ($cols[$t] as $c) {
      $name = $c['c']; $names[] = "`$name`"; $e = "`$name`";
      $numeric = in_array($c['d'], ['int','bigint','smallint','mediumint','tinyint'], true);
      if ($name === 'user_id' && $t === 'erp_residents') $e = 'NULL';
      elseif (isset($uniq[$t][$name]) && $numeric && !isset($fkMap[$t][$name]) && $name !== 'id') $e = "IF(`$name` IS NULL, NULL, `$name` + $off)";
      elseif ($name === 'id' && $numeric) { $e = "`id` + $off"; $hasId = true; }
      elseif (isset($fkMap[$t][$name]) && in_array($fkMap[$t][$name], $all, true)) $e = "`$name` + $off";
      elseif ($name === 'condominium_id') $e = "`$name` + $off";
      elseif (isset($uniq[$t][$name]) && !$numeric && in_array($c['d'], ['varchar','char','text'], true)) $e = "IF(`$name` IS NULL, NULL, CONCAT(LEFT(`$name`, GREATEST(1, CHAR_LENGTH(`$name`) - CHAR_LENGTH('$k') - 1)), '-$k'))";
      elseif ($t === 'condominiums' && $name === 'name') $e = "CONCAT('Carga ', $k)";
      $sel[] = $e;
    }
    if ($t === 'condominiums') $where = "id = $src";
    elseif (in_array($t, $condoTables, true)) $where = "condominium_id = $src";
    else { $f = $children[$t]; $where = "`{$f['c']}` IN (SELECT id FROM `{$f['r']}` WHERE condominium_id = $src)"; }
    try { $pdo->exec("INSERT INTO `$t` (" . implode(',', $names) . ") SELECT " . implode(',', $sel) . " FROM `$t` WHERE $where AND " . ($hasId ? "id < 1000000" : "1")); }
    catch (Throwable $e) { if ($k === $from) fwrite(STDERR, "$t: " . substr($e->getMessage(), 0, 160) . "\n"); }
  }
  if ($k % 50 === 0) fwrite(STDOUT, "cópia $k de $n\n");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

if ($residents > 0) {
    $hash = password_hash('Carga@2026', PASSWORD_DEFAULT, ['cost' => 10]);
    $role = (int)$pdo->query("SELECT id FROM access_roles WHERE slug = 'user'")->fetchColumn();
    $start = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'morador%@carga.lab'")->fetchColumn();
    $rows = $pdo->query("SELECT r.id FROM erp_residents r WHERE r.condominium_id >= 1000000 AND r.user_id IS NULL AND r.status = 'active'
        AND r.deleted_at IS NULL AND (r.ends_on IS NULL OR r.ends_on >= CURDATE()) ORDER BY r.id LIMIT " . max(0, $residents - $start))->fetchAll(PDO::FETCH_COLUMN);
    $insert = $pdo->prepare("INSERT INTO users (first_name, last_name, email, document, password, level, status) VALUES ('Morador', 'Carga', ?, ?, ?, 1, 'confirmed')");
    $link = $pdo->prepare('UPDATE erp_residents SET user_id = ? WHERE id = ?');
    $grant = $pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)');
    $pdo->beginTransaction();
    $count = $start;
    foreach ($rows as $residentId) {
        $count++;
        $insert->execute(["morador{$count}@carga.lab", (string)(10000000000 + $count), $hash]);
        $userId = (int)$pdo->lastInsertId();
        $link->execute([$userId, $residentId]);
        $grant->execute([$userId, $role]);
    }
    $pdo->commit();
    fwrite(STDOUT, "Moradores com conta: {$count}\n");
}
