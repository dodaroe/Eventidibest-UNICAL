<?php
// didattica_sedute.php - Sedute e verbali: consigli (referenti, componenti), sedute, presenze, pratiche con esiti e decisioni, verbale.
// Convocazione per email (facoltativa), estratti per gli studenti, verbale firmato in PAdES: inc/sedute.php.
// Scheda del pannello Didattica: si apre da didattica.php?tab=sedute (stesso indirizzo di sempre), mai direttamente.
// $fase = 'azioni': gestisce i POST della scheda (ognuno risponde con un rimando e termina); $fase = 'vista': la pagina.
if (!defined('DIDATTICA_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }

if ($fase === 'azioni') {
    // ── Consigli: dati, referenti (Ufficio didattico) e componenti (anche i referenti) ──
    if (isset($_POST['salva_consiglio']) && !$solo_ref) {
        $cid = (int)($_POST['consiglio_id'] ?? 0);
        $f = [];
        foreach (['nome' => 500, 'coordinatore' => 200, 'segretario' => 200, 'luogo' => 255, 'odg' => 5000] as $k => $max) $f[$k] = mb_substr(trim((string)($_POST['c_' . $k] ?? '')), 0, $max);
        $corsi_c = json_encode(array_values(array_filter(array_map('trim', (array)($_POST['c_corsi'] ?? [])))), JSON_UNESCAPED_UNICODE);
        $att_c = isset($_POST['c_attivo']) ? 1 : 0; $ord_c = (int)($_POST['c_ordine'] ?? 0);
        if ($f['nome'] === '') flash_set("Scrivi il nome del consiglio.", 'danger');
        else {
            if ($cid) { $st = $conn->prepare("UPDATE didattica_consigli SET nome=?, corsi=?, coordinatore=?, segretario=?, luogo=?, odg=?, attivo=?, ordine=? WHERE id=?"); $st->bind_param("ssssssiii", $f['nome'], $corsi_c, $f['coordinatore'], $f['segretario'], $f['luogo'], $f['odg'], $att_c, $ord_c, $cid); }
            else { $st = $conn->prepare("INSERT INTO didattica_consigli (nome, corsi, coordinatore, segretario, luogo, odg, attivo, ordine) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"); $st->bind_param("ssssssii", $f['nome'], $corsi_c, $f['coordinatore'], $f['segretario'], $f['luogo'], $f['odg'], $att_c, $ord_c); }
            $st->execute();
            if (!$cid) $cid = (int)$conn->insert_id;
            registra_log_audit($conn, "Consiglio salvato", ["Consiglio" => $f['nome']]);
            flash_set("Consiglio salvato.");
        }
        admin_redirect("$base&tab=sedute&consiglio=$cid&r=" . time());
    }
    if (isset($_POST['aggiungi_persona_consiglio'])) {
        $cid = (int)($_POST['consiglio_id'] ?? 0); $ruolo_p = ($_POST['ruolo_persona'] ?? '') === 'referente' ? 'referente' : 'componente';
        if (!$puo_consiglio($cid) || ($ruolo_p === 'referente' && $solo_ref)) nega_accesso();
        $err = aggiungi_persona_consiglio($conn, $cid, $ruolo_p, (string)($_POST['persona_id'] ?? ''), (string)($_POST['qualifica'] ?? ''), (string)($_POST['nominativo'] ?? ''), (string)($_POST['email_persona'] ?? ''));
        if (!$err) registra_log_audit($conn, "Consiglio: " . $ruolo_p . " aggiunto", ["Consiglio" => $cid, "Persona" => $_POST['persona_id'] ?: ($_POST['nominativo'] ?? '')]);
        flash_set($err ?? ($ruolo_p === 'referente' ? "Referente aggiunto: entra nel pannello Didattica con le credenziali Unical e vede le sedute del consiglio." : "Componente aggiunto: lo trovi nelle presenze delle sedute."), $err ? 'danger' : 'success');
        admin_redirect("$base&tab=sedute&consiglio=$cid&r=" . time());
    }
    if (isset($_POST['togli_persona_consiglio'])) {
        $pid_c = (int)$_POST['togli_persona_consiglio'];
        $x = db_riga($conn, "SELECT * FROM didattica_consigli_persone WHERE id = ?", [$pid_c]);
        if (!$x || !$puo_consiglio((int)$x['consiglio_id']) || ($x['ruolo'] === 'referente' && $solo_ref)) nega_accesso();
        db_esegui($conn, "DELETE FROM didattica_consigli_persone WHERE id = ?", [$pid_c]);
        registra_log_audit($conn, "Consiglio: persona tolta", ["Consiglio" => $x['consiglio_id'], "Persona" => $x['nominativo']]);
        flash_set($x['nominativo'] . " tolto dal consiglio (resta nelle sedute già registrate).", 'warning');
        admin_redirect("$base&tab=sedute&consiglio=" . (int)$x['consiglio_id'] . "&r=" . time());
    }
    if (isset($_POST['importa_componenti'])) {
        $cid = (int)($_POST['consiglio_id'] ?? 0); $da = (int)($_POST['da_consiglio'] ?? 0);
        if (!$puo_consiglio($cid)) nega_accesso();
        $n = importa_componenti_consiglio($conn, $da, $cid);
        registra_log_audit($conn, "Consiglio: componenti importati", ["Consiglio" => $cid, "Da" => $da, "Aggiunti" => $n]);
        flash_set($n ? "$n componenti importati: controlla gruppi e ordine." : "Nessun componente da importare (sono già tutti presenti).", $n ? 'success' : 'warning');
        admin_redirect("$base&tab=sedute&consiglio=$cid&r=" . time());
    }
    if (isset($_POST['salva_qualifiche'])) {
        $cid = (int)($_POST['consiglio_id'] ?? 0);
        if (!$puo_consiglio($cid)) nega_accesso();
        $st = $conn->prepare("UPDATE didattica_consigli_persone SET qualifica = ?, ordine = ? WHERE id = ? AND consiglio_id = ? AND ruolo = 'componente'");
        foreach ((array)($_POST['qualifica_p'] ?? []) as $pid_c => $q_c) {
            $q_c = mb_substr(trim((string)$q_c), 0, 150); $o_c = (int)($_POST['ordine_p'][$pid_c] ?? 0); $pid_c = (int)$pid_c;
            $st->bind_param("siii", $q_c, $o_c, $pid_c, $cid); $st->execute();
        }
        flash_set("Gruppi e ordine dei componenti salvati.");
        admin_redirect("$base&tab=sedute&consiglio=$cid&r=" . time());
    }

    // ── Seduta: presenze, esiti e decisioni sulle pratiche ──
    if (isset($_POST['salva_presenze'])) {
        $sed = seduta_didattica($conn, (int)($_POST['seduta_id'] ?? 0));
        if (!$puo_seduta($sed)) nega_accesso();
        $n = salva_presenze_seduta($conn, $sed, array_map('strval', (array)($_POST['presenza'] ?? [])));
        registra_log_audit($conn, "Seduta: presenze salvate", ["Seduta" => $sed['id'], "Componenti" => $n]);
        flash_set("Presenze salvate ($n componenti): sono nel verbale.");
        admin_redirect("$base&tab=sedute&id=" . (int)$sed['id'] . "&r=" . time() . "#presenze");
    }
    if (isset($_POST['salva_decisioni'])) {
        $id = (int)($_POST['pratica_id'] ?? 0); $p = pratica($conn, $id);
        $sed = $p && $p['seduta_id'] ? seduta_didattica($conn, (int)$p['seduta_id']) : null;
        if (!$puo_seduta($sed)) nega_accesso();
        $m = modulo_didattica($conn, (int)$p['modulo_id']); $tipo_d = decisione_modulo($m);
        $dec = $tipo_d !== '' ? leggi_decisioni_post($conn, $tipo_d, $_POST) : null;
        salva_decisioni_seduta($conn, $id, (string)($_POST['esito_seduta'] ?? ''), $dec, isset($_POST['delibera']) ? (string)$_POST['delibera'] : null);
        registra_log_audit($conn, "Seduta: decisioni sulla pratica", ["Pratica" => $p['codice'], "Esito" => $_POST['esito_seduta'] ?? '']);
        flash_set("Decisioni salvate per " . trim($p['cognome'] . ' ' . $p['nome']) . ".");
        admin_redirect("$base&tab=sedute&id=" . (int)$sed['id'] . "&r=" . time() . "#pr" . $id);
    }
    if (isset($_POST['applica_esiti'])) {
        $sed = seduta_didattica($conn, (int)($_POST['seduta_id'] ?? 0));
        if (!$puo_seduta($sed)) nega_accesso();
        [$na, $nr, $nn] = applica_esiti_seduta($conn, $sed, $uid, $autore_nome);
        registra_log_audit($conn, "Seduta: esiti applicati", ["Seduta" => $sed['id'], "Accolte" => $na, "Respinte" => $nr, "Rinviate" => $nn]);
        flash_set("Esiti applicati: $na accolte, $nr respinte (gli studenti ricevono l'email), $nn rinviate alla prossima seduta.");
        admin_redirect("$base&tab=sedute&id=" . (int)$sed['id'] . "&r=" . time());
    }
    // ── Convocazione per email (facoltativa, testo personalizzato) ──
    if (isset($_POST['invia_convocazione'])) {
        $sed = seduta_didattica($conn, (int)($_POST['seduta_id'] ?? 0));
        if (!$puo_seduta($sed)) nega_accesso();
        [$n, $senza, $err] = invia_convocazione($conn, $sed, (string)($_POST['conv_oggetto'] ?? ''), (string)($_POST['conv_testo'] ?? ''), !empty($_POST['conv_link']));
        if (!$err) registra_log_audit($conn, "Seduta: convocazione inviata", ["Seduta" => $sed['id'], "Email" => $n]);
        flash_set($err ?? "Convocazione inviata a $n componenti." . ($senza ? " $senza senza email: avvisali tu." : ''), $err ? 'danger' : 'success');
        admin_redirect("$base&tab=sedute&id=" . (int)$sed['id'] . "&r=" . time() . "#convocazione");
    }
    // ── Verbale firmato in PAdES: PDF alla firma del segretario e del coordinatore ──
    if (isset($_POST['invia_verbale_firma']) || isset($_POST['carica_verbale_firmato'])) {
        $sed = seduta_didattica($conn, (int)($_POST['seduta_id'] ?? 0));
        if (!$puo_seduta($sed)) nega_accesso();
        $f = $_FILES['verbale_pdf'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 30 * 1024 * 1024) $err = "Scegli il PDF (fino a 30 MB).";
        elseif (isset($_POST['invia_verbale_firma'])) $err = invia_verbale_alla_firma($conn, (int)$sed['id'], (string)file_get_contents($f['tmp_name']), (string)($_POST['segretario_email'] ?? ''), (string)($_POST['coordinatore_email'] ?? ''));
        else $err = registra_firma_verbale($conn, (int)$sed['id'], (string)file_get_contents($f['tmp_name']), 'caricata da ' . $autore_nome);
        if (!$err) registra_log_audit($conn, isset($_POST['invia_verbale_firma']) ? "Seduta: verbale inviato alla firma" : "Seduta: firma del verbale caricata", ["Seduta" => $sed['id']]);
        flash_set($err ?? (isset($_POST['invia_verbale_firma']) ? "Verbale inviato: il segretario riceve l'email per firmarlo, poi tocca al coordinatore." : "Firma registrata."), $err ? 'danger' : 'success');
        admin_redirect("$base&tab=sedute&id=" . (int)$sed['id'] . "&r=" . time() . "#verbale");
    }
    // ── Sedute ──
    if (isset($_POST['salva_seduta'])) {
        $id = (int)($_POST['seduta_id'] ?? 0);
        $f = [];
        foreach (['organo' => 500, 'anno_accademico' => 20, 'luogo' => 255, 'segretario' => 200, 'coordinatore' => 200, 'odg' => 5000, 'presenze' => 20000] as $k => $max) $f[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['data'] ?? '')) ? $_POST['data'] : null;
        $oi = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['ora_inizio'] ?? '')) ? $_POST['ora_inizio'] : '';
        $of = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['ora_fine'] ?? '')) ? $_POST['ora_fine'] : '';
        $cons_s = (int)($_POST['consiglio_id'] ?? 0) ?: null;
        if ($cons_s && !isset($consigli[$cons_s])) $cons_s = null;
        if ($id && !$puo_seduta(seduta_didattica($conn, $id)) && !$puo_didattica_tutto) nega_accesso();
        if ($solo_ref && !in_array((int)$cons_s, $consigli_referente, true)) nega_accesso();
        if ($f['organo'] === '' && $cons_s) $f['organo'] = $consigli[$cons_s]['nome'];
        if ($f['organo'] === '') { flash_set("Indica l'organo (es. Consiglio del Corso di Laurea in …).", 'danger'); admin_redirect("$base&tab=sedute&" . ($id ? "modifica=$id" : "nuova=1")); }
        if ($id) {
            $st = $conn->prepare("UPDATE didattica_sedute SET organo=?, anno_accademico=?, data=?, ora_inizio=?, ora_fine=?, luogo=?, odg=?, presenze=?, segretario=?, coordinatore=?, consiglio_id=? WHERE id=?");
            $st->bind_param("ssssssssssii", $f['organo'], $f['anno_accademico'], $data, $oi, $of, $f['luogo'], $f['odg'], $f['presenze'], $f['segretario'], $f['coordinatore'], $cons_s, $id);
        } else {
            $st = $conn->prepare("INSERT INTO didattica_sedute (organo, anno_accademico, data, ora_inizio, ora_fine, luogo, odg, presenze, segretario, coordinatore, consiglio_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->bind_param("ssssssssssi", $f['organo'], $f['anno_accademico'], $data, $oi, $of, $f['luogo'], $f['odg'], $f['presenze'], $f['segretario'], $f['coordinatore'], $cons_s);
        }
        $st->execute();
        if (!$id) $id = (int)$conn->insert_id;
        registra_log_audit($conn, "Seduta del Consiglio salvata", ["Organo" => $f['organo'], "Data" => $data]);
        flash_set($cons_s ? "Seduta salvata: segna le presenze, porta le pratiche e scarica il verbale." : "Seduta salvata: ora porta le pratiche e scarica il verbale.");
        admin_redirect("$base&tab=sedute&id=$id");
    }
    if (isset($_POST['elimina_seduta'])) {
        $id = (int)$_POST['elimina_seduta'];
        if (!$puo_seduta(seduta_didattica($conn, $id))) nega_accesso();
        db_esegui($conn, "DELETE FROM didattica_sedute_presenze WHERE seduta_id = ?", [$id]);
        db_esegui($conn, "DELETE FROM didattica_convocazioni WHERE seduta_id = ?", [$id]);
        db_esegui($conn, "UPDATE pratiche SET seduta_id = NULL WHERE seduta_id = ?", [$id]);
        db_esegui($conn, "DELETE FROM didattica_sedute WHERE id = ?", [$id]);
        flash_set("Seduta eliminata (le pratiche restano, senza seduta).", 'warning');
        admin_redirect("$base&tab=sedute");
    }

    return;
}
?>
<?php
    $sel = !empty($_GET['id']) ? seduta_didattica($conn, (int)$_GET['id']) : null;
    if ($sel && !$puo_seduta($sel)) $sel = null;
    $mod_s = !empty($_GET['modifica']) ? seduta_didattica($conn, (int)$_GET['modifica']) : null;
    if ($mod_s && !$puo_seduta($mod_s) && !$puo_didattica_tutto) $mod_s = null;
    $cons_sel = isset($_GET['consiglio']) ? ((int)$_GET['consiglio'] ? consiglio_didattica($conn, (int)$_GET['consiglio']) : ['id' => 0, 'nome' => '', 'corsi' => '[]', 'coordinatore' => '', 'segretario' => '', 'luogo' => '', 'odg' => '', 'attivo' => 1, 'ordine' => count($consigli) + 1]) : null;
    if ($cons_sel && ((int)$cons_sel['id'] ? !$puo_consiglio((int)$cons_sel['id']) : $solo_ref)) $cons_sel = null;
    $consigli_vis = array_filter($consigli, fn($c) => $puo_consiglio((int)$c['id']));
    if ($cons_sel):
        // ── Consiglio: dati (Ufficio didattico), referenti (Ufficio didattico), componenti (anche i referenti) ──
        $cid = (int)$cons_sel['id'];
        $referenti = $cid ? persone_consiglio($conn, $cid, 'referente') : [];
        $componenti = $cid ? persone_consiglio($conn, $cid) : [];
        $corsi_c = json_decode((string)$cons_sel['corsi'], true) ?: [];
        $qualifiche = array_values(array_unique(array_merge(QUALIFICHE_CONSIGLIO, array_filter(array_column($componenti, 'qualifica')))));
