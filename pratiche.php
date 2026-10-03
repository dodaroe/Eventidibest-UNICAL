<?php
// pratiche.php - Le mie pratiche (Area personale): elenco delle richieste fatte con i moduli online della didattica,
// dettaglio con risposte, stato, storico e messaggi con l'ufficio (anche con allegati).
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$uid = (int)$_SESSION['utente_id'];
$id = (int)($_GET['id'] ?? 0);
$p = $id ? pratica($conn, $id) : null;
if ($p && (int)$p['utente_id'] !== $uid) $p = null; // solo le proprie

if ($p && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autodichiarazione'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $err = autodichiarazione_pratica($conn, (int)$p['id'], $uid, (string)($_POST['aggiunta'] ?? ''), !empty($_POST['conferma']));
    flash_set($err ?? "Autodichiarazione inviata: l'ufficio riprende la pratica.", $err ? 'danger' : 'success');
    header('Location: pratiche.php?id=' . (int)$p['id'] . '&r=' . time()); exit;
}
if ($p && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['messaggio'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (in_array($p['stato'], ['chiusa'], true)) flash_set("La pratica è chiusa: per una nuova richiesta compila di nuovo il modulo.", 'warning');
    else {
        $err = messaggio_pratica($conn, (int)$p['id'], 'studente', $uid, (string)($_POST['testo'] ?? ''), $_FILES['allegato'] ?? null);
        flash_set($err ?? "Messaggio inviato all'ufficio.", $err ? 'danger' : 'success');
    }
    header('Location: pratiche.php?id=' . (int)$p['id'] . '&r=' . time()); exit;
}

$page_cfg['titolo'] = 'Le mie pratiche';
require_once 'header.php';
?>
<style>
.pr-ev { border-left: 3px solid #e2e8f0; padding: 4px 0 10px 14px; position: relative; }
.pr-ev::before { content: ''; position: absolute; left: -7px; top: 6px; width: 11px; height: 11px; border-radius: 50%; background: #0056B3; }
.pr-ev.uff::before { background: #047857; }
</style>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="area_personale.php">Area personale</a> <span class="text-secondary mx-1">/</span> <?php if ($p): ?><a href="pratiche.php">Le mie pratiche</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary"><?php echo $h($p['codice']); ?></span><?php else: ?><span class="text-secondary">Le mie pratiche</span><?php endif; ?></nav>
    <?php echo flash_html(); ?>
    <?php if ($p):
        $risposte = json_decode((string)$p['risposte_json'], true) ?: [];
        $eventi = $conn->query("SELECT * FROM pratiche_eventi WHERE pratica_id = " . (int)$p['id'] . " AND interno = 0 ORDER BY creato_il, id")->fetch_all(MYSQLI_ASSOC);
        $m_p = modulo_didattica($conn, (int)$p['modulo_id']);
        $richiesta = $p['stato'] === 'integrazione' ? (json_decode((string)($p['richiesta_json'] ?? ''), true) ?: ['tipo' => 'documenti', 'testo' => '']) : null;
        $etichette_ev = ['passaggio' => 'Passaggio', 'attivita' => 'Attività', 'autodich' => 'Autodichiarazione']; ?>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div><h1 class="fw-bold h3 mb-0"><?php echo $h($p['modulo_titolo']); ?></h1><div class="small text-secondary font-monospace"><?php echo $h($p['codice']); ?> · inviata il <?php echo date('d/m/Y H:i', strtotime($p['creata_il'])); ?><?php echo ($p['protocollo'] ?? '') !== '' ? ' · prot. ' . $h($p['protocollo']) . (!empty($p['protocollo_data']) ? ' del ' . date('d/m/Y', strtotime($p['protocollo_data'])) : '') : ''; ?></div></div>
            <div class="fs-6"><?php echo badge_stato_pratica($p['stato']); ?></div>
        </div>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h6 fw-bold mb-2">A che punto è la tua pratica</h2>
            <?php echo html_iter_pratica($conn, $p, $m_p); ?>
        </div></div>
        <?php if ($richiesta && $richiesta['tipo'] === 'autodichiarazione'): ?>
            <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:5px solid #b45309 !important;"><div class="card-body">
                <?php csrf_field(); ?>
                <h2 class="h6 fw-bold"><i class="fa fa-file-signature me-1" style="color:#b45309;" aria-hidden="true"></i>L'ufficio ti chiede un'autodichiarazione</h2>
                <p class="mb-2">Il/La sottoscritto/a <strong><?php echo $h(trim($p['nome'] . ' ' . $p['cognome'])); ?></strong><?php echo $p['matricola'] !== '' ? ', matricola ' . $h($p['matricola']) : ''; ?>, dichiara:</p>
                <blockquote class="border-start border-3 ps-3 mb-2"><?php echo nl2br($h($richiesta['testo'])); ?></blockquote>
                <label class="form-label small fw-bold" for="prAgg">Precisazioni (facoltative)</label>
                <textarea class="form-control form-control-sm mb-2" id="prAgg" name="aggiunta" rows="2" maxlength="3000" placeholder="Es. date, voti, dettagli richiesti"></textarea>
                <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="conferma" value="1" required> Dichiaro, ai sensi degli artt. 46 e 47 del D.P.R. 445/2000 e consapevole delle sanzioni penali previste dall'art. 76 per le dichiarazioni mendaci, che quanto sopra corrisponde al vero.</label>
                <button type="submit" name="autodichiarazione" value="1" class="btn btn-sm fw-bold text-white" style="background:#b45309;"><i class="fa fa-signature me-1" aria-hidden="true"></i>Invia l'autodichiarazione</button>
            </div></form>
        <?php elseif ($richiesta): ?>
            <div class="alert alert-warning"><i class="fa fa-circle-exclamation me-1" aria-hidden="true"></i>L'ufficio ti chiede di <strong>integrare la documentazione</strong><?php echo $richiesta['testo'] !== '' ? ': ' . $h($richiesta['testo']) : ''; ?>. Allega i file qui sotto (puoi aggiungere un messaggio): la pratica torna in lavorazione.</div>
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body">
                <h2 class="h6 fw-bold">La tua richiesta</h2>
                <dl class="small mb-0">
                    <?php foreach ($risposte as $i => $r): if (!empty($r['nascosto'])) continue; ?><dt><?php echo $h($r['etichetta']); ?></dt>
                        <dd><?php if (!empty($r['file'])): ?><a href="allegato_pratica.php?p=<?php echo (int)$p['id']; ?>&amp;r=<?php echo $i; ?>"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><?php echo $h($r['nome_file']); ?></a><?php else: echo html_risposta_pratica($r); endif; ?></dd>
                    <?php endforeach; ?>
                    <?php if (!$risposte): ?><dd class="text-muted">Nessuna risposta.</dd><?php endif; ?>
                </dl>
            </div></div></div>
            <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body">
                <h2 class="h6 fw-bold">Storico, attività e messaggi</h2>
                <?php foreach ($eventi as $e): ?>
                    <div class="pr-ev <?php echo $e['autore'] === 'ufficio' ? 'uff' : ''; ?>">
                        <div class="small text-secondary"><?php echo date('d/m/Y H:i', strtotime($e['creato_il'])); ?> · <?php echo $e['autore'] === 'ufficio' ? $h($e['autore_nome'] ?: 'Ufficio didattico') : 'Tu'; ?>
                            <?php if (isset($etichette_ev[$e['tipo']])): ?> · <span class="badge bg-light text-dark border"><?php echo $etichette_ev[$e['tipo']]; ?></span><?php endif; ?>
                            <?php if ($e['stato']): ?> · <?php echo badge_stato_pratica((string)$e['stato']); ?><?php endif; ?></div>
                        <?php if ((string)$e['testo'] !== ''): ?><div class="small"><?php echo nl2br($h($e['testo'])); ?></div><?php endif; ?>
                        <?php if ($e['allegato']): ?><div class="small"><a href="allegato_pratica.php?p=<?php echo (int)$p['id']; ?>&amp;e=<?php echo (int)$e['id']; ?>"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><?php echo $h($e['nome_allegato']); ?></a></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($p['stato'] !== 'chiusa'): ?>
                <form method="POST" enctype="multipart/form-data" class="mt-3">
                    <?php csrf_field(); ?>
                    <label class="form-label small fw-bold" for="prMsg">Scrivi all'ufficio o aggiungi documenti</label>
                    <textarea class="form-control form-control-sm mb-2" id="prMsg" name="testo" rows="3" maxlength="5000"></textarea>
                    <div class="d-flex flex-wrap gap-2"><input type="file" name="allegato" class="form-control form-control-sm" style="max-width:300px;" accept=".pdf,.jpg,.jpeg,.png,.p7m" aria-label="Allegato (facoltativo)">
                        <button type="submit" name="messaggio" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia</button></div>
                    <div class="form-text">Puoi integrare la documentazione in qualsiasi momento finché la pratica non è chiusa.</div>
                </form>
                <?php endif; ?>
            </div></div></div>
        </div>
    <?php else:
        $mie = $conn->query("SELECT p.*, m.titolo AS modulo_titolo FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id WHERE p.utente_id = $uid ORDER BY p.aggiornata_il DESC")->fetch_all(MYSQLI_ASSOC); ?>
        <h1 class="fw-bold h2 mb-1">Le mie pratiche</h1>
        <p class="text-secondary">Le richieste inviate con i moduli online. Per una nuova richiesta vai alla <a href="modulistica.php">Modulistica</a>.</p>
        <?php if (!$mie): ?><div class="alert alert-light border">Non hai ancora inviato richieste.</div><?php endif; ?>
        <div class="list-group">
        <?php foreach ($mie as $x): ?>
            <a href="pratiche.php?id=<?php echo (int)$x['id']; ?>" class="list-group-item list-group-item-action d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><strong><?php echo $h($x['modulo_titolo']); ?></strong><span class="d-block small text-secondary font-monospace"><?php echo $h($x['codice']); ?> · aggiornata il <?php echo date('d/m/Y H:i', strtotime($x['aggiornata_il'] ?: $x['creata_il'])); ?></span></span>
                <?php echo badge_stato_pratica($x['stato']); ?>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
