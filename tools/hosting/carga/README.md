# Teste de carga (ADR 0611)

Mede quantas pessoas o sistema aguenta ao mesmo tempo. **Só numa máquina de teste com dados fictícios.**
Nunca contra a produção, o banco do laboratório do Mac nem sistemas de terceiros.

## 1. Banco só de teste

```bash
mysql -e "CREATE DATABASE connect_lab_carga CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
export MOVESOS_ENV=lab MOVESOS_DB_HOST=127.0.0.1 MOVESOS_DB_NAME=connect_lab_carga MOVESOS_DB_USER=root MOVESOS_DB_PASS=root ERP_DEMO_DATA=true
php service/commands/database-install.php
php tools/hosting/criar-admin.php --email=admin.carga@connect.lab --nome=Admin --sobrenome=Carga --cpf=52998224725
php tools/lab-dados-exemplo.php --usuario=1
php tools/lab-lafayette.php
# 625 cópias do Lafayette = 10 mil unidades; uma conta para cada um dos 10 mil primeiros moradores
php tools/hosting/carga/clonar-condominio.php --origem=3 --copias=625 --moradores=10000
```

Troque a senha do administrador para `Carga@2026` (ou passe `-e ADMIN_EMAIL=... -e SENHA=...` ao k6).

## 2. Servidor parecido com a hospedagem

PHP-FPM com `pm.max_children = 100`, OPcache ligado, Apache ou LiteSpeed lendo o `.htaccess` e o MariaDB na mesma
máquina. O site, o app e o ERP respondem pelos nomes `www.connect.lab`, `app.connect.lab` e `erp.connect.lab`.

## 3. Rodar

```bash
k6 run --local-ips=127.0.1.1-127.0.40.255 -e SERVER=127.0.0.1:8099 -e MAX=10000 -e STEP=2000 -e RAMP=2m -e HOLD=3m \
  --summary-export=resultado.json tools/hosting/carga/misto.js
```

O resumo traz o tempo e os erros de cada degrau (`fase:2000`, `fase:4000`...) e de cada parte (`parte:nativo`,
`parte:web`, `parte:site`, `parte:erp`). A meta de cada degrau é 95% dos pedidos em até 2 s e menos de 1% de erros.

Para só os limites de tentativas do login, use `tools/hosting/load-test-limits.php` (ADR 0348).
