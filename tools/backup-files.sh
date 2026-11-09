#!/usr/bin/env bash
# Connect ERP — backup dos arquivos privados (Disco Virtual, ADR 0047).
#
#   bash tools/backup-files.sh        # imprime o caminho do .tar.gz gerado
#
# storage/private/documents fica fora do Git (.gitignore) e fora da pasta pública; o banco
# guarda só os metadados. Por isso o backup do banco (tools/backup-database.sh) e este
# precisam ser feitos juntos: um sem o outro deixa registros sem arquivo ou arquivo sem dono.
set -euo pipefail
project=$(cd "$(dirname "$0")/.." && pwd)
source_dir="$project/storage/private/documents"
backup_dir="$project/.local/backups"
mkdir -p "$backup_dir"
output="$backup_dir/connect-erp-files-$(date +%Y%m%d-%H%M%S).tar.gz"
if [ -d "$source_dir" ]; then
  tar -czf "$output" -C "$project/storage/private" documents
else
  # Ainda sem arquivos: gera um pacote vazio para o par banco + arquivos ficar completo.
  tar -czf "$output" -T /dev/null
fi
chmod 600 "$output"
echo "$output"
