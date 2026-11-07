<?php

namespace Source\Models\Corporation;

use Source\Core\Model;
use Source\Core\Session;
use Source\Models\Address;


/**
 * ERP | Class AppCondominium
 *
 * @author Djalma Martins
 * @package Source\Models\Corporation;
 */
class AppCondominium extends Model
{
    /**
     * AppCondominium constructor.
     */
    public function __construct()
    {
        parent::__construct("app_condominium", ["id"], ["condo_name", "email"]);
    }

    /**

     * @param string $condo_name
     * @param string $fantasy_name
     * @param string $email
     * @param string|null $document
     * @param string|null $phone
     * @param string|null $cell
     * @return $this
     */
    public function bootstr(
        string $condo_name,
        string $fantasy_name,
        string $email,
        ?string $document = null,
        ?string $datebirth = null,
        ?string $phone = null,
        ?string $phone_cell = null,
    ): AppCondominium
    {
        $this->condo_name = $condo_name;
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
     * @return AppCondominium|null
     */
    public function findByEmail(string $email, string $columns = "*"): ?AppCondominium
    {
        $find = $this->find("email = :email", "email={$email}", $columns);
        return $find->fetch();
    }

    public function search($data): ?AppCondominium
    {

        if (!empty($data)) {
            $search = str_search($data);
            $condominium = (new AppCondominium())->find("lower(condominium_name, fantasy_name, email) LIKE lower('%{$search}%')");
            return $condominium;
        }
        return null;

    }

    public function condo(?int $id): ?AppCondominium
    {
        if (!empty($id)) {
            $condominiumId = filter_var($id, FILTER_VALIDATE_INT);
            return  (new AppCondominium())->findById($condominiumId);
        }
        return null;
    }

    public function listCondominium(?int $id): ?AppCondominium
    {
        if (!empty($id)) {
            $condominiumId = filter_var($id, FILTER_VALIDATE_INT);
            return  (new AppCondominium())->findById($condominiumId);
        }
        return null;
    }
    /**
     * @return string|null
     */
    public function photoCondominium(): ?string
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
            return (new Address())->find("condominium_id = :condominium_id AND status = :status", "condominium_id={$this->id}&status={$status}")->fetch();
        }
        return (new Address())->find("condo_id = :condo_id", "condo_id={$this->id}")->fetch();
    }

    /**
     * @param string $uri
     * @param string $columns
     * @return AppCondominium|null
     */
    public function findByUri(string $uri, string $columns = "*"): ?AppCondominium
    {
        $find = $this->find("uri = :uri", "uri={$uri}", $columns);
        return $find->fetch();
    }

    /**
     * @return bool
     */
    public function save(): bool
    {
        if (!$this->required()) {
            $this->message->warning("Nome do Condomínio é obrigatório");
            return false;
        }

        if (!is_email($this->email)) {
                $this->message->warning("O e-mail informado não tem um formato válido");
                return false;
        }

        if (!empty($this->id)) {
            $condominiumId = $this->id;

                if ($this->find("email = :e AND id != :i", "e={$this->email}&i={$condominiumId}", "id")->fetch()) {
                    $this->message->warning("O e-mail informado já está cadastrado");
                    return false;
                }

            $this->update($this->safe(), "id = :id", "id={$condominiumId}");
            if ($this->fail()) {
                $this->message->error("Erro ao atualizar, verifique os dados");
                return false;
            }
        }

        /** User Create */
        if (empty($this->id)) {

                if ($this->findByEmail($this->email, "id")) {
                    $this->message->warning("O e-mail informado já está cadastrado");
                    return false;
                }


            $condominiumId = $this->create($this->safe());
            if ($this->fail()) {
                $this->message->error("Erro ao cadastrar, verifique os dados");
                return false;
            }
        }

        $this->data = ($this->findById($condominiumId))->data();
        return true;
    }
}
