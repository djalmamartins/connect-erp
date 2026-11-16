---
codigo: CN-007
titulo: Livro caixa mensal
resumo: Tudo o que entrou e saiu do caixa do condomínio no mês, com o saldo dia a dia e as notas fiscais e comprovantes anexados aos lançamentos. Sai pronto em PDF, ou num pacote com os anexos.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/livro-caixa
menu: Prestação de contas > Livro Caixa
icone: document-text-outline
situacao: funciona
relacionados: lancamentos, extrato-conta, prestacao-de-contas, fechamento-mensal
palavras: livro caixa, caixa, prestação de contas, nota fiscal, comprovante, recibo, conta, fatura, anexo, PDF, síndico, conselho fiscal, link, moradores, livro anual, 5º dia útil, feriado, feriado municipal, feriado estadual, pacote, CN-007
rascunho: nao
manual: sim
---

## Para que serve

O livro caixa mostra, em ordem de data, cada pagamento e recebimento do condomínio no mês, com o saldo depois de cada movimento. Ele junta as notas fiscais e os comprovantes que foram anexados aos lançamentos: cada anexo recebe um número (**A1**, **A2**...) que aparece na linha do lançamento. O livro sai pronto para o síndico e o conselho fiscal, com o resumo do mês, as contas bancárias, o resumo por conta e o espaço das assinaturas.

## Onde fica

No menu lateral, abra [[Prestação de contas > Livro Caixa]]. O livro é de um condomínio só: escolha o condomínio na barra superior.

```tela livro-caixa
rota: /prestacao-de-contas/livro-caixa
condominio: residencial-jardim-das-acacias
legenda: Livro caixa do mês
1: main form[method=get] | Escolha do mês
2: main .erp-page-head__actions :is(a,button):has-text("Abrir PDF") | Botão {{Abrir PDF}}
3: main .erp-page-head__actions :is(a,button):has-text("Pacote com anexos") | Botão {{Pacote com anexos}}
```

## Como gerar o livro do mês

1. Confira o condomínio na barra superior.
2. A tela abre no mês anterior (o que costuma ser entregue). Para outro, escolha o **Mês** e clique em {{Gerar}}. A tela mostra o saldo anterior, as entradas, as saídas, o saldo final e cada movimento.
3. Clique em {{Abrir PDF}} para ver o livro pronto numa aba do navegador (de lá você imprime ou baixa), ou em {{Pacote com anexos}} para um arquivo ZIP com o PDF e todos os anexos numerados (pasta `anexos`).
4. Para imprimir direto do navegador, use {{Imprimir}}.

> As fotos (JPG, PNG, WebP) entram dentro do PDF, uma por página, no fim do livro. Anexos em PDF ou XML não cabem dentro de outro PDF: o índice dos anexos diz que estão no pacote, com o nome do arquivo.

## O que entra no livro

- Só lançamentos **válidos** com **Pago em** dentro do mês (regime de caixa). Conta em aberto não entra.
- O **saldo anterior** é a soma dos saldos das contas bancárias no dia 1, com o saldo inicial de cada conta.
- Transferências entre contas do próprio condomínio não mudam o caixa: aparecem só no quadro das contas bancárias.
- No alto, o livro confere o saldo final com a soma das contas bancárias. Se houver diferença, ela aparece em vermelho (em geral, um lançamento pago sem conta bancária).

## Link para os moradores

Todo mês a prestação de contas precisa chegar aos moradores até o **5º dia útil**. Para isso, o livro caixa tem um link público, com a marca da Connect, que qualquer morador abre no celular ou no computador, sem senha.

- O link mostra as contas pagas e recebidas no mês **por categoria e por fornecedor**. Em cada pagamento vêm, em sequência, o **comprovante**, a **conta ou fatura** e a **nota fiscal** anexados ao lançamento. Exemplo: Água e esgoto › Copasa › pagamento de 12/10, com o comprovante, a conta da Copasa e a nota fiscal.
- Os recebimentos das unidades aparecem só somados ("Recebido de 42 unidades"), sem mostrar quem pagou.
- **Ao fechar o mês**, o sistema gera o link sozinho e manda um e-mail para as pessoas de cada unidade do condomínio (só do condomínio do link) que aceitam comunicados.
- **Ao fechar dezembro**, sai também o **livro anual**: o resumo do ano por categoria e cada mês com os seus documentos.

