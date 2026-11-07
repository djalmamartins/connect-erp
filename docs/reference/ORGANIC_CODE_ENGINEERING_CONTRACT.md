# Organic Code — engineering contract

- PHP >=8.2, Composer, MySQL 8, MovesCode packages. Reuse existing packages; do not invent replacements.
- `container/{web,app,cms,erp,send}/{theme}/{version}/` isolates environments.
- Theme root contains only `default.php` (layout) and `routes.php` (route definitions) as PHP files.
- Assets belong to theme `assets/`; publish built assets to `public/` through MovesCode Compress.
- Controllers remain under `source/App/` until an explicit architecture decision changes that contract.
- Never copy Realizze branding, business data, credentials, or environment secrets into Organic Code.
- Never modify the Realizze source repository during migration.
- Before implementation: inspect code, write a concise plan and test expectations. For high-risk changes obtain approval.
- After implementation: run syntax checks, tests, and security review; report commands and actual outcomes.
- No claims of passing tests without execution. No production deploy or destructive migration without explicit approval.
- Document durable decisions under `docs/Organic Code/decisions/`.
