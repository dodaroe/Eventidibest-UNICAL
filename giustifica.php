<?php
// giustifica.php - Giustificazione dell'assenza da una seduta del consiglio, dal link personale della convocazione per email
// (senza accesso: il link è personale). Il componente risulta «assente giustificato» nelle presenze. Logica in inc/sedute.php.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tok = (string)($_GET['t'] ?? '');
$c = convocazione_per_token($conn, $tok);
$s = $c ? seduta_didattica($conn, (int)$c['seduta_id']) : null;
if ($c && $s && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $err = check_rate_limit($conn, 'giustifica', 20, 900) ? giustifica_assenza($conn, $tok, (string)($_POST['motivo'] ?? '')) : "Troppi tentativi: riprova tra qualche minuto.";
    flash_set($err ?? "Assenza giustificata: risulterai «assente giustificato» nel verbale.", $err ? 'danger' : 'success');
    header('Location: giustifica.php?t=' . urlencode($tok) . '&r=' . time()); exit;
}
$page_cfg['titolo'] = 'Giustifica l\'assenza';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 720px;">
    <?php echo flash_html(); ?>
    <?php if (!$c || !$s): ?>
        <h1 class="fw-bold h3">Link non valido</h1><p>Il link non è valido o la seduta è stata eliminata.</p>
    <?php else: $passata = $s['data'] && $s['data'] < date('Y-m-d'); ?>
        <h1 class="fw-bold h3 mb-1">Giustifica l'assenza</h1>
        <p class="text-secondary"><?php echo $h($s['organo']); ?></p>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <dl class="row small mb-0">
                <dt class="col-sm-3">Componente</dt><dd class="col-sm-9"><?php echo $h($c['nominativo']); ?></dd>
                <dt class="col-sm-3">Seduta</dt><dd class="col-sm-9"><?php echo $s['data'] ? date('d/m/Y', strtotime($s['data'])) : 'data da definire'; ?><?php echo $s['ora_inizio'] ? ' alle ore ' . $h($s['ora_inizio']) : ''; ?><?php echo $s['luogo'] ? ' · ' . $h($s['luogo']) : ''; ?></dd>
            </dl>
        </div></div>
        <?php if ($c['giustificata_il']): ?>
            <div class="alert alert-success"><i class="fa fa-check me-1" aria-hidden="true"></i>Hai giustificato l'assenza il <?php echo date('d/m/Y \a\l\l\e H:i', strtotime($c['giustificata_il'])); ?><?php echo $c['motivo'] !== '' ? ': «' . $h($c['motivo']) . '»' : ''; ?>.</div>
        <?php elseif ($passata): ?>
            <div class="alert alert-secondary">La seduta si è già svolta: per giustificare l'assenza scrivi al coordinatore.</div>
        <?php else: ?>
            <form method="POST" class="card border-0 shadow-sm" style="border-left:5px solid #b45309 !important;"><div class="card-body">
                <?php csrf_field(); ?>
                <label class="form-label small fw-bold" for="gMot">Motivo (facoltativo)</label>
                <textarea class="form-control mb-2" id="gMot" name="motivo" rows="2" maxlength="500" placeholder="es. impegni didattici, missione, malattia"></textarea>
                <button type="submit" class="btn btn-warning fw-bold"><i class="fa fa-user-clock me-1" aria-hidden="true"></i>Non potrò partecipare: giustifica l'assenza</button>
            </div></form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
