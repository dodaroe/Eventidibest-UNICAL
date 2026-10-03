<?php
// didattica_statistiche.php - Statistiche delle pratiche: per modulo, per corso di studio, tempi dell'iter.
// Scheda del pannello Didattica: si apre da didattica.php?tab=statistiche (stesso indirizzo di sempre), mai direttamente.
// $fase = 'azioni': gestisce i POST della scheda (ognuno risponde con un rimando e termina); $fase = 'vista': la pagina.
if (!defined('DIDATTICA_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }

if ($fase === 'azioni') {

    return;
}
?>
<?php
    $s_dal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['dal'] ?? '')) ? $_GET['dal'] : (anno_accademico_corrente() . '-09-01');
    $s_al = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['al'] ?? '')) ? $_GET['al'] : date('Y-m-d');
    $stat = statistiche_pratiche($conn, $s_dal, $s_al);
    $barra = function (int $n, int $tot, string $col) { $pc = $tot ? round($n * 100 / $tot) : 0; return '<div class="d-flex align-items-center gap-2"><div class="flex-grow-1 bg-light rounded" style="height:8px;"><div class="rounded" style="height:8px;width:' . $pc . '%;background:' . $col . ';"></div></div><span class="small text-secondary" style="min-width:34px;">' . $pc . '%</span></div>'; };
    $tab_stat = function (array $righe, string $titolo_col) use ($h, $barra) { ?>
        <div class="table-responsive"><table class="table table-sm align-middle small mb-0">
            <thead class="table-light"><tr><th><?php echo $titolo_col; ?></th><th class="text-center">Totale</th><th class="text-center">Aperte</th><th class="text-center">Accolte</th><th class="text-center">Respinte</th><th class="text-center">Chiuse</th><th style="width:22%;">Accolte sul totale</th><?php if ($righe && array_key_exists('giorni', reset($righe))): ?><th class="text-center">Giorni per chiudere</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($righe as $k => $x): ?>
                <tr><td class="fw-bold"><?php echo $h($k); ?></td><td class="text-center"><?php echo $x['totale']; ?></td><td class="text-center"><?php echo $x['aperte']; ?></td><td class="text-center"><?php echo $x['accolta']; ?></td><td class="text-center"><?php echo $x['respinta']; ?></td><td class="text-center"><?php echo $x['chiusa']; ?></td>
                    <td><?php echo $barra($x['accolta'], $x['totale'], '#15803d'); ?></td><?php if (array_key_exists('giorni', $x)): ?><td class="text-center"><?php echo $x['giorni'] !== null ? $x['giorni'] : '—'; ?></td><?php endif; ?></tr>
            <?php endforeach; ?>
            <?php if (!$righe): ?><tr><td colspan="8" class="text-center text-muted py-3">Nessuna pratica nel periodo.</td></tr><?php endif; ?>
        </tbody></table></div>
    <?php };
?>
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="tab" value="statistiche">
        <div><label class="form-label small fw-bold mb-0" for="stDal">Pratiche inviate dal</label><input type="date" class="form-control form-control-sm" id="stDal" name="dal" value="<?php echo $h($s_dal); ?>"></div>
        <div><label class="form-label small fw-bold mb-0" for="stAl">al</label><input type="date" class="form-control form-control-sm" id="stAl" name="al" value="<?php echo $h($s_al); ?>"></div>
        <button class="btn btn-sm btn-primary fw-bold">Aggiorna</button>
        <span class="small text-secondary">Predefinito: dall'inizio dell'anno accademico.</span>
    </form>
    <div class="row g-3 mb-3">
        <?php foreach ([['Pratiche', $stat['totale'], '#0056B3', 'fa-inbox'], ['Aperte', $stat['esiti']['aperte'], '#7c3aed', 'fa-gears'], ['Accolte', $stat['esiti']['accolta'], '#15803d', 'fa-circle-check'],
                        ['Respinte', $stat['esiti']['respinta'], '#b91c1c', 'fa-circle-xmark'], ['Giorni medi per chiudere', $stat['chiusura_media'] ?? '—', '#b45309', 'fa-hourglass-half']] as [$t, $v, $c, $i]): ?>
            <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="small text-secondary"><i class="fa <?php echo $i; ?> me-1" style="color:<?php echo $c; ?>;" aria-hidden="true"></i><?php echo $t; ?></div><div class="fs-3 fw-bold" style="color:<?php echo $c; ?>;"><?php echo $v; ?></div></div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6 class="fw-bold">Per modulo</h6><?php $tab_stat($stat['per_modulo'], 'Modulo'); ?></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6 class="fw-bold">Per corso di studio</h6><p class="small text-secondary mb-2">Dal campo «Corso di studio» dei moduli che lo chiedono.</p><?php $tab_stat($stat['per_corso'], 'Corso di studio'); ?></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6 class="fw-bold">Tempi medi per passo dell'iter</h6>
        <p class="small text-secondary mb-2">Giorni da quando la pratica arriva a un passo a quando passa al successivo (o si conclude). «Smistamento» = dall'invio alla prima assegnazione.</p>
        <?php $max_t = max(1, ...array_values(array_map(fn($x) => (float)$x['media'], $stat['tempi_passi'] ?: [['media' => 1]]))); ?>
        <div class="table-responsive"><table class="table table-sm align-middle small mb-0"><thead class="table-light"><tr><th>Passo</th><th class="text-center">Pratiche</th><th class="text-center">Media (giorni)</th><th class="text-center">Massimo</th><th style="width:35%;"></th></tr></thead><tbody>
            <?php foreach ($stat['tempi_passi'] as $nome => $x): ?>
                <tr><td class="fw-bold"><?php echo $h($nome); ?></td><td class="text-center"><?php echo $x['n']; ?></td><td class="text-center"><?php echo $x['media']; ?></td><td class="text-center"><?php echo $x['max']; ?></td>
                    <td><div class="bg-light rounded" style="height:8px;"><div class="rounded" style="height:8px;width:<?php echo round($x['media'] * 100 / $max_t); ?>%;background:#b45309;"></div></div></td></tr>
            <?php endforeach; ?>
            <?php if (!$stat['tempi_passi']): ?><tr><td colspan="5" class="text-center text-muted py-3">Nessun passaggio nel periodo.</td></tr><?php endif; ?>
        </tbody></table></div></div></div>

