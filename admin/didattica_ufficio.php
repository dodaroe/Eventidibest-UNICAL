<?php
// didattica_ufficio.php - Ufficio e ricevimento: uffici, operatori scelti dall'anagrafe, sportello di ricevimento.
// Scheda del pannello Didattica: si apre da didattica.php?tab=ufficio (stesso indirizzo di sempre), mai direttamente.
// $fase = 'azioni': gestisce i POST della scheda (ognuno risponde con un rimando e termina); $fase = 'vista': la pagina.
if (!defined('DIDATTICA_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }

if ($fase === 'azioni') {
    // ── Ufficio didattico: operatori e sportello ──
    if (isset($_POST['salva_operatore'])) {
        $err = aggiungi_operatore_ufficio($conn, (string)($_POST['persona_id'] ?? ''), (string)($_POST['ruolo'] ?? ''), (array)($_POST['compiti'] ?? []), (int)($_POST['ufficio_id'] ?? 0), (array)($_POST['corsi'] ?? []));
        if (!$err) registra_log_audit($conn, "Ufficio didattico: operatore salvato", ["Persona" => $_POST['persona_id'] ?? '']);
        flash_set($err ?? "Operatore salvato: entra nel pannello Didattica con le sue credenziali Unical.", $err ? 'danger' : 'success');
        admin_redirect("$base&tab=ufficio&r=" . time());
    }
    if (isset($_POST['salva_ufficio'])) {
        $id_u = (int)($_POST['ufficio_id_mod'] ?? 0);
        $nome_u = mb_substr(trim((string)($_POST['nome_ufficio'] ?? '')), 0, 150);
        $descr_u = mb_substr(trim((string)($_POST['descr_ufficio'] ?? '')), 0, 500);
        $sm = isset($_POST['smista']) ? 1 : 0; $sc = isset($_POST['segue_corsi']) ? 1 : 0; $ord_u = (int)($_POST['ordine_ufficio'] ?? 0);
        if ($nome_u === '') flash_set("Scrivi il nome dell'ufficio.", 'danger');
        else {
            if ($id_u) { $st = $conn->prepare("UPDATE didattica_uffici SET nome = ?, descrizione = ?, smista = ?, segue_corsi = ?, ordine = ? WHERE id = ?"); $st->bind_param("ssiiii", $nome_u, $descr_u, $sm, $sc, $ord_u, $id_u); }
            else { $st = $conn->prepare("INSERT INTO didattica_uffici (nome, descrizione, smista, segue_corsi, ordine) VALUES (?, ?, ?, ?, ?)"); $st->bind_param("ssiii", $nome_u, $descr_u, $sm, $sc, $ord_u); }
            $st->execute();
            registra_log_audit($conn, "Ufficio didattico: ufficio salvato", ["Ufficio" => $nome_u]);
            flash_set("Ufficio «" . $nome_u . "» salvato.");
        }
        admin_redirect("$base&tab=ufficio&r=" . time());
    }
    if (isset($_POST['elimina_ufficio'])) {
        $id_u = (int)$_POST['elimina_ufficio'];
        $n_op = (int)db_valore($conn, "SELECT COUNT(*) FROM ufficio_didattica WHERE ufficio_id = ?", [$id_u]);
        $in_iter = 0;
        foreach ($conn->query("SELECT iter_json FROM didattica_moduli WHERE iter_json IS NOT NULL")->fetch_all(MYSQLI_ASSOC) as $mi)
            foreach (json_decode((string)$mi['iter_json'], true) ?: [] as $x) if (ufficio_didattica_id($conn, $x) === $id_u) $in_iter++;
        $n_pr = (int)db_valore($conn, "SELECT COUNT(*) FROM pratiche p JOIN ufficio_didattica o ON o.id = p.assegnata_a WHERE o.ufficio_id = ? AND p.stato NOT IN ('accolta', 'respinta', 'chiusa')", [$id_u]);
        if ($n_op || $in_iter) flash_set("L'ufficio non si può eliminare: " . ($n_op ? "ha $n_op persone (spostale in un altro ufficio)" : '') . ($n_op && $in_iter ? ' e ' : '') . ($in_iter ? "è nell'iter di $in_iter moduli" : '') . ".", 'warning');
        else { db_esegui($conn, "DELETE FROM didattica_uffici WHERE id = ?", [$id_u]); flash_set("Ufficio eliminato.", 'warning'); }
        admin_redirect("$base&tab=ufficio&r=" . time());
    }
    if (isset($_POST['togli_operatore'])) {
        $id = (int)$_POST['togli_operatore'];
        db_esegui($conn, "DELETE FROM ufficio_didattica WHERE id = ?", [$id]);
        registra_log_audit($conn, "Ufficio didattico: operatore tolto", ["ID" => $id]);
        flash_set("Operatore tolto dall'Ufficio didattico.", 'warning');
        admin_redirect("$base&tab=ufficio&r=" . time());
    }
    if (isset($_POST['crea_sportello'])) {
        $pag = (int)($_POST['pagina_id'] ?? 0);
        $ok_area = $pag && tipo_area(db_riga($conn, "SELECT * FROM pagine_eventi WHERE id = ?", [$pag]) ?: []) === 'calendario';
        $rid = $ok_area ? crea_sportello_ufficio($conn, $pag, (string)($_POST['nome'] ?? ''), (string)($_POST['luogo'] ?? '')) : 0;
        if ($rid) registra_log_audit($conn, "Ufficio didattico: sportello di ricevimento creato", ["Risorsa" => $rid]);
        flash_set($rid ? "Sportello creato: ora imposta giorni e orari del ricevimento." : "Scegli un'area di Prenotazioni e risorse.", $rid ? 'success' : 'danger');
        admin_redirect("$base&tab=ufficio&r=" . time());
    }
    return;
}
?>
<?php
    $persone = $conn->query("SELECT id, cognome, nome, email, ruolo, gruppo FROM personale_ateneo WHERE attivo = 1 AND email <> '' ORDER BY FIELD(gruppo, 'pta', 'docenti', 'altro'), cognome, nome")->fetch_all(MYSQLI_ASSOC);
    $corsi_sc = scelte_anagrafe_didattica($conn, 'corso_studio');
    $uffici = uffici_didattica($conn);
    $html_profilo = function ($sel) use ($h, $uffici) { $o = '<option value="0">— nessun ufficio —</option>'; foreach ($uffici as $k => $u) $o .= '<option value="' . $k . '" data-corsi="' . (int)$u['segue_corsi'] . '"' . ((int)$k === (int)$sel ? ' selected' : '') . '>' . $h($u['nome']) . '</option>'; return $o; };
    $html_corsi = function (array $sel) use ($h, $corsi_sc) { $o = ''; foreach ($corsi_sc as $g => $cc) { $o .= '<optgroup label="' . $h($g) . '">'; foreach ($cc as $c) $o .= '<option' . (in_array($c, $sel, true) ? ' selected' : '') . '>' . $h($c) . '</option>'; $o .= '</optgroup>'; } return $o; };
    $gruppi_p = ['pta' => 'Personale tecnico-amministrativo', 'docenti' => 'Docenti', 'altro' => 'Altro personale'];
    $sportelli_uff = sportelli_ufficio_didattica($conn);
    $aree_cal = array_values(array_filter($conn->query("SELECT * FROM pagine_eventi ORDER BY titolo")->fetch_all(MYSQLI_ASSOC), fn($a) => tipo_area($a) === 'calendario'));
    $sono_operatore = utente_operatore_ufficio($conn, $utente_admin);
?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h5 class="fw-bold mb-1"><i class="fa fa-sitemap me-1 text-primary" aria-hidden="true"></i>Uffici</h5>
        <p class="small text-secondary">Gli uffici dell'Ufficio didattico: assegna il personale qui sotto e scegli nei moduli quali uffici ricevono la pratica e in che ordine (iter). Gli uffici che <strong>smistano</strong> ricevono le pratiche nuove; in quelli che <strong>seguono i corsi</strong> ogni persona indica i suoi corsi di studio e le pratiche di quei corsi le vengono proposte per prime.</p>
        <?php $n_per_uff = []; foreach ($operatori as $o) $n_per_uff[(int)$o['ufficio_id']] = ($n_per_uff[(int)$o['ufficio_id']] ?? 0) + 1;
        foreach (array_merge(array_keys($uffici), ['nuovo']) as $k): ?>
            <form method="POST" id="fu<?php echo $k; ?>"><?php csrf_field(); ?><input type="hidden" name="ufficio_id_mod" value="<?php echo (int)$k; ?>"></form>
        <?php endforeach; ?>
        <div class="table-responsive"><table class="table table-sm align-middle small mb-2">
            <thead class="table-light"><tr><th>Ufficio</th><th>Descrizione</th><th class="text-center">Smista</th><th class="text-center">Segue i corsi</th><th class="text-center">Ordine</th><th class="text-center">Persone</th><th></th></tr></thead><tbody>
            <?php foreach ($uffici as $k => $u): $fid = 'fu' . $k; ?>
                <tr>
                    <td><input form="<?php echo $fid; ?>" class="form-control form-control-sm fw-bold" name="nome_ufficio" value="<?php echo $h($u['nome']); ?>" maxlength="150" required aria-label="Nome dell'ufficio"></td>
                    <td><input form="<?php echo $fid; ?>" class="form-control form-control-sm" name="descr_ufficio" value="<?php echo $h($u['descrizione']); ?>" maxlength="500" aria-label="Descrizione"></td>
                    <td class="text-center"><input form="<?php echo $fid; ?>" class="form-check-input" type="checkbox" name="smista" value="1"<?php echo (int)$u['smista'] ? ' checked' : ''; ?> aria-label="Smista le pratiche nuove"></td>
                    <td class="text-center"><input form="<?php echo $fid; ?>" class="form-check-input" type="checkbox" name="segue_corsi" value="1"<?php echo (int)$u['segue_corsi'] ? ' checked' : ''; ?> aria-label="Segue i corsi di studio"></td>
                    <td class="text-center"><input form="<?php echo $fid; ?>" type="number" class="form-control form-control-sm" style="width:64px;" name="ordine_ufficio" value="<?php echo (int)$u['ordine']; ?>" aria-label="Ordine"></td>
                    <td class="text-center"><?php echo (int)($n_per_uff[$k] ?? 0); ?></td>
                    <td class="text-nowrap"><button form="<?php echo $fid; ?>" type="submit" name="salva_ufficio" value="1" class="btn btn-sm btn-outline-primary py-0">Salva</button>
                        <button form="<?php echo $fid; ?>" type="submit" name="elimina_ufficio" value="<?php echo (int)$k; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare l'ufficio «<?php echo $h($u['nome']); ?>»?" aria-label="Elimina l'ufficio"><i class="fa fa-trash" aria-hidden="true"></i></button></td>
                </tr>
            <?php endforeach; ?>
            <tr class="table-light">
                <td><input form="funuovo" class="form-control form-control-sm" name="nome_ufficio" maxlength="150" placeholder="Nuovo ufficio (es. Tirocini)" aria-label="Nome del nuovo ufficio"></td>
                <td><input form="funuovo" class="form-control form-control-sm" name="descr_ufficio" maxlength="500" placeholder="Di cosa si occupa" aria-label="Descrizione del nuovo ufficio"></td>
                <td class="text-center"><input form="funuovo" class="form-check-input" type="checkbox" name="smista" value="1" aria-label="Smista le pratiche nuove"></td>
                <td class="text-center"><input form="funuovo" class="form-check-input" type="checkbox" name="segue_corsi" value="1" aria-label="Segue i corsi di studio"></td>
                <td class="text-center"><input form="funuovo" type="number" class="form-control form-control-sm" style="width:64px;" name="ordine_ufficio" value="<?php echo count($uffici) + 1; ?>" aria-label="Ordine"></td>
                <td></td>
                <td><button form="funuovo" type="submit" name="salva_ufficio" value="1" class="btn btn-sm btn-success fw-bold py-0"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi</button></td>
            </tr>
        </tbody></table></div>
    </div></div>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h5 class="fw-bold mb-1"><i class="fa fa-people-group me-1 text-success" aria-hidden="true"></i>Operatori dell'Ufficio didattico</h5>
                <p class="small text-secondary">Scelti dall'anagrafe di Ateneo: entrano nel pannello Didattica con le loro credenziali Unical e gestiscono pratiche, sedute, modulistica e ricevimento. Ognuno appartiene a un <strong>ufficio</strong> (vedi sopra), che decide il suo ruolo nell'iter delle pratiche. I compiti decidono chi riceve gli altri avvisi.</p>
                <?php if (!$operatori): ?><div class="alert alert-light border small">Nessun operatore: aggiungi il personale dell'ufficio qui sotto.</div><?php endif; ?>
                <?php foreach ($operatori as $o): ?>
                    <form method="POST" class="border rounded p-2 mb-2">
                        <?php csrf_field(); ?><input type="hidden" name="persona_id" value="<?php echo $h($o['persona_id']); ?>">
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <div class="flex-grow-1"><strong><?php echo $h($o['nominativo']); ?></strong> <span class="small text-secondary"><?php echo $h($o['email']); ?></span></div>
                            <select class="form-select form-select-sm dd-prof" style="max-width:230px;" name="ufficio_id" aria-label="Ufficio"><?php echo $html_profilo((int)$o['ufficio_id']); ?></select>
                            <input type="text" class="form-control form-control-sm" style="max-width:180px;" name="ruolo" value="<?php echo $h($o['ruolo']); ?>" placeholder="Ruolo (es. Responsabile)" aria-label="Ruolo">
                        </div>
                        <div class="d-flex flex-wrap gap-3 align-items-center mt-1 small">
                            <?php foreach (COMPITI_UFFICIO as $k => $n): ?><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="compiti[]" value="<?php echo $k; ?>"<?php echo in_array($k, $o['_compiti'], true) ? ' checked' : ''; ?>> <?php echo $h($n); ?></label><?php endforeach; ?>
                            <span class="ms-auto d-flex gap-1"><button type="submit" name="salva_operatore" value="1" class="btn btn-sm btn-outline-primary py-0">Salva</button>
                                <button type="submit" name="togli_operatore" value="<?php echo (int)$o['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Togliere <?php echo $h($o['nominativo']); ?> dall'Ufficio didattico?" aria-label="Togli"><i class="fa fa-user-minus" aria-hidden="true"></i></button></span>
                        </div>
                        <div class="dd-corsi mt-1"<?php echo empty($uffici[(int)$o['ufficio_id']]['segue_corsi']) ? ' hidden' : ''; ?>><label class="small fw-bold">Corsi di studio seguiti</label><select class="form-select form-select-sm" name="corsi[]" multiple size="4" aria-label="Corsi di studio seguiti"><?php echo $html_corsi(json_decode((string)$o['corsi'], true) ?: []); ?></select></div>
                        <?php if (!empty($uffici[(int)$o['ufficio_id']]['segue_corsi']) && ($cs = json_decode((string)$o['corsi'], true))): ?><div class="small text-secondary mt-1"><i class="fa fa-graduation-cap me-1" aria-hidden="true"></i><?php echo $h(implode(' · ', $cs)); ?></div><?php endif; ?>
                    </form>
                <?php endforeach; ?>
                <form method="POST" class="bg-light rounded p-2 mt-3">
                    <?php csrf_field(); ?>
                    <h6 class="fw-bold small mb-2">Aggiungi un operatore dall'anagrafe</h6>
                    <input type="search" class="form-control form-control-sm mb-1" id="opCerca" placeholder="Filtra per cognome…" aria-label="Filtra l'elenco del personale">
                    <select class="form-select form-select-sm mb-2" id="opPersona" name="persona_id" required size="6" aria-label="Persona dell'anagrafe">
                        <?php $g_corr = null; foreach ($persone as $pp): if ($g_corr !== $pp['gruppo']): if ($g_corr !== null) echo '</optgroup>'; $g_corr = $pp['gruppo']; ?><optgroup label="<?php echo $h($gruppi_p[$g_corr] ?? $g_corr); ?>"><?php endif; ?>
                            <option value="<?php echo $h($pp['id']); ?>"><?php echo $h($pp['cognome'] . ' ' . $pp['nome'] . ' · ' . ($pp['ruolo'] ?: $pp['email'])); ?></option>
                        <?php endforeach; if ($g_corr !== null) echo '</optgroup>'; ?>
                    </select>
                    <div class="d-flex flex-wrap gap-3 align-items-center small">
                        <select class="form-select form-select-sm dd-prof" style="max-width:230px;" name="ufficio_id" aria-label="Ufficio"><?php echo $html_profilo(0); ?></select>
                        <input type="text" class="form-control form-control-sm" style="max-width:200px;" name="ruolo" placeholder="Ruolo (facoltativo)" aria-label="Ruolo">
                        <?php foreach (COMPITI_UFFICIO as $k => $n): ?><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="compiti[]" value="<?php echo $k; ?>"<?php echo $k !== 'bandi' ? ' checked' : ''; ?>> <?php echo $h($n); ?></label><?php endforeach; ?>
                        <button type="submit" name="salva_operatore" value="1" class="btn btn-sm btn-success fw-bold ms-auto"><i class="fa fa-user-plus me-1" aria-hidden="true"></i>Aggiungi</button>
                    </div>
                    <div class="dd-corsi mt-1" hidden><label class="small fw-bold">Corsi di studio seguiti</label><select class="form-select form-select-sm" name="corsi[]" multiple size="4" aria-label="Corsi di studio seguiti"><?php echo $html_corsi([]); ?></select><div class="form-text">Con Ctrl si scelgono più corsi: le pratiche di questi corsi gli vengono proposte per prime.</div></div>
                    <?php if (!$persone): ?><div class="small text-danger mt-1">L'anagrafe del personale è vuota: aggiornala da Gestione del portale → Anagrafi.</div><?php endif; ?>
                </form>
            </div></div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h5 class="fw-bold mb-1"><i class="fa fa-user-clock me-1" style="color:#7c3aed;" aria-hidden="true"></i>Ricevimento dell'ufficio</h5>
                <p class="small text-secondary">Sportello a appuntamenti per gli studenti: si prenota dalla pagina pubblica (anche dalla Modulistica); gli operatori impostano giorni, orari e assenze e vedono gli appuntamenti.</p>
                <?php foreach ($sportelli_uff as $sp):
                    $orari = db_righe($conn, "SELECT giorno, dalle, alle FROM risorse_orari WHERE risorsa_id = ? ORDER BY giorno, dalle", [(int)$sp['id']]);
                    $n_app = (int)db_valore($conn, "SELECT COUNT(*) FROM prenotazioni_risorse WHERE risorsa_id = ? AND stato IN ('confermata', 'da_approvare') AND fine >= NOW()", [(int)$sp['id']]); ?>
                    <div class="border rounded p-2 mb-2">
                        <div class="fw-bold"><?php echo $h($sp['nome']); ?> <?php echo (int)$sp['attiva'] ? '<span class="badge bg-success">prenotabile</span>' : '<span class="badge bg-secondary">non prenotabile</span>'; ?></div>
                        <div class="small text-secondary"><?php echo $h($sp['area_titolo']); ?><?php echo $sp['luogo'] ? ' · ' . $h($sp['luogo']) : ''; ?> · <?php echo $n_app; ?> appuntamenti in programma</div>
                        <div class="small"><?php echo $orari ? $h(implode(', ', array_map(fn($o) => GIORNI_SETTIMANA[(int)$o['giorno']] . ' ' . substr($o['dalle'], 0, 5) . '–' . substr($o['alle'], 0, 5), $orari))) : '<span class="text-danger">Orari non ancora impostati</span>'; ?></div>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <?php if ($sono_operatore): ?><a class="btn btn-sm btn-primary fw-bold py-0" href="../ricevimento.php"><i class="fa fa-clock me-1" aria-hidden="true"></i>Orari e appuntamenti</a><?php endif; ?>
                            <?php if ($is_full_admin): ?><a class="btn btn-sm btn-outline-primary py-0" href="risorse.php?p_id=<?php echo (int)$sp['pagina_id']; ?>&amp;modifica=<?php echo (int)$sp['id']; ?>">Impostazioni</a>
                                <a class="btn btn-sm btn-outline-dark py-0" href="prenotazioni_risorse.php?p_id=<?php echo (int)$sp['pagina_id']; ?>">Prenotazioni</a><?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary py-0" href="../<?php echo $h($sp['area_slug']); ?>.php?risorsa=<?php echo (int)$sp['id']; ?>" target="_blank" rel="noopener">Pagina di prenotazione</a>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$sono_operatore && $sportelli_uff): ?><p class="small text-secondary">Gli orari li imposta un operatore dell'ufficio dalla sua Area personale → Il mio ricevimento.</p><?php endif; ?>
                <form method="POST" class="bg-light rounded p-2 mt-2">
                    <?php csrf_field(); ?>
                    <h6 class="fw-bold small mb-2"><?php echo $sportelli_uff ? 'Aggiungi un altro sportello' : 'Crea lo sportello di ricevimento'; ?></h6>
                    <?php if (!$aree_cal): ?><div class="small text-danger">Serve un'area di tipo «Aule, laboratori e sportelli» in Prenotazioni e risorse.</div><?php else: ?>
                    <label class="form-label small fw-bold mb-0" for="spArea">Area di Prenotazioni e risorse</label>
                    <select class="form-select form-select-sm mb-1" id="spArea" name="pagina_id"><?php foreach ($aree_cal as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo $h($a['titolo']); ?></option><?php endforeach; ?></select>
                    <input type="text" class="form-control form-control-sm mb-1" name="nome" value="Ufficio didattico – ricevimento studenti" maxlength="150" aria-label="Nome dello sportello">
                    <input type="text" class="form-control form-control-sm mb-2" name="luogo" placeholder="Luogo (es. Cubo 4B, piano terra)" maxlength="255" aria-label="Luogo">
                    <button type="submit" name="crea_sportello" value="1" class="btn btn-sm fw-bold text-white" style="background:#7c3aed;"><i class="fa fa-plus me-1" aria-hidden="true"></i>Crea</button>
                    <?php endif; ?>
                </form>
            </div></div>
        </div>
    </div>
    <script>
    (function () {
        var c = document.getElementById('opCerca'), s = document.getElementById('opPersona');
        if (!c || !s) return;
        c.addEventListener('input', function () { var q = c.value.toLowerCase(); Array.prototype.forEach.call(s.options, function (o) { o.hidden = q && o.text.toLowerCase().indexOf(q) === -1; }); });
        document.querySelectorAll('.dd-prof').forEach(function (sp) { sp.addEventListener('change', function () { var d = sp.closest('form').querySelector('.dd-corsi'); if (d) d.hidden = !(sp.selectedOptions[0] && sp.selectedOptions[0].dataset.corsi === '1'); }); });
    })();
    </script>

