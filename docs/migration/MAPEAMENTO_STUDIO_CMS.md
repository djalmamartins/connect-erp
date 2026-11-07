# Mapeamento Studio → CMS

Origem `container/apps/studio/default`; destino
`container/cms/studio/v1`. O controller foi apontado ao novo caminho e os URLs
de assets foram adaptados. Há 69 registros de rota.

| Legado | CMS Organic Code |
|---|---|
| `default.php` com rotas | `routes.php` |
| layouts e componentes Studio | layouts e componentes preservados em `v1/` |
| `/studio/login`, logout e dashboard | mesmas URLs públicas |
| posts/categorias/páginas | mesmos controllers/models e tabelas |
| mídia/uploads | storage local ignorado pelo Git |
| usuários e permissões | núcleo `Auth`, `Access` e tabelas `access_*` reutilizados |
| logs/auditoria | `app_log` e `system_audit_logs` reutilizados |

Testado: login renderiza 200; `/studio` e `/studio/dash` redirecionam anônimo;
CSS retorna 200; suíte valida autenticação, ACL, CRUD de conteúdo, fila de
notificações, configurações, versões e service desk. Login válido e envio SMTP
dependem de credenciais de teste autorizadas.
