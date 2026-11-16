# 0332 — Tela Dados pessoais (LGPD) em Configurações

- Status: Aceito
- Data: 16/11/2026
- Pedido: `docs/ROADMAP_V1.md` etapa 6 (LGPD); Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0328 (relatório do titular), ADR 0330 (anonimização), ADR 0316 (modal do sistema), ADR 0141 (Central de Ajuda)

## Contexto

O relatório (ADR 0328) e a anonimização (ADR 0330) existiam só na API. A equipe da Connect precisa atender o titular pela
tela, sem montar chamada à API na mão.

## Decisão

1. Página CN-009 "Dados pessoais (LGPD)" em Configurações › Acesso, rota `/erp/configuracoes/dados-pessoais`, controller
   `PrivacyScreens`, usando os mesmos serviços da API (`PersonalDataExport` e `PersonalDataAnonymizer`).
2. A busca (CPF/CNPJ e/ou e-mail) vai por **POST** com CSRF, e a resposta é mostrada direto (sem redirecionar), para o CPF e o
   e-mail não ficarem na URL, no histórico do navegador nem no log do servidor. Página com `Cache-Control: no-store`.
3. "Baixar relatório (JSON)" refaz a busca por POST e devolve o arquivo para entregar ao titular.
4. Quem tem `erp.users.manage` vê a prévia da anonimização: o que muda, o que fica e o que impede. Com bloqueio, o botão não
   aparece. Sem bloqueio, pede o motivo (10 a 500 caracteres) e confirma na modal do sistema avisando que não tem volta.
5. Mesmo limite de pedidos e mesma auditoria da API (só o hash da busca, quantidades e motivo), com `origin: erp`.
6. Menu: a página só aparece para quem tem `erp.privacy.export` (Panel::PAGE_PERMISSIONS).

## Consequências

- O catálogo foi editado à mão: `tools/erp-catalog.py` está desatualizado em relação ao `catalog.php` (CN-006 e CN-007 não
  estão nele) e regenerar apagaria entradas. A entrada CN-009 foi acrescentada nos dois, sem regenerar.
- Pendente: texto da política de privacidade e nome do encarregado (dependem do Djalma).
