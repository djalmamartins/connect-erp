<!-- markdownlint-disable MD024 -->
# 0402 — Honorários da Connect por condomínio e pedido da nota fiscal (NFS-e), com chave liga/desliga

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - ADR-002 (Connect não é SaaS);
  - 0011 (cadastro de condomínios: `contract_start_date` e `fee_due_day` já existiam, sem valor);
  - 0085 (configurações por chave: `erp_settings`);
  - 0086 (NF-e de fornecedores: só importação; este ADR é o primeiro passo da emissão);
  - 0156 e 0158 (cofre e tela de credenciais do Inter: modelo para o certificado A1 na etapa 2).

## Contexto

O proprietário pediu, em 14/11/2026, para controlar o valor que cada condomínio paga à Connect pela administração, "como se
fosse uma assinatura", e poder pedir a nota fiscal de cada um. Ele também pediu uma chave para ligar e desligar as notas,
para que nada funcione sem ele querer. Os códigos de serviço e a alíquota do ISS ele vai confirmar com o contador.

O cadastro do condomínio tem o início do contrato e o dia de vencimento da taxa, mas não tem o valor. O formulário do
condomínio (`condominiums/form.php`) não pode ser alterado nesta frente.

A NFS-e de serviço segue o padrão nacional: Emissor Nacional ou API do Ambiente Nacional (Sefin Nacional), com DPS assinada
pelo certificado ICP-Brasil (e-CNPJ A1) do emitente. Na nota dos honorários, a emitente é a Connect, então basta um
certificado. A nota do condomínio para o condômino (reforma tributária, a partir de 01/01/2027) usa o certificado de cada
prédio e depende das normas previstas para dezembro/2026.

## Decisão

Fazer em três etapas, com um motor de emissão só para as duas notas. Este ADR cobre a etapa 1.

1. **Contrato de honorários** (`erp_management_contracts`): um por condomínio, com valor mensal em centavos, início, fim
   opcional, dia de vencimento (1 a 28), descrição do serviço (padrão "Honorários de administração condominial"),
   situação (ativo, suspenso, encerrado) e observações. Não é plano nem assinatura de SaaS: não há cadastro de
   administradoras nem `tenant_id`. A segregação continua por `condominium_id` e pelo `CondominiumScope`.
2. **Pedido de nota** (`erp_nfse_requests`): por contrato e competência (AAAA-MM), com o valor e a descrição copiados do
   contrato no momento do pedido. Situações: `requested`, `issued`, `failed` e `cancelled`. Uma coluna gerada (`open_key`)
   garante um pedido aberto por competência no banco, e não só no código. Um pedido cancelado libera a competência. Nada
   é enviado à Receita nesta etapa; `issued` e `failed` ficam para a etapa 2.
3. **Chave geral** `nfse.enabled` no `ErpSettings`, escopo global, **desligada por padrão**, na tela Configurações ›
   Financeiro (AP-157). Desligada, o serviço recusa todo pedido (409) e a tela esconde o botão. Só quem acessa todos os
   condomínios muda a chave, como as outras globais.
4. **Tela** Condomínios › Honorários (CN-008), com controller próprio e sem passar pelo `Panel`: total mensal dos
   contratos ativos, lista por condomínio, contrato para editar e histórico de pedidos. A API REST tem os mesmos serviços.
5. **Permissões** novas `erp.management_fees.view` e `erp.management_fees.manage`, fail-closed (prefixo `erp.`), dadas a
   `developer` e `super_admin`. Toda gravação é auditada sem dados pessoais.

## Consequências

- O proprietário passa a ver quanto entra por mês de honorários e quais notas já pediu.
- Etapa 2: certificado A1 da Connect guardado criptografado (como as credenciais do Inter), DPS, envio à API Nacional
  primeiro na produção restrita, retorno com número, chave, XML e DANFSe, e cancelamento na Receita. Depende do
  município, do regime tributário, do código de serviço e da alíquota do ISS da Connect.
- Etapa 3: nota do condomínio para quem pagou a cota, emitida na baixa do boleto, com o momento da emissão
  configurável por condomínio. Depende da norma de dezembro/2026, da dispensa dos 20% e do certificado de cada prédio.
- O item do menu aparece para quem tem `erp.access`, porque o mapa de permissões do menu fica no `Panel`, que não é
  alterado nesta frente. A rota e a API recusam (403) quem não tem `erp.management_fees.view`.
- O valor dos honorários não gera conta a receber nem boleto da Connect. Se o proprietário quiser, isso entra depois.
