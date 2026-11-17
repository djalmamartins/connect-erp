# ADR 0370 — Armazenamento dos documentos atrás de uma interface (passo 1 do OneDrive)

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0047 (Disco Virtual), ROADMAP_V1 §3.5 (documentos dos condomínios no OneDrive da Connect)

## Contexto

O plano da v1.0 leva os documentos dos condomínios para o OneDrive empresarial da Connect (Microsoft Graph). Isso depende da
conta Microsoft 365 da Connect, que ainda não existe. O Disco Virtual gravava e lia direto no disco (`move_uploaded_file`,
`copy`, `is_file`, `unlink`), e vários serviços pegam o caminho local do arquivo (`absolute()`): download, prestação de
contas em PDF, livro-caixa, anexos dos lançamentos, nova verificação do antivírus e o app do morador.

## Decisão

- Interface `Source\Support\Storage\DocumentStore` com `put`, `path` e `delete`.
- `LocalDocumentStore`: o comportamento de sempre (pasta privada, 0750/0640, `.htaccess` de defesa), recusando caminho
  absoluto, `..`, barra invertida e byte nulo.
- O `VirtualDisk` grava, lê e apaga só pelo armazenamento (parâmetro novo opcional no construtor; padrão: disco local em
  `storage/private/documents`). O formato do caminho relativo continua conferido no `VirtualDisk` antes de chegar ao
  armazenamento.
- `path()` devolve sempre um caminho local legível. A implementação do OneDrive (próximo passo, com a conta da Connect)
  baixa o arquivo para um cache local e devolve esse caminho; assim nenhum dos serviços que usam `absolute()` muda.

Nada muda para o usuário nem no banco. Fotos de vistoria, importações e foto do perfil do morador passam pelo mesmo
caminho nos próximos passos.

## Testes

`DocumentStoreTest`: grava, lê, permissões, `.htaccess`, apaga, não move arquivo que não veio de upload, recusa caminhos
para fora da pasta; o `VirtualDisk` lê por um armazenamento falso e não chama mais funções de arquivo direto. Integração do
Disco Virtual, anexos, prestação de contas e app do morador: OK (84 testes).
