# Arquitetura-alvo (proposta, sujeita à auditoria do Organic Code)
- Monólito modular PHP com limites de domínio: identidade, organização, cadastros, financeiro, contabilidade, cobrança, leituras, operação, documentos, portaria, relatórios e configurações.
- Respeitar contratos existentes do Organic Code; se o padrão de temas Realizze/MovesCode estiver presente, preservar `container/{web,app,cms,erp,send}` e `default.php` + `routes.php` na raiz da versão; não criar `theme.php`.
- Reusar bibliotecas e componentes instalados; não introduzir framework ou duplicar router sem ADR.
- Entidades canônicas: administradora, condomínio, unidade, pessoa, vínculo de ocupação, conta, lançamento, fatura, cobrança, recebimento, documento, usuário, papel, permissão.
- Isolamento por tenant e condomínio em consulta, comando, exportação e arquivo; verificar ownership no servidor.
- Dinheiro com decimal exato; timestamps com fuso explícito; trilha imutável para transações; operações financeiras idempotentes.
- Migrations versionadas, rollback ou plano de reversão, transações e integridade referencial.
- Eventos de domínio/filas apenas se houver justificativa e infraestrutura confirmada.
