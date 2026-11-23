# 0644 — E-mails brancos e dourados

- Status: Aceito
- Data: 22/11/2026
- Substitui em parte: ADR 0397 (só as cores da moldura; estrutura, partes e rodapé continuam)
- Relacionados: ADR 0601 (o morador recebe só 5 e-mails), ADR 0604 (dados da empresa no rodapé)

## Contexto
Em 22/11/2026 o Djalma disse que não gostou do layout dos e-mails: queria algo mais limpo, sem o verde forte do
cabeçalho (`#0d2e27`) e dos botões (`#07884c`), "todo branco e dourado".

## Decisão
1. Moldura (`container/send/default/v1/default.php`): fundo externo creme bem claro `#f8f6f1`, cartão branco com borda
   `#ece5d3` e faixa dourada de 4 px no topo (`#c5a131`); cabeçalho branco com o logo Connect dourado centralizado (o mesmo
   PNG de antes, que já é dourado com fundo transparente); título em grafite `#1f1d17`, texto `#3a3730`; rodapé branco e
   centralizado, com os mesmos dados (nome, CNPJ, endereço, contato, site, política de privacidade e descadastro).
2. Botão dourado `#c5a131` com texto escuro `#1f1d17` (contraste 6,8:1); links em dourado escuro `#8a6d1c` (4,9:1 no branco),
   porque o dourado claro com texto branco não passa no contraste mínimo (2,5:1).
3. Quadros de destaque, citação, tabelas e nota em tons creme (`#fbf8f0`, `#efe6cc`, `#f0eadb`) e texto secundário `#6f6a5e`
   (5,4:1). Vermelho só para "com problema" no relatório de vistoria.
4. Nenhum texto, envio ou regra mudou: só cores e espaçamento. Os 5 e-mails do morador (ADR 0601) continuam os mesmos.

## Consequências
- `MailTemplateTest` confere a faixa dourada, o cabeçalho branco, o botão dourado com texto escuro e que nenhum arquivo da
  moldura, das partes ou dos modelos usa mais os verdes antigos.
- Prévias antes/depois com dados fictícios em `/mnt/project-files/emails/branco-dourado/`.
