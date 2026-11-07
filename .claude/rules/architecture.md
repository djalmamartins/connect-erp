# Architecture
Keep web/app/cms/erp/send isolated by hostname and routes. Keep domain services in source/Services, data models in source/Models, controllers in source/App. Preserve the theme contract documented in CLAUDE.md. Prefer minimal incremental changes and tests.
