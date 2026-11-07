<?php

namespace Source\Models\Banking;


class AppBankInter
{
    /** @var string */
    private $apiurl;

    /** @var string */
    private $apicert;

    /** @var string */
    private $apikey;

    /** @var string */
    private $endpoint;

    /** @var array */
    private $build;

    /** @var string */
    private $callback;

    public function __construct()
    {
        $projectRoot = dirname(__DIR__, 3);
        $this->apiurl = rtrim(getenv("INTER_API_URL") ?: "https://cdpj.partners.bancointer.com.br", "/");
        $this->apicert = $this->resolvePath(getenv("INTER_CERT_PATH") ?: "storage/cert/Certificado.crt", $projectRoot);
        $this->apikey = $this->resolvePath(getenv("INTER_KEY_PATH") ?: "storage/cert/chave.key", $projectRoot);

    }

    public function authentication()
    {
        $clientId = getenv("INTER_CLIENT_ID") ?: "";
        $clientSecret = getenv("INTER_CLIENT_SECRET") ?: "";
        if ($clientId === "" || $clientSecret === "") {
            throw new \RuntimeException("Configure INTER_CLIENT_ID e INTER_CLIENT_SECRET no ambiente.");
        }

        $this->build = [
            "client_id" => $clientId,
            "client_secret" => $clientSecret,
            "scope" => getenv("INTER_SCOPE") ?: "extrato.read boleto-cobranca.read",
            "grant_type" => "client_credentials"
        ];

        $this->endpoint = "/oauth/v2/token";
        $this->post();

        if (!$this->callback) {
            return "não conectado!";
        }

        return $this->callback->{'access_token'};
    }

    public function callback()
    {
        return $this->callback;
    }

    private function post()
    {
        if (!is_readable($this->apicert) || !is_readable($this->apikey)) {
            throw new \RuntimeException("Certificado ou chave mTLS do Banco Inter indisponível.");
        }

        $url = $this->apiurl . $this->endpoint;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge($this->build)));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/x-www-form-urlencoded"
        ));
        curl_setopt($ch, CURLOPT_SSLCERT, $this->apicert);
        curl_setopt($ch, CURLOPT_SSLKEY, $this->apikey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $this->callback = json_decode(curl_exec($ch));
        curl_close($ch);
    }

    private function resolvePath(string $path, string $projectRoot): string
    {
        if (str_starts_with($path, "/")) {
            return $path;
        }

        return $projectRoot . "/" . ltrim($path, "/");
    }

}
