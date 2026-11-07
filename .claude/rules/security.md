# Security
Never commit secrets or `.env`; validate CSRF on state-changing web forms, use parameterized SQL, secure sessions, password hashing, and rate limiting. 2FA must be enforced server-side before authenticated session elevation. Treat all generated code as untrusted until reviewed.
