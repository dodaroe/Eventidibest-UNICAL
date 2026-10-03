<?php
// firma_incarico.php - Firma digitale PAdES della lettera di incarico di tutorato (link personale dall'email):
// prima il docente responsabile dell'attività (presa visione), poi il Direttore. Si firma con la firma remota Aruba
// direttamente dal portale (utente, password/PIN e OTP; non si salvano) oppure scaricando il PDF, firmandolo in PAdES
// (es. ArubaSign, Dike, Acrobat) e caricandolo. Il portale accetta solo PDF firmati PAdES che contengono la lettera
// senza modifiche (i .p7m CAdES sono rifiutati). Logica in inc/tutorato.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tok = (string)($_GET['t'] ?? '');
$ruolo = 'docente';
$i = incarico_per_token($conn, 'docente', $tok);
if (!$i && ($i = incarico_per_token($conn, 'direttore', $tok))) $ruolo = 'direttore';
$atteso = $ruolo === 'docente' ? 'confermata' : 'firmata_docente';
$io = $i && firmatario_incarico($i, $ruolo, $user_info);
$chi = $i ? ($ruolo === 'docente' ? 'Prof. ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome']) : 'Prof. ' . $i['direttore_nome']) : '';

if ($i && $io && isset($_GET['pdf']) && ($f = pdf_corrente_incarico($i))) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . (isset($_GET['scarica']) ? 'attachment' : 'inline') . '; filename="' . nome_file_incarico($i, $ruolo === 'direttore' ? 'firmata_docente' : '') . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f); exit;
}
if ($i && $io && $i['stato'] === $atteso && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $err = null;
    if (isset($_POST['firma_remota'])) {
        if (!check_rate_limit($conn, 'firma_remota', 10, 900)) $err = "Troppi tentativi: riprova tra qualche minuto.";
        else {
            // Firma visibile nello spazio riservato della lettera (stessa impaginazione del PDF)
            [, $spazi] = pdf_lettera_incarico($i, json_decode((string)$i['studente_firma_json'], true) ?: null);
            [$firmato, $err] = firma_remota_aruba((string)file_get_contents(pdf_corrente_incarico($i)), (string)($_POST['utente'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['otp'] ?? ''),
                                                  $spazi[$ruolo] ?? null, $ruolo === 'docente' ? 'Presa visione del responsabile dell\'attività' : 'Il Direttore');
            if ($firmato) $err = registra_firma_incarico($conn, (int)$i['id'], $ruolo, $firmato, 'firma remota Aruba');
        }
    } elseif (isset($_POST['carica'])) {
        $f = $_FILES['pdf_firmato'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $f['size'] > 20 * 1024 * 1024) $err = "Scegli il PDF firmato (fino a 20 MB).";
        else $err = registra_firma_incarico($conn, (int)$i['id'], $ruolo, (string)file_get_contents($f['tmp_name']), 'PDF firmato caricato');
    }
    unset($_POST['password'], $_POST['otp']);
    flash_set($err ?? ($ruolo === 'docente' ? "Firma registrata: la lettera passa al Direttore." : "Firma registrata: la lettera passa all'Ufficio per il protocollo."), $err ? 'danger' : 'success');
    header('Location: firma_incarico.php?t=' . urlencode($tok) . '&r=' . time()); exit;
}

$page_cfg['titolo'] = 'Firma della lettera di incarico';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 1000px;">
    <?php echo flash_html(); ?>
    <?php if (!$i || $i['stato'] === 'annullata'): ?>
        <h1 class="fw-bold h3">Lettera non disponibile</h1><p>Il link non è valido o la lettera è stata annullata.</p>
    <?php elseif (!$io): ?>
        <h1 class="fw-bold h3">Firma della lettera di incarico</h1>
        <div class="alert alert-warning">Questa pagina è per <strong><?php echo $h($chi); ?></strong>: accedi con le sue credenziali (Unical ID, SPID o CIE).</div>
        <a class="btn btn-outline-secondary" href="esci.php">Esci</a>
    <?php else: $d = dati_lettera_incarico($i); ?>
        <h1 class="fw-bold h3 mb-1">Lettera di incarico di <?php echo $h($d['NOMINATIVO']); ?></h1>
        <p class="text-secondary mb-3"><?php echo $h($i['bando_titolo']); ?> · <?php echo badge_stato_incarico($i['stato']); ?></p>
        <div class="row g-3">
            <div class="col-lg-5">
                <?php if ($i['stato'] === $atteso): ?>
                    <div class="alert alert-info small"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Firma richiesta: <strong><?php echo $ruolo === 'docente' ? 'presa visione del responsabile dell\'attività' : 'Direttore'; ?></strong>, in formato <strong>PAdES</strong> (firma dentro il PDF). Il formato CAdES (.p7m) non è accettato.</div>
                    <?php if (firma_remota_disponibile()): ?>
                    <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:5px solid #047857 !important;" autocomplete="off"><div class="card-body">
                        <?php csrf_field(); ?>
                        <h2 class="h6 fw-bold"><i class="fa fa-signature me-1 text-success" aria-hidden="true"></i>Firma remota Aruba</h2>
                        <p class="small text-secondary">Con le credenziali della firma remota: la firma PAdES viene apposta nello spazio riservato della lettera. Le credenziali non vengono salvate.</p>
                        <label class="form-label small fw-bold mb-0" for="frU">Utente della firma remota</label><input class="form-control form-control-sm mb-2" id="frU" name="utente" required autocomplete="off">
                        <label class="form-label small fw-bold mb-0" for="frP">Password / PIN</label><input type="password" class="form-control form-control-sm mb-2" id="frP" name="password" required autocomplete="off">
                        <label class="form-label small fw-bold mb-0" for="frO">Codice OTP</label><input class="form-control form-control-sm mb-2" id="frO" name="otp" required inputmode="numeric" autocomplete="one-time-code" style="max-width:160px;">
                        <button type="submit" name="firma_remota" value="1" class="btn btn-success fw-bold"><i class="fa fa-pen-nib me-1" aria-hidden="true"></i>Firma in PAdES</button>
                    </div></form>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body">
                        <?php csrf_field(); ?>
                        <h2 class="h6 fw-bold"><i class="fa fa-upload me-1" aria-hidden="true"></i><?php echo firma_remota_disponibile() ? 'Oppure: carica il PDF firmato' : 'Firma e carica il PDF'; ?></h2>
                        <ol class="small ps-3 mb-2"><li><a href="firma_incarico.php?t=<?php echo $h(urlencode($tok)); ?>&amp;pdf=1&amp;scarica=1">Scarica il PDF</a> della lettera.</li>
                            <li>Firmalo con il tuo strumento di firma (es. ArubaSign, Dike, Acrobat) scegliendo <strong>PAdES</strong> (PDF), non CAdES (.p7m).</li><li>Carica qui il PDF firmato, senza modificarlo.</li></ol>
                        <input type="file" name="pdf_firmato" class="form-control form-control-sm mb-2" accept=".pdf,application/pdf" required aria-label="PDF firmato in PAdES">
                        <button type="submit" name="carica" value="1" class="btn btn-primary fw-bold">Carica il PDF firmato</button>
                    </div></form>
                <?php elseif (array_search($i['stato'], ['bozza', 'inviata', 'confermata', 'firmata_docente', 'firmata', 'protocollata'], true) > array_search($atteso, ['bozza', 'inviata', 'confermata', 'firmata_docente', 'firmata', 'protocollata'], true)): ?>
                    <div class="alert alert-success">Hai già firmato la lettera: grazie. Ora è «<?php echo $h(STATI_INCARICO[$i['stato']][0] ?? $i['stato']); ?>».</div>
                <?php else: ?>
                    <div class="alert alert-secondary">La lettera non è ancora pronta per la tua firma: riceverai un'email.</div>
                <?php endif; ?>
                <div class="card border-0 shadow-sm mt-3"><div class="card-body small">
                    <h2 class="h6 fw-bold">In sintesi</h2>
                    <p class="mb-1"><strong>Attività:</strong> <?php echo nl2br($h($d['ATTIVITA'])); ?></p>
                    <p class="mb-1"><strong>Ore · periodo · compenso:</strong> <?php echo $h($d['ORE'] . ' · ' . $d['PERIODO'] . ' · ' . $d['COMPENSO']); ?></p>
                    <?php if ($fs = json_decode((string)$i['studente_firma_json'], true)): ?><p class="mb-0"><strong>Accettata dallo studente</strong> con <?php echo $h(METODI_ACCESSO[$fs['metodo']] ?? $fs['metodo']); ?> il <?php echo date('d/m/Y H:i', strtotime($fs['confermata_il'])); ?>.</p><?php endif; ?>
                </div></div>
            </div>
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm"><div class="card-body p-1">
                    <iframe src="firma_incarico.php?t=<?php echo $h(urlencode($tok)); ?>&amp;pdf=1" title="Lettera di incarico in PDF" style="width:100%;height:720px;border:0;"></iframe>
                </div></div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
