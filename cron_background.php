<?php
// cron_background.php - Motore Automazioni (Da richiamare ogni 15 minuti tramite crontab Ubuntu)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
consenti_esecuzione_cron([1]); // solo crontab, chiave CRON_KEY o admin loggato

// =========================================================================
// FASE 3: MUTEX LOCK PER PREVENIRE ESECUZIONI SOVRAPPOSTE (stesso pattern già
// in uso in cron_attestati.php). Se il crontab lancia una nuova esecuzione
// mentre la precedente sta ancora girando (es. invio email lento, tanti
// destinatari), qui evitiamo doppio invio delle email post-evento.
// =========================================================================
$cache_dir = __DIR__ . '/cache';
if (!is_dir($cache_dir)) { @mkdir($cache_dir, 0755, true); }

if (!is_dir($cache_dir) || !is_writable($cache_dir)) {
    die("ERRORE DI CONFIGURAZIONE: la cartella 'cache/' non esiste o non è scrivibile dal server web (" . $cache_dir . "). Crearla manualmente con permessi 755 e riprovare.\n");
}

$lock_file_bg = $cache_dir . '/cron_background.lock';

// Anti lock-orfano: se il lock esiste da più di 10 minuti lo consideriamo residuo
// di un'esecuzione precedente interrotta in modo anomalo e lo rimuoviamo.
if (file_exists($lock_file_bg) && (time() - filemtime($lock_file_bg)) > 600) {
    @unlink($lock_file_bg);
}

$lock_handle_bg = fopen($lock_file_bg, 'w+');

// LOCK_EX = Lock esclusivo | LOCK_NB = Non bloccante (se già in uso, fallisce subito)
if (!$lock_handle_bg || !flock($lock_handle_bg, LOCK_EX | LOCK_NB)) {
    die("PROCESSO IN ESECUZIONE: cron_background.php è già in esecuzione in un altro processo (avviato meno di 10 minuti fa).\n");
}


$now = date('Y-m-d H:i:s');
$sys = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();

echo "Inizio Esecuzione CRON: $now\n";

// =========================================================================
// TASK 0: AUTO-ARCHIVIAZIONE EVENTI SCADUTI
// =========================================================================
$conn->query("UPDATE eventi e SET e.archiviato = 1 WHERE e.archiviato = 0 AND (e.blocca_auto_archivio IS NULL OR e.blocca_auto_archivio = 0) AND (SELECT MAX(data_turno) FROM turni t WHERE t.evento_id = e.id) < CURDATE() AND NOT EXISTS (SELECT 1 FROM turni t2 WHERE t2.evento_id = e.id AND t2.data_turno IS NULL)");
echo "- Auto-archiviati " . $conn->affected_rows . " eventi scaduti.\n";

// =========================================================================
// TASK 1: EMAIL POST-EVENTO (Attestati e Sondaggi)
// =========================================================================
// Seleziona i presenti a eventi finiti, a cui NON è ancora stata mandata l'email
// Progetti: solo a progetto concluso (data di fine passata)
$sql_post = "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.evento_id, e.titolo as evento_titolo, e.luogo
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE pr.presente = 1
             AND pr.email_post_evento_inviata = 0
             AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_fine, '23:59:59')) < '$now')
             AND NOT (IFNULL(e.tipo, 'evento') = 'progetto' AND pd.data_fine IS NOT NULL AND pd.data_fine >= CURDATE())";

$res_post = $conn->query($sql_post);
$count_post = 0;

