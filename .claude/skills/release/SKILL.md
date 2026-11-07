---
name: release
description: Validate and prepare an Organic Code pull request.
---
Run composer validate, PHP lint, PHPUnit and security checks when installed; check git diff for secrets; document schema migrations and rollback; prepare PR summary. Never merge or deploy without explicit approval.
