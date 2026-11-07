# Reutilização obrigatória — módulos existentes
Usuários do Sistema, Permissões de Usuários e Logs são funcionalidades já implementadas segundo o proprietário. As rotas AP-097 e equivalentes do sistema de referência ficam como **REFERÊNCIA NÃO NECESSÁRIA PARA REIMPLEMENTAÇÃO**; não como funcionalidade faltante no Connect ERP.
1. Identificar implementações reais no Organic Code, testes, migrations, políticas e contratos.
2. Produzir matriz AS-IS / requisito ERP / lacuna / extensão mínima / risco.
3. Reutilizar sessão, autorização server-side, auditoria e perfis existentes.
4. Se houver lacuna, propor adaptação compatível, sem duplicar tabelas, controllers ou autenticação.
5. Testar isolamento entre condomínios, negação de acesso e auditabilidade.
6. Sem autorização, não reescrever nem migrar o módulo existente.
