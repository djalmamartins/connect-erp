# ADR 0374 — Escolha do armazenamento dos arquivos pelo .env (passo 5 do OneDrive)

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADRs 0370–0373, ROADMAP_V1 §3.5, HOSPEDAGEM.md §10

## Contexto

O OneDrive (ADR 0373) existia mas nenhum serviço o usava. Faltava escolher pelo `.env` e conferir a configuração antes de
abrir.

## Decisão

- `DocumentStores::make(área, pasta local)`: `DOCUMENT_STORAGE=local` (padrão) ou `onedrive`. Áreas: `documents`
  (Disco Virtual), `inspections` (fotos de vistoria) e `resident-photos` (foto do morador), cada uma numa subpasta de
  `ONEDRIVE_FOLDER` e com cache em `storage/cache/onedrive/{área}` (já fora do Git).
- OneDrive pedido mas incompleto: continua no disco local (nada se perde) e o `deploy-check` dá **erro** listando as chaves
  que faltam; valor desconhecido em `DOCUMENT_STORAGE` também é erro.
- Os três serviços usam a fábrica só quando ninguém passou pasta ou armazenamento (testes e ferramentas continuam locais).
- Chaves novas na lista do `Config.php` e no modelo `.env.production.example` (`DOCUMENT_STORAGE=local`, OneDrive comentado).
- Roteiro do registro do aplicativo no Entra ID em HOSPEDAGEM.md §10.

Fica para depois: copiar os arquivos que já estão no servidor para o OneDrive antes de trocar, e a limpeza do cache.

## Testes

`DocumentStoresTest`: padrão local, incompleto fica local, pasta por área, área desconhecida recusada, `deploy-check`
acusa chave faltando e valor desconhecido, serviços chamam a fábrica. `EnvironmentKeysTest` cobre as chaves novas.
