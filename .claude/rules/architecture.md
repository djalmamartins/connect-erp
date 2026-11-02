# Architecture

Keep web/app/cms/erp/send isolated by hostname and routes. Domain services live in source/Services, models in source/Models, controllers in source/App. Preserve the theme contract in CLAUDE.md. Prefer incremental, tested changes.