if ($res_post && $res_post->num_rows > 0) {
    // Calcoliamo l'URL di base dinamicamente (puoi anche hardcodarlo se preferisci)
    $domain = "https://" . ($_SERVER['HTTP_HOST'] ?? 'il-tuo-sito.unical.it') . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $link_area = "<a href='$domain/area_personale.php' style='color:#B30000; font-weight:bold;'>Area Personale</a>";

    while ($p = $res_post->fetch_assoc()) {
        $r_find = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{LINK_AREA_PERSONALE}'];
        $ora_f = orario_turno($p) ?: 'da definire';
        $data_f = implode(' · ', array_filter([$p['nome_turno'] ?? '', !empty($p['data_turno']) ? date('d/m/Y', strtotime($p['data_turno'])) : '']));
        $r_repl = [$p['nome'], $p['cognome'], $p['matricola'], $p['evento_titolo'], $data_f, $ora_f, $p['luogo'], $link_area];

        // 1. Invia Avviso Attestato Disponibile (se configurato e se l'attestato personale esiste:
        //    non nei progetti senza attestati né in quelli per le scuole, dove li riceve il docente per la classe)
        $regola_att = regola_attestato_evento($conn, (int)$p['evento_id']);
        if (!empty($sys['email_attestato_corpo']) && in_array($regola_att, ['evento', 'singolo'], true)) {
            inviaNotificaEmail($p['email'], str_replace($r_find, $r_repl, $sys['email_attestato_oggetto']), str_replace($r_find, $r_repl, $sys['email_attestato_corpo']), $conn, colore_area_turno($conn, $p['turno_id']));
        }
        // 2. Invia Sondaggio (se configurato)
        if (!empty($sys['email_sondaggio_corpo'])) {
            inviaNotificaEmail($p['email'], str_replace($r_find, $r_repl, $sys['email_sondaggio_oggetto']), str_replace($r_find, $r_repl, $sys['email_sondaggio_corpo']), $conn, colore_area_turno($conn, $p['turno_id']));
        }

        // Segna come inviata per non spammare l'utente al prossimo giro di Cron
        $conn->query("UPDATE prenotazioni SET email_post_evento_inviata = 1 WHERE id = {$p['id']}");
        $count_post++;
    }
}
echo "- Inviate $count_post email post-evento (Attestati/Sondaggi).\n";

// =========================================================================
// TASK 1b: PROMEMORIA AL DOCENTE PER L'ELENCO DEGLI STUDENTI
// Prenotazioni di classe con attestati ed elenco ancora vuoto: chi ha prenotato riceve un'email (una sola volta)
// con il link per compilarlo. Progetti per le scuole: a 7 giorni (o meno) dalla fine.
// Eventi con attestati per la classe: da 3 giorni prima del turno fino a 14 giorni dopo.
// =========================================================================
$sql_prom = "SELECT pr.id, pr.nome, pr.cognome, pr.email, pr.codice_prenotazione, pr.turno_id, e.titolo, e.tipo, pd.data_fine, t.data_turno
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE e.archiviato = 0 AND pd.attestati = 1
               AND IFNULL(pr.stato, 'confermata') = 'confermata' AND pr.promemoria_elenco_inviato = 0
               AND ((e.tipo = 'progetto' AND pd.per_scuole = 1 AND pd.data_fine BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY)
                 OR (IFNULL(e.tipo, 'evento') <> 'progetto' AND t.data_turno BETWEEN CURDATE() - INTERVAL 14 DAY AND CURDATE() + INTERVAL 3 DAY))
               AND NOT EXISTS (SELECT 1 FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id)";
$res_prom = $conn->query($sql_prom);
$count_prom = 0;
while ($res_prom && $pm = $res_prom->fetch_assoc()) {
    if (empty($pm['email'])) continue;
    $link_el = url_base_sito() . '/elenco_studenti.php?code=' . urlencode($pm['codice_prenotazione']);
    $corpo_pm = "<p>Gentile <strong>" . htmlspecialchars($pm['nome'] . ' ' . $pm['cognome']) . "</strong>,</p>"
              . ($pm['tipo'] === 'progetto'
                  ? "<p>il progetto <strong>" . htmlspecialchars($pm['titolo']) . "</strong> si conclude il <strong>" . date('d/m/Y', strtotime($pm['data_fine'])) . "</strong>.</p>"
                  : "<p>l'attività <strong>" . htmlspecialchars($pm['titolo']) . "</strong> " . ($pm['data_turno'] < date('Y-m-d') ? "si è svolta" : "si svolge") . " il <strong>" . date('d/m/Y', strtotime($pm['data_turno'])) . "</strong>.</p>")
              . "<p>Per ricevere gli <strong>attestati di partecipazione</strong> dei tuoi studenti inserisci il loro elenco (cognome e nome): puoi scriverlo, incollarlo da Excel o caricare il modello compilato.</p>"
              . "<p style='text-align:center; margin:28px 0;'><a href='" . htmlspecialchars($link_el) . "' style='background-color:#198754; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold;'>Inserisci l'elenco degli studenti</a></p>";
    inviaNotificaEmail($pm['email'], "Promemoria: elenco degli studenti per gli attestati - " . $pm['titolo'], $corpo_pm, $conn, colore_area_turno($conn, (int)$pm['turno_id']));
    $conn->query("UPDATE prenotazioni SET promemoria_elenco_inviato = 1 WHERE id = " . (int)$pm['id']);
    $count_prom++;
}
echo "- Inviati $count_prom promemoria per l'elenco degli studenti.\n";

