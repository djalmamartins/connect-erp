# 0394 — Remoção dos controladores antigos do ERP sem rota

- Status: Aceito
- Data: 18/11/2026
- Contexto: revisão dos envios de arquivo (ADR 0388) e aprovação do Djalma em 18/11/2026 ("Pode apagar", no cartão
  "Posso apagar 10 telas antigas do ERP que não abrem mais?").

## Contexto
Dez controladores do ERP antigo continuavam no código sem nenhuma rota, teste ou classe que os usasse:
`source/Controllers/Erp/V1/` (Condominium, Corporations, Dash, Erp, Manager, Users) e, em `Erp/Connect/`, os antigos
`Users`, `Register`, `Condo` e `Condominium`. Não respondiam a nenhum endereço, mas tinham código inseguro que voltaria a
valer se alguém os ligasse por engano: senha inicial igual ao e-mail em base64, RG e CPF da equipe gravados na pasta
pública com o CPF em base64 no nome do arquivo, sem conferência de condomínio.

## Decisão
Os dez arquivos foram apagados. `Erp/Connect/Erp.php` fica: é a base de `Finance` e `Dash`, que estão em uso.
O mapa do código (`docs/map/`) perdeu as dez classes, e o `DownloadCacheHeadersTest` deixou de precisar da exceção
para `Erp/V1`.

## Consequências
- Nada que funciona hoje muda: as telas atuais de usuários, condomínios e cadastros são outras (ADR 0014, 0168).
- Arquivos antigos que esses controladores tenham gravado em `storage/files` no laboratório continuam lá; na produção a
  pasta começa vazia.
- Testes: `composer test:unit` (1380) e a suíte de integração no CI.