?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>&amp;tab=sedute" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Sedute e consigli</a></nav>
    <style>
    .dd-chip { display: inline-flex; align-items: center; gap: 4px; background: #f1f5f9; border-radius: 999px; padding: 2px 10px; font-size: .75rem; color: #334155; }
    .dd-gruppo th { background: #eff6ff !important; color: #0056B3; font-size: .7rem; text-transform: uppercase; letter-spacing: .03em; }
    </style>
    <?php if ($cid): $sed_c = array_values(array_filter($sedute, fn($x) => (int)$x['consiglio_id'] === $cid)); ?>
    <div class="card border-0 shadow-sm mb-3" style="border-left:4px solid #0056B3 !important;"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <div class="flex-grow-1" style="min-width:260px;">
                <h5 class="fw-bold mb-2"><i class="fa fa-landmark me-1 text-primary" aria-hidden="true"></i><?php echo $h($cons_sel['nome']); ?><?php echo (int)$cons_sel['attivo'] ? '' : ' <span class="badge bg-secondary">non attivo</span>'; ?></h5>
                <div class="d-flex flex-wrap gap-1">
                    <span class="dd-chip"><i class="fa fa-user-tie" aria-hidden="true"></i>Coordinatore: <?php echo $h($cons_sel['coordinatore'] ?: '—'); ?></span>
                    <span class="dd-chip"><i class="fa fa-pen" aria-hidden="true"></i>Segretario: <?php echo $h($cons_sel['segretario'] ?: '—'); ?></span>
                    <?php if ($cons_sel['luogo'] !== ''): ?><span class="dd-chip"><i class="fa fa-location-dot" aria-hidden="true"></i><?php echo $h($cons_sel['luogo']); ?></span><?php endif; ?>
                    <span class="dd-chip"><i class="fa fa-graduation-cap" aria-hidden="true"></i><?php echo count($corsi_c); ?> corsi di studio</span>
                    <span class="dd-chip"><i class="fa fa-calendar" aria-hidden="true"></i><?php echo count($sed_c); ?> sedute</span>
                </div>
            </div>
            <a class="btn btn-sm btn-primary fw-bold" href="<?php echo $base; ?>&amp;tab=sedute&amp;nuova=1&amp;consiglio_id=<?php echo $cid; ?>"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuova seduta</a>
        </div>
    </div></div>
    <?php endif; ?>
    <?php if (!$solo_ref): ?>
    <details class="card border-0 shadow-sm mb-3"<?php echo $cid ? '' : ' open'; ?>>
        <summary class="card-header bg-white fw-bold small" style="cursor:pointer;"><i class="fa fa-sliders me-1 text-primary" aria-hidden="true"></i><?php echo $cid ? 'Dati del consiglio (nome, coordinatore, segretario, corsi, ordine del giorno)' : 'Nuovo consiglio'; ?></summary>
        <form method="POST" class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="consiglio_id" value="<?php echo $cid; ?>">
        <div class="row g-2">
            <div class="col-md-10"><label class="form-label small fw-bold" for="cNome">Nome del consiglio (organo nel verbale) <span class="text-danger">*</span></label><input class="form-control" id="cNome" name="c_nome" value="<?php echo $h($cons_sel['nome']); ?>" required maxlength="500"></div>
            <div class="col-md-1"><label class="form-label small fw-bold" for="cOrd">Ordine</label><input type="number" class="form-control" id="cOrd" name="c_ordine" value="<?php echo (int)$cons_sel['ordine']; ?>"></div>
            <div class="col-md-1 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="c_attivo" value="1"<?php echo (int)$cons_sel['attivo'] ? ' checked' : ''; ?>> attivo</label></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="cCoo">Coordinatore</label><input class="form-control form-control-sm" id="cCoo" name="c_coordinatore" value="<?php echo $h($cons_sel['coordinatore']); ?>" placeholder="Prof. …" maxlength="200"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="cSeg">Segretario verbalizzante</label><input class="form-control form-control-sm" id="cSeg" name="c_segretario" value="<?php echo $h($cons_sel['segretario']); ?>" placeholder="la Dott.ssa …" maxlength="200"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="cLuo">Luogo abituale</label><input class="form-control form-control-sm" id="cLuo" name="c_luogo" value="<?php echo $h($cons_sel['luogo']); ?>" maxlength="255"></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="cCorsi">Corsi di studio del consiglio</label>
                <select class="form-select form-select-sm" id="cCorsi" name="c_corsi[]" multiple size="5"><?php foreach (scelte_anagrafe_didattica($conn, 'corso_studio') as $g => $cc): ?><optgroup label="<?php echo $h($g); ?>"><?php foreach ($cc as $c): ?><option<?php echo in_array($c, $corsi_c, true) ? ' selected' : ''; ?>><?php echo $h($c); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
                <div class="form-text">Con Ctrl se ne scelgono più d'uno: le pratiche di questi corsi vengono proposte per le sedute del consiglio.</div></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="cOdg">Ordine del giorno proposto</label><textarea class="form-control form-control-sm" id="cOdg" name="c_odg" rows="5" placeholder="Comunicazioni&#10;Pratiche studenti&#10;Varie ed eventuali"><?php echo $h($cons_sel['odg']); ?></textarea></div>
        </div>
        <button type="submit" name="salva_consiglio" value="1" class="btn btn-sm btn-primary fw-bold mt-2"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva il consiglio</button>
        </form>
    </details>
    <?php endif; ?>
    <?php if ($cid):
        $altri = array_filter($consigli_vis, fn($c) => (int)$c['id'] !== $cid && persone_consiglio($conn, (int)$c['id'])); ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                    <h6 class="fw-bold mb-0"><i class="fa fa-users me-1 text-primary" aria-hidden="true"></i>Componenti (<?php echo count($componenti); ?>)</h6>
                    <span class="small text-secondary ms-auto">In ogni seduta si segna solo presente / assente giustificato / ingiustificato.</span>
                </div>
                <?php if ($componenti): ?>
                <form method="POST"><?php csrf_field(); ?><input type="hidden" name="consiglio_id" value="<?php echo $cid; ?>">
                    <datalist id="dlQual"><?php foreach ($qualifiche as $q): ?><option value="<?php echo $h($q); ?>"><?php endforeach; ?></datalist>
                    <div class="table-responsive" style="max-height:560px;overflow:auto;"><table class="table table-sm table-hover align-middle small mb-2">
                        <thead class="table-light" style="position:sticky;top:0;z-index:1;"><tr><th>Componente</th><th style="width:36%;">Gruppo nel verbale</th><th style="width:72px;">Ordine</th><th style="width:36px;"></th></tr></thead><tbody>
                        <?php $g_corr = null; foreach ($componenti as $x): if ($x['qualifica'] !== $g_corr): $g_corr = $x['qualifica']; ?>
                            <tr class="dd-gruppo"><th colspan="4"><?php echo $h($g_corr ?: 'Senza gruppo'); ?></th></tr>
                        <?php endif; ?>
                            <tr><td><strong><?php echo $h($x['nominativo']); ?></strong><?php echo $x['persona_id'] ? ' <i class="fa fa-address-book text-secondary" title="dall\'anagrafe" aria-hidden="true"></i>' : ''; ?><div class="text-secondary"><?php echo $h($x['email'] ?: 'senza email: non riceve la convocazione'); ?></div></td>
                                <td><input class="form-control form-control-sm" name="qualifica_p[<?php echo (int)$x['id']; ?>]" value="<?php echo $h($x['qualifica']); ?>" list="dlQual" aria-label="Gruppo di <?php echo $h($x['nominativo']); ?>"></td>
                                <td><input type="number" class="form-control form-control-sm" name="ordine_p[<?php echo (int)$x['id']; ?>]" value="<?php echo (int)$x['ordine']; ?>" aria-label="Ordine"></td>
                                <td><button type="submit" name="togli_persona_consiglio" value="<?php echo (int)$x['id']; ?>" class="btn btn-sm btn-link text-danger p-0" data-confirm="Togliere <?php echo $h($x['nominativo']); ?> dai componenti?" aria-label="Togli"><i class="fa fa-user-minus" aria-hidden="true"></i></button></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <button type="submit" name="salva_qualifiche" value="1" class="btn btn-sm btn-outline-primary"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva gruppi e ordine</button>
                </form>
                <?php else: ?>
                    <div class="text-center text-secondary small py-4"><i class="fa fa-users fa-2x mb-2 d-block" aria-hidden="true"></i>Nessun componente: aggiungili dall'anagrafe, a mano o importali da un altro consiglio.</div>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <h6 class="fw-bold"><i class="fa fa-user-plus me-1 text-success" aria-hidden="true"></i>Aggiungi componenti</h6>
                <ul class="nav nav-tabs small mb-2" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active py-1 px-2" data-bs-toggle="tab" data-bs-target="#agAn" type="button" role="tab">Anagrafe</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link py-1 px-2" data-bs-toggle="tab" data-bs-target="#agMano" type="button" role="tab">A mano</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link py-1 px-2" data-bs-toggle="tab" data-bs-target="#agImp" type="button" role="tab">Da un altro consiglio</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="agAn" role="tabpanel">
                        <form method="POST" class="dd-scelta-persona"><?php csrf_field(); ?><input type="hidden" name="consiglio_id" value="<?php echo $cid; ?>"><input type="hidden" name="ruolo_persona" value="componente"><input type="hidden" name="persona_id" value="">
                            <input type="hidden" name="aggiungi_persona_consiglio" value="1">
                            <label class="form-label small fw-bold mb-0" for="qualAn">Gruppo (vuoto = dal ruolo dell'anagrafe)</label>
                            <input class="form-control form-control-sm mb-1" id="qualAn" name="qualifica" list="dlQual2" placeholder="es. Professori associati">
                            <datalist id="dlQual2"><?php foreach ($qualifiche as $q): ?><option value="<?php echo $h($q); ?>"><?php endforeach; ?></datalist>
                            <?php echo html_ricerca_personale($conn, 'Aggiungi'); ?>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="agMano" role="tabpanel">
                        <form method="POST"><?php csrf_field(); ?><input type="hidden" name="consiglio_id" value="<?php echo $cid; ?>"><input type="hidden" name="ruolo_persona" value="componente">
                            <div class="small text-secondary mb-1">Chi non è nell'anagrafe (es. rappresentanti degli studenti).</div>
                            <input class="form-control form-control-sm mb-1" name="nominativo" placeholder="Cognome e nome" maxlength="200" aria-label="Cognome e nome">
                            <input class="form-control form-control-sm mb-1" name="email_persona" type="email" placeholder="Email (per la convocazione)" aria-label="Email">
                            <input class="form-control form-control-sm mb-2" name="qualifica" list="dlQual2" value="Rappresentanti degli studenti" aria-label="Gruppo nel verbale">
                            <button type="submit" name="aggiungi_persona_consiglio" value="1" class="btn btn-sm btn-success fw-bold"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi</button>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="agImp" role="tabpanel">
                        <form method="POST"><?php csrf_field(); ?><input type="hidden" name="consiglio_id" value="<?php echo $cid; ?>">
                            <div class="small text-secondary mb-1">Copia i componenti di un altro consiglio con il loro gruppo: chi c'è già non si duplica.</div>
                            <?php if ($altri): ?>
                            <select class="form-select form-select-sm mb-2" name="da_consiglio" aria-label="Consiglio da cui importare">
                                <?php foreach ($altri as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo $h(mb_strimwidth($c['nome'], 0, 90, '…')); ?> (<?php echo count(persone_consiglio($conn, (int)$c['id'])); ?>)</option><?php endforeach; ?></select>
                            <button type="submit" name="importa_componenti" value="1" class="btn btn-sm btn-outline-primary fw-bold"><i class="fa fa-file-import me-1" aria-hidden="true"></i>Importa componenti</button>
                            <?php else: ?><p class="small text-muted mb-0">Nessun altro consiglio con componenti.</p><?php endif; ?>
                        </form>
                    </div>
                </div>
            </div></div>
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold"><i class="fa fa-user-shield me-1 text-success" aria-hidden="true"></i>Referenti (<?php echo count($referenti); ?>)</h6>
                <p class="small text-secondary mb-2">Entrano nel pannello con le credenziali Unical e gestiscono solo le sedute di questo consiglio.</p>
                <?php foreach ($referenti as $x): ?>
                    <form method="POST" class="d-flex align-items-center gap-2 small border-bottom py-1"><?php csrf_field(); ?>
                        <i class="fa fa-circle-user text-success" aria-hidden="true"></i>
                        <span class="flex-grow-1"><strong><?php echo $h($x['nominativo']); ?></strong><span class="d-block text-secondary"><?php echo $h($x['email']); ?></span></span>
                        <?php if (!$solo_ref): ?><button type="submit" name="togli_persona_consiglio" value="<?php echo (int)$x['id']; ?>" class="btn btn-sm btn-link text-danger p-0" data-confirm="Togliere <?php echo $h($x['nominativo']); ?> dai referenti?" aria-label="Togli"><i class="fa fa-user-minus" aria-hidden="true"></i></button><?php endif; ?>
                    </form>
                <?php endforeach; ?>
                <?php if (!$referenti): ?><p class="small text-muted">Nessun referente.</p><?php endif; ?>
                <?php if (!$solo_ref): ?>
                <form method="POST" class="mt-2 dd-scelta-persona"><?php csrf_field(); ?><input type="hidden" name="consiglio_id" value="<?php echo $cid; ?>"><input type="hidden" name="ruolo_persona" value="referente"><input type="hidden" name="persona_id" value="">
                    <input type="hidden" name="aggiungi_persona_consiglio" value="1">
                    <?php echo html_ricerca_personale($conn, 'Referente'); ?>
                </form>
                <?php endif; ?>
            </div></div>
        </div>
    </div>
    <script>
    // Persona scelta dall'anagrafe: si salva subito (referente o componente del consiglio)
    document.querySelectorAll('.dd-scelta-persona').forEach(function (f) {
        f.addEventListener('persona-scelta', function (e) { f.querySelector('[name=persona_id]').value = e.detail.id; f.submit(); });
        f.addEventListener('submit', function (e) { if (!f.querySelector('[name=persona_id]').value) e.preventDefault(); });
    });
    </script>
    <?php endif; ?>

<?php elseif ($mod_s || !empty($_GET['nuova'])):
        // Nuova seduta: dati ripresi dal consiglio scelto e dall'ultima seduta dello stesso consiglio
        $cons_new = (int)($_GET['consiglio_id'] ?? 0);
        if ($solo_ref && !in_array($cons_new, $consigli_referente, true)) $cons_new = (int)($consigli_referente[0] ?? 0);
        $ult = null;
        foreach ($sedute as $x) if (!$cons_new || (int)$x['consiglio_id'] === $cons_new) { $ult = $x; break; }
        $cc = $cons_new ? ($consigli[$cons_new] ?? null) : null;
        $f = $mod_s ?: ['id' => 0, 'consiglio_id' => $cons_new, 'organo' => $cc['nome'] ?? ($ult['organo'] ?? ''), 'anno_accademico' => anno_accademico_corrente() . '/' . (anno_accademico_corrente() + 1), 'data' => '', 'ora_inizio' => '', 'ora_fine' => '',
                        'luogo' => ($cc['luogo'] ?? '') ?: ($ult['luogo'] ?? ''), 'odg' => ($cc['odg'] ?? '') ?: ($ult['odg'] ?? "Comunicazioni\nPratiche studenti\nVarie ed eventuali"), 'presenze' => $cc ? '' : ($ult['presenze'] ?? ''),
                        'segretario' => ($cc['segretario'] ?? '') ?: ($ult['segretario'] ?? ''), 'coordinatore' => ($cc['coordinatore'] ?? '') ?: ($ult['coordinatore'] ?? '')];
        $organi = array_values(array_unique(array_filter(array_column($sedute, 'organo'))));
?>
    <form method="POST" class="card border-0 shadow-sm"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="seduta_id" value="<?php echo (int)$f['id']; ?>">
        <h5 class="fw-bold mb-1"><?php echo $f['id'] ? 'Modifica seduta' : 'Nuova seduta del Consiglio'; ?></h5>
        <?php if (!$f['id'] && ($cc || $ult)): ?><p class="small text-secondary">Ho ripreso organo, luogo, ordine del giorno, coordinatore e segretario <?php echo $cc ? 'dal consiglio' : "dall'ultima seduta"; ?>: aggiorna quello che cambia.</p><?php endif; ?>
        <div class="row g-2">
            <div class="col-md-12"><label class="form-label small fw-bold" for="sCons">Consiglio</label>
                <select class="form-select" id="sCons" name="consiglio_id" onchange="if(!<?php echo (int)$f['id']; ?>) location.href='<?php echo $base; ?>&tab=sedute&nuova=1&consiglio_id='+this.value;">
                    <?php if (!$solo_ref): ?><option value="0">— nessuno (presenze scritte a mano) —</option><?php endif; ?>
                    <?php foreach ($consigli_vis as $k => $c): if (!(int)$c['attivo'] && (int)$f['consiglio_id'] !== $k) continue; ?><option value="<?php echo $k; ?>"<?php echo (int)$f['consiglio_id'] === $k ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($c['nome'], 0, 150, '…')); ?></option><?php endforeach; ?></select>
                <div class="form-text">Con il consiglio le presenze si segnano per componente (presente / assente giustificato / ingiustificato) e finiscono nel verbale.</div></div>
            <div class="col-md-9"><label class="form-label small fw-bold" for="sOrg">Organo <span class="text-danger">*</span></label><input type="text" class="form-control" id="sOrg" name="organo" value="<?php echo $h($f['organo']); ?>" list="sOrgList" required maxlength="500" placeholder="Consiglio del Corso di Laurea in …">
                <datalist id="sOrgList"><?php foreach ($organi as $o): ?><option value="<?php echo $h($o); ?>"><?php endforeach; ?></datalist></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="sAa">Anno accademico</label><select class="form-select" id="sAa" name="anno_accademico"><?php foreach (anni_accademici_scelta() as $a): ?><option<?php echo $a === $f['anno_accademico'] ? ' selected' : ''; ?>><?php echo $a; ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="sData">Data</label><input type="date" class="form-control" id="sData" name="data" value="<?php echo $h($f['data']); ?>"></div>
            <div class="col-md-2"><label class="form-label small fw-bold" for="sOi">Ora di inizio</label><input type="time" class="form-control" id="sOi" name="ora_inizio" value="<?php echo $h($f['ora_inizio']); ?>"></div>
            <div class="col-md-2"><label class="form-label small fw-bold" for="sOf">Ora di fine</label><input type="time" class="form-control" id="sOf" name="ora_fine" value="<?php echo $h($f['ora_fine']); ?>"></div>
            <div class="col-md-5"><label class="form-label small fw-bold" for="sLuogo">Luogo</label><input type="text" class="form-control" id="sLuogo" name="luogo" value="<?php echo $h($f['luogo']); ?>" placeholder="l'aula L3 del cubo 4A" maxlength="255"></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="sOdg">Ordine del giorno</label><textarea class="form-control" id="sOdg" name="odg" rows="10"><?php echo $h($f['odg']); ?></textarea>
                <div class="form-text">Un punto per riga. Le pratiche vanno nel punto che contiene la parola «pratiche».</div></div>
            <div class="col-md-6"<?php echo (int)$f['consiglio_id'] ? ' hidden' : ''; ?>><label class="form-label small fw-bold" for="sPres">Presenze (senza consiglio)</label><textarea class="form-control font-monospace" id="sPres" name="presenze" rows="10" style="font-size:.8rem;" placeholder="Professori Ordinari e Associati&#10;Prof. Rossi Mario (Coordinatore) | PRESENTE&#10;Prof.ssa Bianchi Anna | GIUSTIFICATA"><?php echo $h($f['presenze']); ?></textarea>
                <div class="form-text">Una riga per persona: <code>Nome | PRESENTE</code> (o ASSENTE, GIUSTIFICATO). Le righe senza «|» diventano titoli di gruppo.</div></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="sSeg">Segretario verbalizzante</label><input type="text" class="form-control" id="sSeg" name="segretario" value="<?php echo $h($f['segretario']); ?>" placeholder="la Dott.ssa …" maxlength="200"></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="sCoo">Coordinatore</label><input type="text" class="form-control" id="sCoo" name="coordinatore" value="<?php echo $h($f['coordinatore']); ?>" placeholder="Prof. …" maxlength="200"></div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" name="salva_seduta" value="1" class="btn btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
            <a href="<?php echo $base; ?>&amp;tab=sedute<?php echo $f['id'] ? '&amp;id=' . (int)$f['id'] : ''; ?>" class="btn btn-outline-secondary">Annulla</a>
        </div>
    </div></form>
    <?php elseif ($sel):
        $pr_sel = pratiche_per_esportazione($conn, array_column(db_righe($conn, "SELECT id FROM pratiche WHERE seduta_id = ?", [(int)$sel['id']]), 'id'));
        $cons_s = $sel['consiglio_id'] ? ($consigli[(int)$sel['consiglio_id']] ?? null) : null;
        $corsi_cons = $cons_s ? (json_decode((string)$cons_s['corsi'], true) ?: []) : [];
        $libere = $conn->query("SELECT p.id, p.codice, p.cognome, p.nome, p.matricola, p.stato, p.risposte_json, m.titolo AS modulo_titolo FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id
                                WHERE p.seduta_id IS NULL AND p.stato NOT IN ('chiusa', 'respinta') ORDER BY m.titolo, p.cognome, p.nome")->fetch_all(MYSQLI_ASSOC);
        // Pratiche dei corsi del consiglio per prime (per i referenti solo quelle, se il consiglio ha i corsi)
        $corso_di = function ($x) { foreach (json_decode((string)$x['risposte_json'], true) ?: [] as $r) if (($r['tipo'] ?? '') === 'corso_studio' && $r['valore'] !== '') return $r['valore']; return ''; };
        if ($corsi_cons) {
            usort($libere, fn($a, $b) => (int)!in_array($corso_di($a), $corsi_cons, true) <=> (int)!in_array($corso_di($b), $corsi_cons, true));
            if ($solo_ref) $libere = array_values(array_filter($libere, fn($x) => in_array($corso_di($x), $corsi_cons, true)));
        }
        $url_sel = "$base&tab=sedute&id=" . (int)$sel['id'];
        $presenze = presenze_seduta($conn, $sel);
        $pres_reg = presenze_registrate($conn, $sel);
        $np = riepilogo_presenze($pres_reg);
        $conv = convocazioni_seduta($conn, (int)$sel['id']);
        $n_giust = count(array_filter($conv, fn($c) => !empty($c['giustificata_il'])));
        $da_applicare = count(array_filter($pr_sel, fn($x) => (in_array($x['esito_seduta'], ['approvata', 'approvata_mod'], true) && !in_array($x['stato'], ['accolta', 'chiusa'], true)) || ($x['esito_seduta'] === 'respinta' && $x['stato'] !== 'respinta') || $x['esito_seduta'] === 'rinviata'));
        $passi_s = [
            ['Convocazione', !empty($sel['convocazione_il']), 'convocazione', 'facoltativa'],
            ['Presenze', count(array_filter($presenze, fn($r) => !empty($r['_salvata']))) > $n_giust, 'presenze', ''],
            ['Pratiche e decisioni', $pr_sel && $con_esito === count($pr_sel), 'pratiche', ''],
            ['Esiti agli studenti', $pr_sel && $con_esito && !$da_applicare, 'pratiche', ''],
            ['Verbale firmato', ($sel['verbale_stato'] ?? '') === 'firmato', 'verbale', ''],
        ];
        $con_esito = count(array_filter($pr_sel, fn($x) => ($x['esito_seduta'] ?? '') !== ''));
        $ins_dip = insegnamenti_dipartimento_scelta($conn);
    ?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>&amp;tab=sedute" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le sedute</a></nav>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body d-flex flex-wrap gap-3 align-items-start">
        <div class="flex-grow-1" style="min-width:260px;">
            <h5 class="fw-bold mb-1"><?php echo $h($sel['organo']); ?></h5>
            <div class="small text-secondary"><?php echo $sel['data'] ? date('d/m/Y', strtotime($sel['data'])) : 'data da definire'; ?><?php echo $sel['ora_inizio'] ? ' · ore ' . $h($sel['ora_inizio']) : ''; ?><?php echo $sel['luogo'] ? ' · ' . $h($sel['luogo']) : ''; ?><?php echo $sel['anno_accademico'] ? ' · a.a. ' . $h($sel['anno_accademico']) : ''; ?></div>
            <?php if ($cons_s): ?><div class="small"><a href="<?php echo $base; ?>&amp;tab=sedute&amp;consiglio=<?php echo (int)$cons_s['id']; ?>"><i class="fa fa-users me-1" aria-hidden="true"></i>Componenti e referenti del consiglio</a></div><?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary fw-bold" href="<?php echo $h($url_sel); ?>&amp;esporta=docx"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Verbale in Word</a>
            <a class="btn btn-success fw-bold" href="<?php echo $h($url_sel); ?>&amp;esporta=xlsx"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Excel</a>
            <a class="btn btn-outline-secondary" href="<?php echo $base; ?>&amp;tab=sedute&amp;modifica=<?php echo (int)$sel['id']; ?>"><i class="fa fa-pen me-1" aria-hidden="true"></i>Modifica</a>
            <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="elimina_seduta" value="<?php echo (int)$sel['id']; ?>" class="btn btn-outline-danger" data-confirm="Eliminare la seduta? Le pratiche restano, senza seduta." aria-label="Elimina la seduta"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
        </div>
    </div></div>

    <style>
    .dd-passi { display: flex; flex-wrap: wrap; gap: 6px; counter-reset: passo; }
    .dd-passi a { display: flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: .8rem; font-weight: 600; text-decoration: none; }
    .dd-passi a.fatto { background: #dcfce7; color: #166534; }
    .dd-passi a::before { counter-increment: passo; content: counter(passo); display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 50%; background: #fff; font-size: .7rem; }
    </style>
    <nav class="dd-passi mb-3" aria-label="Passi della seduta">
        <?php foreach ($passi_s as [$n, $fatto, $anc, $nota]): ?><a href="#<?php echo $anc; ?>" class="<?php echo $fatto ? 'fatto' : ''; ?>"><?php echo $h($n); ?><?php echo $fatto ? ' <i class="fa fa-check" aria-hidden="true"></i>' : ($nota ? ' <span class="fw-normal">(' . $h($nota) . ')</span>' : ''); ?></a><?php endforeach; ?>
    </nav>

    <?php if ($cons_s):
        $con_email = count(array_filter(persone_consiglio($conn, (int)$cons_s['id']), fn($c) => filter_var($c['email'], FILTER_VALIDATE_EMAIL)));
        $conv_ok = $sel['data'] && $sel['data'] >= date('Y-m-d'); ?>
    <details class="card border-0 shadow-sm mb-3" id="convocazione">
        <summary class="card-header bg-white small d-flex flex-wrap gap-2 align-items-center" style="cursor:pointer;">
            <span class="fw-bold"><i class="fa fa-envelope me-1 text-primary" aria-hidden="true"></i>Convocazione per email <span class="fw-normal text-secondary">(facoltativa)</span></span>
            <span class="ms-auto text-secondary"><?php echo $sel['convocazione_il'] ? 'Inviata il ' . date('d/m/Y H:i', strtotime($sel['convocazione_il'])) . ' · ' . count($conv) . ' componenti · ' . $n_giust . ' assenze giustificate' : 'Non inviata'; ?></span>
        </summary>
        <form method="POST" class="card-body"><?php csrf_field(); ?><input type="hidden" name="seduta_id" value="<?php echo (int)$sel['id']; ?>">
            <?php if (!$conv_ok): ?><div class="alert alert-light border small py-2"><?php echo $sel['data'] ? 'La seduta è già passata.' : 'Indica prima la data della seduta (Modifica).'; ?></div><?php endif; ?>
            <div class="row g-2">
                <div class="col-lg-8">
                    <label class="form-label small fw-bold mb-0" for="cvOgg">Oggetto</label>
                    <input class="form-control form-control-sm mb-2" id="cvOgg" name="conv_oggetto" maxlength="255" value="<?php echo $h($sel['convocazione_oggetto'] ?: 'Convocazione {ORGANO} – {DATA} ore {ORA}'); ?>">
                    <label class="form-label small fw-bold mb-0" for="cvTxt">Testo dell'email (personalizzalo)</label>
                    <textarea class="form-control form-control-sm font-monospace" id="cvTxt" name="conv_testo" rows="12" maxlength="10000" style="font-size:.8rem;"><?php echo $h($sel['convocazione_testo'] ?: TESTO_CONVOCAZIONE); ?></textarea>
                </div>
                <div class="col-lg-4 small">
                    <div class="bg-light rounded p-2 mb-2"><div class="fw-bold mb-1">Segnaposti</div>
                        <?php foreach (['{NOME}' => 'nome del componente', '{ORGANO}' => 'nome del consiglio', '{DATA}' => 'data', '{ORA}' => 'ora di inizio', '{LUOGO}' => 'luogo', '{ODG}' => 'ordine del giorno numerato', '{COORDINATORE}' => 'coordinatore', '{LINK_GIUSTIFICA}' => 'link personale per giustificare'] as $k => $n): ?><div><code><?php echo $k; ?></code> <?php echo $h($n); ?></div><?php endforeach; ?>
                    </div>
                    <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="conv_link" value="1" checked> ogni componente riceve il <strong>link per giustificare l'assenza</strong>: chi lo usa risulta «assente giustificato» nelle presenze</label>
                    <p class="text-secondary mb-2"><?php echo $con_email; ?> componenti con email<?php echo count(persone_consiglio($conn, (int)$cons_s['id'])) - $con_email > 0 ? ', ' . (count(persone_consiglio($conn, (int)$cons_s['id'])) - $con_email) . ' senza (vanno avvisati a parte)' : ''; ?>.</p>
                    <button type="submit" name="invia_convocazione" value="1" class="btn btn-sm btn-primary fw-bold"<?php echo $conv_ok && $con_email ? '' : ' disabled'; ?> data-confirm="Inviare la convocazione a <?php echo $con_email; ?> componenti?"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i><?php echo $sel['convocazione_il'] ? 'Invia di nuovo' : 'Invia la convocazione'; ?></button>
                </div>
            </div>
        </form>
    </details>
    <?php endif; ?>

    <?php if ($presenze): ?>
    <form method="POST" class="card border-0 shadow-sm mb-3" id="presenze"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="seduta_id" value="<?php echo (int)$sel['id']; ?>">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="fa fa-clipboard-user me-1 text-primary" aria-hidden="true"></i>Presenze</h6>
            <span class="small text-secondary"><?php echo $pres_reg ? "Presenti {$np['P']} · assenti giustificati {$np['AG']} · ingiustificati {$np['AI']}" . ($n_giust ? " · $n_giust giustificati dal link della convocazione" : '') : 'Non ancora registrate: tutti proposti presenti.'; ?></span>
            <span class="ms-auto d-flex gap-1">
                <?php foreach (STATI_PRESENZA as $k => $n): ?><button type="button" class="btn btn-sm btn-outline-secondary py-0 dd-tutti" data-stato="<?php echo $k; ?>">Tutti: <?php echo $h(mb_strtolower($n)); ?></button><?php endforeach; ?>
            </span>
        </div>
        <div class="table-responsive" style="max-height:520px;overflow:auto;"><table class="table table-sm align-middle small mb-2">
            <thead class="table-light" style="position:sticky;top:0;z-index:1;"><tr><th>Componente</th><?php foreach (STATI_PRESENZA as $k => $n): ?><th class="text-center" style="width:120px;"><?php echo $h($n); ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php $g_corr = null; foreach ($presenze as $cid_p => $r): if ($r['qualifica'] !== $g_corr): $g_corr = $r['qualifica']; ?><tr class="table-light"><th colspan="4" class="text-uppercase text-secondary" style="font-size:.7rem;"><?php echo $h($g_corr ?: 'Senza gruppo'); ?></th></tr><?php endif; $cv = $conv[(int)$cid_p] ?? null; ?>
                <tr><td><?php echo $h($r['nominativo']); ?><?php if ($cv && $cv['giustificata_il']): ?> <span class="badge text-bg-warning" title="<?php echo $h($cv['motivo']); ?>"><i class="fa fa-user-clock me-1" aria-hidden="true"></i>giustificato il <?php echo date('d/m', strtotime($cv['giustificata_il'])); ?></span><?php if ($cv['motivo'] !== ''): ?><div class="text-secondary fst-italic"><?php echo $h($cv['motivo']); ?></div><?php endif; ?><?php elseif ($cv): ?> <i class="fa fa-envelope-circle-check text-secondary" title="convocato per email" aria-hidden="true"></i><?php endif; ?></td>
                    <?php foreach (STATI_PRESENZA as $k => $n): ?><td class="text-center"><input class="form-check-input dd-pres" type="radio" name="presenza[<?php echo (int)$cid_p; ?>]" value="<?php echo $k; ?>"<?php echo $r['stato'] === $k ? ' checked' : ''; ?> aria-label="<?php echo $h($r['nominativo'] . ': ' . $n); ?>"></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <button type="submit" name="salva_presenze" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva le presenze</button>
    </div></form>
    <?php elseif ($cons_s): ?>
        <div class="alert alert-light border small">Il consiglio non ha ancora componenti: <a href="<?php echo $base; ?>&amp;tab=sedute&amp;consiglio=<?php echo (int)$cons_s['id']; ?>">inseriscili una volta sola</a> e qui segnerai le presenze.</div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm" id="pratiche"><div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <h6 class="fw-bold mb-0"><i class="fa fa-folder-open me-1 text-primary" aria-hidden="true"></i>Pratiche in seduta (<?php echo count($pr_sel); ?>)</h6>
                    <?php if ($con_esito): ?><form method="POST" class="ms-auto m-0"><?php csrf_field(); ?><input type="hidden" name="seduta_id" value="<?php echo (int)$sel['id']; ?>">
                        <button type="submit" name="applica_esiti" value="1" class="btn btn-sm btn-success fw-bold" data-confirm="Applicare gli esiti? Le approvate diventano accolte e le respinte respinte: gli studenti ricevono l'email e trovano nella pratica l'estratto del verbale in PDF; le rinviate tornano senza seduta."><i class="fa fa-check-double me-1" aria-hidden="true"></i>Applica gli esiti alle pratiche (<?php echo $con_esito; ?>)</button></form><?php endif; ?>
                </div>
                <?php if (!$pr_sel): ?><p class="small text-muted mb-0">Nessuna: aggiungile dall'elenco a destra<?php echo $solo_ref ? '' : ' o dalla scheda Pratiche'; ?>.</p><?php endif; ?>
                <?php $mod_corr = null; foreach ($pr_sel as $x):
                    if ($mod_corr !== $x['modulo_titolo']): $mod_corr = $x['modulo_titolo']; ?><div class="small fw-bold text-secondary text-uppercase mt-3" style="font-size:.7rem;"><?php echo $h($mod_corr); ?></div><?php endif;
                    $m_x = ['verbale_json' => $x['verbale_json']]; $tipo_d = decisione_modulo($m_x); $campi_x = campi_modulo($x['campi_json']);
                    $dec = $tipo_d !== '' ? decisioni_pratica($x, $tipo_d, $campi_x) : null;
                    $es = ESITI_SEDUTA[$x['esito_seduta'] ?? ''] ?? null; ?>
                    <details class="border rounded mb-2" id="pr<?php echo (int)$x['id']; ?>"<?php echo (int)($_GET['apri'] ?? 0) === (int)$x['id'] ? ' open' : ''; ?>>
                        <summary class="d-flex flex-wrap gap-2 align-items-center small p-2" style="cursor:pointer;">
                            <strong><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?></strong><span class="text-secondary"><?php echo $h($x['matricola']); ?></span>
                            <span class="ms-auto"><?php echo $es ? '<span class="badge" style="background:' . $es[1] . ';">' . $h($es[0]) . '</span>' : '<span class="badge bg-light text-secondary border">esito da decidere</span>'; ?> <?php echo badge_stato_pratica($x['stato']); ?></span>
                        </summary>
                        <div class="p-2 border-top">
                            <dl class="row small mb-2">
                                <?php foreach (json_decode((string)$x['risposte_json'], true) ?: [] as $i => $r): if (!empty($r['nascosto'])) continue; ?>
                                    <dt class="col-sm-4"><?php echo $h($r['etichetta']); ?></dt>
                                    <dd class="col-sm-8"><?php if (!empty($r['file'])): ?><a href="../allegato_pratica.php?p=<?php echo (int)$x['id']; ?>&amp;r=<?php echo $i; ?>"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><?php echo $h($r['nome_file']); ?></a><?php else: echo html_risposta_pratica($r); endif; ?></dd>
                                <?php endforeach; ?>
                            </dl>
                            <?php if (!$solo_ref): ?><p class="small mb-2"><a href="<?php echo $base; ?>&amp;tab=pratiche&amp;id=<?php echo (int)$x['id']; ?>">Apri la pratica completa</a></p><?php endif; ?>
                            <form method="POST" class="bg-light rounded p-2 dd-dec" data-tipo="<?php echo $h($tipo_d); ?>"><?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$x['id']; ?>">
                                <div class="d-flex flex-wrap gap-3 small mb-2"><span class="fw-bold">Esito in seduta:</span>
                                    <?php foreach (ESITI_SEDUTA as $k => [$n]): ?><label class="form-check m-0"><input class="form-check-input" type="radio" name="esito_seduta" value="<?php echo $k; ?>"<?php echo ($x['esito_seduta'] ?? '') === $k ? ' checked' : ''; ?>> <?php echo $h($n); ?></label><?php endforeach; ?>
                                </div>
                                <?php if ($tipo_d !== ''): ?>
                                    <div class="small fw-bold mb-1"><?php echo $tipo_d === 'piano' ? 'Insegnamenti richiesti: in piano o fuori piano' : 'Convalide: insegnamento indicato dallo studente → insegnamento del Dipartimento'; ?><?php echo !empty($dec['_proposte']) && $dec['righe'] ? ' <span class="badge bg-warning text-dark">proposte dalla domanda, da confermare</span>' : ''; ?></div>
                                    <div class="table-responsive"><table class="table table-sm table-bordered align-middle small mb-1 bg-white" style="min-width:<?php echo $tipo_d === 'piano' ? 560 : 980; ?>px;">
                                        <thead class="table-light"><tr>
                                            <?php if ($tipo_d === 'piano'): ?><th>Insegnamento richiesto</th><th style="width:80px;">CFU</th><th style="width:200px;">Decisione</th>
                                            <?php else: ?><th>Sostenuto dallo studente</th><th style="width:64px;">CFU</th><th style="width:70px;">Voto</th><th style="min-width:220px;">Convalidato con (anagrafe)</th><th style="width:150px;">Convalida</th><th style="width:70px;">CFU ric.</th><th style="width:70px;">Da integrare</th><?php endif; ?>
                                            <th style="width:28px;"></th></tr></thead>
                                        <tbody>
                                        <?php foreach (array_merge($dec['righe'], [[]]) as $r): ?>
                                            <tr><td><input class="form-control form-control-sm" name="d_richiesto[]" value="<?php echo $h($r['richiesto'] ?? ''); ?>" aria-label="Insegnamento richiesto">
                                                    <input type="hidden" name="d_ssd[]" value="<?php echo $h($r['ssd'] ?? ''); ?>"><input type="hidden" name="d_data[]" value="<?php echo $h($r['data'] ?? ''); ?>"></td>
                                                <td><input class="form-control form-control-sm d-cfu" name="d_cfu[]" value="<?php echo $h($r['cfu'] ?? ''); ?>" aria-label="CFU"></td>
                                                <?php if ($tipo_d === 'piano'): ?>
                                                    <td><select class="form-select form-select-sm" name="d_esito[]" aria-label="Decisione"><?php foreach (ESITI_PIANO as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo ($r['esito'] ?? 'in_piano') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></td>
                                                <?php else: ?>
                                                    <td><input class="form-control form-control-sm" name="d_voto[]" value="<?php echo $h($r['voto'] ?? ''); ?>" aria-label="Voto"></td>
                                                    <td><input class="form-control form-control-sm d-ins" name="d_ins[]" value="<?php echo $h(!empty($r['ins_id']) && isset($ins_dip[(int)$r['ins_id']]) ? etichetta_insegnamento_scelta($ins_dip[(int)$r['ins_id']]) : ($r['ins'] ?? '')); ?>" list="dlInsDip" placeholder="Scrivi e scegli" aria-label="Insegnamento convalidato">
                                                        <input type="hidden" class="d-ins-cfu" name="d_ins_cfu[]" value="<?php echo $h($r['ins_cfu'] ?? ''); ?>"></td>
                                                    <td><select class="form-select form-select-sm d-esito" name="d_esito[]" aria-label="Convalida"><?php foreach (ESITI_CONVALIDA as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo ($r['esito'] ?? 'totale') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></td>
                                                    <td><input class="form-control form-control-sm d-ric" name="d_cfu_ric[]" value="<?php echo $h($r['cfu_ric'] ?? ''); ?>" aria-label="CFU riconosciuti"></td>
                                                    <td><input class="form-control form-control-sm d-int" name="d_cfu_int[]" value="<?php echo $h($r['cfu_int'] ?? ''); ?>" aria-label="CFU da integrare"></td>
                                                <?php endif; ?>
                                                <td><button type="button" class="btn btn-sm btn-link text-danger p-0 d-togli" aria-label="Togli la riga"><i class="fa fa-times" aria-hidden="true"></i></button></td></tr>
                                        <?php endforeach; ?>
                                        </tbody></table></div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 mb-2 d-aggiungi"><i class="fa fa-plus me-1" aria-hidden="true"></i>Riga</button>
                                    <?php if ($tipo_d === 'convalide'): ?><div class="form-text mb-2">Convalida totale: CFU riconosciuti = CFU dell'insegnamento del Dipartimento. Parziale: indica i CFU riconosciuti, quelli da integrare si calcolano. Lasciando vuoti i CFU li completa il portale.</div><?php endif; ?>
                                <?php endif; ?>
                                <label class="form-label small fw-bold mb-0" for="del<?php echo (int)$x['id']; ?>">Delibera nel verbale</label>
                                <textarea class="form-control form-control-sm mb-2" id="del<?php echo (int)$x['id']; ?>" name="delibera" rows="2" maxlength="5000" placeholder="<?php echo $h(verbale_modulo($m_x + ['titolo' => $x['modulo_titolo']])['delibera'] ?: 'Vuoto = testo predefinito del modulo'); ?>"><?php echo $h($x['delibera']); ?></textarea>
                                <button type="submit" name="salva_decisioni" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
                <?php if ($pr_sel): ?>
                <form method="POST" class="mt-2"><?php csrf_field(); ?><input type="hidden" name="torna" value="<?php echo $h($url_sel); ?>"><input type="hidden" name="seduta_id" value="0">
                    <details class="small"><summary>Togli pratiche dalla seduta</summary>
                        <?php foreach ($pr_sel as $x): ?><label class="d-block"><input type="checkbox" class="form-check-input" name="ids[]" value="<?php echo (int)$x['id']; ?>"> <?php echo $h(trim($x['cognome'] . ' ' . $x['nome']) . ' – ' . $x['modulo_titolo']); ?></label><?php endforeach; ?>
                        <button type="submit" name="assegna_seduta" value="1" class="btn btn-sm btn-outline-danger mt-1">Togli le selezionate</button></details>
                </form>
                <?php endif; ?>
            </div></div>
            <datalist id="dlInsDip"><?php foreach ($ins_dip as $i): ?><option value="<?php echo $h(etichetta_insegnamento_scelta($i)); ?>"><?php endforeach; ?></datalist>
            <script>
            (function () {
                var cfuIns = <?php $mappa = []; foreach ($ins_dip as $i) $mappa[etichetta_insegnamento_scelta($i)] = $i['cfu']; echo json_encode($mappa, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                function n(v) { v = String(v || '').replace(',', '.'); return v === '' || isNaN(v) ? null : parseFloat(v); }
                function ricalcola(tr) {
                    var es = tr.querySelector('.d-esito'); if (!es) return;
                    var ins = n(tr.querySelector('.d-ins-cfu').value), cfu = n(tr.querySelector('.d-cfu').value), ric = tr.querySelector('.d-ric'), int = tr.querySelector('.d-int');
                    if (es.value === 'totale') { ric.value = ins !== null ? ins : (cfu !== null ? cfu : ''); int.value = '0'; }
                    else if (es.value === 'no') { ric.value = '0'; int.value = ''; }
                    else if (ins !== null && n(ric.value) !== null) int.value = Math.max(0, ins - n(ric.value));
                }
                document.querySelectorAll('.dd-dec').forEach(function (f) {
                    f.addEventListener('change', function (e) {
                        var tr = e.target.closest('tr'); if (!tr) return;
                        if (e.target.classList.contains('d-ins')) { var c = cfuIns[e.target.value]; tr.querySelector('.d-ins-cfu').value = c === undefined || c === null ? '' : c; }
                        if (e.target.classList.contains('d-ins') || e.target.classList.contains('d-esito') || e.target.classList.contains('d-ric')) ricalcola(tr);
                    });
                    f.addEventListener('click', function (e) {
                        var b = e.target.closest('.d-aggiungi');
                        if (b) { var tb = f.querySelector('tbody'), r = tb.lastElementChild.cloneNode(true); r.querySelectorAll('input').forEach(function (i) { i.value = ''; }); r.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; }); tb.appendChild(r); return; }
                        var t = e.target.closest('.d-togli');
                        if (t) { var tr = t.closest('tr'); if (tr.parentNode.children.length > 1) tr.remove(); else tr.querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
                    });
                });
                document.querySelectorAll('.dd-tutti').forEach(function (b) { b.addEventListener('click', function () { document.querySelectorAll('.dd-pres[value="' + b.dataset.stato + '"]').forEach(function (r) { r.checked = true; }); }); });
            })();
            </script>
        </div>
        <div class="col-lg-4">
            <form method="POST" class="card border-0 shadow-sm"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="torna" value="<?php echo $h($url_sel); ?>"><input type="hidden" name="seduta_id" value="<?php echo (int)$sel['id']; ?>">
                <h6 class="fw-bold">Pratiche ancora senza seduta (<?php echo count($libere); ?>)</h6>
                <?php if ($corsi_cons): ?><p class="small text-secondary"><?php echo $solo_ref ? 'Le pratiche dei corsi del consiglio.' : 'Prima quelle dei corsi del consiglio.'; ?></p><?php endif; ?>
                <div style="max-height:520px;overflow:auto;">
                <?php foreach ($libere as $x): $del_cons = $corsi_cons && in_array($corso_di($x), $corsi_cons, true); ?>
                    <label class="d-flex gap-2 align-items-center small py-1 border-bottom"><input type="checkbox" class="form-check-input" name="ids[]" value="<?php echo (int)$x['id']; ?>">
                        <span><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?><span class="d-block text-secondary"><?php echo $h($x['modulo_titolo']); ?><?php echo $del_cons ? ' · <i class="fa fa-star text-warning" aria-hidden="true"></i> corso del consiglio' : ''; ?></span></span><span class="ms-auto"><?php echo badge_stato_pratica($x['stato']); ?></span></label>
                <?php endforeach; ?>
                <?php if (!$libere): ?><p class="small text-muted mb-0">Nessuna pratica da portare in seduta.</p><?php endif; ?>
                </div>
                <?php if ($libere): ?><button type="submit" name="assegna_seduta" value="1" class="btn btn-sm btn-dark fw-bold mt-2"><i class="fa fa-plus me-1" aria-hidden="true"></i>Porta in seduta</button><?php endif; ?>
            </div></form>
        </div>
    </div>
    <?php $vs = (string)($sel['verbale_stato'] ?? ''); $sv = STATI_VERBALE[$vs] ?? STATI_VERBALE['']; ?>
    <div class="card border-0 shadow-sm mt-3" id="verbale" style="border-left:4px solid <?php echo $sv[1]; ?> !important;"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="fa fa-file-signature me-1 text-primary" aria-hidden="true"></i>Verbale firmato in PAdES</h6>
            <span class="badge" style="background:<?php echo $sv[1]; ?>;"><?php echo $h($sv[0]); ?></span>
            <?php if ($sel['verbale_pdf']): ?><a class="btn btn-sm btn-outline-primary ms-auto" href="<?php echo $h($url_sel); ?>&amp;esporta=verbale_pdf"><i class="fa fa-download me-1" aria-hidden="true"></i><?php echo $vs === 'firmato' ? 'PDF firmato' : 'PDF attuale'; ?></a><?php endif; ?>
        </div>
        <?php if ($vs === '' ): ?>
            <p class="small text-secondary">Scarica il <strong>Verbale in Word</strong>, completalo, salvalo in PDF e caricalo qui: firma prima il segretario verbalizzante e poi il coordinatore (link personale per email; firma remota Aruba se configurata, altrimenti scaricano il PDF, lo firmano in PAdES e lo ricaricano).</p>
            <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end"><?php csrf_field(); ?><input type="hidden" name="seduta_id" value="<?php echo (int)$sel['id']; ?>">
                <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="vPdf">Verbale in PDF</label><input type="file" class="form-control form-control-sm" id="vPdf" name="verbale_pdf" accept=".pdf,application/pdf" required></div>
                <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="vSeg">Email del segretario<?php echo $sel['segretario'] ? ' (' . $h($sel['segretario']) . ')' : ''; ?></label><input type="email" class="form-control form-control-sm" id="vSeg" name="segretario_email" value="<?php echo $h($sel['segretario_email']); ?>" required></div>
                <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="vCoo">Email del coordinatore<?php echo $sel['coordinatore'] ? ' (' . $h($sel['coordinatore']) . ')' : ''; ?></label><input type="email" class="form-control form-control-sm" id="vCoo" name="coordinatore_email" value="<?php echo $h($sel['coordinatore_email']); ?>" required></div>
                <div class="col-md-2"><button type="submit" name="invia_verbale_firma" value="1" class="btn btn-sm btn-primary fw-bold w-100"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia alla firma</button></div>
            </form>
        <?php elseif ($vs !== 'firmato'): $chi_v = $vs === 'segretario' ? 'del segretario (' . $sel['segretario_email'] . ')' : 'del coordinatore (' . $sel['coordinatore_email'] . ')'; ?>
            <p class="small mb-2">In attesa della firma <?php echo $h($chi_v); ?>: ha ricevuto l'email con il link<?php echo $sel['verbale_solleciti'] ? ' · ' . (int)$sel['verbale_solleciti'] . ' solleciti' : ''; ?>. Se ti manda il PDF firmato in PAdES, caricalo qui.</p>
            <form method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2"><?php csrf_field(); ?><input type="hidden" name="seduta_id" value="<?php echo (int)$sel['id']; ?>">
                <input type="file" class="form-control form-control-sm" style="max-width:360px;" name="verbale_pdf" accept=".pdf,application/pdf" required aria-label="Verbale firmato">
                <button type="submit" name="carica_verbale_firmato" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-upload me-1" aria-hidden="true"></i>Carica la firma <?php echo $vs === 'segretario' ? 'del segretario' : 'del coordinatore'; ?></button>
            </form>
        <?php else: ?>
            <p class="small mb-0 text-success"><i class="fa fa-check me-1" aria-hidden="true"></i>Firmato dal segretario e dal coordinatore il <?php echo date('d/m/Y H:i', strtotime($sel['verbale_firmato_il'])); ?>: il PDF è stato inviato ai referenti.</p>
        <?php endif; ?>
    </div></div>
    <?php else:
        // ── Elenco: consigli (schede) e sedute (prossime e passate) con lo stato di convocazione, presenze e verbale ──
        $oggi = date('Y-m-d');
        $n_pres = [];
        foreach (db_righe($conn, "SELECT seduta_id, COUNT(*) n FROM didattica_sedute_presenze GROUP BY seduta_id") as $x) $n_pres[(int)$x['seduta_id']] = (int)$x['n'];
        $f_cons = (int)($_GET['cons'] ?? 0);
        $sedute_vis = array_values(array_filter($sedute, fn($x) => !$f_cons || (int)$x['consiglio_id'] === $f_cons));
        $prossime = array_reverse(array_values(array_filter($sedute_vis, fn($x) => !$x['data'] || $x['data'] >= $oggi)));
        $passate = array_values(array_filter($sedute_vis, fn($x) => $x['data'] && $x['data'] < $oggi));
        $riga_seduta = function (array $x) use ($h, $base, $n_pres, $consigli) {
            $ts = $x['data'] ? strtotime($x['data']) : null;
            $gg = ['dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab']; $mm = [1 => 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
            $badge = [];
            if (!empty($x['convocazione_il'])) $badge[] = '<span class="badge rounded-pill text-bg-light border"><i class="fa fa-envelope me-1" aria-hidden="true"></i>convocata</span>';
            if (!empty($n_pres[(int)$x['id']])) $badge[] = '<span class="badge rounded-pill text-bg-light border"><i class="fa fa-clipboard-user me-1" aria-hidden="true"></i>presenze</span>';
            $vs = STATI_VERBALE[$x['verbale_stato'] ?? ''] ?? null;
            if (($x['verbale_stato'] ?? '') !== '' && $vs) $badge[] = '<span class="badge rounded-pill" style="background:' . $vs[1] . ';"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>' . $h($x['verbale_stato'] === 'firmato' ? 'verbale firmato' : 'verbale in firma') . '</span>';
            $cons = $x['consiglio_id'] ? ($consigli[(int)$x['consiglio_id']]['nome'] ?? '') : '';
            return '<a class="list-group-item list-group-item-action d-flex gap-3 align-items-center py-2" href="' . $base . '&amp;tab=sedute&amp;id=' . (int)$x['id'] . '">'
                . '<span class="dd-data text-center flex-shrink-0">' . ($ts ? '<span class="d-block small text-uppercase">' . $gg[(int)date('w', $ts)] . '</span><strong class="d-block fs-5 lh-1">' . date('d', $ts) . '</strong><span class="d-block small">' . date('m/Y', $ts) . '</span>' : '<strong>—</strong>') . '</span>'
                . '<span class="flex-grow-1 small" style="min-width:0;"><span class="fw-bold text-dark dd-due-righe">' . $h($x['organo']) . '</span>'
                . '<span class="text-secondary">' . ($x['ora_inizio'] ? 'ore ' . $h($x['ora_inizio']) . ' · ' : '') . ($x['luogo'] ? $h($x['luogo']) . ' · ' : '') . (int)$x['n_pratiche'] . ' pratiche</span> ' . implode(' ', $badge) . '</span>'
                . '<i class="fa fa-chevron-right text-secondary" aria-hidden="true"></i></a>';
        };
    ?>
    <style>
    .dd-cons { border-left: 4px solid #0056B3 !important; transition: box-shadow .15s; }
    .dd-cons:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.08) !important; }
    .dd-cons.dd-off { border-left-color: #94a3b8 !important; opacity: .8; }
    .dd-due-righe { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .dd-data { width: 60px; line-height: 1.2; background: #eff6ff; color: #0056B3; border-radius: .5rem; padding: 4px 0; }
    .dd-chip { display: inline-flex; align-items: center; gap: 4px; background: #f1f5f9; border-radius: 999px; padding: 2px 10px; font-size: .75rem; color: #334155; }
    </style>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <h5 class="fw-bold mb-0"><i class="fa fa-landmark me-1 text-primary" aria-hidden="true"></i>Consigli dei corsi di studio</h5>
        <?php if (!$solo_ref): ?><a href="<?php echo $base; ?>&amp;tab=sedute&amp;consiglio=0" class="btn btn-sm btn-outline-primary ms-auto"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuovo consiglio</a><?php endif; ?>
    </div>
    <p class="small text-secondary mb-2"><?php echo $solo_ref ? 'Sei referente di questi consigli: inserisci i componenti una volta sola, crea le sedute, segna le presenze e decidi sulle pratiche.' : "L'Ufficio didattico sceglie i referenti di ogni consiglio; i referenti inseriscono i componenti e gestiscono le sedute."; ?></p>
    <div class="row g-3 mb-4">
    <?php foreach ($consigli_vis as $k => $c):
        $nr = count(persone_consiglio($conn, $k, 'referente')); $nc = count(persone_consiglio($conn, $k));
        $sed_c = array_filter($sedute, fn($x) => (int)$x['consiglio_id'] === $k);
        $pross = null; foreach ($sed_c as $x) if ($x['data'] && $x['data'] >= $oggi) $pross = $x; ?>
        <div class="col-md-6 col-xl-4">
            <div class="card border-0 shadow-sm h-100 dd-cons<?php echo (int)$c['attivo'] ? '' : ' dd-off'; ?>"><div class="card-body d-flex flex-column">
                <div class="small fw-bold dd-due-righe mb-2" title="<?php echo $h($c['nome']); ?>"><?php echo $h($c['nome']); ?></div>
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <span class="dd-chip"><i class="fa fa-user-shield" aria-hidden="true"></i><?php echo $nr; ?> referenti</span>
                    <span class="dd-chip<?php echo $nc ? '' : ' text-danger'; ?>"><i class="fa fa-users" aria-hidden="true"></i><?php echo $nc; ?> componenti</span>
                    <span class="dd-chip"><i class="fa fa-calendar" aria-hidden="true"></i><?php echo count($sed_c); ?> sedute</span>
                    <?php if (!(int)$c['attivo']): ?><span class="badge bg-secondary">non attivo</span><?php endif; ?>
                </div>
                <div class="small text-secondary mb-3"><?php if ($pross): ?><i class="fa fa-clock me-1" aria-hidden="true"></i>Prossima: <a href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$pross['id']; ?>"><?php echo date('d/m/Y', strtotime($pross['data'])); ?></a><?php else: ?>Nessuna seduta in programma<?php endif; ?></div>
                <div class="d-flex gap-2 mt-auto">
                    <a class="btn btn-sm btn-outline-secondary flex-fill" href="<?php echo $base; ?>&amp;tab=sedute&amp;consiglio=<?php echo $k; ?>"><i class="fa fa-users me-1" aria-hidden="true"></i><?php echo $solo_ref ? 'Componenti' : 'Gestisci'; ?></a>
                    <a class="btn btn-sm btn-primary fw-bold flex-fill" href="<?php echo $base; ?>&amp;tab=sedute&amp;nuova=1&amp;consiglio_id=<?php echo $k; ?>"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuova seduta</a>
                </div>
            </div></div>
        </div>
    <?php endforeach; ?>
    <?php if (!$consigli_vis): ?><div class="col-12 text-muted small">Nessun consiglio.</div><?php endif; ?>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <h5 class="fw-bold mb-0"><i class="fa fa-calendar-days me-1 text-primary" aria-hidden="true"></i>Sedute</h5>
        <form method="GET" class="d-flex gap-1 ms-auto"><input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="tab" value="sedute">
            <select class="form-select form-select-sm" name="cons" onchange="this.form.submit()" aria-label="Filtra per consiglio" style="max-width:320px;"><option value="0">Tutti i consigli</option>
                <?php foreach ($consigli_vis as $k => $c): ?><option value="<?php echo $k; ?>"<?php echo $f_cons === $k ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($c['nome'], 0, 70, '…')); ?></option><?php endforeach; ?></select></form>
        <?php if (!$solo_ref): ?><a href="<?php echo $base; ?>&amp;tab=sedute&amp;nuova=1" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuova seduta</a><?php endif; ?>
    </div>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-bold small">Prossime (<?php echo count($prossime); ?>)</div>
                <div class="list-group list-group-flush"><?php foreach ($prossime as $x) echo $riga_seduta($x); ?><?php if (!$prossime): ?><div class="list-group-item small text-muted">Nessuna seduta in programma.</div><?php endif; ?></div></div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-bold small">Passate (<?php echo count($passate); ?>)</div>
                <div class="list-group list-group-flush" style="max-height:560px;overflow:auto;"><?php foreach ($passate as $x) echo $riga_seduta($x); ?><?php if (!$passate): ?><div class="list-group-item small text-muted">Nessuna.</div><?php endif; ?></div></div>
        </div>
    </div>
    <p class="small text-secondary mt-3 mb-0"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>In ogni seduta: convocazione per email (facoltativa) → presenze → pratiche con le decisioni → esiti agli studenti con l'estratto → verbale in Word e firma PAdES del segretario e del coordinatore.</p>
    <?php endif; ?>
