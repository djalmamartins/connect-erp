<?php

namespace Source\Models;

use Source\Core\Model;
use Source\Models\Corporation\AppOwner;
use Source\Support\Access;

/**
 * ERP | Class User
 *
 * @author Djalma Martins
 * @package Source\Models
 */
class User extends Model
{
    /**
     * User constructor.
     */
    public function __construct()
    {
        parent::__construct("users", ["id"], ["first_name", "last_name", "email", "document", "password"]);
    }

    /**
     * @param string $firstName
     * @param string $lastName
     * @param string $email
     * @param string $document
     * @param string $password
     * @return $this
     */
    public function bootstrap(
        string $firstName,
        string $lastName,
        string $email,
        string $document,
        string $password,
    ): User {
        $this->first_name = $firstName;
        $this->last_name = $lastName;
        $this->email = $email;
        $this->document = $document;
        $this->password = $password;
        return $this;
    }

    /**
     * @param string $email
     * @param string $columns
     * @return User|null
     */
    public function findByEmail(string $email, string $columns = "*"): ?User
    {
        $find = $this->find("email = :email", "email={$email}", $columns);
        return $find->fetch();
    }

    /**
     * @param string $email
     * @param string $columns
     * @return User|null
     */
    public function findByDocument(string $email, string $columns = "*"): ?User
    {
        $find = $this->find("document = :document", "document={$email}", $columns);
        return $find->fetch();
    }

    /**
     * @return Address
     */
    public function address(?string $status = null): ?Address
    {
        if(!empty($status)){
            return (new Address())->find("users_id = :users_id AND status = :status", "users_id={$this->id}&status={$status}")->fetch();
        }
        return (new Address())->find("users_id = :users_id", "users_id={$this->id}")->fetch();
    }


    /**
     * @return string|null
     */
    public function name(): ?string
    {
        if ($this->first_name) {
            return $this->first_name;
        }
        return null;
    }

    /**
     * @return string
     */
    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function can(string $permission): bool
    {
        return Access::can($permission, $this);
    }

    public function accessRole(): ?object
    {
        return Access::role($this);
    }

    /**
     * @return string|null
     */
    public function photo(): ?string
    {
        if ($this->photo && file_exists(__DIR__ . "/../../" . CONF_UPLOAD_DIR . "/{$this->photo}")) {
            return $this->photo;
        }
        return null;
    }

    /**
     * @return bool
     */
    public function save(): bool
    {
        if ((int)($this->id ?? 0) === 1) {
            $this->level = 10;
            $this->status = "confirmed";
        }

        if (!$this->required()) {
            $this->message->warning("Nome, sobrenome, email, cpf e senha são obrigatórios");
            return false;
        }

        if (!is_document($this->document)) {
            $this->message->warning("O CPF ou CNPJ informado não tem um formato válido");
            return false;
        }

        if (!is_email($this->email)) {
            $this->message->warning("O e-mail informado não tem um formato válido");
            return false;
        }

        if (!is_passwd($this->password)) {
            $min = CONF_PASSWD_MIN_LEN;
            $max = CONF_PASSWD_MAX_LEN;
            $this->message->warning("A senha deve ter entre {$min} e {$max} caracteres");
            return false;
        } else {
            $this->password = passwd($this->password);
        }

        $this->data = (object)filter_var_array((array)$this->data, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        // Campos opcionais tipados não podem ser persistidos como string vazia
        // em bancos executando com modo SQL estrito.
        if (($this->datebirth ?? null) === "") {
            $this->datebirth = null;
        }
        if (($this->session_condo ?? null) === "") {
            $this->session_condo = null;
        }

        /** User Update */
        if (!empty($this->id)) {
            $userId = $this->id;

            if ($this->find("document = :d AND id != :i", "d={$this->document}&i={$userId}", "id")->fetch()) {
                $this->message->warning("O CPF ou CNPJ informado já está cadastrado");
                return false;
            }

            if ($this->find("email = :e AND id != :i", "e={$this->email}&i={$userId}", "id")->fetch()) {
                $this->message->warning("O e-mail informado já está cadastrado");
                return false;
            }

            $this->update($this->safe(), "id = :id", "id={$userId}");
            if ($this->fail()) {
                $this->message->error("Erro ao atualizar, verifique os dados");
                return false;
            }
        }

        /** User Create */
        if (empty($this->id)) {

            if ($this->findByDocument($this->document, "id")) {
                $this->message->warning("O CPF informado já está cadastrado");
                return false;
            }

            if ($this->findByEmail($this->email, "id")) {
                $this->message->warning("O e-mail informado já está cadastrado");
                return false;
            }

            $userId = $this->create($this->safe());
            if ($this->fail()) {
                $this->message->error("Erro ao cadastrar, verifique os dados");
                return false;
            }
        }

        $this->data = ($this->findById($userId))->data();
        return true;
    }

    public function destroy(): bool
    {
        if ((int)($this->id ?? 0) === 1) {
            $this->message->warning("O usuário principal do sistema não pode ser excluído");
            return false;
        }

        return parent::destroy();
    }

}
