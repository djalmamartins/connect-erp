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

> Escolha a unidade no campo do alto. A lista mostra só as unidades do condomínio escolhido na barra superior.

## Ver a ficha da pessoa

1. No bloco **Pessoas**, clique no nome do proprietário, inquilino ou morador.
2. Abre a ficha com CPF/CNPJ, telefone, e-mail, início e fim do vínculo, se recebe boleto e comunicados por e-mail, o endereço da unidade, as outras unidades da pessoa nos seus condomínios e as observações.
3. A ficha é só para consulta: nada pode ser alterado nela. Para corrigir um dado, clique em {{Abrir cadastro para alterar}}.

> Clique no telefone ou no e-mail para ligar ou escrever direto.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar unidades](artigo:unidades), [Cadastrar proprietários e inquilinos](artigo:proprietarios-e-inquilinos) e [Visão geral do condomínio](artigo:condominios).
