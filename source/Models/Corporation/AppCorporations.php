<?php

namespace Source\Models\Corporation;

use Source\Core\Model;
use Source\Core\Session;
use Source\Models\Address;

/**
 * ERP | Class AppCorporations
 *
 * @author Djalma Martins
 * @package Source\Models\Corporation
 */
class AppCorporations extends Model
{
    /**
     * AppCorporations constructor.
     */
    public function __construct()
    {
        parent::__construct("app_corporations", ["id"], ["corporation_name", "fantasy_name", "email" ]);
    }

    public function bootstr(
        string $corporation_name,
        string $fantasy_name,
        string $email,
        ?string $document = null,
        ?string $datebirth = null,
        ?string $phone = null,
        ?string $phone_cell = null,

    ): AppCorporations {
        $this->corporation_name = $corporation_name;
        $this->fantasy_name = $fantasy_name;
        $this->email = $email;
        $this->document = $document;
        $this->datebirth = $datebirth;
        $this->phone = $phone;
        $this->phone_cell = $phone_cell;
        return $this;
    }

    /**
     * @param string $email
     * @param string $columns
     * @return AppCorporations|null
     */
    public function findByEmail(string $email, string $columns = "*"): ?AppCorporations
    {
        $find = $this->find("email = :email", "email={$email}", $columns);
        return $find->fetch();
    }

    /**
     * @param $id
     * @return mixed|Model|void
     */
    public function start($id)
    {
        if (!empty($id)) {
            $session = new Session();
            $corporations = (new AppCorporations())->findById($id);
            if (!$corporations) {
                $this->message->info("Você tentnou entrar em um condomínio que não existe")->flash();
                redirect("/erp/corporate/home");
            } else {
                $session->set("corporations", $corporations->data());
                return $corporations;
            }
        } else {
            $this->message->info("Selecione um condomínio para gerenciar")->flash();
            redirect("/erp/corporate/home");
        }
    }

    /**
     * @return void
     */
    public function end()
    {
        $session = new Session();
        $session->unset("corporations");
    }

    public function listCorporations(?int $id): ?AppCorporations
    {
        if (!empty($id)) {
            $corporationsId = filter_var($id, FILTER_VALIDATE_INT);
            return  (new AppCorporations())->findById($corporationsId);
        }
        return null;
    }

    /**
     * @return string|null
     */
    public function photoCorporations(): ?string
    {
        if ($this->photo && file_exists(__DIR__ . "/../../" . CONF_UPLOAD_DIR . "/{$this->photo}")) {
            return $this->photo;
        }
        return null;
    }

    /**
     * @return Address
     */
    public function address(?string $status = null): ?Address
    {
        if(!empty($status)){
            return (new Address())->find("corporations_id = :corporations_id AND status = :status", "corporations_id={$this->id}&status={$status}")->fetch();
        }
        return (new Address())->find("corporations_id = :corporations_id", "corporations_id={$this->id}")->fetch();
    }

    /**
     * @return bool
     */
    public function save(): bool
    {
        if (!$this->required()) {
            $this->message->warning("Condomínio é obrigatório");
            return false;
        }

        if (!is_email($this->email)) {
            $this->message->warning("O e-mail informado não tem um formato válido");
            return false;
        }

        if (!empty($this->id)) {
            $corporationsId = $this->id;

            if ($this->find("email = :e AND id != :i", "e={$this->email}&i={$corporationsId}", "id")->fetch()) {
                $this->message->warning("O e-mail informado já está cadastrado");
                return false;
            }

            $this->update($this->safe(), "id = :id", "id={$corporationsId}");
            if ($this->fail()) {
                $this->message->error("Erro ao atualizar, verifique os dados");
                return false;
            }
        }

        /** User Create */
        if (empty($this->id)) {


            if ($this->findByEmail($this->email, "id")) {
                $this->message->warning("O e-mail informado já está cadastrado 1");
                return false;
            }

            $corporationsId = $this->create($this->safe());
            if ($this->fail()) {
                $this->message->error("Erro ao cadastrar, verifique os dados");
                return false;
            }
        }

        $this->data = ($this->findById($corporationsId))->data();
        return true;
    }
}
