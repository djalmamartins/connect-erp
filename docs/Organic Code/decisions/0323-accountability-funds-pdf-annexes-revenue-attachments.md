# 0323 — Sobras e fundo de reserva na prestação de contas, comprovantes em PDF dentro do documento e anexos na Nova Receita

- Status: Aceito
- Data: 16/11/2026
- Pedido: Djalma, 16/11/2026 ("vamos seguir" sobre as pendências da revisão de 15/11; escolheu "Instalar a biblioteca" para juntar os PDFs).
- Relacionadas: ADR 0169 (lançamentos patrimoniais), 0306 (PDF da prestação de contas), 0314 (modelo 3 e PDF no navegador)

## Contexto

O relatório de lançamentos da prestação de contas não mostrava quanto de cada despesa saiu das sobras de rateio
ou do fundo de reserva. Comprovantes anexados em PDF só eram citados, porque o dompdf não incorpora PDF. A Nova
Receita (modelo 8) mandava anexar depois, lançamento por lançamento.

## Decisão

1. Colunas **Sobras de Rateio (R$)** e **Fundo de Reserva (R$)** no relatório de lançamentos, com total e total
   geral. Valor = débito menos crédito dos lançamentos patrimoniais ligados à despesa (`linked_entry_id`) nas
   raízes 8.2 (fundo de reserva) e 9.1 (resultados acumulados, lida como "sobras"), incluindo subcontas.
   A leitura de 9.1 como "sobras de rateio" é INFERIDA do plano padrão; trocar a constante se o Djalma indicar outra conta.
2. Comprovantes em PDF entram no documento: instaladas `setasign/fpdi` 2.6 e `setasign/fpdf` 1.8 (MIT, livres).
   O dompdf marca a página de cada despesa (âncora `data-annex-anchor`); o FPDI importa o documento e põe as
   páginas de cada comprovante logo depois da despesa, com faixa de rodapé (linha e site) e numeração refeita em
   todas as páginas. O documento sem anexo em PDF continua idêntico (numerado pelo dompdf).
3. O parser livre do FPDI não lê PDF com compressão de objetos (1.5+) nem protegido: esses continuam citados e
   ficam no pacote do livro caixa (posição `package`). O teste de leitura acontece antes de gerar o documento.
4. Anexos na Nova Receita: `EntryAttachments::attachMany` grava o arquivo uma vez e liga o mesmo documento a
   todas as contas do lançamento (até 2000), mesmo condomínio obrigatório, pulando as que já têm o arquivo ou
   chegaram ao limite, com registro na auditoria. API `POST /api/v1/entry-attachments` (multipart, `entry_ids`
   separado por vírgula), mesma permissão de alterar lançamentos. A tela envia até 5 arquivos de 20 MB depois de
   salvar; falha no envio deixa as contas gravadas e avisa qual arquivo faltou.
5. PENDENTE com o Djalma: "tipo da fatura" (juntar o rateio no boleto da fatura da unidade muda o modelo, hoje
   um boleto por conta) e "conta relacionada" (sentido não confirmado).

## Consequências

- Nova dependência Composer: no Mac, `bash tools/lab-atualizar.sh` roda o `composer install`.
- Testes: `AccountabilityDocumentTest` (colunas e junção do PDF), `EntryAttachmentsManyTest`.
