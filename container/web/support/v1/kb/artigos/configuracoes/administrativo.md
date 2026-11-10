---
codigo: AP-147
titulo: Configurar administrativo
resumo: Alterações valem para os condomínios indicados e ficam registradas no histórico.
categoria: configuracoes
rota: /configuracoes/administrativo
menu: Configurações > Acesso > Administrativo
icone: options-outline
situacao: exemplo
palavras: administrativo, configurações, AP-147
rascunho: nao
---

>! Esta tela ainda mostra **dados de exemplo**. Ela já abre com os filtros e as colunas definitivos, mas os números reais chegam numa próxima versão. Use-a para conhecer o relatório; não tome decisões com os valores mostrados.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Administrativo]].

```tela administrativo
rota: /configuracoes/administrativo
condominio: residencial-jardim-das-acacias
legenda: Tela Administrativo
1: menu | Administrativo no menu lateral
2: main .erp-card | Histórico de alterações
```

## Como preencher

- **Razão social** (obrigatório)
- **CNPJ** (obrigatório)
- **E-mail de contato**
- **Telefone**
- **Horário de atendimento**
- **Prazo de resposta (horas)**

Depois de preencher, clique em {{Salvar}}. O sistema avisa no próprio campo o que precisa corrigir.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

