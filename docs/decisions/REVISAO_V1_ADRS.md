# Revisão das ADRs para a v1.0 (20/11/2026)

Base: `main` 0c0f4f4 + PR da ADR 0399, 376 ADRs em `docs/Organic Code/decisions/` e 3 de fundação.
ADR é histórico da decisão e é citada no código, nas migrations e nos testes; por isso **nenhum arquivo de
ADR é apagado**. "Eliminar" aqui quer dizer tirar do caminho da v1.0 a função que a ADR criou.

## 1. Prontas e ligadas (nenhuma ação)

Cerca de 350 ADRs, de 0001 a 0402, menos as dos itens 2 a 4. Cadastros, unidades, pessoas, financeiro,
cobrança no sandbox, relatórios, prestação de contas, operacional, portaria, assembleia, Help Desk, Central
de Ajuda, segurança (0337–0348, 0355, 0364–0367, 0387–0393), LGPD (0328, 0330, 0332, 0350–0353), hospedagem
(0325, 0333–0336, 0354–0362, 0368, 0379–0385, 0396), e-mails (0397) e a base do Realizze (0399). Todas na
`main`, com testes (unit 1388, integração 697 em 20/11).

## 2. Prontas, mas desligadas: só ligam com uma ação do Djalma

| ADR | Função | O que falta para ligar | v1.0? |
|---|---|---|---|
| 0156, 0158, 0318 | Banco Inter (boleto, credencial pela tela, extrato) | certificado de produção no Internet Banking e ok para o 1º boleto real; webhook precisa do endereço público | **sim** |
| 0322 | Cora (2º banco) | credencial de teste (stage) | não (v1.1, se o piloto usar Inter) |
| 0370–0378 | Documentos no OneDrive (`DOCUMENT_STORAGE=onedrive`) | conta Microsoft 365 da Connect e o aplicativo no Azure | não obrigatório: `local` funciona |
| 0098, 0161 | Antivírus no upload (`ERP_AV_MODE=off`) | serviço de antivírus acessível pela hospedagem (INFERIDO: hospedagem compartilhada costuma não ter clamd; conferir) | não |
| 0402 | Honorários e pedido de NFS-e (chave desligada) | dados fiscais da Connect; etapas 2 e 3 dependem da norma de dez/2026 | etapa 1 sim, emissão manual |
| 0302, 0147 | Portal do morador, função por função por condomínio | ligar no condomínio piloto | **sim** (app web) |
| 0303 | App nativo do morador | contas Apple/Google, ícones e textos de loja | não (v1.1) |

## 3. Substituídas: candidatas a eliminar (precisam do ok do Djalma)

| ADR | O que foi | Por que sai | Proposta |
|---|---|---|---|
| 0324, 0349 | Connect Talk dentro do ERP (etapas 1 e 2) | 0359 (17/11): o Talk volta inteiro para o Raspberry Pi | **feito (ADR 0620, aprovado em 20/11):** telas e worker removidos; ficam as tabelas e a API que o Talk do Pi usa |
| 0308 | número reservado para a integração do Talk | a integração virou 0359/0386 | liberar o número |
| 0010 (parte) | identidade dourada do ERP | trocada pelo visual verde (08/11) | nada a remover: já substituída no código |

## 4. Abertas para fechar a v1.0 (do `docs/ROADMAP_V1.md`)

1. Servidor: plano Cloud Startup com SSH e DNS de connectcondominios.com.br (até 25/11).
2. Inter: 4 itens do checklist do sandbox (sandbox fora do ar em 20/11, retestar 23/11), webhook e produção.
3. LGPD/CDC: encarregado, textos de privacidade e termos revisados, contrato com os condomínios, prazos PENDENTES do registro (0350), e o checklist legal de 20/11 (Djalma pediu para conversar antes de mudar).
4. `ALERT_EMAIL`, monitor externo e SMTP da AWS (thread da configuração geral do sistema).
5. CNPJ da Connect no site (rodapé e JSON-LD; `CONNECT_CNPJ` já existe no `.env`).
6. CSP que limita scripts: depois do piloto (0387).
7. Condomínio piloto e os dados reais.

## 5. Arrumação do índice

O índice tem 168 linhas sem a coluna de situação e as outras alternam "Aceita"/"Aceito". Não muda nada no
sistema; pode ser padronizado num PR só de documentação quando houver tempo.
