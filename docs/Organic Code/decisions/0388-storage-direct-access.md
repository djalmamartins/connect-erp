# 0388 — Mídia do Talk, propostas e páginas fora do endereço direto de storage/

- Status: Aceito
- Data: 18/11/2026
- Contexto: ROADMAP_V1 §3.7 ("revisão final antes de abrir ao público: ... upload ..."), ADR 0338 e 0339.

## Contexto
Revisão dos envios de arquivo em 18/11/2026. O que já estava certo:

- Disco Virtual, anexos dos lançamentos, fotos de vistoria, foto do morador e importações gravam em `storage/private`
  (bloqueado), conferem o tipo pelo conteúdo (`finfo`) numa lista fechada e têm limite de tamanho.
- O `Upload` do núcleo (imagens do Studio, anexos dos chamados) confere o tipo pelo conteúdo e a extensão numa lista
  fechada, e grava com nome aleatório; nenhuma lista aceita `.php`, `.html` ou `.svg`.
- Downloads saem como anexo, ou em linha só para PDF e imagens, com `nosniff` e CSP `sandbox`.
- Os controladores antigos `Erp/V1/Users`, `Erp/Connect/Users`, `Register` e `Condo` (que gravavam RG e CPF da equipe
  em `storage/files`) não têm rota: código morto, sem exposição.

Dois achados de acesso direto, sem login, pelo endereço do arquivo:

1. **Mídia do WhatsApp do Talk** (`storage/talk/media/...`): `storage/talk` não estava na lista do `.htaccess`. O nome tem
   128 bits aleatórios, então era difícil de adivinhar, mas o comentário do `MediaStore` dizia "fora da pasta pública".
2. **PDF das propostas comerciais** (`storage/files/proposals/AAAA/MM/prop-AAMMDD-XXXXXX-vN.pdf`): nome, e-mail e telefone do
   interessado. O protocolo tem só 24 bits aleatórios por dia e o `vN` é sequencial; a rota do Studio confere a
   permissão `proposals.manage`, mas o endereço direto não.

## Decisão
No `.htaccess` da raiz:

- `storage/talk`, `storage/media`, `storage/uploads` e `storage/output` entram na lista das pastas sempre bloqueadas.
- `storage/files/proposals` bloqueada: a proposta só abre pela rota do Studio, com login e permissão.
- Qualquer arquivo de página ou script dentro de `storage/` (`.php`, `.phtml`, `.phar`, `.html`, `.svg`, `.js`...) dá 403,
  como defesa extra caso algum envio futuro escape da lista de tipos.

`storage/files` e `storage/images` continuam públicas para as imagens do site e do Studio.

## Consequências
- Conferido num Apache 2.4 real em 18/11/2026: 403 na mídia do Talk, na proposta, num `.php` e num `.svg` dentro de
  `storage/images`; 200 num PDF de `storage/files` e numa imagem `.webp` de `storage/images`.
- O e-mail da proposta continua anexando o PDF lido do disco; nada muda no Studio.
- Testes: `HtaccessExposureTest` com os novos caminhos bloqueados e os públicos (o teste falha com o `.htaccess` anterior).
- Fica de fora: apagar os controladores antigos sem rota (pedir ao Djalma antes de remover arquivos).
