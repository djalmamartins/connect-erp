# ADR 0375 — Faxina do cache local do OneDrive

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0336 (faxina diária da hospedagem), ADRs 0373/0374 (OneDrive)

## Contexto

Com `DOCUMENT_STORAGE=onedrive`, cada arquivo aberto fica numa cópia local em `storage/cache/onedrive/{área}` para quem
precisa do caminho no disco (download, PDF, antivírus). Sem limpeza, o cache viraria uma segunda cópia de tudo no plano
de 100 GB.

## Decisão

- `HostingHousekeeping::cachePlan`: apaga a cópia que ficou **7 dias sem uso** e o envio interrompido (`.part`) com
  mais de 1 dia. O original continua no OneDrive; a próxima leitura baixa de novo.
- O `path()` do OneDrive renova a data da cópia a cada uso (`touch`), então o que é usado fica.
- A faxina diária (`tools/hosting/housekeeping.php`, já no cron) percorre o cache, só apaga dentro dele, não segue
  links, e informa a quantidade no JSON (`plan.cache`, `done.cache`); `--dry-run` só mostra.

## Testes

`HostingHousekeepingTest::testOneDriveCachePlan` (7 dias exatos fica, um segundo a mais sai, `.part` em 1 dia);
`OneDriveDocumentStoreTest` (leitura pelo cache renova a data). Rodado de ponta a ponta com um cache falso: `--dry-run`
lista 1 e não apaga; sem ele apaga só o arquivo velho.
