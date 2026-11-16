# 0318 — Carta de cobrança: resumo em duas folhas, textos editáveis e Pix

- Status: Aceito
- Data: 16/11/2026
- Pedido: Djalma, 16/11/2026 à tarde, revendo a carta do ADR 0317 ("o floral tinha que ser um pouco mais
  transparente [...] a área dos canais de atendimento [...] preciso que tenha alguma parte no sistema para mudar
  [...] receita, X cotas de tanto [...] embaixo as despesas [...] do outro lado contas bancárias, fundo [...] em
  algumas gerou um demonstrativo de setembro, receita gigante, despesa gigante, tem que resumir [...] só duas
  folhas") e o QR code do Pix no boleto (pedido na thread do Inter).
- Relacionadas: ADR 0317 (carta de cobrança), 0156 (boleto híbrido do Inter), 0177 (fundos)

## Decisão

1. **Duas folhas, sempre.** Sai a folha de continuação do ADR 0317. A parte interna mostra um resumo:
   - coluna da esquerda: composição do boleto, **receitas do mês** por grupo e conta com as cotas
     ("6 cotas de R$ 650,00", recebimentos da conta agrupados pelo valor, só quando a conta tem mais de um),
     **despesas do mês** por grupo e conta, e o saldo do mês;
   - coluna da direita: **contas bancárias**, **fundos** (as contas de passivo do Razão, como na prestação de
     contas, ADR 0177) e **inadimplência**.
   `BillingLetter::fit` encolhe o lado maior até caber (receitas: cotas → contas → grupos → só o total; despesas:
   contas → grupos → só o total). A área das colunas tem altura fixa com o excedente cortado, como última defesa.
2. **Textos editáveis** em Configurações > Financeiro (AP-157), sem migration (parâmetros do `ErpSettings`):
   título e texto das duas metades da aba externa (valem para todos os condomínios) e título e texto da área de
   avisos da parte interna (cada condomínio pode ter o seu; vazio = sem área de avisos). Tipo novo `textarea`
   (várias linhas, até 900 caracteres). Marcação por linha: `# ` subtítulo, `! ` destaque, `- ` item, `~ ` letra
   miúda. Os padrões repetem o externa.pdf.
3. **Floral** com metade da opacidade.
4. **Pix**: quando o boleto tem o Pix copia e cola (boleto híbrido do Inter), a ficha mostra o QR code do Pix ao
   lado do código de barras. O QR é gerado no servidor (bacon/bacon-qr-code), o copia e cola não sai para serviço
   externo.

## Consequências

- Imagem na área de avisos fica para depois (o parâmetro guarda só texto).
- Muitas contas bancárias ou fundos (mais de ~10) passam da altura e são cortados.

## Testes

`BillingLetterTest`: cotas por valor, encaixe nas duas folhas, blocos do texto livre e quebra de linha do textarea.
