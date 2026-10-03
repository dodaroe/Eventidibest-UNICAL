#!/usr/bin/env bash
# =========================================================================
# Verifica automatica del portale dopo un aggiornamento.
# Controlla dall'esterno, come farebbe un visitatore, che:
#   - le pagine pubbliche rispondano 200 e senza errori PHP visibili
#   - l'area admin senza login rimandi al login (non mostri contenuti)
#   - i file riservati (.env, config.php, cache/, backups/, strumenti/) siano bloccati
#   - i cron rifiutino le chiamate senza chiave (403)
#   - le intestazioni di sicurezza (CSP, HSTS, X-Frame-Options) siano presenti
#   - fogli di stile e script siano serviti dal portale (nessuna connessione a terzi)
#     e rispondano tutti 200
# Facoltativo: se trova php, controlla la sintassi di tutti i file .php della cartella.
#
# Uso (dal server o da qualunque computer con bash e curl):
#   bash strumenti/verifica_sito.sh
#   bash strumenti/verifica_sito.sh https://dibest2.unical.it/eventi openlab fsl
#   PHP_BIN=/opt/lampp/bin/php bash strumenti/verifica_sito.sh      (anche controllo sintassi)
# Esce con codice 0 se tutto è a posto, 1 se almeno un controllo fallisce.
# La cartella strumenti/ è bloccata al web dal suo .htaccess.
# =========================================================================

# Indirizzo predefinito: la cartella del portale sul server (didattica o eventi); da un altro computer: didattica
CARTELLA_SITO="$(basename "$(cd "$(dirname "$0")/.." && pwd)")"
case "$CARTELLA_SITO" in eventi|didattica) ;; *) CARTELLA_SITO=didattica ;; esac
BASE="${1:-https://dibest2.unical.it/$CARTELLA_SITO}"
BASE="${BASE%/}"
shift 2>/dev/null
AREE=("$@")
[ ${#AREE[@]} -eq 0 ] && AREE=(openlab fsl)

OK=0; KO=0
TMP="$(mktemp -d 2>/dev/null || echo /tmp/verifica_sito_$$)"; mkdir -p "$TMP"
trap 'rm -rf "$TMP"' EXIT

if [ -t 1 ]; then V=$'\e[32m'; R=$'\e[31m'; G=$'\e[90m'; N=$'\e[0m'; else V=; R=; G=; N=; fi
bene()  { OK=$((OK+1)); echo "  ${V}OK${N}  $1"; }
male()  { KO=$((KO+1)); echo "  ${R}KO${N}  $1"; }
titolo(){ echo; echo "== $1"; }

# codice HTTP senza seguire i redirect
codice() { curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$1"; }

HOST="$(echo "$BASE" | sed -E 's#^https?://([^/]+).*#\1#')"
PERCORSO="$(echo "$BASE" | sed -E 's#^https?://[^/]+##')"
echo "Verifica di $BASE  ($(date '+%d/%m/%Y %H:%M'))"

# -------------------------------------------------------------------------
titolo "Pagine pubbliche"
PAGINE=(index.php privacy.php crediti.php verifica_attestato.php)
for a in "${AREE[@]}"; do PAGINE+=("$a.php"); done
i=0
for p in "${PAGINE[@]}"; do
    i=$((i+1))
    f="$TMP/pagina_$i.html"
    c=$(curl -s -o "$f" -D "$TMP/head_$i.txt" -w '%{http_code}' --max-time 30 "$BASE/$p")
    if [ "$c" != "200" ]; then male "$p risponde $c"; continue; fi
    if grep -qE '<b>(Fatal error|Parse error|Warning|Notice|Deprecated)</b>|(PHP )?(Fatal error|Parse error): |Uncaught (Error|Exception|TypeError)' "$f"; then
        male "$p contiene un messaggio di errore PHP"
    else
        bene "$p (200)"
    fi
done

# -------------------------------------------------------------------------
titolo "Area amministrazione senza login"
for p in admin/ admin/dashboard.php admin/utenti.php admin/sistema.php; do
    c=$(codice "$BASE/$p")
    case "$c" in 30[1237]|401|403) bene "$p → $c (non accessibile)";; *) male "$p risponde $c: dovrebbe rimandare al login";; esac
done

# -------------------------------------------------------------------------
titolo "File e cartelle riservati"
for p in .env config.php functions.php cache/configurazione_portale.json backups/ strumenti/verifica_sito.sh database/ install.php.save inc/base.php modelli_documenti/convenzione_precompilabile.docx modelli_documenti/lettera_incarico_tutorato.docx uploads/convenzioni/ uploads/incarichi/ uploads/pratiche/ uploads/verbali/ strumenti/prove_server.sh; do
    c=$(codice "$BASE/$p")
    case "$c" in 403|404) bene "$p → $c";; *) male "$p risponde $c: deve essere bloccato (403/404)";; esac
done