```tela livro-caixa-link
rota: /prestacao-de-contas/livro-caixa
condominio: residencial-jardim-das-acacias
legenda: Link para os moradores
1: main #link-moradores | Quadro {{Link para os moradores}}
2: main #link-moradores :is(a,button):has-text("Gerar"), main #link-moradores :is(a,button):has-text("Atualizar") | Botão {{Gerar}} ou {{Atualizar}}
```

No quadro **Link para os moradores** você vê o endereço, a versão, se já foi enviado e quantas visitas teve:

1. {{Gerar}} cria o link (o mês precisa estar fechado). Se você anexou um documento depois, {{Atualizar}} cria a versão nova **no mesmo endereço**: quem já recebeu o link vê a versão corrigida.
2. {{Enviar aos moradores}} manda o e-mail da versão atual. A mesma versão não é enviada duas vezes.
3. {{Desativar}} tira o link do ar, com um motivo. Se gerar de novo depois, o endereço muda.
4. {{PDF}} baixa o livro em PDF, igual ao que o morador vê. Cada documento no PDF é um link: clique no nome para abrir a nota ou o comprovante. O morador também tem o botão **Baixar PDF** na página dele.
5. {{Pacote com as notas}} baixa um arquivo ZIP com o PDF e **todos os documentos** do livro, numerados na mesma ordem. No livro anual vem uma pasta por mês. É o livro do ano com as notas para guardar ou mandar ao conselho.

> Se o mês for reaberto para correção, o link continua no ar e avisa que a prestação está em revisão. Ao fechar de novo, ele é atualizado e reenviado.

>! Tudo o que estiver anexado às despesas aparece no link. Antes de fechar o mês, confira se nenhum anexo traz dado pessoal que não deve ir aos moradores.

### O prazo do 5º dia útil

Todo dia o sistema confere o mês anterior de cada condomínio. Quando faltam 2 dias úteis para o 5º dia útil e o mês ainda está aberto, quem pode fechar o mês recebe um aviso no sino do sistema; se o prazo passar, recebe outro. Se o mês já estiver fechado e o link ainda não tiver saído, o sistema envia sozinho. Sábados, domingos, feriados nacionais, Carnaval, Sexta-feira Santa e Corpus Christi não contam como dia útil.

### Feriados do estado e da cidade

Os feriados do estado e da cidade do condomínio também empurram o prazo, mas precisam ser cadastrados uma vez, porque mudam de lugar para lugar. O sistema não traz uma lista pronta.

```tela livro-caixa-feriados
rota: /prestacao-de-contas/livro-caixa
condominio: residencial-jardim-das-acacias
legenda: Prazo e feriados locais
1: main #feriados | Quadro {{Prazo e feriados locais}} com a data do prazo
2: main #feriados button:has-text("Incluir feriado") | Botão {{Incluir feriado}}
```

1. No quadro **Prazo e feriados locais** aparece a data do prazo do livro do mês na cidade do condomínio.
2. Preencha a **Data** e o **Nome**, escolha se vale para a **cidade** ou para o **estado** e se **repete todo ano**. Para um ponto facultativo decretado só neste ano, desmarque **Todo ano**. Depois clique em {{Incluir feriado}}.

O feriado da cidade vale para todos os condomínios da mesma cidade, e o do estado para todos os condomínios da mesma UF. A cidade e o estado vêm do cadastro do condomínio: se o quadro avisar que falta a UF, complete o cadastro em [[Cadastros > Condomínios]].

## Notas fiscais e comprovantes

Os anexos ficam no próprio lançamento: nota fiscal, **conta ou fatura** (a conta de água, luz ou internet), comprovante de pagamento, recibo ou outro documento. Abra o lançamento em [[Financeiro > Lançamentos > Lançamentos]] e use o quadro **Notas e comprovantes**, ao lado do formulário. Veja o passo a passo em [Lançamentos](artigo:lancamentos).

O livro lista, em **Documentos das despesas**, as despesas pagas sem nota fiscal, comprovante ou recibo. Na tela, essas linhas mostram **sem comprovante**: clique para abrir o lançamento e anexar.

>! Enquanto o mês não for fechado no [Fechamento mensal](artigo:fechamento-mensal), o livro sai marcado como **Prévia**. Feche o mês antes de entregar a versão final.

## Quem pode usar

Quem tem acesso aos **Lançamentos** do condomínio pode ver e baixar o livro. Gerar, enviar e desativar o link para os moradores e cadastrar feriados é só para quem pode **fechar o mês**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e cada download fica registrado na auditoria.
