---
codigo: CN-009
titulo: Atender pedidos de dados pessoais (LGPD)
resumo: Veja tudo o que o sistema guarda de uma pessoa, entregue o relatório para ela e anonimize os dados de quem já saiu do condomínio.
categoria: configuracoes
rota: /configuracoes/dados-pessoais
menu: Configurações > Acesso > Dados pessoais (LGPD)
icone: shield-checkmark-outline
situacao: funciona
relacionados: usuarios-do-sistema, permissoes-de-usuarios
palavras: LGPD, dados pessoais, titular, anonimizar, apagar dados, relatório, privacidade, CN-009
rascunho: nao
---

Veja tudo o que o sistema guarda de uma pessoa, entregue o relatório para ela e anonimize os dados de quem já saiu do condomínio.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Dados pessoais (LGPD)]]. A busca vale para todos os condomínios que você acessa.

## Antes de buscar

Confira a identidade de quem pediu. O pedido precisa vir da própria pessoa (o titular) ou de alguém com procuração. Anote como o pedido chegou, por exemplo o e-mail e a data: isso vira o motivo, se for anonimizar.

## Como ver os dados de uma pessoa

1. Preencha **CPF ou CNPJ**, **E-mail** ou os dois.
2. Clique em {{Buscar}}.
3. A tela mostra quantos registros existem em cada lugar: cadastros de morador, proprietário ou inquilino, convites do portal, visitantes, veículos, animais, contas a receber, chamados, entradas e saídas da portaria, visitas previstas, conselho e síndico, mudanças, reservas, lista de espera, presença em assembleia, contatos de cobrança e conta de acesso. Logo abaixo vem a lista dos cadastros.
4. Para entregar à pessoa, clique em {{Baixar relatório (JSON)}}. O arquivo traz todos os dados encontrados, sem senha e sem anotações internas.

O CPF e o e-mail não ficam na barra de endereço. O histórico do sistema registra só que a busca aconteceu.

## Como anonimizar

A anonimização aparece para quem também pode gerenciar usuários.

1. Faça a busca da pessoa.
2. No quadro **Anonimizar**, confira o que vai mudar e o que fica guardado.
3. Escreva o **Motivo** (de 10 a 500 caracteres), por exemplo "pedido do titular por e-mail em 16/11/2026".
4. Clique em {{Anonimizar}} e confirme.

Nome, documento, e-mail e telefone viram "Titular anonimizado" nos cadastros, visitantes, veículos, animais, chamados, entradas e saídas da portaria, visitas previstas, mudanças, reservas, lista de espera e contatos de cobrança. Os convites pendentes são cancelados. As contas a receber, os boletos, os acordos e a prestação de contas continuam guardados, porque o condomínio precisa manter a contabilidade. Os cargos no conselho e a presença nas assembleias também ficam, porque fazem parte das atas.

> Não tem volta. Os dados só voltam restaurando o backup.

## Quando não dá para anonimizar

- A pessoa ainda mora ou tem vínculo ativo: preencha o **Fim do vínculo** em [[Cadastros > Moradores]] antes.
- Existe conta a receber em aberto com ela como pagadora: quite ou baixe a conta antes.

Nesses casos a tela mostra o motivo e o botão {{Anonimizar}} não aparece.

## O que acontece por trás

Quando você usa esta tela, o sistema faz o seguinte:

- A busca vai pelo CPF/CNPJ e/ou pelo e-mail e não fica na barra de endereço. O histórico do sistema guarda só um código da busca, nunca o CPF ou o e-mail.
- Anonimizar troca nome, documento, e-mail e telefone por "Titular anonimizado" nos cadastros, visitantes, veículos, animais, chamados, portaria, mudanças, reservas, lista de espera e contatos de cobrança. As contas a receber, a prestação de contas, o conselho e as atas continuam guardados.
- Não dá para anonimizar quem ainda mora (cadastro ativo sem data de saída no passado) nem quem tem conta em aberto como pagador.
- Não tem volta: os dados só voltam restaurando o backup.
- Consultar exige a permissão de dados pessoais; anonimizar exige também a de gerenciar usuários.

## Quem pode usar

Consultar e baixar exigem a permissão de dados pessoais. Anonimizar exige também a permissão de gerenciar usuários. O administrador libera as permissões em [[Configurações > Acesso > Permissões de Usuários]]. Toda busca, download e anonimização fica no histórico, sem o CPF e sem o e-mail.

>> Veja também: [Usuários do Sistema](artigo:usuarios-do-sistema) e [Permissões de Usuários](artigo:permissoes-de-usuarios).
