# Organic Code — engineering contract

- PHP >=8.2, Composer, MySQL 8, MovesCode packages. Reuse existing packages.
- Isolate `container/{web,app,cms,erp,send}/{theme}/{version}/`.
- Only `default.php` and `routes.php` may be PHP files at a theme version root.
- Keep controllers in `source/App/` until a documented change.
- Do not copy Realizze branding, data, or secrets.
- Do not modify the Realizze source repository during migration.
- Plan, implement, test, review, then propose a PR.
- Never claim tests passed unless executed; never deploy or perform destructive migrations without approval.
- Record decisions in `docs/Organic Code/decisions/`.
