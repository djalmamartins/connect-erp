# 0085 — Configurações do ERP (AP-147 a AP-167): parâmetros, orçamento, saldo inicial, frações, banners e impostos

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: issue #46

## Contexto

O catálogo tem as configurações AP-147 a AP-167. AP-148, AP-152 e AP-153 (acesso) ficaram com o
Djalma. A coordenação fixou: nenhuma credencial bancária ou SMTP no código nem no banco (vem do
ambiente, ADR 0060), toda mudança pela auditoria, e os padrões de hoje mantidos, editáveis por
condomínio quando fizer sentido.

## Decisão

- **Parâmetros** (`ErpSettings`, `erp_settings`, migration 20261108_0540):
  - o valor efetivo vem em camadas: padrão do código, depois padrão da Connect (`condominium_id`
    NULL), depois o valor do condomínio;
  - uma chave `global` só tem valor da Connect, e mudar o padrão da Connect exige acesso a todos os
    condomínios;
  - o catálogo tipado tem 14 chaves:
    - AP-147 Administrativo: e-mail e telefone de atendimento;
    - AP-151 Notificações da prestação de contas: avisar o conselho no fechamento;
    - AP-154 Layout: nome no aplicativo;
    - AP-155 Notificações: comunicados por e-mail e dias entre avisos de cobrança (7);
    - AP-157 Financeiro: dia de vencimento padrão (10);
    - AP-158 Demonstrativo: mostrar contas em aberto e agrupar por conta ou centro;
    - AP-159 Alertas: dias de atraso, vencimento de contratos e saldo baixo;
    - AP-166 Parâmetros: tolerância das frações (0,0001) e linhas por relatório (5.000);
  - **os padrões são os valores de hoje** (o teste compara com as constantes do código);
  - **nenhuma chave guarda credencial**: `safeKey()` recusa nome com senha, token, SMTP, chave de API
    ou conta bancária, e o teste percorre o catálogo;
  - toda alteração e cada volta ao padrão (`reset`) vão para a auditoria, com o valor de antes e o de
    depois.
- **AP-162 Saldo inicial por centro de custo** (`erp_cost_center_openings`): um valor com sinal por
  condomínio e centro, com data até hoje, auditado.
- **AP-163 Previsão orçamentária** (`erp_budget_lines`):
  - os 12 meses de uma conta analítica no ano;
  - competência fechada não muda (ADR 0031);
  - a consulta traz o previsto contra o lançado por competência, com a diferença. Isso resolve também
    o AP-084, que estava pendente no ADR 0084.
- **AP-167 Frações ideais em lote:** todas as unidades ativas, soma 1 dentro da tolerância
  (`params.fraction_tolerance`), tudo ou nada, com auditoria por unidade.
- **AP-156 Banners e AP-164 Impostos**, pelo motor de cadastros (definições `banners` e `taxes`,
  tabelas `erp_banners` e `erp_taxes`; sem condomínio = vale para todos):
  - o banner é **só texto** (título, texto, período e ordem), **sem link nem imagem**: sem risco de
    phishing nem de HTML;
  - o imposto (ISS, IRRF, INSS, PIS/COFINS/CSLL ou outro, com alíquota e a que se aplica) é
    **referência**: não calcula nem lança retenção sozinho.
- **Permissões:** `erp.settings.view` e `erp.settings.manage`. As frações exigem também
  `erp.units.manage`.

## Fora deste ADR

- AP-148, AP-152 e AP-153 (acesso de imobiliárias e moradores) ficaram com o Djalma.
- AP-149 e AP-150 (usuários e permissões) já são o núcleo do Studio (ADR-001).
- AP-160, AP-165 e AP-168 já existem (ADR 0019, 0040 e 0020).
- Os demais módulos ainda não leem estes parâmetros. Passar a ler (por exemplo, o vencimento padrão na
  fatura) é uma mudança por vez, com teste.

## Consequências

- Telas (layout): `/settings` agrupado por página, com a origem de cada valor e o botão "voltar ao
  padrão"; saldo inicial, orçamento e frações; banners e impostos pelas telas de cadastro.
