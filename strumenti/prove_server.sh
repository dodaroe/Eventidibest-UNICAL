#!/usr/bin/env bash
# =========================================================================
# prove_server.sh - Controlli da lanciare SUL SERVER dopo aver caricato i file (dalla cartella del portale):
#
#   PROVE_DB_USER=utente_prove PROVE_DB_PASS='…' bash strumenti/prove_server.sh
#
# 1. Versione di PHP ed estensioni necessarie (mysqli, openssl, zip, curl, mbstring, soap per la firma remota Aruba).
# 2. Sintassi di tutti i file .php (php -l): un file caricato a metà o rovinato si vede subito.
# 3. Cartelle scrivibili (cache/, uploads/) e file riservati presenti (.htaccess, .env).
# 4. Prove delle funzioni (strumenti/prove/esegui.php) su un database usa e getta: serve un utente MySQL che possa
#    creare e cancellare il database PROVE_DB_NAME (predefinito eventi_prova; il nome deve contenere «prova»).
#    Il database del portale non viene mai toccato; i file delle prove vanno in cache/prove/ e si cancellano alla fine.
#    Variabili: PROVE_DB_HOST (127.0.0.1), PROVE_DB_USER (root), PROVE_DB_PASS, PROVE_DB_NAME, PHP_BIN (php), MYSQL_BIN (mysql).
#    PROVE_SALTA_DB=1 salta questa parte (se non c'è un utente MySQL per le prove).
# Esce con codice 1 se qualcosa non va.
# =========================================================================
set -u
cd "$(dirname "$0")/.." || exit 2
PHP="${PHP_BIN:-php}"
KO=0
ok()  { printf '  \e[32mOK\e[0m  %s\n' "$1"; }
ko()  { printf '  \e[31mKO\e[0m  %s\n' "$1"; KO=$((KO + 1)); }
avv() { printf '  \e[33m!!\e[0m  %s\n' "$1"; }

echo "== PHP"
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.0.0", ">=") ? 0 : 1);' && ok "PHP $("$PHP" -r 'echo PHP_VERSION;')" || ko "serve PHP 8.0 o successivo (ora $("$PHP" -r 'echo PHP_VERSION;'))"
for e in mysqli openssl zip curl mbstring json fileinfo; do
    "$PHP" -r "exit(extension_loaded('$e') ? 0 : 1);" && ok "estensione $e" || ko "manca l'estensione $e"
done
"$PHP" -r "exit(extension_loaded('soap') ? 0 : 1);" && ok "estensione soap (firma remota Aruba)" || avv "manca l'estensione soap: la firma remota Aruba non funziona (resta scarica, firma e ricarica)"

echo; echo "== Sintassi dei file PHP"
n=0; err=0
while IFS= read -r -d '' f; do
    n=$((n + 1))
    out=$("$PHP" -l "$f" 2>&1) || { ko "$out"; err=$((err + 1)); }
done < <(find . -name '*.php' -not -path './strumenti/locale/www/*' -not -path './vendor/*' -not -path './cache/*' -print0)
[ "$err" -eq 0 ] && ok "$n file senza errori di sintassi"

echo; echo "== Cartelle e file"
for d in cache uploads; do
    [ -d "$d" ] && [ -w "$d" ] && ok "$d/ scrivibile" || ko "$d/ non esiste o non è scrivibile dall'utente $(whoami) (il server web deve poterci scrivere)"
done
[ -f .env ] && ok ".env presente" || ko ".env mancante (copia .env.example e compilalo)"
[ -f .htaccess ] && ok ".htaccess presente" || ko ".htaccess mancante nella cartella del portale"
[ -f cache/.htaccess ] && ok "cache/.htaccess presente" || ko "cache/.htaccess mancante: la cache sarebbe raggiungibile dal web"
for d in uploads/incarichi uploads/pratiche uploads/verbali; do
    if [ -d "$d" ]; then [ -f "$d/.htaccess" ] && ok "$d/.htaccess presente" || ko "$d/.htaccess mancante: i documenti sarebbero raggiungibili dal web"; fi
done
if [ -f .env ]; then
    for k in DB_HOST DB_NAME DB_USER; do grep -q "^$k=" .env && ok ".env: $k" || ko ".env: manca $k"; done
    grep -q '^ARUBA_ARSS_URL=.\+' .env && ok ".env: firma remota Aruba configurata" || avv ".env: ARUBA_ARSS_URL vuoto, la firma si fa scaricando e ricaricando il PDF"
fi

echo
if [ -n "${PROVE_SALTA_DB:-}" ]; then
    avv "prove delle funzioni saltate (PROVE_SALTA_DB)"
else
    echo "== Prove delle funzioni (database ${PROVE_DB_NAME:-eventi_prova} su ${PROVE_DB_HOST:-127.0.0.1})"
    ZIPOPT=$("$PHP" -r "echo extension_loaded('zip') ? '' : '-d extension=zip';")
    # shellcheck disable=SC2086
    PROVE_SOLO_FUNZIONI=1 MYSQL_BIN="${MYSQL_BIN:-$(command -v mysql || echo mysql)}" "$PHP" $ZIPOPT strumenti/prove/esegui.php 2>&1 | sed 's/^/  /'
    [ "${PIPESTATUS[0]}" -eq 0 ] || ko "alcune prove delle funzioni non sono passate (vedi sopra)"
fi

echo
if [ "$KO" -eq 0 ]; then printf '\e[32mTutto a posto.\e[0m\n'; else printf '\e[31m%d problemi.\e[0m\n' "$KO"; fi
exit $([ "$KO" -eq 0 ] && echo 0 || echo 1)
