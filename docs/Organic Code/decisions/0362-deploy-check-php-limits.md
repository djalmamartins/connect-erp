# ADR 0362 — Limites do PHP no deploy-check

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0098 (limites de upload), ADR 0325 (hospedagem), ADR 0354 (deploy-check em produção)

## Contexto

O sistema aceita arquivos de até 50 MB (mídia do Studio; anexos 25 MB, Disco Virtual 20 MB, fotos de vistoria 15 MB).
O PHP de fábrica costuma vir com `upload_max_filesize=2M`, `post_max_size=8M` e `memory_limit=128M`. Na hospedagem
compartilhada, com esses valores, o PHP descarta o arquivo antes do sistema ver o pedido: o formulário chega vazio e a
mensagem ao usuário não explica nada. O ROADMAP §3.4 já alertava que o `memory_limit` pode apertar nos PDFs grandes e
nas importações.

## Decisão

- `DeployReadiness::php()` avisa (não para a publicação) quando `memory_limit` < 256M (salvo -1), `upload_max_filesize`
  < 50M, `post_max_size` menor que o upload ou que 50M (salvo 0, sem limite) e `file_uploads` desligado. Cada aviso diz
  onde ajustar no hPanel.
- `DeployReadiness::REQUIRED_UPLOAD_BYTES` (50M) tem teste que falha se algum limite de envio do código passar dele.
- O `deploy-check` lê os valores pelo `ini_get` no CLI. A configuração do hPanel vale para o site; o HOSPEDAGEM §1 pede
  os mesmos valores lá.

## Testes

`DeployReadinessTest`: valores bons sem aviso; -1 e 0 aceitos; os quatro avisos com os valores de fábrica; post menor
que o upload; leitura de `128M`, `2k`, `1G`, `-1` e lixo; o limite pedido cobre todos os `*_MAX_BYTES` de envio; o
`deploy-check` chama a função.

## Riscos

- O PHP do SSH pode ter `php.ini` diferente do PHP do site. Por isso é aviso e o roteiro pede conferir no hPanel.
- 256M é estimativa; a medição real dos PDFs e importações fica para o piloto.
