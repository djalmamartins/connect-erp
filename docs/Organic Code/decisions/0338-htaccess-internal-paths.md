# 0338 — Revisão de segurança: pastas internas bloqueadas no servidor web

- Status: Aceito
- Data: 17/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.7 (revisão final antes de abrir ao público); Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0325 (backup local em `.local/backups`), ADR 0337 (CSRF)

## Contexto

O `index.php` e os assets dos temas (`container/*/assets`, `storage/images`) moram na raiz do projeto, então na hospedagem a
pasta pública do subdomínio tende a ser a própria pasta do projeto. O `.htaccess` só barrava `source`, `service`, `tests`,
`vendor`, parte de `storage` e o `.env`. Num Apache de teste com o `.htaccess` antigo, `/.git/config`, `/tools/...`,
`/docs/...` e o código dos temas (`/container/erp/connect/v1/routes.php`) respondiam 200. O backup local do banco
(`.local/backups/*.sql.gz`) só não saía porque o arquivo é 0600 e o Apache do teste roda com outro usuário; na hospedagem o
PHP e o servidor usam o mesmo usuário, e o dump do banco inteiro ficaria para download.

## Decisão

1. Bloquear (403) tudo que começa com ponto (`.git`, `.local`, `.github`, `.claude`, `.env*`), menos `/.well-known/` (o SSL
   automático do hPanel precisa dele).
2. Bloquear as pastas `apps`, `database`, `docker`, `docs` e `tools`.
3. Em `container/`, bloquear `.php`, `.sql`, `.md`, `.json`, `.yaml`, `.sh`, `.lock` e `.txt` fora de pastas `assets/`.
4. Bloquear por nome `.sql`, `.gz`, `.sh`, `.yaml`, `.lock`, `.py`, `.log`, `.bak`, `CLAUDE.md` e `compose.yaml` em qualquer pasta.
5. `HtaccessExposureTest` confere as regras com caminhos que devem ser barrados e caminhos que devem continuar públicos.

## Verificação

Apache 2.4 local com `AllowOverride All` e a raiz do projeto como pasta pública: os 19 caminhos internos testados deram
403; assets do ERP, do site e do Organic, favicon e manifest do Talk e `.well-known` deram 200.

## Consequências

- Se a hospedagem permitir, apontar a pasta pública para o projeto e manter o `.htaccess` (as regras valem dos dois jeitos).
- Pendente na §3.7: os anexos do Help Desk ficam em `storage/files` com endereço aleatório e sem login (80 bits de
  aleatoriedade, não dá para adivinhar, mas um link vazado continua abrindo). Proposta: servir por uma rota autenticada.
