---
name: authentication
description: Implement and audit account registration, login, email verification, password reset and TOTP 2FA.
---
Review user schema and migrations; verify email uniqueness, password_hash/password_verify, secure sessions, CSRF, rate limits, reset token expiry and one-time use. TOTP secrets must be encrypted at rest, recovery codes hashed and single-use. Add tests for incorrect credentials, unverified accounts, 2FA bypass, replay, logout and session revocation. Do not claim readiness without database-backed integration tests.
