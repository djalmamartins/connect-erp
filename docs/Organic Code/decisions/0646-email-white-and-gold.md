# 0646 — E-mails brancos com logo dourado, sem cabeçalho verde-escuro

- Status: Aceito
- Data: 22/11/2026
- Substitui em parte: ADR 0397 (só as cores da moldura; estrutura, partes e rodapé continuam)
- Relacionados: ADR 0601 (o morador recebe só 5 e-mails), ADR 0604 (dados da empresa no rodapé)

## Contexto
Em 22/11/2026 o Djalma disse que não gostou do layout dos e-mails: queria algo mais limpo, sem o verde forte do
cabeçalho (`#0d2e27`) e dos botões (`#07884c`), "todo branco e dourado".

## Decisão
Primeira versão (22/11, 21h): cartão branco com faixa dourada, botão dourado e quadros creme. O Djalma gostou do estilo e
pediu ajustes no mesmo dia: tirar as bordas e linhas douradas, voltar o botão verde e o fundo verde claro nos quadros e
no rodapé. Versão final:
1. Moldura (`container/send/default/v1/default.php`): fundo externo cinza-esverdeado claro `#eef1ef`, cartão branco sem
   borda, sem faixa e sem linha abaixo do logo; cabeçalho branco com o logo Connect dourado centralizado (o mesmo PNG);
   título em grafite `#1f1d17`, texto `#3a3730`; rodapé centralizado em verde bem claro `#f6faf8`, com os mesmos dados
   (nome, CNPJ, endereço, contato, site, política de privacidade e descadastro).
2. Botão verde `#07884c` com texto branco, como antes. Links em dourado escuro `#8a6d1c` (4,7:1 no verde claro).
3. Quadros de destaque, citação e mensagem da vistoria em verde bem claro `#f6faf8`, sem borda; linhas finas das tabelas em
   `#e6eeea`/`#dfe8e3`. Texto secundário `#6f6a5e`. Vermelho só para "com problema" no relatório de vistoria.
4. Nenhum texto, envio ou regra mudou: só cores e espaçamento. Os 5 e-mails do morador (ADR 0601) continuam os mesmos.

## Consequências
- `MailTemplateTest` confere o cabeçalho branco, o rodapé verde claro, o botão verde e que nenhum arquivo da moldura, das
  partes ou dos modelos usa o verde-escuro do cabeçalho antigo ou borda dourada.
- Prévias antes/depois com dados fictícios em `/mnt/project-files/emails/branco-dourado/`.
