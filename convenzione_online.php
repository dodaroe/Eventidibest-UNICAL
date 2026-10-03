<?php
// convenzione_online.php - Convenzione FSL e Allegato A compilati online dalla scuola con un modulo guidato:
// dati dell'istituto e del Dirigente, logo della scuola, attività dell'Allegato A (quelle già prenotate dalla scuola e,
// se vuole, altre attività FSL ancora da prenotare, con il promemoria di prenotarle). Al termine si scaricano i due
// documenti Word dai modelli del Dipartimento, da firmare digitalmente e inviare via PEC.
// Accesso con il codice della prenotazione (?code=, come la ricevuta) o con il link personale ricevuto per email (?t=).
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$nega = function (string $msg) use ($h) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    exit('<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Convenzione non disponibile</title></head>'
       . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem;"><h1>Convenzione non disponibile</h1><p>' . $h($msg) . '</p><p><a href="index.php">Torna al portale</a></p></body></html>');
};
if (!check_rate_limit($conn, 'convenzione_online', 120, 3600)) $nega("Troppe richieste: riprova tra poco.");

// ── Accesso: link personale (token) o codice della prenotazione ──
$cc = null;
$tok = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
if ($tok !== '') {
    $st = $conn->prepare("SELECT * FROM convenzioni_compilate WHERE token = ?"); $st->bind_param("s", $tok); $st->execute();
    $cc = $st->get_result()->fetch_assoc() ?: null;
    if (!$cc) $nega("Il link non è valido o è scaduto.");
} else {
    $codice = strtoupper(trim((string)($_GET['code'] ?? '')));
    $pr = null;
    if (preg_match('/^[A-Z0-9-]{4,50}$/', $codice)) {
        $st = $conn->prepare("SELECT pr.id, pr.scuola_codice, pr.email FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
                              WHERE pr.codice_prenotazione = ? AND pd.convenzione = 1 AND IFNULL(pr.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta') LIMIT 1");
        $st->bind_param("s", $codice); $st->execute();
        $pr = $st->get_result()->fetch_assoc();
    }
    if (!$pr) $nega("Controlla il codice della prenotazione: la convenzione si compila dal link della conferma o dell'email.");
    // Una sola compilazione per prenotazione: se c'è già si riprende
    $r = $conn->query("SELECT * FROM convenzioni_compilate WHERE prenotazione_id = " . (int)$pr['id'] . " ORDER BY id DESC LIMIT 1");
    $cc = $r ? $r->fetch_assoc() : null;
    if (!$cc) {
        $tok = bin2hex(random_bytes(16)); $sc = $pr['scuola_codice'] ?: null; $em = (string)$pr['email'];
        $st = $conn->prepare("INSERT INTO convenzioni_compilate (token, scuola_codice, prenotazione_id, email) VALUES (?, ?, ?, ?)");
        $st->bind_param("ssis", $tok, $sc, $pr['id'], $em); $st->execute();
        $cc = $conn->query("SELECT * FROM convenzioni_compilate WHERE id = " . (int)$conn->insert_id)->fetch_assoc();
    }
    header('Location: convenzione_online.php?t=' . $cc['token']); exit;
}

$p0 = dati_prenotazione_convenzione($conn, (int)$cc['prenotazione_id']);
if (!$p0) $nega("La prenotazione collegata non esiste più.");
$cfg = dati_convenzione($p0);
[$prenotate, $prenotabili] = attivita_fsl_scuola($conn, (int)$cc['prenotazione_id'], $cc['scuola_codice']);
$dati = json_decode((string)$cc['dati_json'], true) ?: [];
$DIR_LOGHI = 'uploads/convenzioni_compilate/';

// Valori proposti: dall'anagrafe delle scuole e dalla prenotazione
$base_pre = dati_convenzione_precompilata($conn, $p0);
$sc_ana = !empty($cc['scuola_codice']) ? scuola_per_codice($conn, $cc['scuola_codice']) : null;
$def_scuola = [
    'denominazione' => $base_pre['ISTITUTO_FIRMA'], 'codice' => $sc_ana ? (string)($sc_ana['istituto_codice'] ?: $sc_ana['codice']) : '',
    'comune' => $base_pre['COMUNE'], 'indirizzo' => $base_pre['INDIRIZZO'], 'cf' => '', 'dirigente' => '', 'luogo_nascita' => '', 'data_nascita' => '', 'dir_cf' => '',
    'pec' => '', 'email' => (string)$cc['email'],
];
$s = ($dati['scuola'] ?? []) + $def_scuola;

// ── Documenti: genera e scarica ──
$doc_dati = function () use ($conn, $dati, $prenotate, $prenotabili) {
    $s = $dati['scuola'] ?? [];
    $scuola = [
        'ISTITUTO' => trim($s['denominazione'] ?? '') !== '' ? $s['denominazione'] . (trim($s['codice'] ?? '') !== '' ? ' (codice meccanografico ' . $s['codice'] . ')' : '') : '',
        'ISTITUTO_FIRMA' => $s['denominazione'] ?? '', 'COMUNE' => $s['comune'] ?? '', 'INDIRIZZO' => $s['indirizzo'] ?? '', 'CF_ISTITUTO' => $s['cf'] ?? '',
        'DIRIGENTE' => $s['dirigente'] ?? '', 'DIRIGENTE_FIRMA' => $s['dirigente'] ?? '', 'DIR_LUOGO_NASCITA' => $s['luogo_nascita'] ?? '',
        'DIR_DATA_NASCITA' => !empty($s['data_nascita']) ? date('d/m/Y', strtotime($s['data_nascita'])) : '', 'DIR_CF' => $s['dir_cf'] ?? '',
    ];
    $att = [];
    foreach ($dati['attivita'] ?? [] as $a) {
        $p = !empty($a['pr']) ? ($prenotate[(int)$a['pr']] ?? null) : ($prenotabili[(int)($a['ev'] ?? 0)] ?? null);
        if (!$p) continue;
        $v = dati_convenzione_precompilata($conn, $p);
        $att[] = ['TITOLO' => $v['TITOLO'], 'DESCRIZIONE' => $v['DESCRIZIONE'], 'PERIODO' => $v['PERIODO'], 'DURATA' => $v['DURATA'], 'TUTOR_DIBEST' => $v['TUTOR_DIBEST'],
                  'STUDENTI' => (int)($a['studenti'] ?? 0) > 0 ? (string)(int)$a['studenti'] : $v['STUDENTI'], 'TUTOR_SCUOLA' => trim(preg_replace('/^(\s*(prof(\.ssa|essoressa|essore)?|dott(\.ssa|oressa|ore)?)\.?\s*\/?)+/i', '', (string)($a['tutor'] ?? ''))) ?: $v['TUTOR_SCUOLA']];
        // il modello scrive già "Prof./Prof.ssa" davanti al tutor della scuola
    }
    return [$scuola, $att];
};
if (isset($_GET['scarica']) && $dati) {
    $doc = $_GET['scarica'] === 'allegato' ? 'allegato' : 'convenzione';
    [$scuola, $att] = $doc_dati();
    $logo = $cc['logo'] && is_file(RADICE_SITO . '/' . $cc['logo']) ? RADICE_SITO . '/' . $cc['logo'] : null;
    $prot_cc = trim((string)($cc['protocollo'] ?? '') . (!empty($cc['protocollo_data']) ? ' del ' . date('d/m/Y', strtotime($cc['protocollo_data'])) : ''));
    $file = genera_docx_convenzione($doc, $scuola, $att, $logo, (string)($cc['protocollo'] ?? '') !== '' ? $prot_cc : '');
    if ($file) {
        $conn->query("UPDATE convenzioni_compilate SET scaricata_il = NOW() WHERE id = " . (int)$cc['id']);
        $nome = ($doc === 'allegato' ? 'Allegato_A_FSL_' : 'Convenzione_FSL_') . preg_replace('/[^A-Za-z0-9]+/', '_', (string)($s['codice'] ?: $s['denominazione'])) . '.docx';
        invia_file_scaricabile($file, $nome, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
}

// ── Salvataggio del modulo ──
$errori = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salva_convenzione'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $in = fn($k, $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $s = ['denominazione' => $in('denominazione'), 'codice' => strtoupper($in('codice', 20)), 'comune' => $in('comune'), 'indirizzo' => $in('indirizzo'),
          'cf' => strtoupper(preg_replace('/\s+/', '', $in('cf', 20))), 'dirigente' => $in('dirigente'), 'luogo_nascita' => $in('luogo_nascita'),
          'data_nascita' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $in('data_nascita', 10)) ? $in('data_nascita', 10) : '',
          'dir_cf' => strtoupper(preg_replace('/\s+/', '', $in('dir_cf', 20))), 'pec' => $in('pec'), 'email' => $in('email')];
    if ($s['denominazione'] === '') $errori[] = "Indica la denominazione dell'istituzione scolastica.";
    if ($s['cf'] !== '' && !preg_match('/^(\d{11}|[A-Z0-9]{16})$/', $s['cf'])) $errori[] = "Il codice fiscale dell'istituto ha 11 cifre.";
    if ($s['dirigente'] === '') $errori[] = "Indica il Dirigente Scolastico.";
    if ($s['dir_cf'] !== '' && !preg_match('/^[A-Z0-9]{16}$/', $s['dir_cf'])) $errori[] = "Il codice fiscale del Dirigente ha 16 caratteri.";
    if ($s['pec'] !== '' && !filter_var($s['pec'], FILTER_VALIDATE_EMAIL)) $errori[] = "La PEC della scuola non è valida.";
    if ($s['email'] !== '' && !filter_var($s['email'], FILTER_VALIDATE_EMAIL)) $errori[] = "L'email di riferimento non è valida.";
    $att = [];
    foreach ((array)($_POST['att'] ?? []) as $k => $a) {
        if (empty($a['scelta'])) continue;
        $voce = ['studenti' => max(0, min(500, (int)($a['studenti'] ?? 0))), 'tutor' => mb_substr(trim((string)($a['tutor'] ?? '')), 0, 150)];
        if (str_starts_with((string)$k, 'pr') && isset($prenotate[(int)substr($k, 2)])) $att[] = ['pr' => (int)substr($k, 2)] + $voce;
        elseif (str_starts_with((string)$k, 'ev') && isset($prenotabili[(int)substr($k, 2)])) $att[] = ['ev' => (int)substr($k, 2)] + $voce;
    }
    if (!$att) $errori[] = "Scegli almeno un'attività per l'Allegato A.";
    // Logo della scuola (facoltativo): PNG o JPG fino a 2 MB, in una cartella non raggiungibile dal web
    $logo = $cc['logo'];
    if (!empty($_POST['togli_logo'])) $logo = null;
    if (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        if ($_FILES['logo']['size'] > 2 * 1024 * 1024) $errori[] = "Il logo supera 2 MB.";
        else {
            $dir = RADICE_SITO . '/' . $DIR_LOGHI;
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            if (!is_file($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "# Loghi delle scuole per le convenzioni: usati solo per creare i documenti\nRequire all denied\n");
            $fn = secure_upload($_FILES['logo'], $dir, ['png', 'jpg', 'jpeg'], ['image/png', 'image/jpeg']);
            if ($fn) $logo = $DIR_LOGHI . $fn; else $errori[] = "Il logo deve essere un'immagine PNG o JPG.";
        }
    }
    if (!$errori) {
        $prima = empty($cc['dati_json']);
        $dati = ['scuola' => $s, 'attivita' => $att];
        $json = json_encode($dati, JSON_UNESCAPED_UNICODE); $email = $s['email'] ?: (string)$cc['email'];
        $st = $conn->prepare("UPDATE convenzioni_compilate SET dati_json = ?, logo = ?, email = ?, aggiornata_il = NOW() WHERE id = ?");
        $st->bind_param("sssi", $json, $logo, $email, $cc['id']); $st->execute();
        if ($cc['logo'] && $cc['logo'] !== $logo && is_file(RADICE_SITO . '/' . $cc['logo'])) @unlink(RADICE_SITO . '/' . $cc['logo']);
        // Email con il link per riprendere e scaricare i documenti (alla prima compilazione)
        if ($prima && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $link = url_base_sito() . '/convenzione_online.php?t=' . $cc['token'];
            inviaNotificaEmail($email, "Convenzione Formazione Scuola Lavoro – documenti da firmare",
                "<p>Gentile docente,</p><p>la Convenzione e l'Allegato A per <strong>" . $h($s['denominazione']) . "</strong> sono pronti. Da questo link puoi scaricarli o correggerli:</p>"
                . "<p style='margin:18px 0;'><a href='" . $h($link) . "' style='background:#B30000;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Apri la convenzione</a></p>"
                . "<p>Poi il Dirigente Scolastico li <strong>firma digitalmente in PAdES</strong> (PDF firmato, non .p7m) e la scuola li invia via PEC a <a href='mailto:" . $h($cfg['pec']) . "'>" . $h($cfg['pec']) . "</a>. Appena riceviamo la convenzione confermiamo le prenotazioni.</p>", $conn);
        }
        flash_set("Dati salvati: scarica i documenti, falli firmare digitalmente al Dirigente e inviali via PEC.");
        header('Location: convenzione_online.php?t=' . $cc['token'] . '&r=' . time() . '#documenti'); exit;
    }
}

$scelte = [];
foreach ($dati['attivita'] ?? [] as $a) $scelte[!empty($a['pr']) ? 'pr' . $a['pr'] : 'ev' . ($a['ev'] ?? 0)] = $a;
if (!$dati) foreach ($prenotate as $id => $p) $scelte['pr' . $id] = ['studenti' => 0, 'tutor' => ''];
$non_prenotate = array_filter(array_keys($scelte), fn($k) => str_starts_with($k, 'ev'));

$page_cfg['titolo'] = 'Convenzione Formazione Scuola Lavoro';
require_once 'header.php';
?>
<style>
.cv-passo { display:inline-flex; width:28px; height:28px; border-radius:50%; background:#B30000; color:#fff; align-items:center; justify-content:center; font-weight:bold; margin-right:.4rem; }
.cv-att { border:1px solid #e5e7eb; border-radius:10px; padding:.7rem .9rem; margin-bottom:.5rem; }
.cv-att.scelta { border-color:#16a34a; background:#f0fdf4; }
</style>
<div class="container my-4" style="max-width: 960px;">
    <h1 class="fw-bold h2 mb-1"><i class="fa fa-file-signature me-2" style="color:#B30000;" aria-hidden="true"></i>Convenzione Formazione Scuola Lavoro</h1>
    <p class="text-secondary">Compila i dati: prepariamo noi la <strong>Convenzione</strong> e l'<strong>Allegato A</strong> sui modelli del Dipartimento. Poi li scarichi in Word, il Dirigente li firma digitalmente in PAdES (PDF firmato, non .p7m) e la scuola li invia via PEC.</p>
    <?php echo flash_html(); ?>
    <?php if ($errori): ?><div class="alert alert-danger" role="alert"><strong>Controlla i dati:</strong><ul class="mb-0"><?php foreach ($errori as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if ($dati && !$errori): ?>
    <section id="documenti" class="card border-0 shadow-sm mb-4" style="border-left:5px solid #16a34a !important;"><div class="card-body">
        <h2 class="h5 fw-bold"><i class="fa fa-circle-check text-success me-1" aria-hidden="true"></i>I documenti sono pronti</h2>
        <div class="d-flex flex-wrap gap-2 my-3">
            <a class="btn btn-success fw-bold" href="convenzione_online.php?t=<?php echo $h($cc['token']); ?>&amp;scarica=convenzione"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Scarica la Convenzione (con Allegato A)</a>
            <a class="btn btn-outline-success fw-bold" href="convenzione_online.php?t=<?php echo $h($cc['token']); ?>&amp;scarica=allegato"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Scarica solo l'Allegato A</a>
        </div>
        <ol class="mb-2">
            <li>Apri i file e controlla i dati (i campi rimasti in <span style="background:#fef08a;">giallo</span> sono da completare).</li>
            <li>Il <strong>Dirigente Scolastico</strong> firma i documenti <strong>digitalmente in formato PAdES</strong> (PDF firmato; il formato CAdES .p7m non è accettato).</li>
            <li>La scuola li invia dalla propria PEC a <a href="mailto:<?php echo $h($cfg['pec']); ?>?subject=<?php echo rawurlencode('Convenzione Formazione Scuola Lavoro – ' . ($s['denominazione'] ?? '')); ?>" class="fw-bold"><?php echo $h($cfg['pec']); ?></a>.</li>
            <li>Il Dipartimento firma, registra la convenzione e <strong>conferma le prenotazioni</strong>: riceverete un'email.</li>
        </ol>
        <?php if ($non_prenotate): ?>
            <div class="alert alert-warning small mb-0"><i class="fa fa-bell me-1" aria-hidden="true"></i><strong>Ricordati di prenotare</strong> le attività che hai inserito nell'Allegato A ma che non hai ancora prenotato:
                <?php foreach ($non_prenotate as $k): $ev = $prenotabili[(int)substr($k, 2)] ?? null; if (!$ev) continue; ?>
                    <a class="d-block fw-bold" href="<?php echo $h($ev['slug'] . '.php?' . (($ev['tipo'] ?? '') === 'progetto' ? 'progetto' : 'evento') . '=' . (int)$ev['evento_id']); ?>"><?php echo $h($ev['evento_titolo']); ?> → prenota</a>
                <?php endforeach; ?></div>
        <?php endif; ?>
        <p class="small text-secondary mt-2 mb-0">Puoi tornare su questa pagina dal link che ti abbiamo inviato per email e correggere i dati qui sotto: i documenti si aggiornano.</p>
    </div></section>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <section class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold"><span class="cv-passo">1</span>Istituzione scolastica</h2>
            <div class="row g-2">
                <div class="col-md-8"><label class="form-label small fw-bold" for="cvDen">Denominazione <span class="text-danger">*</span></label><input class="form-control" id="cvDen" name="denominazione" value="<?php echo $h($s['denominazione']); ?>" required maxlength="255"></div>
                <div class="col-md-4"><label class="form-label small fw-bold" for="cvCod">Codice meccanografico</label><input class="form-control" id="cvCod" name="codice" value="<?php echo $h($s['codice']); ?>" maxlength="20"></div>
                <div class="col-md-4"><label class="form-label small fw-bold" for="cvCom">Comune (provincia)</label><input class="form-control" id="cvCom" name="comune" value="<?php echo $h($s['comune']); ?>" maxlength="255"></div>
                <div class="col-md-5"><label class="form-label small fw-bold" for="cvInd">Indirizzo</label><input class="form-control" id="cvInd" name="indirizzo" value="<?php echo $h($s['indirizzo']); ?>" maxlength="255"></div>
                <div class="col-md-3"><label class="form-label small fw-bold" for="cvCf">Codice fiscale dell'istituto</label><input class="form-control font-monospace" id="cvCf" name="cf" value="<?php echo $h($s['cf']); ?>" maxlength="16" placeholder="11 cifre"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvPec">PEC della scuola</label><input type="email" class="form-control" id="cvPec" name="pec" value="<?php echo $h($s['pec']); ?>" maxlength="255"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvEm">Email a cui mandare il link</label><input type="email" class="form-control" id="cvEm" name="email" value="<?php echo $h($s['email']); ?>" maxlength="255"></div>
                <div class="col-12"><label class="form-label small fw-bold" for="cvLogo">Logo della scuola (facoltativo, PNG o JPG fino a 2 MB)</label>
                    <input type="file" class="form-control" id="cvLogo" name="logo" accept=".png,.jpg,.jpeg">
                    <?php if ($cc['logo']): ?><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="togli_logo" value="1"> Il logo è già caricato: spunta per toglierlo</label><?php endif; ?>
                    <div class="form-text">Compare in testa alla Convenzione e all'Allegato A.</div></div>
            </div>
        </div></section>

        <section class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold"><span class="cv-passo">2</span>Dirigente Scolastico</h2>
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvDir">Nome e cognome <span class="text-danger">*</span></label><input class="form-control" id="cvDir" name="dirigente" value="<?php echo $h($s['dirigente']); ?>" required maxlength="255" placeholder="Dott.ssa Maria Rossi"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvDcf">Codice fiscale</label><input class="form-control font-monospace" id="cvDcf" name="dir_cf" value="<?php echo $h($s['dir_cf']); ?>" maxlength="16" style="text-transform:uppercase;"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvLn">Luogo di nascita</label><input class="form-control" id="cvLn" name="luogo_nascita" value="<?php echo $h($s['luogo_nascita']); ?>" maxlength="255"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvDn">Data di nascita</label><input type="date" class="form-control" id="cvDn" name="data_nascita" value="<?php echo $h($s['data_nascita']); ?>"></div>
            </div>
            <p class="small text-secondary mb-0 mt-2">I campi lasciati vuoti restano evidenziati nel documento: potete completarli a mano prima della firma.</p>
        </div></section>

        <section class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold"><span class="cv-passo">3</span>Attività dell'Allegato A</h2>
            <p class="small text-secondary">Scegli le attività da inserire nell'Allegato A. Per ognuna puoi indicare il numero di studenti e il docente tutor della scuola.</p>
            <h3 class="h6 fw-bold mt-2">Attività già prenotate dalla scuola</h3>
            <?php foreach ($prenotate as $id => $p): $k = 'pr' . $id; $v = dati_convenzione_precompilata($conn, $p); $sel = isset($scelte[$k]); ?>
                <div class="cv-att<?php echo $sel ? ' scelta' : ''; ?>">
                    <label class="form-check fw-bold mb-1"><input class="form-check-input cv-sc" type="checkbox" name="att[<?php echo $k; ?>][scelta]" value="1"<?php echo $sel ? ' checked' : ''; ?>> <?php echo $h($v['TITOLO']); ?></label>
                    <div class="small text-secondary mb-1"><?php echo $h($v['PERIODO']); ?><?php echo $v['DURATA'] ? ' · ' . $h($v['DURATA']) : ''; ?> · prenotazione <?php echo $h($p['codice_prenotazione']); ?></div>
                    <div class="row g-2"><div class="col-sm-4"><input type="number" min="0" max="500" class="form-control form-control-sm" name="att[<?php echo $k; ?>][studenti]" value="<?php echo $h(($scelte[$k]['studenti'] ?? 0) ?: $v['STUDENTI']); ?>" placeholder="N. studenti" aria-label="Numero di studenti"></div>
                        <div class="col-sm-8"><input class="form-control form-control-sm" name="att[<?php echo $k; ?>][tutor]" value="<?php echo $h(($scelte[$k]['tutor'] ?? '') ?: $v['TUTOR_SCUOLA']); ?>" placeholder="Docente tutor della scuola" aria-label="Docente tutor della scuola" maxlength="150"></div></div>
                </div>
            <?php endforeach; ?>
            <?php if ($prenotabili): ?>
                <details class="mt-3"<?php echo $non_prenotate ? ' open' : ''; ?>><summary class="fw-bold">Altre attività di Formazione Scuola Lavoro da prenotare (<?php echo count($prenotabili); ?>)</summary>
                    <p class="small text-secondary mt-2">Se le inserisci nell'Allegato A, <strong>ricordati di prenotarle</strong>: la convenzione non sostituisce la prenotazione.</p>
                    <?php foreach ($prenotabili as $id => $ev): $k = 'ev' . $id; $v = dati_convenzione_precompilata($conn, $ev); $sel = isset($scelte[$k]); ?>
                        <div class="cv-att<?php echo $sel ? ' scelta' : ''; ?>">
                            <label class="form-check fw-bold mb-1"><input class="form-check-input cv-sc" type="checkbox" name="att[<?php echo $k; ?>][scelta]" value="1"<?php echo $sel ? ' checked' : ''; ?>> <?php echo $h($v['TITOLO']); ?></label>
                            <div class="small text-secondary mb-1"><?php echo $h($v['PERIODO']); ?><?php echo $v['DURATA'] ? ' · ' . $h($v['DURATA']) : ''; ?> · <a href="<?php echo $h($ev['slug'] . '.php?' . (($ev['tipo'] ?? '') === 'progetto' ? 'progetto' : 'evento') . '=' . $id); ?>" target="_blank" rel="noopener">vedi e prenota<span class="visually-hidden"> (si apre in una nuova scheda)</span></a></div>
                            <div class="cv-promemoria alert alert-warning small py-1 px-2 mb-1"<?php echo $sel ? '' : ' hidden'; ?>><i class="fa fa-bell me-1" aria-hidden="true"></i>Non l'hai ancora prenotata: ricordati di farlo.</div>
                            <div class="row g-2"><div class="col-sm-4"><input type="number" min="0" max="500" class="form-control form-control-sm" name="att[<?php echo $k; ?>][studenti]" value="<?php echo $h($scelte[$k]['studenti'] ?? ''); ?>" placeholder="N. studenti" aria-label="Numero di studenti"></div>
                                <div class="col-sm-8"><input class="form-control form-control-sm" name="att[<?php echo $k; ?>][tutor]" value="<?php echo $h($scelte[$k]['tutor'] ?? ''); ?>" placeholder="Docente tutor della scuola" aria-label="Docente tutor della scuola" maxlength="150"></div></div>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        </div></section>

        <button type="submit" name="salva_convenzione" value="1" class="btn btn-lg fw-bold text-white mb-2" style="background:#B30000;"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i><?php echo $dati ? 'Aggiorna i documenti' : 'Prepara i documenti'; ?></button>
        <p class="small text-secondary">Preferisci i modelli vuoti? <a href="<?php echo $h($cfg['modello']); ?>">Convenzione</a> · <a href="<?php echo $h($cfg['allegato']); ?>">Allegato A</a></p>
    </form>
</div>
<script>
document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('cv-sc')) return;
    var box = e.target.closest('.cv-att'); box.classList.toggle('scelta', e.target.checked);
    var pm = box.querySelector('.cv-promemoria'); if (pm) pm.hidden = !e.target.checked;
});
</script>
<?php require_once 'footer.php'; ?>
