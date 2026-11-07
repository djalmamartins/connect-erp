<?php

declare(strict_types=1);

use Source\Core\Connect;

$_SERVER["HTTP_HOST"] ??= "localhost";
require dirname(__DIR__, 2) . "/vendor/autoload.php";

$root = dirname(__DIR__, 2);
$import = $root . "/storage/database/imports/moves-content/pages_posts.sql";
if (!is_file($import)) {
    fwrite(STDERR, "Arquivo de conteúdo não encontrado: {$import}" . PHP_EOL);
    exit(1);
}

$imagesImport = dirname($import) . "/images";
$imagesTarget = $root . "/storage/images";
$imagesOnly = in_array("--images-only", $argv ?? [], true);
$imageCounts = ["copied" => 0, "verified" => 0];
if (is_dir($imagesImport)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($imagesImport, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $source) {
        $relative = substr($source->getPathname(), strlen($imagesImport) + 1);
        $target = $imagesTarget . "/" . $relative;
        if ($source->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                throw new RuntimeException("Não foi possível criar {$target}.");
            }
            chmod($target, 0775);
            continue;
        }
        $mustCopy = !is_file($target) || filesize($target) !== $source->getSize()
            || hash_file("sha256", $target) !== hash_file("sha256", $source->getPathname());
        if ($mustCopy) {
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                throw new RuntimeException("Não foi possível criar " . dirname($target) . ".");
            }
            if (!copy($source->getPathname(), $target)) {
                throw new RuntimeException("Não foi possível copiar {$relative}. Verifique as permissões de storage/images.");
            }
            chmod($target, 0664);
            $imageCounts["copied"]++;
        }
        if (!is_file($target) || filesize($target) !== $source->getSize()) {
            throw new RuntimeException("A verificação da imagem {$relative} falhou.");
        }
        $imageCounts["verified"]++;
    }
}

printf("Imagens: %d copiadas/reparadas; %d verificadas.%s", $imageCounts["copied"], $imageCounts["verified"], PHP_EOL);
if ($imagesOnly) {
    exit(0);
}

$pdo = Connect::getInstance();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$backupDir = $root . "/storage/backups";
if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    throw new RuntimeException("Não foi possível criar o diretório de backup.");
}

