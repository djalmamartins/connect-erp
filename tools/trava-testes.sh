# Connect ERP — trava para não rodar dois scripts de teste/atualização ao mesmo tempo no Mac.
# Usado (com `source`) pelo tools/testar-main.sh e pelo tools/lab-atualizar.sh.
#
# 16/11/2026: um lab-atualizar.sh rodou junto com o testar-main.sh. O segundo script chama o
# limpar-banco-teste.sh, que encerra os PHPs de teste (composer test:*) e as conexões do movesos_test
# do primeiro, e ainda recria o banco de teste e reinicia o legacy-db. O primeiro mostrava
# "MySQL server has gone away" em quase toda a integração e o smoke caía no meio.
#
# A trava é uma pasta (mkdir é atômico também no macOS, que não tem flock) em .local/trava-testes,
# com o PID, o script e a hora. Trava de um processo que já morreu (Ctrl+C, Mac reiniciado) é
# tomada de volta sozinha.

trava_dir=""

# trava_pegar <nome do script>: sai com erro e explica se outro script estiver rodando.
trava_pegar() {
  local nome=$1 base pid quem
  base=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.local
  mkdir -p "$base"
  trava_dir="$base/trava-testes"
  if ! mkdir "$trava_dir" 2>/dev/null; then
    pid=$(cat "$trava_dir/pid" 2>/dev/null || true)
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
      quem=$(cat "$trava_dir/quem" 2>/dev/null || echo "outro script")
      echo "PARADO: $quem ainda está rodando (PID $pid)." >&2
      echo "Espere ele terminar. Dois scripts juntos derrubam os testes um do outro." >&2
      echo "Se ele travou, encerre com: kill $pid" >&2
      trava_dir=""
      return 1
    fi
    # Processo morto: a trava ficou de uma rodada interrompida.
    rm -rf "$trava_dir"
    if ! mkdir "$trava_dir" 2>/dev/null; then
      echo "PARADO: outro script pegou a trava agora mesmo; rode de novo daqui a pouco." >&2
      trava_dir=""
      return 1
    fi
  fi
  echo "$$" >"$trava_dir/pid"
  echo "$nome, iniciado às $(date '+%H:%M:%S de %d/%m')" >"$trava_dir/quem"
}

# trava_soltar: remove a trava só se ela for deste processo.
trava_soltar() {
  if [ -n "$trava_dir" ] && [ "$(cat "$trava_dir/pid" 2>/dev/null)" = "$$" ]; then
    rm -rf "$trava_dir"
  fi
  trava_dir=""
}
