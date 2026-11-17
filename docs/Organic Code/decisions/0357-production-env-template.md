# ADR 0357 — Modelo do .env de produção e chaves que a hospedagem ignorava

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0325 (hospedagem sem Docker), ADR 0354 (deploy-check), HOSPEDAGEM.md §3

## Contexto

No laboratório, o Docker passa as variáveis para o PHP. Na Hostinger não há Docker: o único lugar é o `.env`, e o
`source/Boot/Config.php` só copia dele as chaves da sua lista. Três chaves lidas pelo sistema estavam fora dessa lista e
seriam ignoradas na hospedagem mesmo escritas no `.env`:

- `CONNECT_ERP_URL` (endereço do sistema mostrado na Central de Ajuda);
- `CONNECT_SUPPORT_HOURS` (horário de atendimento na Central de Ajuda);
- `CONNECT_JOB_HOST` (host usado pela rotina de avisos de implantação).

Além disso, o roteiro mandava copiar o `.env.example`, que é do laboratório (banco do Docker, sem as chaves do 2FA,
entrega de e-mail e outras que o deploy-check exige).

## Decisão

- As três chaves entram na lista do `Config.php`.
- Novo `.env.production.example`, versionado (exceção no `.gitignore`; o `.htaccess` já barra `.env*` no navegador), com
  todas as chaves da produção, segredos só como marcadores `<...>` e comentários de como gerar cada chave. O roteiro
  (HOSPEDAGEM.md §3) passa a copiar este arquivo.
- `EnvironmentKeysTest` impede que volte a acontecer: toda chave lida com `getenv` em `source`, `service`, `container` e
  `index.php` precisa estar na lista do `Config.php` (exceto as só de processo, como as dos testes).

## Testes

- `EnvironmentKeysTest`: chaves lidas x lista (falha tirando as três da lista, conferido); o modelo tem o que o
  deploy-check pede, só chaves que o sistema lê, segredos vazios; com o modelo sem preencher, o deploy-check acusa só as
  duas chaves a gerar (nunca debug, demonstração ou endereço do laboratório); o roteiro usa o modelo.