$backup = [
    "created_at" => date(DATE_ATOM),
    "pages" => $pdo->query("SELECT * FROM pages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    "posts" => $pdo->query("SELECT * FROM posts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
];
$backupPath = $backupDir . "/content-before-migration-" . date("Ymd-His") . ".json";
file_put_contents($backupPath, json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

$pagesStage = "moves_legacy_pages_20260830";
$postsStage = "moves_legacy_posts_20260830";
$pdo->exec("DROP TABLE IF EXISTS `{$pagesStage}`, `{$postsStage}`");

$sql = file_get_contents($import);
$sql = str_replace(["`pages`", "`posts`"], ["`{$pagesStage}`", "`{$postsStage}`"], $sql);
$pdo->exec($sql);

$targetAuthor = $pdo->query("SELECT id FROM users WHERE email='djalma.martins@moves.com.br' LIMIT 1")->fetchColumn();
$targetAuthor = $targetAuthor ?: $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
$defaultCategory = $pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
if (!$targetAuthor || !$defaultCategory) {
    throw new RuntimeException("A migração precisa de ao menos um usuário e uma categoria válidos.");
}

$categoryIds = array_map("intval", $pdo->query("SELECT id FROM categories")->fetchAll(PDO::FETCH_COLUMN));
$validCategories = array_fill_keys($categoryIds, true);
$pageFind = $pdo->prepare("SELECT id FROM pages WHERE uri=:uri LIMIT 1");
$pageInsert = $pdo->prepare("INSERT INTO pages (author,title,uri,content,cover,video,views,status,post_at,created_at,updated_at,deleted_at) VALUES (:author,:title,:uri,:content,:cover,:video,:views,:status,:post_at,:created_at,:updated_at,:deleted_at)");
$pageUpdate = $pdo->prepare("UPDATE pages SET author=:author,title=:title,content=:content,cover=:cover,video=:video,views=:views,status=:status,post_at=:post_at,updated_at=:updated_at,deleted_at=:deleted_at WHERE id=:id");
$postFind = $pdo->prepare("SELECT id FROM posts WHERE uri=:uri LIMIT 1");
$postInsert = $pdo->prepare("INSERT INTO posts (author,category,title,uri,subtitle,content,cover,video,views,status,post_at,created_at,updated_at,deleted_at) VALUES (:author,:category,:title,:uri,:subtitle,:content,:cover,:video,:views,:status,:post_at,:created_at,:updated_at,:deleted_at)");
$postUpdate = $pdo->prepare("UPDATE posts SET author=:author,category=:category,title=:title,subtitle=:subtitle,content=:content,cover=:cover,video=:video,views=:views,status=:status,post_at=:post_at,updated_at=:updated_at,deleted_at=:deleted_at WHERE id=:id");

$pageCounts = ["inserted" => 0, "updated" => 0];
$postCounts = ["inserted" => 0, "updated" => 0];

$pdo->beginTransaction();
try {
    foreach ($pdo->query("SELECT * FROM `{$pagesStage}` ORDER BY id", PDO::FETCH_ASSOC) as $row) {
        $pageFind->execute(["uri" => $row["uri"]]);
        $id = $pageFind->fetchColumn();
        $data = [
            "author" => $targetAuthor, "title" => $row["title"], "content" => $row["content"],
            "cover" => $row["cover"], "video" => $row["video"], "views" => $row["views"],
            "status" => $row["status"], "post_at" => $row["post_at"],
            "updated_at" => $row["updated_at"], "deleted_at" => $row["deleted_at"],
        ];
        if ($id) {
            $pageUpdate->execute($data + ["id" => $id]);
            $pageCounts["updated"]++;
        } else {
            $pageInsert->execute($data + ["uri" => $row["uri"], "created_at" => $row["created_at"]]);
            $pageCounts["inserted"]++;
        }
    }

    foreach ($pdo->query("SELECT * FROM `{$postsStage}` ORDER BY id", PDO::FETCH_ASSOC) as $row) {
        $postFind->execute(["uri" => $row["uri"]]);
        $id = $postFind->fetchColumn();
        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($row["content"]), ENT_QUOTES | ENT_HTML5, "UTF-8")));
        $data = [
            "author" => $targetAuthor,
            "category" => isset($validCategories[(int)$row["category"]]) ? (int)$row["category"] : (int)$defaultCategory,
            "title" => html_entity_decode($row["title"], ENT_QUOTES | ENT_HTML5, "UTF-8"),
            "subtitle" => mb_strimwidth($plain, 0, 250, "…", "UTF-8"),
            "content" => $row["content"], "cover" => $row["cover"], "video" => $row["video"],
            "views" => $row["views"], "status" => $row["status"], "post_at" => $row["post_at"],
            "updated_at" => $row["updated_at"], "deleted_at" => $row["deleted_at"],
        ];
        if ($id) {
            $postUpdate->execute($data + ["id" => $id]);
            $postCounts["updated"]++;
        } else {
            $postInsert->execute($data + ["uri" => $row["uri"], "created_at" => $row["created_at"]]);
            $postCounts["inserted"]++;
        }
    }
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
} finally {
    $pdo->exec("DROP TABLE IF EXISTS `{$pagesStage}`, `{$postsStage}`");
}

printf(
    "Migração concluída: páginas %d novas/%d atualizadas; posts %d novos/%d atualizados.%sBackup: %s%s",
    $pageCounts["inserted"], $pageCounts["updated"], $postCounts["inserted"], $postCounts["updated"], PHP_EOL, $backupPath, PHP_EOL
);
