#!/usr/bin/env bash
# Connect ERP — backup dos arquivos privados: Disco Virtual (ADR 0047) e arquivos importados (ADR 0058).
#
#   bash tools/backup-files.sh        # imprime o caminho do .tar.gz gerado
#
# storage/private/{documents,imports} fica fora do Git (.gitignore) e fora da pasta pública; o banco
# guarda só os metadados. Por isso o backup do banco (tools/backup-database.sh) e este
# precisam ser feitos juntos: um sem o outro deixa registros sem arquivo ou arquivo sem dono.
set -euo pipefail
project=$(cd "$(dirname "$0")/.." && pwd)
private_dir="$project/storage/private"
backup_dir="$project/.local/backups"
mkdir -p "$backup_dir"
output="$backup_dir/connect-erp-files-$(date +%Y%m%d-%H%M%S).tar.gz"
dirs=""
for d in documents imports; do
  [ -d "$private_dir/$d" ] && dirs="$dirs $d"
done
if [ -n "$dirs" ]; then
  # shellcheck disable=SC2086
  tar -czf "$output" -C "$private_dir" $dirs
else
  # Ainda sem arquivos: gera um pacote vazio para o par banco + arquivos ficar completo.
  tar -czf "$output" -T /dev/null
fi
chmod 600 "$output"
echo "$output"