// Attestati degli studenti ad attività conclusa (se il cron degli attestati non è pianificato a parte):
// progetti per le scuole dopo la data di fine, eventi con attestati per la classe dopo il giorno del turno
$res_grp = $conn->query("SELECT pr.id FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                         JOIN progetti_dettagli pd ON pd.evento_id = e.id
                         WHERE pd.attestati = 1
                           AND ((e.tipo = 'progetto' AND pd.per_scuole = 1 AND pd.data_fine < CURDATE())
                             OR (IFNULL(e.tipo, 'evento') <> 'progetto' AND (t.data_turno IS NULL OR t.data_turno < CURDATE())))
                           AND pr.presente = 1 AND IFNULL(pr.stato, 'confermata') = 'confermata' AND pr.attestato_inviato = 0");
$count_grp = 0;
while ($res_grp && $g = $res_grp->fetch_assoc()) { if (invia_attestati_gruppo($conn, (int)$g['id']) === true) $count_grp++; }
echo "- Inviati attestati degli studenti per $count_grp iscrizioni.\n";

// =========================================================================
// TASK 1g: CONVENZIONI CON LE SCUOLE
// 0) verifica delle iscrizioni alle attività FSL (anche confermate): la convenzione deve coprire il periodo dell'attività;
// a) promemoria alla scuola ogni 7 giorni (massimo 3) finché la convenzione non arriva, fino alla fine dell'attività
//    (dopo la richiesta del gestore: le iscrizioni segnate dalla verifica partono senza promemoria);
// b) avviso ai gestori (una volta) quando l'attività inizia entro 7 giorni e ci sono scuole ancora senza convenzione;
// c) avviso agli amministratori (una volta) per le convenzioni del registro che scadono entro 60 giorni.
// =========================================================================
$stati_conv = "'confermata', 'in_attesa', 'da_approvare', 'richiesta_conferma'";
// Prima la verifica di tutte le iscrizioni alle attività FSL (anche confermate) con il registro delle convenzioni
$v_conv = verifica_convenzioni_fsl($conn);
echo "- Convenzioni FSL: {$v_conv['coperte']} iscrizioni coperte, {$v_conv['da_stipulare']} da stipulare ({$v_conv['nuove_da_stipulare']} nuove), {$v_conv['senza_codice']} con la scuola scritta a mano.\n";
$res_cv = @$conn->query("SELECT pr.id, pr.scuola_codice, t.data_turno, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                         LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
                         WHERE e.archiviato = 0 AND pr.convenzione = 'no' AND IFNULL(pr.stato, 'confermata') IN ($stati_conv)
                           AND pr.conv_promemoria < 3 AND COALESCE(pr.conv_promemoria_il, pr.data_prenotazione) <= NOW() - INTERVAL 7 DAY
                           AND (COALESCE(pd.data_fine, t.data_turno) IS NULL OR COALESCE(pd.data_fine, t.data_turno) >= CURDATE())");
$count_cv = 0; $count_cv_reg = 0;
while ($res_cv && $x = $res_cv->fetch_assoc()) {
    // Nel frattempo registrata nell'anagrafe: niente promemoria, la prenotazione si aggiorna
    [$x_dal, $x_al] = periodo_prenotazione($x);
    if (!empty($x['scuola_codice']) && convenzione_valida($conn, $x['scuola_codice'], false, $x_dal, $x_al)) { segna_convenzione_ricevuta($conn, (int)$x['id']); $count_cv_reg++; continue; }
    email_richiesta_convenzione($conn, (int)$x['id'], 'promemoria');
    $conn->query("UPDATE prenotazioni SET conv_promemoria = conv_promemoria + 1, conv_promemoria_il = NOW() WHERE id = " . (int)$x['id']);
    $count_cv++;
}
echo "- Convenzioni: $count_cv promemoria alle scuole, $count_cv_reg prenotazioni aggiornate dal registro.\n";

$res_cg = @$conn->query("SELECT pr.id, pr.nome, pr.cognome, pr.email, pr.codice_prenotazione, pr.stato, pr.dati_custom_json, pr.scuola_codice,
                                t.evento_id, t.nome_turno, t.data_turno, e.titolo, e.pagina_id, COALESCE(pd.data_inizio, t.data_turno) AS inizio
                         FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                         LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
                         WHERE e.archiviato = 0 AND pr.convenzione = 'no' AND pr.conv_avviso_gestori = 0 AND IFNULL(pr.stato, 'confermata') IN ($stati_conv)
                           AND COALESCE(pd.data_inizio, t.data_turno) BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY
                         ORDER BY t.evento_id, inizio");
$per_ev_cg = [];
while ($res_cg && $x = $res_cg->fetch_assoc()) $per_ev_cg[(int)$x['evento_id']][] = $x;
foreach ($per_ev_cg as $ev_cg => $righe_cg) {
    $dest_cg = get_email_gestori_evento($conn, $ev_cg) ?: email_amministratori($conn);
    $h_cg = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $tab_cg = '';
    foreach ($righe_cg as $x) {
        $s_cg = !empty($x['scuola_codice']) ? scuola_per_codice($conn, $x['scuola_codice']) : null;
        $scuola_cg = $s_cg ? etichetta_scuola($s_cg) : (nome_scuola_prenotazione($x) ?: '—');
        $tab_cg .= "<tr><td style='padding:5px 8px;border-bottom:1px solid #e5e7eb;'>" . $h_cg($scuola_cg) . "</td><td style='padding:5px 8px;border-bottom:1px solid #e5e7eb;'>" . $h_cg(trim($x['nome'] . ' ' . $x['cognome'])) . "<br><span style='color:#6b7280;'>" . $h_cg($x['email']) . "</span></td>"
                 . "<td style='padding:5px 8px;border-bottom:1px solid #e5e7eb;'>" . $h_cg(etichetta_turno($x)) . "</td><td style='padding:5px 8px;border-bottom:1px solid #e5e7eb;font-family:monospace;'>" . $h_cg($x['codice_prenotazione']) . "</td></tr>";
    }
    $link_cg = url_base_sito() . '/admin/iscritti.php?p_id=' . (int)$righe_cg[0]['pagina_id'];
    $corpo_cg = "<p>L'attività <strong>" . $h_cg($righe_cg[0]['titolo']) . "</strong> inizia il <strong>" . date('d/m/Y', strtotime($righe_cg[0]['inizio'])) . "</strong> e queste scuole non hanno ancora inviato la <strong>convenzione</strong>:</p>"
              . "<table style='border-collapse:collapse;font-size:13px;width:100%;'><tr style='background:#f3f4f6;'><th style='padding:5px 8px;text-align:left;'>Scuola</th><th style='padding:5px 8px;text-align:left;'>Docente</th><th style='padding:5px 8px;text-align:left;'>Turno</th><th style='padding:5px 8px;text-align:left;'>Codice</th></tr>$tab_cg</table>"
              . "<p>Quando arriva la convenzione segnala come ricevuta da Iscrizioni: la prenotazione si conferma e la scuola riceve l'email.</p>"
              . "<p><a href='" . $h_cg($link_cg) . "' style='background:#c2410c;color:#fff;padding:9px 16px;text-decoration:none;border-radius:6px;font-weight:bold;'>Apri Iscrizioni</a></p>";
    foreach ($dest_cg as $em_cg) inviaNotificaEmail($em_cg, "Convenzioni mancanti: " . $righe_cg[0]['titolo'], $corpo_cg, $conn);
    $conn->query("UPDATE prenotazioni SET conv_avviso_gestori = 1 WHERE id IN (" . implode(',', array_map(fn($x) => (int)$x['id'], $righe_cg)) . ")");
}
echo "- Convenzioni: avvisati i gestori di " . count($per_ev_cg) . " attività in partenza con scuole senza convenzione.\n";

// Solo le convenzioni che scadono senza un rinnovo già registrato per la stessa scuola
$res_sc = @$conn->query("SELECT c.* FROM convenzioni_scuole c
                         WHERE c.avviso_scadenza_inviato = 0 AND c.scadenza BETWEEN CURDATE() AND CURDATE() + INTERVAL 60 DAY
                           AND NOT EXISTS (SELECT 1 FROM convenzioni_scuole c2 WHERE c2.scuola_codice = c.scuola_codice AND c2.id <> c.id
                                           AND (c2.scadenza IS NULL OR c2.scadenza > c.scadenza))
                         ORDER BY c.scadenza");
$righe_sc = [];
while ($res_sc && $x = $res_sc->fetch_assoc()) $righe_sc[] = $x;
if ($righe_sc) {
    $h_sc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $lista_sc = '';
    foreach ($righe_sc as $x) {
        $s_sc = scuola_per_codice($conn, $x['scuola_codice']);
        $lista_sc .= "<li><strong>" . $h_sc($s_sc ? etichetta_scuola($s_sc) : $x['scuola_codice']) . "</strong> (" . $h_sc($x['scuola_codice']) . "): scade il <strong>" . date('d/m/Y', strtotime($x['scadenza'])) . "</strong>"
                   . ($x['protocollo'] !== '' ? " · prot. " . $h_sc($x['protocollo']) : '') . "</li>";
    }
    $corpo_sc = "<p>Queste convenzioni con le scuole scadono nei prossimi 60 giorni:</p><ul>$lista_sc</ul>"
              . "<p>Dopo la scadenza, alle nuove iscrizioni di queste scuole verrà chiesta di nuovo la convenzione. Il rinnovo si registra in <a href='" . $h_sc(url_base_sito() . '/admin/fsl.php?tab=convenzioni') . "'>Formazione Scuola Lavoro → Convenzioni</a>.</p>";
    foreach (email_amministratori($conn) as $em_sc) inviaNotificaEmail($em_sc, "Convenzioni con le scuole in scadenza (" . count($righe_sc) . ")", $corpo_sc, $conn);
    $conn->query("UPDATE convenzioni_scuole SET avviso_scadenza_inviato = 1 WHERE id IN (" . implode(',', array_map(fn($x) => (int)$x['id'], $righe_sc)) . ")");
}
echo "- Convenzioni: " . count($righe_sc) . " in scadenza segnalate agli amministratori.\n";

// =========================================================================
// TASK 1i: SCHEDA DI VALUTAZIONE DELLA STRUTTURA OSPITANTE (FSL)
// Attività FSL concluse da non più di 30 giorni, prenotazione confermata con la presenza registrata: il docente
// riceve il link alla scheda; se dopo 7 giorni non l'ha compilata, un solo promemoria.
// =========================================================================
$res_vf = @$conn->query("SELECT pr.id FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                         JOIN progetti_dettagli pd ON pd.evento_id = e.id
                         WHERE pd.convenzione = 1 AND IFNULL(pr.stato, 'confermata') = 'confermata' AND pr.presente = 1 AND pr.valutazione_inviata IS NULL
                           AND COALESCE(IF(e.tipo = 'progetto', pd.data_fine, t.data_turno), t.data_turno) < CURDATE()
                           AND COALESCE(IF(e.tipo = 'progetto', pd.data_fine, t.data_turno), t.data_turno) >= CURDATE() - INTERVAL 30 DAY");
$count_vf = 0;
while ($res_vf && $x = $res_vf->fetch_assoc()) if (invia_invito_valutazione($conn, (int)$x['id'])) $count_vf++;
$res_vf = @$conn->query("SELECT pr.id FROM prenotazioni pr LEFT JOIN valutazioni_fsl v ON v.prenotazione_id = pr.id
                         WHERE pr.valutazione_inviata IS NOT NULL AND pr.valutazione_inviata <= NOW() - INTERVAL 7 DAY
                           AND pr.valutazione_promemoria = 0 AND v.id IS NULL AND IFNULL(pr.stato, 'confermata') = 'confermata'");
$count_vf_p = 0;
while ($res_vf && $x = $res_vf->fetch_assoc()) if (invia_invito_valutazione($conn, (int)$x['id'], true)) $count_vf_p++;
echo "- Schede di valutazione FSL: $count_vf inviti, $count_vf_p promemoria.\n";

// =========================================================================
// TASK 1c: CONSERVAZIONE DEI NOMI DEGLI STUDENTI (privacy)
// - Iscrizioni annullate/rifiutate/scadute: l'elenco senza attestati emessi non serve più e si cancella.
// - Dopo MESI_CONSERVAZIONE_STUDENTI dalla fine del progetto o dal giorno dell'evento (o dall'inserimento, se non
//   c'è una data) i nomi si riducono alle iniziali: i codici degli attestati restano verificabili.
// =========================================================================
$conn->query("DELETE pp FROM partecipanti_prenotazione pp JOIN prenotazioni pr ON pp.prenotazione_id = pr.id
              WHERE pr.stato IN ('annullata', 'rifiutata', 'scaduta') AND pp.codice IS NULL");
$cancellati_pp = $conn->affected_rows;
$mesi_cons = (int)MESI_CONSERVAZIONE_STUDENTI;
$conn->query("UPDATE partecipanti_prenotazione pp
              JOIN prenotazioni pr ON pp.prenotazione_id = pr.id
              JOIN turni t ON pr.turno_id = t.id
              LEFT JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
              SET pp.cognome = CONCAT(LEFT(pp.cognome, 1), '.'),
                  pp.nome = IF(pp.nome = '', '', CONCAT(LEFT(pp.nome, 1), '.')),
                  pp.anonimizzato = 1
              WHERE pp.anonimizzato = 0 AND COALESCE(pd.data_fine, t.data_turno, DATE(pp.created_at)) < CURDATE() - INTERVAL $mesi_cons MONTH");
echo "- Elenchi studenti: $cancellati_pp nomi cancellati (iscrizioni annullate), " . $conn->affected_rows . " ridotti alle iniziali dopo $mesi_cons mesi.\n";

// =========================================================================
// TASK 1e: CONSERVAZIONE DEI DATI (art. 5.1.e GDPR) - durate nel file .env, descritte in privacy.php
// - Registri tecnici (accessi con IP e browser, email inviate): CONSERVAZIONE_LOG_MESI, predefinito 12
// - Registro delle azioni amministrative: CONSERVAZIONE_AUDIT_MESI, predefinito 24
// - Prenotazioni: CONSERVAZIONE_PRENOTAZIONI_MESI dopo l'attività (0 = mai). Non si cancellano: nome e cognome
//   ridotti alle iniziali, email/matricola/campi del modulo svuotati, messaggi e allegati eliminati.
//   Restano codice, turno, stato e presenza: statistiche e codici degli attestati continuano a funzionare.
// - Account senza accesso da CONSERVAZIONE_UTENTI_MESI (0 = mai), esclusi amministratori e gestori.
// =========================================================================
$mesi_log   = max(1, (int)(env_valore('CONSERVAZIONE_LOG_MESI') ?? 12));
$mesi_audit = max(1, (int)(env_valore('CONSERVAZIONE_AUDIT_MESI') ?? 24));
@$conn->query("DELETE FROM log_accessi WHERE created_at < NOW() - INTERVAL $mesi_log MONTH");
$tolti_acc = max(0, $conn->affected_rows);
@$conn->query("DELETE FROM log_email WHERE created_at < NOW() - INTERVAL $mesi_log MONTH");
$tolti_em = max(0, $conn->affected_rows);
@$conn->query("DELETE FROM log_attivita WHERE data_ora < NOW() - INTERVAL $mesi_audit MONTH");
$tolti_aud = max(0, $conn->affected_rows);
echo "- Conservazione registri: eliminati $tolti_acc accessi e $tolti_em email più vecchi di $mesi_log mesi, $tolti_aud azioni più vecchie di $mesi_audit mesi.\n";

$mesi_pren = max(0, (int)(env_valore('CONSERVAZIONE_PRENOTAZIONI_MESI') ?? 0));
if ($mesi_pren > 0) {
    $res_an = $conn->query("SELECT pr.id, pr.dati_custom_json FROM prenotazioni pr
                            JOIN turni t ON pr.turno_id = t.id LEFT JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
                            WHERE IFNULL(pr.email, '') <> ''
                              AND COALESCE(pd.data_fine, t.data_turno, DATE(pr.data_prenotazione)) < CURDATE() - INTERVAL $mesi_pren MONTH
                            LIMIT 500");
    $n_an = 0;
    while ($res_an && $pa = $res_an->fetch_assoc()) {
        $id_an = (int)$pa['id'];
        // Allegati caricati nel modulo (solo file dentro uploads/allegati_prenotazioni)
        foreach ((json_decode((string)$pa['dati_custom_json'], true) ?: []) as $val) {
            if (!is_string($val)) continue;
            foreach (array_map('trim', explode(',', $val)) as $perc) {
                if (preg_match('#^uploads/allegati_prenotazioni/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $perc)) @unlink(__DIR__ . '/' . $perc);
            }
        }
        @$conn->query("DELETE FROM messaggi_prenotazioni WHERE prenotazione_id = $id_an");
        $conn->query("UPDATE prenotazioni SET nome = CONCAT(LEFT(nome, 1), '.'), cognome = IF(cognome = '', '', CONCAT(LEFT(cognome, 1), '.')),
                      email = '', matricola = '', dati_custom_json = NULL, utente_id = NULL WHERE id = $id_an");
        $n_an++;
    }
    echo "- Conservazione prenotazioni: $n_an anonimizzate (attività concluse da più di $mesi_pren mesi).\n";
}

// - Tutorato: lettere protocollate o annullate da CONSERVAZIONE_INCARICHI_MESI (0 = mai) senza dati personali, PDF e registro;
//   convocazioni delle sedute (link, email, motivi delle assenze) cancellate dopo CONSERVAZIONE_CONVOCAZIONI_MESI (predefinito 12)
$mesi_inc = max(0, (int)(env_valore('CONSERVAZIONE_INCARICHI_MESI') ?? 0));
if ($mesi_inc > 0 && function_exists('conserva_dati_tutorato')) echo "- Conservazione tutorato: " . conserva_dati_tutorato($conn, $mesi_inc) . " lettere di incarico senza dati personali (più vecchie di $mesi_inc mesi).\n";
$mesi_conv = max(0, (int)(env_valore('CONSERVAZIONE_CONVOCAZIONI_MESI') ?? 12));
if ($mesi_conv > 0 && function_exists('conserva_dati_sedute')) echo "- Conservazione sedute: " . conserva_dati_sedute($conn, $mesi_conv) . " convocazioni cancellate (sedute di più di $mesi_conv mesi fa).\n";

$mesi_ut = max(0, (int)(env_valore('CONSERVAZIONE_UTENTI_MESI') ?? 0));
if ($mesi_ut > 0) {
    // Esclusi anche i gestori abilitati da "Utenti & Abilitazioni" su un'area o un evento,
    // qualunque sia il loro ruolo (spesso Dipendente): perderebbero l'accesso al pannello
    $ids_gestori = [];
    foreach (['pagine_eventi' => 'gestore_utente_id', 'eventi' => '0'] as $tab_g => $col_singolo) {
        $r_g = $conn->query("SELECT $col_singolo AS singolo, gestori_utenti_ids, permessi_gestori_json FROM $tab_g");
        while ($r_g && $g = $r_g->fetch_assoc()) {
            foreach (ids_gestori_da_campi($g['singolo'], $g['gestori_utenti_ids'], $g['permessi_gestori_json']) as $id_g) $ids_gestori[$id_g] = true;
        }
    }
    // … e chi ha un perimetro (tutti i progetti / eventi di un'area, Formazione Scuola Lavoro)
    $r_g = @$conn->query("SELECT DISTINCT utente_id FROM abilitazioni_ambito");
    while ($r_g && $g = $r_g->fetch_assoc()) $ids_gestori[(int)$g['utente_id']] = true;
    $esclusi_gestori = $ids_gestori ? ' AND id NOT IN (' . implode(',', array_map('intval', array_keys($ids_gestori))) . ')' : '';
    $cond_ut = "ruolo_id NOT IN (1, 2) AND FIND_IN_SET('1', IFNULL(ruoli_secondari, '')) = 0 AND FIND_IN_SET('2', IFNULL(ruoli_secondari, '')) = 0
                AND COALESCE(ultimo_accesso, '1970-01-01') < NOW() - INTERVAL $mesi_ut MONTH" . $esclusi_gestori;
    // Le prenotazioni restano (collegate all'email, o già anonimizzate): si toglie solo il legame con l'account
    $conn->query("UPDATE prenotazioni SET utente_id = NULL WHERE utente_id IN (SELECT id FROM (SELECT id FROM utenti WHERE $cond_ut) AS x)");
    $conn->query("DELETE FROM utenti WHERE $cond_ut");
    echo "- Conservazione account: " . max(0, $conn->affected_rows) . " account eliminati (nessun accesso da $mesi_ut mesi).\n";
}

// =========================================================================
// TASK 1d: RIEPILOGO SETTIMANALE DELLE EMAIL AGLI AMMINISTRATORI (dal lunedì, una volta a settimana)
// =========================================================================
if (date('N') >= 1) {
    $esito_rep = invia_report_email_settimanale($conn);
    echo "- Riepilogo settimanale email: " . ($esito_rep === true ? "inviato agli amministratori" : $esito_rep) . ".\n";
}

// =========================================================================
// TASK 1h: CONTROLLO AUTOMATICO DEL PORTALE, una volta al giorno dalle 3 di notte
// (pagine, file riservati, spazio su disco, backup, email): avviso agli amministratori solo se qualcosa non va
// =========================================================================
$file_ctrl = __DIR__ . '/cache/controllo_sito.json';
if ((int)date('G') >= 3 && (!is_file($file_ctrl) || date('Y-m-d', filemtime($file_ctrl)) !== date('Y-m-d'))) {
    $st_ctrl = esegui_controllo_sito($conn);
    echo "- Controllo del portale: " . ($st_ctrl['problemi'] ? $st_ctrl['problemi'] . " problemi (amministratori avvisati)" : "tutto a posto") . ".\n";
}

// =========================================================================
// TASK 1f: ANAGRAFE DEL PERSONALE E CORSI DI STUDIO (API del portale di Ateneo), una volta a settimana
// =========================================================================
$file_sync_anag = __DIR__ . '/cache/anagrafe_sync.txt';
if (!is_file($file_sync_anag) || filemtime($file_sync_anag) < time() - 7 * 86400) {
    $esiti_anag = [];
    foreach (sincronizza_anagrafe($conn) as $cod => $es) $esiti_anag[] = "$cod: " . $es['esito'];
    echo "- Anagrafe di Ateneo aggiornata (" . ($esiti_anag ? implode('; ', $esiti_anag) : 'nessuna struttura') . ").\n";
}

// =========================================================================
// TASK 2: PROMEMORIA PRE-EVENTO E ALTRE AUTOMAZIONI
// =========================================================================
$file_reminders = __DIR__ . '/admin/cron_reminders.php';
if (file_exists($file_reminders)) {
    ob_start();
    include $file_reminders;
    ob_end_clean();
    echo "- Promemoria (admin/cron_reminders.php) eseguiti.\n";
}

echo "Esecuzione CRON terminata con successo.\n";

// Rilascio esplicito del lock (viene comunque rilasciato dal sistema alla chiusura dello script)
flock($lock_handle_bg, LOCK_UN);
fclose($lock_handle_bg);
?>
