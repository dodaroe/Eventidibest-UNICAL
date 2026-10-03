<?php
// incarico.php - Lettera di incarico di tutorato: lo studente (link personale dall'email) entra con SPID o CIE,
// controlla i dati e conferma. La conferma, con i dati dell'identità digitale, finisce nel PDF e vale come accettazione;
// poi la lettera passa alla firma del docente responsabile (firma_incarico.php). Logica in inc/tutorato.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tok = (string)($_GET['t'] ?? '');
$i = incarico_per_token($conn, 'studente', $tok);
$meta = $_SESSION['auth_meta'] ?? ['metodo' => 'ateneo'];
$mio = $i && strtoupper(trim((string)($user_info['codice_fiscale'] ?? ''))) === strtoupper($i['codice_fiscale']);

if ($i && $mio && isset($_GET['pdf'])) {
    // PDF: anteprima finché non conferma, poi quello con la sua conferma (e le firme che si aggiungono)
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . nome_file_incarico($i) . '"');
    if ($i['stato'] === 'inviata') { [$pdf] = pdf_lettera_incarico($i); echo $pdf; }
    elseif ($f = pdf_corrente_incarico($i)) readfile($f);
    exit;
}
if ($i && $mio && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['conferma'])) {
        $err = empty($_POST['accetto']) ? "Per confermare spunta la dichiarazione di accettazione." : conferma_incarico_studente($conn, (int)$i['id'], $user_info, $meta);
        flash_set($err ?? "Grazie: hai accettato l'incarico. La lettera passa ora alla firma del docente responsabile e del Direttore; riceverai la copia firmata.", $err ? 'danger' : 'success');
    } elseif (isset($_POST['segnala'])) {
        $err = segnala_errore_incarico($conn, (int)$i['id'], (string)($_POST['testo'] ?? ''));
        flash_set($err ?? "Segnalazione inviata all'ufficio: riceverai una lettera corretta.", $err ? 'danger' : 'success');
    }
    header('Location: incarico.php?t=' . urlencode($tok) . '&r=' . time()); exit;
}

