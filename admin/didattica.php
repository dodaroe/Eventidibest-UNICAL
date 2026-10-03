<?php
// didattica.php - Modulo Didattica (Ufficio didattico):
// - Pratiche: elenco con filtri, dettaglio, stato, messaggi, istruttoria (campi dell'ufficio, seduta, delibera), Excel e Word;
// - Sedute e verbali: sedute del Consiglio con o.d.g. e presenze, pratiche assegnate, verbale in Word ed Excel;
// - Moduli e documenti: documenti da scaricare e moduli online (campi guidati dalle anagrafi, tabelle, campi dell'ufficio,
//   come compaiono nel verbale), con modelli pronti;
// - Ufficio e ricevimento: operatori scelti dall'anagrafe di Ateneo con i loro compiti, sportello di ricevimento dell'ufficio.
// Pagine pubbliche: modulistica.php, modulo.php, pratiche.php.
// Ogni scheda sta in un suo file (didattica_pratiche.php, didattica_sedute.php, didattica_moduli.php, didattica_ufficio.php,
// didattica_statistiche.php) con le sue azioni e la sua pagina; questo file prepara i dati comuni e le include.
require_once 'admin_header.php';
define('DIDATTICA_PANNELLO', true);
const SCHEDE_DIDATTICA = ['pratiche', 'sedute', 'moduli', 'ufficio', 'statistiche'];

if (!$puo_didattica) nega_accesso();
function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tab = in_array($_GET['tab'] ?? '', SCHEDE_DIDATTICA, true) ? $_GET['tab'] : 'pratiche';
$base = "didattica.php?p_id=" . (int)$filtro_p;
$uid = (int)$u_id_curr;
$ids_post = fn() => array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
$autore_nome = nome_autore_ufficio($conn, $utente_admin);
$io_operatore = operatore_ufficio($conn, 0, $utente_admin);
// Referenti dei consigli (senza gli altri permessi della Didattica): solo le sedute dei loro consigli
$solo_ref = !$puo_didattica_tutto;
if ($solo_ref) { $tab = 'sedute'; $autore_nome = trim(($utente_admin['nome'] ?? '') . ' ' . ($utente_admin['cognome'] ?? '')) . ' · Referente del consiglio'; }
$consigli = consigli_didattica($conn);
$puo_consiglio = fn(int $cid) => $puo_didattica_tutto || in_array($cid, $consigli_referente, true);
$puo_seduta = fn(?array $s) => $s && ($puo_didattica_tutto || in_array((int)$s['consiglio_id'], $consigli_referente, true));

// ==============================================================================
// AZIONI
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    // I referenti dei consigli fanno solo le azioni delle sedute (ognuna controlla anche il consiglio)
    if ($solo_ref && !array_intersect(array_keys($_POST), ['salva_seduta', 'elimina_seduta', 'assegna_seduta', 'salva_presenze', 'salva_decisioni', 'applica_esiti', 'aggiungi_persona_consiglio', 'togli_persona_consiglio', 'salva_qualifiche', 'importa_componenti', 'invia_convocazione', 'invia_verbale_firma', 'carica_verbale_firmato'])) nega_accesso();

    $fase = 'azioni';
    foreach (SCHEDE_DIDATTICA as $scheda) require __DIR__ . '/didattica_' . $scheda . '.php';
}

