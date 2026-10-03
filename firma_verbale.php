<?php
// firma_verbale.php - Firma digitale PAdES del verbale di una seduta (link personale dall'email): prima il segretario
// verbalizzante, poi il coordinatore. Con la firma remota Aruba (se configurata nel .env) si firma dal portale; altrimenti
// si scarica il PDF, si firma in PAdES e si ricarica. I .p7m (CAdES) non sono accettati. Logica in inc/sedute.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tok = (string)($_GET['t'] ?? '');
$s = seduta_per_token_verbale($conn, $tok);
$io = $s && firmatario_verbale($s, $user_info);
$chi = $s ? ($s['verbale_stato'] === 'segretario' ? 'segretario verbalizzante' : 'coordinatore') : '';

if ($s && $io && isset($_GET['pdf']) && ($f = percorso_verbale_pdf($s))) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . (isset($_GET['scarica']) ? 'attachment' : 'inline') . '; filename="Verbale_' . ($s['data'] ? date('d_m_Y', strtotime($s['data'])) : 'seduta') . '.pdf"');
    header('Content-Length: ' . filesize($f));
    readfile($f); exit;
}
if ($s && $io && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $err = null;
    if (isset($_POST['firma_remota'])) {
        if (!check_rate_limit($conn, 'firma_remota', 10, 900)) $err = "Troppi tentativi: riprova tra qualche minuto.";
        else {
            [$firmato, $err] = firma_remota_aruba((string)file_get_contents(percorso_verbale_pdf($s)), (string)($_POST['utente'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['otp'] ?? ''), null, 'Il ' . ucfirst($chi));
            if ($firmato) $err = registra_firma_verbale($conn, (int)$s['id'], $firmato, 'firma remota Aruba');
        }
    } elseif (isset($_POST['carica'])) {
        $f = $_FILES['pdf_firmato'] ?? null;
        $err = (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $f['size'] > 30 * 1024 * 1024) ? "Scegli il PDF firmato (fino a 30 MB)." : registra_firma_verbale($conn, (int)$s['id'], (string)file_get_contents($f['tmp_name']), 'PDF firmato caricato');
    }
    unset($_POST['password'], $_POST['otp']);
    flash_set($err ?? ($s['verbale_stato'] === 'segretario' ? "Firma registrata: il verbale passa al coordinatore." : "Firma registrata: il verbale è firmato."), $err ? 'danger' : 'success');
    header('Location: firma_verbale.php?t=' . urlencode($tok) . '&r=' . time()); exit;
}

$page_cfg['titolo'] = 'Firma del verbale';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 1000px;">
    <?php echo flash_html(); ?>
    <?php if (!$s): ?>
        <h1 class="fw-bold h3">Verbale non disponibile</h1><p>Il link non è valido oppure il verbale è già stato firmato (in quel caso hai ricevuto l'email con il PDF).</p>
    <?php elseif (!$io): ?>
        <h1 class="fw-bold h3">Firma del verbale</h1>
        <div class="alert alert-warning">Questa pagina è per il <strong><?php echo $h($chi); ?></strong> (<?php echo $h($s['verbale_stato'] === 'segretario' ? $s['segretario_email'] : $s['coordinatore_email']); ?>): accedi con le sue credenziali (Unical ID, SPID o CIE).</div>
        <a class="btn btn-outline-secondary" href="esci.php">Esci</a>
    <?php else: ?>
        <h1 class="fw-bold h3 mb-1">Verbale della seduta<?php echo $s['data'] ? ' del ' . date('d/m/Y', strtotime($s['data'])) : ''; ?></h1>
        <p class="text-secondary mb-3"><?php echo $h($s['organo']); ?> · <span class="badge" style="background:<?php echo STATI_VERBALE[$s['verbale_stato']][1]; ?>;"><?php echo $h(STATI_VERBALE[$s['verbale_stato']][0]); ?></span></p>
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="alert alert-info small"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Firma richiesta: <strong><?php echo $h($chi); ?></strong>, in formato <strong>PAdES</strong> (firma dentro il PDF). Il formato CAdES (.p7m) non è accettato.</div>
                <?php if (firma_remota_disponibile()): ?>
                <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:5px solid #047857 !important;" autocomplete="off"><div class="card-body">
                    <?php csrf_field(); ?>
                    <h2 class="h6 fw-bold"><i class="fa fa-signature me-1 text-success" aria-hidden="true"></i>Firma remota Aruba</h2>
                    <label class="form-label small fw-bold mb-0" for="frU">Utente della firma remota</label><input class="form-control form-control-sm mb-2" id="frU" name="utente" required autocomplete="off">
                    <label class="form-label small fw-bold mb-0" for="frP">Password / PIN</label><input type="password" class="form-control form-control-sm mb-2" id="frP" name="password" required autocomplete="off">
                    <label class="form-label small fw-bold mb-0" for="frO">Codice OTP</label><input class="form-control form-control-sm mb-2" id="frO" name="otp" required inputmode="numeric" autocomplete="one-time-code" style="max-width:160px;">
                    <button type="submit" name="firma_remota" value="1" class="btn btn-success fw-bold"><i class="fa fa-pen-nib me-1" aria-hidden="true"></i>Firma in PAdES</button>
                </div></form>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body">
                    <?php csrf_field(); ?>
                    <h2 class="h6 fw-bold"><i class="fa fa-upload me-1" aria-hidden="true"></i><?php echo firma_remota_disponibile() ? 'Oppure: scarica, firma e ricarica' : 'Scarica, firma e ricarica'; ?></h2>
                    <ol class="small ps-3 mb-2"><li><a href="firma_verbale.php?t=<?php echo $h(urlencode($tok)); ?>&amp;pdf=1&amp;scarica=1">Scarica il PDF</a> del verbale.</li>
                        <li>Firmalo con il tuo strumento di firma (es. ArubaSign, Dike, Acrobat) scegliendo <strong>PAdES</strong> (PDF), non CAdES (.p7m).</li><li>Carica qui il PDF firmato, senza modificarlo.</li></ol>
                    <input type="file" name="pdf_firmato" class="form-control form-control-sm mb-2" accept=".pdf,application/pdf" required aria-label="PDF firmato in PAdES">
                    <button type="submit" name="carica" value="1" class="btn btn-primary fw-bold">Carica il PDF firmato</button>
                </div></form>
            </div>
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm"><div class="card-body p-1">
                    <iframe src="firma_verbale.php?t=<?php echo $h(urlencode($tok)); ?>&amp;pdf=1" title="Verbale in PDF" style="width:100%;height:720px;border:0;"></iframe>
                </div></div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
