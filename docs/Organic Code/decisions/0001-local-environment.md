# 0001 — Local environment on the shared XAMPP stack

Status: accepted (2026-11-02)

## Decision
Organic Code runs locally on the existing `dev-apache` and `dev-mysql` containers. The project `compose.yaml` and `tools/setup.py --start` are not used, because they would start a second Apache and MySQL.

- All `*.organiccode.lab` hosts serve `public/`; the environment is selected by hostname.
- `organiccode.lab` redirects to `www.organiccode.lab`.
- Certificates live in the shared `certs/` as `organiccode.pem` and `organiccode-key.pem`.
- Data lives in the dedicated `organiccode_dev` database, accessed by the `organiccode` user only. From containers, the database host is `mysql`.

## Activation without rebuild
`apache/organiccode.conf` is kept for future image builds, and is activated with `docker cp`, `a2ensite`, `configtest` and `graceful`. The running container is not recreated, because `homolog.realizze.lab` has no configuration in `apache/` and a rebuild could remove it.

## Consequence
Recreating `dev-apache` requires first confirming that every active vhost has a source file in `apache/`.

## Scripts
`tools/local/install-xampp.sh` applies the changes after a backup. `tools/local/test-local.sh` is read-only.
