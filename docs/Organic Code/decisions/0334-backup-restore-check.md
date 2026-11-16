# 0334 — Teste de restauração do backup

- Status: Aceito
- Data: 16/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.5 e etapa 5 ("Testar a restauração... Sem isso o backup não conta"); Djalma, 16/11/2026:
  "tome as melhores decisões"
- Relacionadas: ADR 0325 (backup na hospedagem), ADR 0333 (monitoramento)

## Contexto

O backup diário (ADR 0325) gera o banco e os arquivos e manda ao OneDrive cifrado, mas nunca tinha sido restaurado. Na
hospedagem compartilhada o usuário do banco não cria bancos: só o hPanel cria.

## Decisão

1. `tools/hosting/restore-check.sh` restaura o backup (o local mais novo, um arquivo dado ou, com `--remoto`, o diário mais
   novo do `BACKUP_RCLONE_REMOTE`) num banco separado, `BACKUP_RESTORE_DB`, criado vazio no hPanel com o mesmo usuário.
2. Travas antes de qualquer acesso ao banco: o banco de teste precisa existir no `.env`, ter "restore" no nome, ser só
   letras, números e `_` e ser diferente do banco do sistema. O script só lê o banco real (contagens).
3. Confere o `.sql.gz` (`gzip -t`), esvazia só o banco de teste, importa, lista o pacote de arquivos e compara tabela por
   tabela: tabelas faltando no backup fazem o teste falhar; as linhas aparecem lado a lado (o real muda depois do backup).
4. Mostra o tempo de restauração e grava a linha em `storage/logs/restore-check.log`. Roda à mão, uma vez por mês.

## Consequências

- Testado no ambiente de desenvolvimento: 165 tabelas restauradas e conferidas em 4 s. Falta rodar na hospedagem com o
  backup do OneDrive, o que depende do plano contratado e da conta Microsoft da Connect.
- Não restaura por cima do banco real: restaurar de verdade (desastre) continua manual, pelo roteiro de HOSPEDAGEM.md.