$page_cfg['titolo'] = 'Lettera di incarico';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 980px;">
    <?php echo flash_html(); ?>
    <?php if (!$i || $i['stato'] === 'annullata'): ?>
        <h1 class="fw-bold h3">Lettera non disponibile</h1>
        <p>Il link non è valido o la lettera è stata annullata: se ne hai ricevuta una nuova usa il link dell'ultima email.</p>
    <?php elseif (!$mio): ?>
        <h1 class="fw-bold h3">Lettera di incarico</h1>
        <div class="alert alert-warning">Questa lettera è intestata a un'altra persona. Se sei <?php echo $h($i['nome'] . ' ' . $i['cognome']); ?>, esci e accedi con <strong>la tua</strong> identità digitale (SPID o CIE).</div>
        <a class="btn btn-outline-secondary" href="esci.php">Esci</a>
    <?php else:
        $d = dati_lettera_incarico($i);
        $err_acc = $i['stato'] === 'inviata' ? accesso_valido_incarico($i, $user_info, $meta) : null;
        $firma = json_decode((string)$i['studente_firma_json'], true) ?: null; ?>
        <h1 class="fw-bold h3 mb-1">Lettera di incarico di tutorato</h1>
        <p class="text-secondary mb-3"><?php echo $h($i['bando_titolo']); ?> · <?php echo badge_stato_incarico($i['stato']); ?></p>
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                    <h2 class="h6 fw-bold">Controlla i tuoi dati</h2>
                    <dl class="small mb-0">
                        <dt>Nome</dt><dd><?php echo $h($d['TITOLO'] . ' ' . $d['NOMINATIVO']); ?></dd>
                        <dt>Codice fiscale</dt><dd class="font-monospace"><?php echo $h($i['codice_fiscale']); ?></dd>
                        <dt>Nascita</dt><dd><?php echo $h($d['LUOGO_NASCITA'] . ', ' . $d['DATA_NASCITA']); ?></dd>
                        <dt>Residenza</dt><dd><?php echo $h($d['COMUNE_RESIDENZA'] . ', ' . $d['INDIRIZZO']); ?></dd>
                        <dt>Attività</dt><dd><?php echo nl2br($h($d['ATTIVITA'])); ?></dd>
                        <dt>Ore · periodo</dt><dd><?php echo $h($d['ORE'] . ' ore · ' . $d['PERIODO']); ?></dd>
                        <dt>Compenso</dt><dd><?php echo $h($d['COMPENSO']); ?> (al netto degli oneri a carico dell'Ente)</dd>
                        <dt>Responsabile dell'attività</dt><dd><?php echo $h($d['FIRMA_DOCENTE']); ?></dd>
                    </dl>
                </div></div>
                <?php if ($i['stato'] === 'inviata'): ?>
                    <?php if ($err_acc): ?>
                        <div class="alert alert-warning"><i class="fa fa-id-card me-1" aria-hidden="true"></i><?php echo $h($err_acc); ?>
                            <div class="mt-2"><a class="btn btn-sm btn-dark" href="esci.php">Esci e rientra con SPID o CIE</a></div></div>
                    <?php else: ?>
                        <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:5px solid #047857 !important;"><div class="card-body">
                            <?php csrf_field(); ?>
                            <h2 class="h6 fw-bold">Conferma e accetta</h2>
                            <p class="small">Sei entrato con <strong><?php echo $h(METODI_ACCESSO[$meta['metodo'] ?? 'ateneo'] ?? ''); ?></strong><?php echo !empty($meta['livello']) ? ' (livello ' . (int)$meta['livello'] . ')' : ''; ?>. Con la conferma nella lettera vengono riportati il tuo codice fiscale, il metodo di accesso, l'identity provider, la data e l'ora: valgono come accettazione dell'incarico.</p>
                            <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="accetto" value="1" required> Ho letto la lettera, i dati sono corretti e <strong>accetto l'incarico</strong> alle condizioni indicate.</label>
                            <button type="submit" name="conferma" value="1" class="btn btn-success fw-bold"><i class="fa fa-signature me-1" aria-hidden="true"></i>Confermo e accetto</button>
                        </div></form>
                    <?php endif; ?>
                    <form method="POST" class="card border-0 shadow-sm"><div class="card-body">
                        <?php csrf_field(); ?>
                        <h2 class="h6 fw-bold">C'è un errore nei dati?</h2>
                        <textarea class="form-control form-control-sm mb-2" name="testo" rows="2" maxlength="2000" placeholder="Scrivi cosa va corretto" aria-label="Cosa va corretto"></textarea>
                        <button type="submit" name="segnala" value="1" class="btn btn-sm btn-outline-secondary">Segnala all'ufficio</button>
                    </div></form>
                <?php else: ?>
                    <div class="card border-0 shadow-sm"><div class="card-body small">
                        <h2 class="h6 fw-bold">Hai accettato l'incarico</h2>
                        <?php if ($firma): ?><p>Il <?php echo date('d/m/Y \a\l\l\e H:i', strtotime($firma['confermata_il'])); ?> con <?php echo $h(METODI_ACCESSO[$firma['metodo']] ?? $firma['metodo']); ?>.</p><?php endif; ?>
                        <p class="mb-0">Ora: <?php echo $h(STATI_INCARICO[$i['stato']][0] ?? $i['stato']); ?>. Quando avrà tutte le firme riceverai la lettera per email.</p>
                    </div></div>
                    <?php if (in_array($i['stato'], ['firmata', 'protocollata'], true)): ?>
                        <a class="btn btn-success fw-bold mt-3" href="registro_tutorato.php?id=<?php echo (int)$i['id']; ?>"><i class="fa fa-clipboard-list me-1" aria-hidden="true"></i>Apri il registro delle attività</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm"><div class="card-body p-1">
                    <iframe src="incarico.php?t=<?php echo $h(urlencode($tok)); ?>&amp;pdf=1" title="Lettera di incarico in PDF" style="width:100%;height:720px;border:0;"></iframe>
                    <div class="p-2 small"><a href="incarico.php?t=<?php echo $h(urlencode($tok)); ?>&amp;pdf=1" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Apri il PDF</a></div>
                </div></div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
