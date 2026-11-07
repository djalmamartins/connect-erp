---
name: release
description: Validar e preparar um pull request do Organic Code / Connect ERP com checagem de backup, migrations, segurança, rollback e aceite humano.
---
1. Rodar `composer validate`, PHP lint, PHPUnit (dentro do container) e as checagens de segurança instaladas; relatar comandos e resultados reais.
2. Revisar o diff em busca de segredos, dados pessoais ou credenciais.
3. Documentar migrations, backup necessário, plano de rollback e observabilidade (logs/alertas).
4. Preparar o resumo do PR com riscos e lacunas.
5. Nunca fazer merge na `main` nem deploy sem aprovação explícita do proprietário.
