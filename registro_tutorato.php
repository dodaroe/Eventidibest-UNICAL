<?php
// registro_tutorato.php - Registro delle attività di tutorato (serve l'accesso):
// - il tutor (riconosciuto dal codice fiscale) segna giorno, ore e attività e, alla fine, dichiara concluse le attività;
// - il docente responsabile (riconosciuto da email, codice fiscale o scheda dell'anagrafe) approva o respinge le ore e
//   conferma la fine: il portale prepara la dichiarazione di fine attività da firmare in PAdES (firma_incarico.php).
// Logica in inc/tutorato_registro.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$miei = incarichi_registro($conn, $user_info);
$id = (int)($_GET['id'] ?? 0);
$i = null;
foreach ($miei as $x) if ((int)$x['id'] === $id) $i = $x;
if (!$i && count($miei) === 1 && !$id) $i = $miei[0];

if ($i && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $err = null; $ok = '';
    $tutor = $i['_ruolo'] === 'tutor';
    if ($tutor && isset($_POST['aggiungi'])) { $err = aggiungi_registro($conn, (int)$i['id'], (string)($_POST['data'] ?? ''), $_POST['ore'] ?? '', (string)($_POST['attivita'] ?? '')); $ok = "Attività segnata nel registro: il docente la approverà."; }
    elseif ($tutor && isset($_POST['togli'])) { $err = togli_registro($conn, (int)$i['id'], (int)$_POST['togli']); $ok = "Riga tolta."; }
    elseif ($tutor && isset($_POST['concludi'])) { $err = empty($_POST['conferma']) ? "Spunta la conferma." : richiedi_fine_attivita($conn, (int)$i['id']); $ok = "Fatto: il docente responsabile riceve l'avviso per approvare le ore e confermare la fine."; }
    elseif (!$tutor && (isset($_POST['approva']) || isset($_POST['respingi']))) {
        $n = decidi_registro($conn, (int)$i['id'], isset($_POST['respingi']) ? 'respinta' : 'approvata', array_map('intval', (array)($_POST['righe'] ?? [])), (string)($_POST['nota'] ?? ''));
        if (!$n) $err = "Scegli le righe."; else $ok = "$n righe " . (isset($_POST['respingi']) ? 'respinte' : 'approvate') . ".";
    } elseif (!$tutor && isset($_POST['conferma_fine'])) {
        [$tok, $err] = conferma_fine_attivita($conn, (int)$i['id'], trim(($user_info['nome'] ?? '') . ' ' . ($user_info['cognome'] ?? '')));
        if (!$err) { flash_set("Dichiarazione di fine attività pronta: controllala e firmala in PAdES."); header('Location: firma_incarico.php?t=' . $tok); exit; }
    }
    flash_set($err ?? $ok, $err ? 'danger' : 'success');
    header('Location: registro_tutorato.php?id=' . (int)$i['id'] . '&r=' . time()); exit;
}

