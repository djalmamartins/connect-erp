---
codigo: AP-003
titulo: Painel da unidade
resumo: Tudo de uma unidade em uma tela: quem mora e quem é o responsável financeiro, débitos em aberto, acordos, documentos emitidos e a rotina (reservas, ocorrências, entregas). Ideal para atender um morador ao telefone.
categoria: condominios
rota: /condominios/painel-da-unidade
menu: Condomínios > Painel da unidade
icone: speedometer-outline
situacao: funciona
relacionados: unidades, proprietarios-e-inquilinos, condominios
palavras: painel da unidade, condomínios, AP-003, ficha da pessoa, telefone do proprietário, cpf do morador, dados do inquilino
rascunho: nao
---

Tudo de uma unidade em uma tela: quem mora e quem é o responsável financeiro, débitos em aberto, acordos, documentos emitidos e a rotina (reservas, ocorrências, entregas). Ideal para atender um morador ao telefone.

## Onde fica

No menu lateral, abra [[Condomínios > Painel da unidade]].

```tela painel-da-unidade
rota: /condominios/painel-da-unidade
condominio: residencial-jardim-das-acacias
legenda: Tela Painel da unidade
1: menu | Painel da unidade no menu lateral
2: main :is(form[method=get], .erp-filters) | Filtros: Unidade do Residencial Jardim das Acácias
```

## Pesquisar e filtrar

Use os filtros no alto da lista: **Unidade do Residencial Jardim das Acácias**.
Depois clique em {{Ver painel}}. Para voltar à lista completa, limpe os filtros.

> Escolha primeiro o condomínio na barra superior: sem ele, o campo de unidade não aparece. Com o condomínio escolhido, a lista mostra só as unidades dele.

No alto do painel aparece **Unidade vazia** ou **Unidade ocupada**, conforme a marca do cadastro da unidade. Para mudar, clique em {{Alterar na ficha da unidade}}.

## Ver a ficha da pessoa

1. No bloco **Pessoas**, clique no nome do proprietário, inquilino ou morador.
2. Abre a ficha com CPF/CNPJ, telefone, e-mail, início e fim do vínculo, se recebe boleto e comunicados por e-mail, o endereço da unidade, as outras unidades da pessoa nos seus condomínios e as observações.
3. A ficha é só para consulta: nada pode ser alterado nela. Para corrigir um dado, clique em {{Abrir cadastro para alterar}}.

> Clique no telefone ou no e-mail para ligar ou escrever direto.

## O que acontece por trás

Quando você usa esta tela, o sistema faz o seguinte:

- O painel só lê: nada é gravado aqui. Para mudar um dado, use o botão que leva ao cadastro.
- Os débitos são as receitas da unidade ainda sem pagamento, calculadas na hora a partir dos lançamentos: as vencidas, com os encargos de atraso até hoje, e as que ainda vão vencer. Os acordos e documentos vêm das telas de cobrança e de documentos.
- Só aparecem as unidades dos condomínios do seu acesso. Sem condomínio escolhido na barra superior, o campo de unidade não aparece; com um escolhido, ele lista só as unidades daquele condomínio.
- A marca **Unidade vazia** ou **Unidade ocupada** vem do cadastro da unidade, onde a equipe a altera.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar unidades](artigo:unidades), [Cadastrar proprietários e inquilinos](artigo:proprietarios-e-inquilinos) e [Visão geral do condomínio](artigo:condominios).