# -------------------------------------------------------------------------
titolo "Cron senza chiave"
for p in cron_background.php cron_attestati.php admin/cron_backup.php admin/cron_reminders.php; do
    c=$(codice "$BASE/$p")
    case "$c" in 403|302|401) bene "$p → $c";; *) male "$p risponde $c: senza chiave deve rispondere 403";; esac
done

# -------------------------------------------------------------------------
titolo "Intestazioni di sicurezza (home)"
H="$TMP/head_1.txt"
controlla_header() {
    if grep -qi "^$1:" "$H"; then bene "$1 presente"; else male "$1 mancante"; fi
}
controlla_header "Content-Security-Policy"
controlla_header "Strict-Transport-Security"
controlla_header "X-Frame-Options"
controlla_header "X-Content-Type-Options"
if grep -i '^Content-Security-Policy:' "$H" | grep -qiE 'fonts\.googleapis|fonts\.gstatic'; then
    male "la CSP consente ancora Google Fonts"
fi

# -------------------------------------------------------------------------
titolo "Risorse caricate dalle pagine (script, fogli di stile, icone)"
: > "$TMP/risorse.txt"
for f in "$TMP"/pagina_*.html; do
    # src="..." di script/img/iframe e href="..." dei <link>
    grep -oiE '<(script|img|iframe|source)[^>]+src="[^"]+"' "$f" | sed -E 's/.*src="([^"]+)".*/\1/' >> "$TMP/risorse.txt"
    grep -oiE '<link[^>]+href="[^"]+"' "$f" | grep -viE 'rel="(canonical|alternate)"' | sed -E 's/.*href="([^"]+)".*/\1/' >> "$TMP/risorse.txt"
    # risorse scritte dagli script di ripiego (document.write) non contano: sono usate solo se manca il file locale
done
sort -u "$TMP/risorse.txt" -o "$TMP/risorse.txt"
esterne=0; rotte=0; n=0
while IFS= read -r u; do
    [ -z "$u" ] && continue
    case "$u" in data:*|blob:*|javascript:*|\#*) continue;; esac
    u="${u//&amp;/&}"
    if echo "$u" | grep -qE '^(https?:)?//'; then
        h="$(echo "$u" | sed -E 's#^(https?:)?//([^/]+).*#\2#')"
        if [ "$h" != "$HOST" ]; then esterne=$((esterne+1)); male "risorsa esterna: $u"; continue; fi
        url="$u"; case "$url" in //*) url="https:$url";; esac
    elif [ "${u:0:1}" = "/" ]; then url="https://$HOST$u"
    else url="$BASE/$u"
    fi
    n=$((n+1))
    c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 -L "$url")
    if [ "$c" != "200" ]; then rotte=$((rotte+1)); male "$u risponde $c"; fi
done < "$TMP/risorse.txt"
[ $esterne -eq 0 ] && bene "nessuna risorsa caricata da siti esterni"
[ $rotte -eq 0 ] && bene "$n risorse locali, tutte raggiungibili"

# font e icone richiamati dai fogli di stile locali
for css in assets/vendor/fonts/titillium-lora.css assets/vendor/cdnjs/ajax/libs/font-awesome/6.4.0/css/all.min.css; do
    c=$(codice "$BASE/$css")
    [ "$c" = "200" ] && bene "$css" || male "$css risponde $c"
done
for js in assets/js/qrcode-generator-1.4.4.min.js assets/js/html5-qrcode-2.3.8.min.js sw.js manifest.json; do
    c=$(codice "$BASE/$js")
    [ "$c" = "200" ] && bene "$js" || male "$js risponde $c"
done

# -------------------------------------------------------------------------
if [ -n "$PHP_BIN" ] || command -v php >/dev/null 2>&1; then
    PHP="${PHP_BIN:-php}"
    CARTELLA="$(cd "$(dirname "$0")/.." && pwd)"
    titolo "Sintassi dei file PHP ($CARTELLA)"
    errori=0; tot=0
    while IFS= read -r -d '' f; do
        tot=$((tot+1))
        out="$("$PHP" -l "$f" 2>&1)" || { errori=$((errori+1)); male "${f#$CARTELLA/}: $(echo "$out" | head -1)"; }
    done < <(find "$CARTELLA" -name '*.php' -not -path '*/vendor/*' -not -path '*/backups/*' -not -path '*/.git/*' -print0)
    [ $errori -eq 0 ] && bene "$tot file PHP senza errori di sintassi"
else
    echo; echo "${G}(php non trovato: salto il controllo di sintassi; sul server usare PHP_BIN=/opt/lampp/bin/php)${N}"
fi

# -------------------------------------------------------------------------
echo
if [ $KO -eq 0 ]; then
    echo "${V}Tutto a posto: $OK controlli superati.${N}"; exit 0
else
    echo "${R}$KO controlli falliti${N}, $OK superati."; exit 1
fi
