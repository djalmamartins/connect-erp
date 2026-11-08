# Módulo Vistorias (ERP v1, CN-001)

Spec: `docs/specs/pages/cn-001-vistorias.md`. Decisão: `docs/Organic Code/decisions/0015-inspections-gps-photos.md`.

## Entregue (2026-11-08)

| Item | Implementação |
|---|---|
| Rotas web | `/erp/operacional/vistorias` (lista e programação), `/nova` (check-in), `/{id}` (checklist), `/{id}/relatorio`, `/{id}/relatorio/pdf`, `/configurar` (modelos, planos, localização); POST de itens, fotos, concluir, cancelar, enviar (`container/erp/connect/v1/routes.php`) |
| API | `GET /api/v1/inspection-templates`, `GET /api/v1/inspections`, `GET /api/v1/inspections/schedule`, `POST /api/v1/inspections`, `GET /api/v1/inspections/{id}`, `PUT /api/v1/inspections/{id}/items/{item}`, `POST /api/v1/inspections/{id}/items/{item}/photos`, `POST /api/v1/inspections/{id}/complete` (`docs/api/openapi.yaml`) |
| Controllers | `Erp\Connect\Inspections`, `Api\V1\Inspections` |
| Serviços | `Services\Inspections\InspectionService` (regras e escopo), `Geo`, `Schedule`, `ChecklistText`, `PhotoStorage`, `InspectionReport` |
| Dados | migration `20261108_0101_inspections` (+ `.down.sql`): `condominium_geolocations`, `inspection_templates`, `inspection_template_items`, `inspection_plans`, `inspections`, `inspection_answers`, `inspection_photos`, `inspection_report_deliveries`; dois modelos iniciais (Ronda semanal, Vistoria mensal) |
| Permissões | `erp.inspections.view`, `erp.inspections.perform`, `erp.inspections.manage` (developer e super_admin) |
| E-mail | `container/send/default/v1/pages/inspection-report.php` via `MailTemplate` + `Email::queue` (fila `mail_queue`) |
| Páginas | `pages/inspections/{index,create,show,settings,report-document}.php`, `assets/css/inspections.css`, `assets/js/inspections.js` |

## Segurança e LGPD

- Toda leitura/escrita passa por `CondominiumScope`; fora do escopo responde 404.
- Só quem fez o check-in (ou quem tem `manage`) altera a vistoria; concluída/cancelada não muda.
- Fotos em `storage/private/inspections` (bloqueado no `.htaccess`, no `.gitignore` e com `.htaccess` próprio), servidas por rota com escopo, `nosniff` e CSP.
- Upload: tipo pelo conteúdo, imagem decodificada e regravada como JPEG ≤ 1600 px (descarta EXIF e qualquer carga escondida), limite 15 MB.
- CSRF em todo POST da tela; `X-CSRF-Token` na API; limite de 5 envios de relatório a cada 10 min por sessão.
- Auditoria (`Support\Audit`) em modelos, planos, localização, check-in, respostas, fotos, conclusão e cancelamento.
- A posição GPS é informada pelo aparelho e pode ser falsificada: é evidência, não prova.

## Operação

- A localização do navegador só funciona em **HTTPS** (ou localhost). Em `http://erp.connect.lab` o check-in cai em "sem localização" e pede justificativa.
- Rodar `composer db:migrate` para criar as tabelas; cadastrar a localização de cada condomínio em Configurar (botão "Usar minha localização" no local).
- Envio real depende do worker da fila `mail_queue` já existente.

## Pendente

- Respostas do proprietário listadas na spec (destinatários padrão, integração com ocorrências/checklist de chegada).
- Envio offline (sem sinal dentro do prédio): hoje cada item é salvo na hora; fila offline exigiria service worker.
- Limpeza de PDFs gerados em `storage/private/inspections/reports` após o envio.