$page_cfg['titolo'] = 'Registro delle attività di tutorato';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 1050px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="area_personale.php">Area personale</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Registro delle attività di tutorato</span></nav>
    <?php echo flash_html(); ?>
    <?php if (!$miei): ?>
        <h1 class="fw-bold h3">Registro delle attività di tutorato</h1>
        <div class="alert alert-light border">Non ci sono incarichi di tutorato attivi per te. Il registro si apre quando la lettera di incarico ha tutte le firme.</div>
    <?php elseif (!$i): ?>
        <h1 class="fw-bold h3 mb-3">Registro delle attività di tutorato</h1>
        <div class="list-group">
        <?php foreach ($miei as $x): $o = ore_registro(registro_incarico($conn, (int)$x['id'])); ?>
            <a class="list-group-item list-group-item-action d-flex flex-wrap justify-content-between gap-2" href="registro_tutorato.php?id=<?php echo (int)$x['id']; ?>">
                <span><strong><?php echo $h($x['_ruolo'] === 'tutor' ? $x['bando_titolo'] : trim($x['cognome'] . ' ' . $x['nome'])); ?></strong><span class="d-block small text-secondary"><?php echo $h(mb_strimwidth((string)$x['attivita'], 0, 90, '…')); ?></span></span>
                <span class="small"><?php echo $x['_ruolo'] === 'docente' ? '<span class="badge bg-light text-dark border">sei il responsabile</span> ' : ''; ?><?php echo ore_testo($o['totale']); ?> / <?php echo ore_testo($x['ore']); ?> ore<?php echo $o['inviata'] > 0 ? ' · <span class="badge" style="background:#b45309;">' . ore_testo($o['inviata']) . ' da approvare</span>' : ''; ?></span>
            </a>
        <?php endforeach; ?>
        </div>
    <?php else:
        $tutor = $i['_ruolo'] === 'tutor';
        $reg = registro_incarico($conn, (int)$i['id']); $o = ore_registro($reg);
        $pc = $i['ore'] ? min(100, round($o['approvata'] * 100 / (float)$i['ore'])) : 0;
        $pc_att = $i['ore'] ? min(100 - $pc, round($o['inviata'] * 100 / (float)$i['ore'])) : 0;
        [$fs_nome, $fs_col] = STATI_FINE_ATTIVITA[(string)$i['fine_stato']] ?? ['', '#64748b'];
        $aperto = registro_aperto($i); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div><h1 class="fw-bold h3 mb-0">Registro delle attività di tutorato</h1>
                <div class="text-secondary"><?php echo $h($tutor ? $i['bando_titolo'] : 'Tutor: ' . trim($i['nome'] . ' ' . $i['cognome']) . ' · ' . $i['bando_titolo']); ?></div></div>
            <span class="badge fs-6" style="background:<?php echo $fs_col; ?>;"><?php echo $h($fs_nome); ?></span>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-8"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-secondary mb-1">Attività</div><div class="mb-2"><?php echo nl2br($h($i['attivita'])); ?></div>
                <div class="small text-secondary">Periodo: <?php echo $h($i['periodo']); ?> · Responsabile: <?php echo $h($i['docente_titolo'] . ' ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome'])); ?></div>
            </div></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-secondary">Ore</div>
                <div class="fs-3 fw-bold"><?php echo ore_testo($o['totale']); ?> <span class="fs-6 text-secondary fw-normal">/ <?php echo ore_testo($i['ore']); ?></span></div>
                <div class="progress mb-1" style="height:10px;" role="img" aria-label="<?php echo ore_testo($o['approvata']); ?> ore approvate, <?php echo ore_testo($o['inviata']); ?> da approvare">
                    <div class="progress-bar" style="width:<?php echo $pc; ?>%;background:#15803d;"></div><div class="progress-bar" style="width:<?php echo $pc_att; ?>%;background:#f59e0b;"></div></div>
                <div class="small"><span style="color:#15803d;">■</span> <?php echo ore_testo($o['approvata']); ?> approvate · <span style="color:#f59e0b;">■</span> <?php echo ore_testo($o['inviata']); ?> da approvare</div>
            </div></div></div>
        </div>

        <?php if ($tutor && $aperto && $i['fine_stato'] === ''): ?>
        <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:5px solid #047857 !important;"><div class="card-body">
            <?php csrf_field(); ?>
            <h2 class="h6 fw-bold">Segna un'attività</h2>
            <div class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="rData">Giorno</label><input type="date" class="form-control" id="rData" name="data" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="rOre">Ore</label><input type="number" class="form-control" id="rOre" name="ore" min="0.5" max="12" step="0.5" required></div>
                <div class="col-md-5"><label class="form-label small fw-bold mb-0" for="rAtt">Attività svolta</label><input class="form-control" id="rAtt" name="attivita" maxlength="1000" required placeholder="es. esercitazione di stechiometria con 12 studenti"></div>
                <div class="col-md-2"><button type="submit" name="aggiungi" value="1" class="btn btn-success fw-bold w-100"><i class="fa fa-plus me-1" aria-hidden="true"></i>Segna</button></div>
            </div>
        </div></form>
        <?php endif; ?>

        <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body">
            <?php csrf_field(); ?>
            <h2 class="h6 fw-bold">Registro</h2>
            <div class="table-responsive"><table class="table table-sm align-middle small mb-2">
                <thead class="table-light"><tr><?php if (!$tutor && $aperto): ?><th style="width:28px;"><input type="checkbox" class="form-check-input" id="rTutte" aria-label="Seleziona tutte le righe da approvare"></th><?php endif; ?><th>Giorno</th><th class="text-center">Ore</th><th>Attività</th><th>Stato</th><?php if ($tutor && $aperto): ?><th></th><?php endif; ?></tr></thead><tbody>
                <?php foreach ($reg as $r): [$sn, $sc] = STATI_REGISTRO[$r['stato']]; ?>
                    <tr><?php if (!$tutor && $aperto): ?><td><?php if ($r['stato'] === 'inviata'): ?><input type="checkbox" class="form-check-input r-sel" name="righe[]" value="<?php echo (int)$r['id']; ?>" aria-label="Seleziona il <?php echo date('d/m/Y', strtotime($r['data'])); ?>"><?php endif; ?></td><?php endif; ?>
                        <td class="text-nowrap"><?php echo date('d/m/Y', strtotime($r['data'])); ?></td><td class="text-center"><?php echo ore_testo($r['ore']); ?></td>
                        <td><?php echo $h($r['attivita']); ?><?php echo $r['nota_docente'] !== '' ? '<div class="text-secondary"><i class="fa fa-comment me-1" aria-hidden="true"></i>' . $h($r['nota_docente']) . '</div>' : ''; ?></td>
                        <td><span class="badge" style="background:<?php echo $sc; ?>;"><?php echo $h($sn); ?></span></td>
                        <?php if ($tutor && $aperto): ?><td><?php if ($r['stato'] === 'inviata'): ?><button type="submit" name="togli" value="<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-link text-danger p-0" data-confirm="Togliere questa riga?" aria-label="Togli la riga"><i class="fa fa-trash" aria-hidden="true"></i></button><?php endif; ?></td><?php endif; ?></tr>
                <?php endforeach; ?>
                <?php if (!$reg): ?><tr><td colspan="6" class="text-center text-muted py-3">Nessuna attività segnata.</td></tr><?php endif; ?>
            </tbody></table></div>
            <?php if (!$tutor && $aperto && $o['inviata'] > 0): ?>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <input class="form-control form-control-sm" style="max-width:320px;" name="nota" maxlength="500" placeholder="Nota per il tutor (facoltativa)" aria-label="Nota per il tutor">
                    <button type="submit" name="approva" value="1" class="btn btn-sm btn-success fw-bold"><i class="fa fa-check me-1" aria-hidden="true"></i>Approva le selezionate</button>
                    <button type="submit" name="respingi" value="1" class="btn btn-sm btn-outline-danger">Respingi le selezionate</button>
                </div>
            <?php endif; ?>
        </div></form>

        <?php if ($tutor && $aperto && $i['fine_stato'] === '' && $reg): ?>
        <form method="POST" class="card border-0 shadow-sm"><div class="card-body">
            <?php csrf_field(); ?>
            <h2 class="h6 fw-bold">Hai finito?</h2>
            <p class="small text-secondary mb-2">Quando hai svolto tutte le attività dichiarale concluse: il docente responsabile approva le ore e firma la dichiarazione di fine attività; poi l'Ufficio procede per il compenso.</p>
            <label class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="conferma" value="1" required> Dichiaro di aver concluso le attività e che il registro è completo.</label>
            <button type="submit" name="concludi" value="1" class="btn btn-primary fw-bold"><i class="fa fa-flag-checkered me-1" aria-hidden="true"></i>Ho concluso le attività</button>
        </div></form>
        <?php elseif (!$tutor && $aperto && $reg): ?>
        <form method="POST" class="card border-0 shadow-sm" style="border-left:5px solid #7c3aed !important;"><div class="card-body">
            <?php csrf_field(); ?>
            <h2 class="h6 fw-bold">Conferma la fine delle attività</h2>
            <p class="small text-secondary mb-2"><?php echo $i['fine_stato'] === 'richiesta' ? 'Il tutor ha dichiarato concluse le attività. ' : ''; ?>Con la conferma il portale prepara la <strong>dichiarazione di fine attività</strong> (modello del Dipartimento) con le <?php echo ore_testo($o['approvata']); ?> ore approvate, da firmare in PAdES.<?php echo $o['inviata'] > 0 ? ' Prima approva o respingi le ore ancora da decidere.' : ''; ?></p>
            <button type="submit" name="conferma_fine" value="1" class="btn fw-bold text-white" style="background:#7c3aed;"<?php echo $o['inviata'] > 0 ? ' disabled' : ''; ?>><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Conferma e prepara la dichiarazione</button>
        </div></form>
        <?php elseif (!$tutor && $i['fine_stato'] === 'da_firmare'): ?>
            <div class="alert alert-warning">La dichiarazione di fine attività aspetta la sua firma: <a href="firma_incarico.php?t=<?php echo $h($i['token_fine']); ?>" class="fw-bold">apri e firma</a>.</div>
        <?php endif; ?>
        <script>(function () { var t = document.getElementById('rTutte'); if (t) t.addEventListener('change', function () { document.querySelectorAll('.r-sel').forEach(function (c) { c.checked = t.checked; }); }); })();</script>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