// ==============================================================================
// DATI
// ==============================================================================
$moduli = $conn->query("SELECT m.*, (SELECT COUNT(*) FROM pratiche p WHERE p.modulo_id = m.id) AS n_pratiche,
                               (SELECT COUNT(*) FROM pratiche p WHERE p.modulo_id = m.id AND p.stato IN ('inviata', 'in_lavorazione', 'integrazione')) AS n_aperte
                        FROM didattica_moduli m ORDER BY m.categoria, m.ordine, m.titolo")->fetch_all(MYSQLI_ASSOC);
$categorie = array_values(array_unique(array_column($moduli, 'categoria')));
$n_aperte = (int)$conn->query("SELECT COUNT(*) n FROM pratiche WHERE stato IN ('inviata', 'in_lavorazione', 'integrazione')")->fetch_assoc()['n'];
$sedute = $conn->query("SELECT s.*, (SELECT COUNT(*) FROM pratiche p WHERE p.seduta_id = s.id) AS n_pratiche FROM didattica_sedute s"
                       . ($solo_ref ? " WHERE s.consiglio_id IN (" . implode(',', array_map('intval', $consigli_referente ?: [0])) . ")" : '') . " ORDER BY s.data DESC, s.id DESC")->fetch_all(MYSQLI_ASSOC);
$sedute_future = array_values(array_filter($sedute, fn($s) => !$s['data'] || $s['data'] >= date('Y-m-d', strtotime('-60 days'))));
$operatori = operatori_ufficio($conn);

// Filtri dell'elenco delle pratiche (servono anche per l'esportazione)
$f_stato = isset(STATI_PRATICA[$_GET['stato'] ?? '']) ? $_GET['stato'] : (in_array($_GET['stato'] ?? '', ['tutte', 'aperte'], true) ? $_GET['stato'] : 'aperte');
$f_mod = (int)($_GET['modulo'] ?? 0); $f_q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$f_sed = (string)($_GET['seduta'] ?? '');
$f_car = in_array($_GET['carico'] ?? '', ['me', 'smistare', 'seguite'], true) ? $_GET['carico'] : '';
$f_dal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['dal'] ?? '')) ? $_GET['dal'] : '';
$f_al = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['al'] ?? '')) ? $_GET['al'] : '';
$elenco_pratiche = function () use ($conn, $f_stato, $f_mod, $f_q, $f_sed, $f_dal, $f_al, $f_car, $io_operatore) {
    $where = ['1=1']; $tipi = ''; $par = [];
    if ($f_stato === 'aperte') $where[] = "p.stato IN ('inviata', 'in_lavorazione', 'integrazione')"; elseif ($f_stato !== 'tutte') { $where[] = "p.stato = ?"; $tipi .= 's'; $par[] = $f_stato; }
    if ($f_mod) $where[] = "p.modulo_id = $f_mod";
    if ($f_sed === 'nessuna') $where[] = "p.seduta_id IS NULL"; elseif ((int)$f_sed > 0) $where[] = "p.seduta_id = " . (int)$f_sed;
    if ($f_car === 'me') $where[] = "p.assegnata_a = " . (int)($io_operatore['id'] ?? -1);
    elseif ($f_car === 'seguite') $where[] = "p.id IN (SELECT pratica_id FROM pratiche_operatori WHERE operatore_id = " . (int)($io_operatore['id'] ?? -1) . ") AND (p.assegnata_a IS NULL OR p.assegnata_a <> " . (int)($io_operatore['id'] ?? -1) . ")";
    elseif ($f_car === 'smistare') $where[] = "p.assegnata_a IS NULL AND p.stato NOT IN ('accolta', 'respinta', 'chiusa')";
    if ($f_dal !== '') { $where[] = "p.creata_il >= ?"; $tipi .= 's'; $par[] = "$f_dal 00:00:00"; }
    if ($f_al !== '') { $where[] = "p.creata_il <= ?"; $tipi .= 's'; $par[] = "$f_al 23:59:59"; }
    if ($f_q !== '') { $like = '%' . addcslashes($f_q, '%_\\') . '%'; $where[] = "(p.nome LIKE ? OR p.cognome LIKE ? OR p.email LIKE ? OR p.codice LIKE ? OR p.matricola LIKE ?)"; $tipi .= 'sssss'; array_push($par, $like, $like, $like, $like, $like); }
    $st = $conn->prepare("SELECT p.*, m.titolo AS modulo_titolo, s.data AS seduta_data FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id LEFT JOIN didattica_sedute s ON s.id = p.seduta_id WHERE " . implode(' AND ', $where) . " ORDER BY p.aggiornata_il DESC LIMIT 1000");
    if ($par) $st->bind_param($tipi, ...$par);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
};

