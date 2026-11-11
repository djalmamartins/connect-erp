---
codigo: AP-122
titulo: Disco Virtual: arquivos do condomínio
resumo: Guarde os arquivos do condomínio organizados por pasta: atas, contratos, plantas, notas fiscais, apólices. Os arquivos ficam fora do site e só baixa quem tem acesso ao condomínio.
categoria: documentos
rota: /documentos/disco-virtual
menu: Documentos > Disco Virtual
icone: list-outline
situacao: funciona
relacionados: certidao-de-debitos, declaracao-de-inadimplencia, comprovante-de-quitacao, avisos-de-recebimento
palavras: disco virtual, documentos, AP-122
rascunho: nao
---

Guarde os arquivos do condomínio organizados por pasta: atas, contratos, plantas, notas fiscais, apólices. Os arquivos ficam fora do site e só baixa quem tem acesso ao condomínio.

## Onde fica

No menu lateral, abra [[Documentos > Disco Virtual]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela disco-virtual
rota: /documentos/disco-virtual
condominio: residencial-jardim-das-acacias
legenda: Tela Disco Virtual
1: menu | Disco Virtual no menu lateral
2: main :is(form[method=get], .erp-filters) | Filtros: Buscar arquivos
3: main form:not([method=get]) | Enviar arquivo
```

## Enviar arquivo

PDF, imagem (JPG, PNG, WebP), DOCX, XLSX, ODT, ODS, CSV, TXT ou XML, até 20 MB.

1. Preencha **Pasta** e **Escolha ou arraste o arquivo Um arquivo por vez**.
2. Clique em {{Enviar}}.

```tela disco-virtual-form
rota: /documentos/disco-virtual
condominio: residencial-jardim-das-acacias
legenda: Enviar arquivo
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="folder"] | Pasta (obrigatório)
2: main form:not([method=get]) [name="file"] | Escolha ou arraste o arquivo Um arquivo por vez (obrigatório)
3: main form:not([method=get]) [name="title"] | Título (opcional)
4: main form:not([method=get]) button:has-text("Enviar") | Botão {{Enviar}}
```

### O que preencher

- **Pasta** (obrigatório): Opções: Atas, Contratos, Plantas, Notas fiscais e Outros.
- **Escolha ou arraste o arquivo Um arquivo por vez** (obrigatório): Escolha o arquivo no seu computador.
- **Título (opcional)**: Exemplo: Vazio = nome do arquivo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Buscar arquivos**.
Depois clique em {{Buscar}}. Para voltar à lista completa, limpe os filtros.

> Cada arquivo enviado passa pelo antivírus quando ele está ligado. Arquivo bloqueado não pode ser baixado.

> Na tela: *Pasta vazia Envie o primeiro arquivo ao lado.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Documentos**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Emitir a certidão de débitos](artigo:certidao-de-debitos), [Emitir a declaração de inadimplência](artigo:declaracao-de-inadimplencia) e [Emitir o comprovante de quitação](artigo:comprovante-de-quitacao).
