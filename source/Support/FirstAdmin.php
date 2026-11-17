<?php

declare(strict_types=1);

namespace Source\Support;

use PDO;
use Source\Support\Erp\BrDocument;

/**
 * Primeiro administrador da instalação limpa (ADR 0380): php tools/hosting/criar-admin.php.
 *
 * Só funciona enquanto nenhuma conta tem o papel super_admin ou developer, para não virar porta dos fundos. A conta nasce
 * confirmada, com o papel super_admin (que recebe todas as permissões cadastradas), protegida contra exclusão quando a tabela
 * system_protected_users existe, e com uma
 * senha forte aleatória mostrada uma vez só. O 2FA é cobrado no primeiro login (CONNECT_2FA_ENFORCE_FROM).
 */
final class FirstAdmin
{
    public const ROLE = 'super_admin';

    /** Motivo da recusa, ou null quando pode criar (regra pura). */
    public static function problem(string $email, string $first, string $last, string $document): ?string
    {
        return match (true) {
            filter_var($email, FILTER_VALIDATE_EMAIL) === false => 'E-mail inválido.',
            trim($first) === '' || trim($last) === '' => 'Informe nome e sobrenome.',
            !BrDocument::isCpf($document) => 'CPF inválido.',
            default => null,
        };
    }

    /** Senha aleatória de 20 caracteres com maiúscula, minúscula, número e símbolo. */
    public static function password(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '@#%&*+=?'];
        $all = implode('', $sets);
        $chars = array_map(static fn (string $set): string => $set[random_int(0, strlen($set) - 1)], $sets);
        while (count($chars) < 20) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    /**
     * @return array{id: int, password: string}
     * @throws \DomainException já existe administrador, papel ausente ou dados inválidos
     */
    public static function create(PDO $pdo, string $email, string $first, string $last, string $document): array
    {
        $document = (string)preg_replace('/\D+/', '', $document);
        $email = strtolower(trim($email));
        if (($problem = self::problem($email, $first, $last, $document)) !== null) {
            throw new \DomainException($problem);
        }
        $admins = (int)$pdo->query("SELECT COUNT(*) FROM access_user_roles ur JOIN access_roles r ON r.id = ur.role_id
            WHERE r.slug IN ('super_admin', 'developer')")->fetchColumn();
        if ($admins > 0) {
            throw new \DomainException('Já existe administrador. Crie as próximas contas pela tela Usuários do Sistema.');
        }
        $role = $pdo->prepare('SELECT id FROM access_roles WHERE slug = ?');
        $role->execute([self::ROLE]);
        $roleId = (int)$role->fetchColumn();
        if ($roleId === 0) {
            throw new \DomainException('Papel super_admin não cadastrado: rode composer db:migrate antes.');
        }
        $password = self::password();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO users (first_name, last_name, email, document, password, level, status) VALUES (?, ?, ?, ?, ?, 10, 'confirmed')")
                ->execute([trim($first), trim($last), $email, $document, password_hash($password, PASSWORD_DEFAULT)]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $roleId]);
            $pdo->prepare('INSERT IGNORE INTO access_role_permissions (role_id, permission_id) SELECT ?, id FROM access_permissions')->execute([$roleId]);
            // Tabela do Organic Code (20260830_protected_primary_user); o User::destroy já protege o id 1 de qualquer forma.
            if ($pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'system_protected_users'")->fetchColumn()) {
                $pdo->prepare("INSERT IGNORE INTO system_protected_users (user_id, reason) VALUES (?, 'Primeiro administrador (ADR 0380)')")->execute([$id]);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        Audit::record('create', 'users', $id, null, ['first_admin' => true, 'role' => self::ROLE]);
        return ['id' => $id, 'password' => $password];
    }
}