// ── Verbale in PDF (da firmare o firmato in PAdES) ──
if (($_GET['esporta'] ?? '') === 'verbale_pdf' && $tab === 'sedute') {
    $seduta_exp = seduta_didattica($conn, (int)($_GET['id'] ?? 0));
    if (!$puo_seduta($seduta_exp) || !($f = percorso_verbale_pdf($seduta_exp))) nega_accesso();
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($f));
    header('Content-Disposition: attachment; filename="Verbale_' . ($seduta_exp['data'] ? date('d_m_Y', strtotime($seduta_exp['data'])) : 'seduta') . ($seduta_exp['verbale_stato'] === 'firmato' ? '_firmato' : '') . '.pdf"');
    readfile($f); exit;
}
// ── Esportazioni (Excel e Word): scartano la pagina già prodotta dall'intestazione ──
if (isset($_GET['esporta']) && in_array($_GET['esporta'], ['xlsx', 'docx'], true)) {
    $seduta_exp = $tab === 'sedute' ? seduta_didattica($conn, (int)($_GET['id'] ?? 0)) : null;
    if ($solo_ref && !$puo_seduta($seduta_exp)) nega_accesso();
    if ($seduta_exp) $ids = array_column(db_righe($conn, "SELECT id FROM pratiche WHERE seduta_id = ?", [(int)$seduta_exp['id']]), 'id');
    elseif (!empty($_GET['ids'])) $ids = array_map('intval', explode(',', (string)$_GET['ids']));
    else $ids = array_column($elenco_pratiche(), 'id');
    $pr_exp = pratiche_per_esportazione($conn, $ids);
    $nome_f = ($seduta_exp ? 'Verbale_' . ($seduta_exp['data'] ? date('d_m_Y', strtotime($seduta_exp['data'])) : 'seduta') : 'Pratiche_' . date('d_m_Y'));
    $file = $_GET['esporta'] === 'xlsx' ? genera_excel_pratiche($conn, $pr_exp) : genera_verbale_pratiche($conn, $seduta_exp, $pr_exp);
    if ($file) {
        registra_log_audit($conn, "Pratiche esportate", ["Formato" => $_GET['esporta'], "Pratiche" => count($pr_exp)]);
        invia_file_scaricabile($file, ($_GET['esporta'] === 'xlsx' ? str_replace('Verbale_', 'Pratiche_seduta_', $nome_f) : $nome_f) . '.' . $_GET['esporta'],
            $_GET['esporta'] === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    flash_set("Esportazione non riuscita (manca l'estensione ZIP di PHP).", 'danger');
}
?>
<style>
.dd-ev { border-left: 3px solid #e2e8f0; padding: 4px 0 10px 14px; position: relative; }
.dd-ev::before { content: ''; position: absolute; left: -7px; top: 6px; width: 11px; height: 11px; border-radius: 50%; background: #94a3b8; }
.dd-ev.uff::before { background: #047857; } .dd-ev.stu::before { background: #0056B3; }
.dd-campo { display: grid; grid-template-columns: 2fr 1.4fr 2fr auto auto 1.6fr 34px 34px; gap: .4rem; align-items: center; margin-bottom: .4rem; padding: .3rem; border-radius: 6px; }
.dd-campo.dd-testa { margin-bottom: 0; padding-bottom: 0; }
.dd-logica { grid-column: 1 / -1; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 6px; padding: .5rem; }
.dd-col-riga { display: grid; grid-template-columns: 2fr 1.6fr 2fr 30px; gap: .3rem; margin-bottom: .25rem; }
.dd-campo.uff { background: #ecfdf5; }
@media (max-width: 991.98px) { .dd-campo { grid-template-columns: 1fr 1fr; } }
.dd-preset { border: 1px dashed #94a3b8; border-radius: 10px; padding: .6rem .8rem; background: #f8fafc; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-folder-open me-2" style="color:#047857;" aria-hidden="true"></i>Didattica · Ufficio didattico</h4>
    <a href="../modulistica.php" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Pagina pubblica della modulistica</a>
</div>
<ul class="nav nav-pills gap-1 mb-3" style="--bs-nav-pills-link-active-bg:#047857;">
    <?php foreach (['pratiche' => ['fa-inbox', 'Pratiche' . ($n_aperte ? " <span class='badge bg-warning text-dark'>$n_aperte aperte</span>" : '')],
                    'sedute' => ['fa-gavel', 'Sedute e verbali (' . count($sedute) . ')'],
                    'moduli' => ['fa-file-lines', 'Moduli e documenti (' . count($moduli) . ')'],
                    'ufficio' => ['fa-people-group', 'Ufficio e ricevimento'],
                    'statistiche' => ['fa-chart-column', 'Statistiche']] as $k => [$ico, $txt]): ?>
        <?php if ($solo_ref && $k !== 'sedute') continue; ?>
        <li class="nav-item"><a class="nav-link fw-bold<?php echo $tab === $k ? ' active' : ' bg-light text-dark'; ?>" href="<?php echo $base; ?>&amp;tab=<?php echo $k; ?>"><i class="fa <?php echo $ico; ?> me-1" aria-hidden="true"></i><?php echo $txt; ?></a></li>
    <?php endforeach; ?>
    <?php if ($puo_tutorato): ?><li class="nav-item"><a class="nav-link fw-bold bg-light text-dark" href="tutorato.php?p_id=<?php echo (int)$filtro_p; ?>"><i class="fa fa-user-graduate me-1" aria-hidden="true"></i>Tutorato · lettere di incarico</a></li><?php endif; ?>
</ul>

<?php
$fase = 'vista';
require __DIR__ . '/didattica_' . $tab . '.php';
require_once 'admin_footer.php';
