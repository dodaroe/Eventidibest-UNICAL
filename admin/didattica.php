<?php
// didattica.php - Modulo Didattica (Ufficio didattico):
// - Pratiche: elenco con filtri, dettaglio, stato, messaggi, istruttoria (campi dell'ufficio, seduta, delibera), Excel e Word;
// - Sedute e verbali: sedute del Consiglio con o.d.g. e presenze, pratiche assegnate, verbale in Word ed Excel;
// - Moduli e documenti: documenti da scaricare e moduli online (campi guidati dalle anagrafi, tabelle, campi dell'ufficio,
//   come compaiono nel verbale), con modelli pronti;
// - Ufficio e ricevimento: operatori scelti dall'anagrafe di Ateneo con i loro compiti, sportello di ricevimento dell'ufficio.
// Pagine pubbliche: modulistica.php, modulo.php, pratiche.php.
require_once 'admin_header.php';

if (!$puo_didattica) nega_accesso();
function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tab = in_array($_GET['tab'] ?? '', ['pratiche', 'sedute', 'moduli', 'ufficio', 'statistiche'], true) ? $_GET['tab'] : 'pratiche';
$base = "didattica.php?p_id=" . (int)$filtro_p;
$uid = (int)$u_id_curr;
$ids_post = fn() => array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
$autore_nome = nome_autore_ufficio($conn, $utente_admin);
$io_operatore = operatore_ufficio($conn, 0, $utente_admin);
// Referenti dei consigli (senza gli altri permessi della Didattica): solo le sedute dei loro consigli
$solo_ref = !$puo_didattica_tutto;
if ($solo_ref) { $tab = 'sedute'; $autore_nome = trim(($utente_admin['nome'] ?? '') . ' ' . ($utente_admin['cognome'] ?? '')) . ' · Referente del consiglio'; }
$consigli = consigli_didattica($conn);
$puo_consiglio = fn(int $cid) => $puo_didattica_tutto || in_array($cid, $consigli_referente, true);
$puo_seduta = fn(?array $s) => $s && ($puo_didattica_tutto || in_array((int)$s['consiglio_id'], $consigli_referente, true));

// ==============================================================================
// AZIONI
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    // I referenti dei consigli fanno solo le azioni delle sedute (ognuna controlla anche il consiglio)
    if ($solo_ref && !array_intersect(array_keys($_POST), ['salva_seduta', 'elimina_seduta', 'assegna_seduta', 'salva_presenze', 'salva_decisioni', 'applica_esiti', 'aggiungi_persona_consiglio', 'togli_persona_consiglio', 'salva_qualifiche'])) nega_accesso();

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
        $x = $conn->query("SELECT * FROM didattica_consigli_persone WHERE id = $pid_c")->fetch_assoc();
        if (!$x || !$puo_consiglio((int)$x['consiglio_id']) || ($x['ruolo'] === 'referente' && $solo_ref)) nega_accesso();
        $conn->query("DELETE FROM didattica_consigli_persone WHERE id = $pid_c");
        registra_log_audit($conn, "Consiglio: persona tolta", ["Consiglio" => $x['consiglio_id'], "Persona" => $x['nominativo']]);
        flash_set($x['nominativo'] . " tolto dal consiglio (resta nelle sedute già registrate).", 'warning');
        admin_redirect("$base&tab=sedute&consiglio=" . (int)$x['consiglio_id'] . "&r=" . time());
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
    if (isset($_POST['richiedi_operatore'])) {
        $id = (int)$_POST['pratica_id'];
        $err = richiedi_a_operatore($conn, $id, (int)($_POST['operatore_prec'] ?? 0), (string)($_POST['testo_operatore'] ?? ''), $uid, $autore_nome);
        flash_set($err ?? "Richiesta inviata: l'operatore riceve un'email e integra la pratica.", $err ? 'danger' : 'success');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }

    // ── Pratiche: stato, integrazioni, messaggi, attività, iter, istruttoria, seduta ──
    if (isset($_POST['stato_pratica'])) {
        $id = (int)$_POST['pratica_id'];
        $ok = cambia_stato_pratica($conn, $id, (string)$_POST['stato_pratica'], mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 2000), $uid, $autore_nome);
        if ($ok) registra_log_audit($conn, "Pratica: cambio di stato", ["Pratica" => $id, "Stato" => $_POST['stato_pratica']]);
        flash_set($ok ? (in_array($_POST['stato_pratica'], ['accolta', 'respinta', 'chiusa'], true) ? "Stato aggiornato: lo studente riceve un'email." : "Stato aggiornato.") : "Stato non valido.", $ok ? 'success' : 'danger');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['richiedi_integrazione'])) {
        $id = (int)$_POST['pratica_id'];
        $testo_r = mb_substr(trim((string)($_POST['testo_richiesta'] ?? '')), 0, 3000);
        if ($testo_r === '') flash_set("Scrivi cosa deve integrare o dichiarare lo studente.", 'danger');
        else {
            cambia_stato_pratica($conn, $id, 'integrazione', '', $uid, $autore_nome, ['tipo' => (string)($_POST['tipo_richiesta'] ?? 'documenti'), 'testo' => $testo_r]);
            registra_log_audit($conn, "Pratica: integrazione richiesta", ["Pratica" => $id, "Tipo" => $_POST['tipo_richiesta'] ?? '']);
            flash_set("Richiesta inviata allo studente: la vede nella pratica e riceve un'email.");
        }
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['messaggio_pratica']) || isset($_POST['attivita_pratica'])) {
        $id = (int)$_POST['pratica_id'];
        $att = isset($_POST['attivita_pratica']);
        $interno = $att ? empty($_POST['visibile']) : !empty($_POST['interno']);
        $err = messaggio_pratica($conn, $id, 'ufficio', $uid, (string)($_POST['testo'] ?? ''), $_FILES['allegato'] ?? null, $interno, $att ? 'attivita' : 'messaggio', $autore_nome);
        flash_set($err ?? ($att ? "Attività aggiunta alla pratica." : ($interno ? "Nota interna salvata (lo studente non la vede)." : "Messaggio inviato allo studente.")), $err ? 'danger' : 'success');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['assegna_pratica'])) {
        $id = (int)$_POST['pratica_id'];
        $err = assegna_pratica($conn, $id, (int)($_POST['operatore_id'] ?? 0), (int)($_POST['passo'] ?? 1), (string)($_POST['nota_passaggio'] ?? ''), $uid, $autore_nome);
        if (!$err) registra_log_audit($conn, "Pratica: assegnata", ["Pratica" => $id, "Operatore" => $_POST['operatore_id'] ?? '']);
        flash_set($err ?? "Pratica assegnata: l'operatore riceve un'email, lo studente vede il passaggio.", $err ? 'danger' : 'success');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['salva_istruttoria'])) {
        $id = (int)$_POST['pratica_id'];
        $p = pratica($conn, $id);
        if ($p) {
            $m = modulo_didattica($conn, (int)$p['modulo_id']);
            [$ris_u] = leggi_risposte_modulo(campi_ufficio(campi_modulo($m['campi_json'] ?? '')), $conn);
            $json_u = $ris_u ? json_encode($ris_u, JSON_UNESCAPED_UNICODE) : null;
            $sed = (int)($_POST['seduta_id'] ?? 0) ?: null;
            $del = mb_substr(trim((string)($_POST['delibera'] ?? '')), 0, 5000);
            $st = $conn->prepare("UPDATE pratiche SET ufficio_json = ?, seduta_id = ?, delibera = ?, protocollo = ?, protocollo_data = ?, aggiornata_il = NOW() WHERE id = ?");
            $prot = mb_substr(trim((string)($_POST['protocollo'] ?? '')), 0, 100);
            $prot_d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['protocollo_data'] ?? '')) ? $_POST['protocollo_data'] : null;
            $st->bind_param("sisssi", $json_u, $sed, $del, $prot, $prot_d, $id); $st->execute();
            registra_log_audit($conn, "Pratica: istruttoria salvata", ["Pratica" => $p['codice']]);
            flash_set("Istruttoria salvata.");
        }
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['assegna_seduta'])) {
        $ids = $ids_post(); $sed = (int)($_POST['seduta_id'] ?? 0);
        if ($solo_ref) {
            // Referente: solo verso le sedute del suo consiglio e solo pratiche libere o già nelle sue sedute
            if ($sed && !$puo_seduta(seduta_didattica($conn, $sed))) nega_accesso();
            $mie_s = array_column($conn->query("SELECT id FROM didattica_sedute WHERE consiglio_id IN (" . implode(',', array_map('intval', $consigli_referente ?: [0])) . ")")->fetch_all(MYSQLI_ASSOC), 'id');
            $ids = array_values(array_filter($ids, function ($i) use ($conn, $mie_s, $sed) { $x = pratica($conn, $i); return $x && ($x['seduta_id'] === null ? $sed > 0 : in_array((int)$x['seduta_id'], array_map('intval', $mie_s), true)); }));
        }
        if ($ids && ($sed === 0 || seduta_didattica($conn, $sed))) {
            $conn->query("UPDATE pratiche SET seduta_id = " . ($sed ?: 'NULL') . " WHERE id IN (" . implode(',', $ids) . ")");
            flash_set(count($ids) . ($sed ? " pratiche portate alla seduta." : " pratiche tolte dalla seduta."));
        } else flash_set("Scegli le pratiche e la seduta.", 'warning');
        admin_redirect((string)($_POST['torna'] ?? "$base&tab=pratiche") . '&r=' . time());
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
        $conn->query("DELETE FROM didattica_sedute_presenze WHERE seduta_id = $id");
        $conn->query("UPDATE pratiche SET seduta_id = NULL WHERE seduta_id = $id");
        $conn->query("DELETE FROM didattica_sedute WHERE id = $id");
        flash_set("Seduta eliminata (le pratiche restano, senza seduta).", 'warning');
        admin_redirect("$base&tab=sedute");
    }

    // ── Moduli: salvataggio ed eliminazione ──
    if (isset($_POST['salva_modulo'])) {
        $id = (int)($_POST['modulo_id'] ?? 0);
        $titolo = mb_substr(trim((string)($_POST['titolo'] ?? '')), 0, 200);
        if ($titolo === '') { flash_set("Il titolo è obbligatorio.", 'danger'); admin_redirect("$base&tab=moduli&" . ($id ? "modifica=$id" : "nuovo=1")); }
        $tipo = ($_POST['tipo'] ?? '') === 'online' ? 'online' : 'documento';
        $cat = mb_substr(trim((string)($_POST['categoria'] ?? '')), 0, 100) ?: 'Altro';
        $descr = mb_substr(trim(strip_tags((string)($_POST['descrizione'] ?? ''), '<p><br><strong><em><ul><ol><li><a>')), 0, 5000);
        $dest = isset(DESTINATARI_MODULO[$_POST['destinatari'] ?? '']) ? $_POST['destinatari'] : 'tutti';
        $emails = implode(', ', array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($_POST['email_ufficio'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $link = trim((string)($_POST['link'] ?? ''));
        if ($link !== '' && !preg_match('#^https?://#i', $link)) $link = 'https://' . $link;
        if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) $link = '';
        $attivo = isset($_POST['attivo']) ? 1 : 0; $ordine = (int)($_POST['ordine'] ?? 0);
        // Campi del modulo online (righe del costruttore)
        $campi = [];
        foreach ((array)($_POST['c_etichetta'] ?? []) as $i => $et) {
            $et = trim((string)$et); if ($et === '') continue;
            $campo = ['etichetta' => $et, 'tipo' => (string)($_POST['c_tipo'][$i] ?? 'text'), 'opzioni' => (string)($_POST['c_opzioni'][$i] ?? ''),
                      'obbligatorio' => ($_POST['c_obbl'][$i] ?? '0') === '1', 'ufficio' => ($_POST['c_uff'][$i] ?? '0') === '1', 'aiuto' => (string)($_POST['c_aiuto'][$i] ?? '')];
            // Logica: "mostra solo se" e "compila in automatico se" (l'altro campo è indicato con la sua domanda)
            $rif = trim((string)($_POST['c_cond_campo'][$i] ?? ''));
            if ($rif !== '' && isset(OPERATORI_CONDIZIONE[$_POST['c_cond_op'][$i] ?? ''])) $campo['cond'] = ['campo' => $rif, 'op' => $_POST['c_cond_op'][$i], 'valore' => (string)($_POST['c_cond_val'][$i] ?? '')];
            $rif = trim((string)($_POST['c_auto_campo'][$i] ?? ''));
            if ($rif !== '' && isset(OPERATORI_CONDIZIONE[$_POST['c_auto_op'][$i] ?? ''])) $campo['auto'] = ['campo' => $rif, 'op' => $_POST['c_auto_op'][$i], 'valore' => (string)($_POST['c_auto_val'][$i] ?? ''), 'imposta' => (string)($_POST['c_auto_imposta'][$i] ?? '')];
            $campi[] = $campo;
        }
        $campi_json = $campi ? json_encode($campi, JSON_UNESCAPED_UNICODE) : null;
        $verbale = [];
        foreach (['sezione' => 200, 'stile' => 10, 'intro' => 3000, 'testo' => 3000, 'delibera' => 3000, 'chiusura' => 3000, 'colonne' => 500, 'raggruppa' => 200, 'decisione' => 20] as $k => $max) $verbale[$k] = mb_substr(trim((string)($_POST['v_' . $k] ?? '')), 0, $max);
        $verbale_json = json_encode($verbale, JSON_UNESCAPED_UNICODE);
        $iter = [];
        foreach ((array)($_POST['iter'] ?? []) as $x) if (($id_u = ufficio_didattica_id($conn, (int)$x)) && !(int)(uffici_didattica($conn)[$id_u]['smista'] ?? 0)) $iter[] = $id_u;
        $iter_json = $iter ? json_encode($iter) : null;
        $dal_m = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['aperto_dal'] ?? '')) ? $_POST['aperto_dal'] : null;
        $al_m = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['aperto_al'] ?? '')) ? $_POST['aperto_al'] : null;
        $gg_m = max(0, min(90, (int)($_POST['giorni_promemoria'] ?? 7)));
        if ($id) {
            $st = $conn->prepare("UPDATE didattica_moduli SET titolo=?, categoria=?, descrizione=?, tipo=?, link=?, campi_json=?, verbale_json=?, iter_json=?, destinatari=?, email_ufficio=?, attivo=?, ordine=?, aperto_dal=?, aperto_al=?, giorni_promemoria=?, aggiornato_il=NOW() WHERE id=?");
            $st->bind_param("ssssssssssiissii", $titolo, $cat, $descr, $tipo, $link, $campi_json, $verbale_json, $iter_json, $dest, $emails, $attivo, $ordine, $dal_m, $al_m, $gg_m, $id); $st->execute();
        } else {
            $st = $conn->prepare("INSERT INTO didattica_moduli (titolo, categoria, descrizione, tipo, link, campi_json, verbale_json, iter_json, destinatari, email_ufficio, attivo, ordine, aperto_dal, aperto_al, giorni_promemoria, aggiornato_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $st->bind_param("ssssssssssiissi", $titolo, $cat, $descr, $tipo, $link, $campi_json, $verbale_json, $iter_json, $dest, $emails, $attivo, $ordine, $dal_m, $al_m, $gg_m); $st->execute();
            $id = (int)$conn->insert_id;
        }
        // Documento da scaricare (pubblico)
        if (($_FILES['file_modulo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $fn = secure_upload($_FILES['file_modulo'], RADICE_SITO . '/' . DIR_MODULISTICA, ['pdf', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'rtf'],
                ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text',
                 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.oasis.opendocument.spreadsheet', 'text/rtf', 'application/rtf', 'application/zip', 'application/octet-stream']);
            if ($fn) {
                $vecchio = modulo_didattica($conn, $id)['file_path'] ?? null;
                $path = DIR_MODULISTICA . $fn;
                $st = $conn->prepare("UPDATE didattica_moduli SET file_path = ? WHERE id = ?"); $st->bind_param("si", $path, $id); $st->execute();
                if ($vecchio && is_file(RADICE_SITO . '/' . $vecchio)) @unlink(RADICE_SITO . '/' . $vecchio);
            } else flash_set("Il file non è stato caricato: usa PDF, Word, LibreOffice o Excel.", 'warning');
        }
        registra_log_audit($conn, "Modulo della didattica salvato", ["Titolo" => $titolo, "Tipo" => $tipo]);
        if (!isset($_SESSION['_flash'])) flash_set("Modulo \"$titolo\" salvato.");
        admin_redirect("$base&tab=moduli");
    }
    if (isset($_POST['elimina_modulo'])) {
        $id = (int)$_POST['elimina_modulo'];
        $n = (int)$conn->query("SELECT COUNT(*) n FROM pratiche WHERE modulo_id = $id")->fetch_assoc()['n'];
        if ($n > 0) {
            $conn->query("UPDATE didattica_moduli SET attivo = 0 WHERE id = $id");
            flash_set("Il modulo ha $n pratiche: non l'ho eliminato ma nascosto agli studenti.", 'warning');
        } else {
            $m = modulo_didattica($conn, $id);
            if ($m && $m['file_path'] && is_file(RADICE_SITO . '/' . $m['file_path'])) @unlink(RADICE_SITO . '/' . $m['file_path']);
            $conn->query("DELETE FROM didattica_moduli WHERE id = $id");
            registra_log_audit($conn, "Modulo della didattica eliminato", ["ID" => $id]);
            flash_set("Modulo eliminato.", 'warning');
        }
        admin_redirect("$base&tab=moduli");
    }

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
        $n_op = (int)$conn->query("SELECT COUNT(*) n FROM ufficio_didattica WHERE ufficio_id = $id_u")->fetch_assoc()['n'];
        $in_iter = 0;
        foreach ($conn->query("SELECT iter_json FROM didattica_moduli WHERE iter_json IS NOT NULL")->fetch_all(MYSQLI_ASSOC) as $mi)
            foreach (json_decode((string)$mi['iter_json'], true) ?: [] as $x) if (ufficio_didattica_id($conn, $x) === $id_u) $in_iter++;
        $n_pr = (int)$conn->query("SELECT COUNT(*) n FROM pratiche p JOIN ufficio_didattica o ON o.id = p.assegnata_a WHERE o.ufficio_id = $id_u AND p.stato NOT IN ('accolta', 'respinta', 'chiusa')")->fetch_assoc()['n'];
        if ($n_op || $in_iter) flash_set("L'ufficio non si può eliminare: " . ($n_op ? "ha $n_op persone (spostale in un altro ufficio)" : '') . ($n_op && $in_iter ? ' e ' : '') . ($in_iter ? "è nell'iter di $in_iter moduli" : '') . ".", 'warning');
        else { $conn->query("DELETE FROM didattica_uffici WHERE id = $id_u"); flash_set("Ufficio eliminato.", 'warning'); }
        admin_redirect("$base&tab=ufficio&r=" . time());
    }
    if (isset($_POST['togli_operatore'])) {
        $id = (int)$_POST['togli_operatore'];
        $conn->query("DELETE FROM ufficio_didattica WHERE id = $id");
        registra_log_audit($conn, "Ufficio didattico: operatore tolto", ["ID" => $id]);
        flash_set("Operatore tolto dall'Ufficio didattico.", 'warning');
        admin_redirect("$base&tab=ufficio&r=" . time());
    }
    if (isset($_POST['crea_sportello'])) {
        $pag = (int)($_POST['pagina_id'] ?? 0);
        $ok_area = $pag && tipo_area($conn->query("SELECT * FROM pagine_eventi WHERE id = $pag")->fetch_assoc() ?: []) === 'calendario';
        $rid = $ok_area ? crea_sportello_ufficio($conn, $pag, (string)($_POST['nome'] ?? ''), (string)($_POST['luogo'] ?? '')) : 0;
        if ($rid) registra_log_audit($conn, "Ufficio didattico: sportello di ricevimento creato", ["Risorsa" => $rid]);
        flash_set($rid ? "Sportello creato: ora imposta giorni e orari del ricevimento." : "Scegli un'area di Prenotazioni e risorse.", $rid ? 'success' : 'danger');
        admin_redirect("$base&tab=ufficio&r=" . time());
    }
}

// ==============================================================================
// DATI
// ==============================================================================
$moduli = $conn->query("SELECT m.*, (SELECT COUNT(*) FROM pratiche p WHERE p.modulo_id = m.id) AS n_pratiche,
                               (SELECT COUNT(*) FROM pratiche p WHERE p.modulo_id = m.id AND p.stato IN ('inviata', 'in_lavorazione', 'integrazione')) AS n_aperte
                        FROM didattica_moduli m ORDER BY m.categoria, m.ordine, m.titolo")->fetch_all(MYSQLI_ASSOC);
$categorie = array_values(array_unique(array_column($moduli, 'categoria')));
$n_aperte = (int)$conn->query("SELECT COUNT(*) n FROM pratiche WHERE stato IN ('inviata', 'in_lavorazione', 'integrazione')")->fetch_assoc()['n'];
$sedute = $conn->query("SELECT s.*, (SELECT COUNT(*) FROM pratiche p WHERE p.seduta_id = s.id) AS n_pratiche FROM didattica_sedute s"
                       . ($solo_ref ? " WHERE s.consiglio_id IN (" . implode(',', array_map('intval', $consigli_referente ?: [0])) . ")" : '') . " ORDER BY s.data DESC, s.id DESC")->fetch_all(MYSQLI_ASSOC);
$sedute_future = array_values(array_filter($sedute, fn($s) => !$s['data'] || $s['data'] >= date('Y-m-d', strtotime('-60 days'))));
$operatori = operatori_ufficio($conn);

// Filtri dell'elenco delle pratiche (servono anche per l'esportazione)
$f_stato = isset(STATI_PRATICA[$_GET['stato'] ?? '']) ? $_GET['stato'] : (in_array($_GET['stato'] ?? '', ['tutte', 'aperte'], true) ? $_GET['stato'] : 'aperte');
$f_mod = (int)($_GET['modulo'] ?? 0); $f_q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$f_sed = (string)($_GET['seduta'] ?? '');
$f_car = in_array($_GET['carico'] ?? '', ['me', 'smistare', 'seguite'], true) ? $_GET['carico'] : '';
$f_dal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['dal'] ?? '')) ? $_GET['dal'] : '';
$f_al = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['al'] ?? '')) ? $_GET['al'] : '';
$elenco_pratiche = function () use ($conn, $f_stato, $f_mod, $f_q, $f_sed, $f_dal, $f_al, $f_car, $io_operatore) {
    $where = ['1=1']; $tipi = ''; $par = [];
    if ($f_stato === 'aperte') $where[] = "p.stato IN ('inviata', 'in_lavorazione', 'integrazione')"; elseif ($f_stato !== 'tutte') { $where[] = "p.stato = ?"; $tipi .= 's'; $par[] = $f_stato; }
    if ($f_mod) $where[] = "p.modulo_id = $f_mod";
    if ($f_sed === 'nessuna') $where[] = "p.seduta_id IS NULL"; elseif ((int)$f_sed > 0) $where[] = "p.seduta_id = " . (int)$f_sed;
    if ($f_car === 'me') $where[] = "p.assegnata_a = " . (int)($io_operatore['id'] ?? -1);
    elseif ($f_car === 'seguite') $where[] = "p.id IN (SELECT pratica_id FROM pratiche_operatori WHERE operatore_id = " . (int)($io_operatore['id'] ?? -1) . ") AND (p.assegnata_a IS NULL OR p.assegnata_a <> " . (int)($io_operatore['id'] ?? -1) . ")";
    elseif ($f_car === 'smistare') $where[] = "p.assegnata_a IS NULL AND p.stato NOT IN ('accolta', 'respinta', 'chiusa')";
    if ($f_dal !== '') { $where[] = "p.creata_il >= ?"; $tipi .= 's'; $par[] = "$f_dal 00:00:00"; }
    if ($f_al !== '') { $where[] = "p.creata_il <= ?"; $tipi .= 's'; $par[] = "$f_al 23:59:59"; }
    if ($f_q !== '') { $like = '%' . addcslashes($f_q, '%_\\') . '%'; $where[] = "(p.nome LIKE ? OR p.cognome LIKE ? OR p.email LIKE ? OR p.codice LIKE ? OR p.matricola LIKE ?)"; $tipi .= 'sssss'; array_push($par, $like, $like, $like, $like, $like); }
    $st = $conn->prepare("SELECT p.*, m.titolo AS modulo_titolo, s.data AS seduta_data FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id LEFT JOIN didattica_sedute s ON s.id = p.seduta_id WHERE " . implode(' AND ', $where) . " ORDER BY p.aggiornata_il DESC LIMIT 1000");
    if ($par) $st->bind_param($tipi, ...$par);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
};

// ── Esportazioni (Excel e Word): scartano la pagina già prodotta dall'intestazione ──
if (isset($_GET['esporta']) && in_array($_GET['esporta'], ['xlsx', 'docx'], true)) {
    $seduta_exp = $tab === 'sedute' ? seduta_didattica($conn, (int)($_GET['id'] ?? 0)) : null;
    if ($solo_ref && !$puo_seduta($seduta_exp)) nega_accesso();
    if ($seduta_exp) $ids = array_column($conn->query("SELECT id FROM pratiche WHERE seduta_id = " . (int)$seduta_exp['id'])->fetch_all(MYSQLI_ASSOC), 'id');
    elseif (!empty($_GET['ids'])) $ids = array_map('intval', explode(',', (string)$_GET['ids']));
    else $ids = array_column($elenco_pratiche(), 'id');
    $pr_exp = pratiche_per_esportazione($conn, $ids);
    $nome_f = ($seduta_exp ? 'Verbale_' . ($seduta_exp['data'] ? date('d_m_Y', strtotime($seduta_exp['data'])) : 'seduta') : 'Pratiche_' . date('d_m_Y'));
    $file = $_GET['esporta'] === 'xlsx' ? genera_excel_pratiche($conn, $pr_exp) : genera_verbale_pratiche($conn, $seduta_exp, $pr_exp);
    if ($file) {
        registra_log_audit($conn, "Pratiche esportate", ["Formato" => $_GET['esporta'], "Pratiche" => count($pr_exp)]);
        invia_file_scaricabile($file, ($_GET['esporta'] === 'xlsx' ? str_replace('Verbale_', 'Pratiche_seduta_', $nome_f) : $nome_f) . '.' . $_GET['esporta'],
            $_GET['esporta'] === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    flash_set("Esportazione non riuscita (manca l'estensione ZIP di PHP).", 'danger');
}
?>
<style>
.dd-ev { border-left: 3px solid #e2e8f0; padding: 4px 0 10px 14px; position: relative; }
.dd-ev::before { content: ''; position: absolute; left: -7px; top: 6px; width: 11px; height: 11px; border-radius: 50%; background: #94a3b8; }
.dd-ev.uff::before { background: #047857; } .dd-ev.stu::before { background: #0056B3; }
.dd-campo { display: grid; grid-template-columns: 2fr 1.4fr 2fr auto auto 1.6fr 34px 34px; gap: .4rem; align-items: center; margin-bottom: .4rem; padding: .3rem; border-radius: 6px; }
.dd-campo.dd-testa { margin-bottom: 0; padding-bottom: 0; }
.dd-logica { grid-column: 1 / -1; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 6px; padding: .5rem; }
.dd-col-riga { display: grid; grid-template-columns: 2fr 1.6fr 2fr 30px; gap: .3rem; margin-bottom: .25rem; }
.dd-campo.uff { background: #ecfdf5; }
@media (max-width: 991.98px) { .dd-campo { grid-template-columns: 1fr 1fr; } }
.dd-preset { border: 1px dashed #94a3b8; border-radius: 10px; padding: .6rem .8rem; background: #f8fafc; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-folder-open me-2" style="color:#047857;" aria-hidden="true"></i>Didattica · Ufficio didattico</h4>
    <a href="../modulistica.php" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Pagina pubblica della modulistica</a>
</div>
<ul class="nav nav-pills gap-1 mb-3" style="--bs-nav-pills-link-active-bg:#047857;">
    <?php foreach (['pratiche' => ['fa-inbox', 'Pratiche' . ($n_aperte ? " <span class='badge bg-warning text-dark'>$n_aperte aperte</span>" : '')],
                    'sedute' => ['fa-gavel', 'Sedute e verbali (' . count($sedute) . ')'],
                    'moduli' => ['fa-file-lines', 'Moduli e documenti (' . count($moduli) . ')'],
                    'ufficio' => ['fa-people-group', 'Ufficio e ricevimento'],
                    'statistiche' => ['fa-chart-column', 'Statistiche']] as $k => [$ico, $txt]): ?>
        <?php if ($solo_ref && $k !== 'sedute') continue; ?>
        <li class="nav-item"><a class="nav-link fw-bold<?php echo $tab === $k ? ' active' : ' bg-light text-dark'; ?>" href="<?php echo $base; ?>&amp;tab=<?php echo $k; ?>"><i class="fa <?php echo $ico; ?> me-1" aria-hidden="true"></i><?php echo $txt; ?></a></li>
    <?php endforeach; ?>
    <?php if ($puo_tutorato): ?><li class="nav-item"><a class="nav-link fw-bold bg-light text-dark" href="tutorato.php?p_id=<?php echo (int)$filtro_p; ?>"><i class="fa fa-user-graduate me-1" aria-hidden="true"></i>Tutorato · lettere di incarico</a></li><?php endif; ?>
</ul>

<?php if ($tab === 'pratiche'):
    $id_sel = (int)($_GET['id'] ?? 0);
    $p = $id_sel ? pratica($conn, $id_sel) : null;
    if ($p):
        $risposte = json_decode((string)$p['risposte_json'], true) ?: [];
        $eventi = $conn->query("SELECT * FROM pratiche_eventi WHERE pratica_id = " . (int)$p['id'] . " ORDER BY creato_il, id")->fetch_all(MYSQLI_ASSOC);
        $m_p = modulo_didattica($conn, (int)$p['modulo_id']);
        $c_uff = campi_ufficio(campi_modulo($m_p['campi_json'] ?? ''));
        $val_uff = [];
        foreach (json_decode((string)$p['ufficio_json'], true) ?: [] as $r) $val_uff[mb_strtolower($r['etichetta'])] = ($r['tipo'] ?? '') === 'tabella' ? ($r['righe'] ?? []) : (string)$r['valore'];
        $v_m = verbale_modulo($m_p ?: ['titolo' => $p['modulo_titolo']]);
        $passi = passi_pratica($m_p);
        $passo_dopo = min(count($passi) - 1, max(1, (int)$p['passo'] + 1));
        $o_carico = $p['assegnata_a'] ? operatore_ufficio($conn, (int)$p['assegnata_a']) : null;
        $conclusa = in_array($p['stato'], ['accolta', 'respinta', 'chiusa'], true);
        $richiesta = json_decode((string)($p['richiesta_json'] ?? ''), true) ?: null;
        $tipi_ev = ['richiesta' => ['fa-people-arrows', 'Richiesta a un operatore'], 'passaggio' => ['fa-route', 'Passaggio'], 'attivita' => ['fa-paperclip', 'Attività'], 'autodich' => ['fa-file-signature', 'Autodichiarazione'], 'messaggio' => ['fa-comment', ''], 'stato' => ['fa-flag', '']];
?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>&amp;tab=pratiche" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le pratiche</a></nav>

    <div class="card border-0 shadow-sm mb-3" style="border-left:4px solid #0056B3 !important;"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="fa fa-route me-1 text-primary" aria-hidden="true"></i>Iter della pratica</h6>
            <span class="small text-secondary"><?php echo $o_carico ? 'In carico a <strong>' . $h(etichetta_operatore($o_carico)) . '</strong>' . ($io_operatore && (int)$io_operatore['id'] === (int)$o_carico['id'] ? ' <span class="badge bg-success">a te</span>' : '') : ($conclusa ? 'Conclusa' : '<span class="badge bg-warning text-dark">da smistare</span>'); ?>
                <?php if ($io_operatore && (int)$io_operatore['id'] !== (int)$p['assegnata_a'] && in_array((int)$io_operatore['id'], operatori_pratica($conn, (int)$p['id']), true)): ?> · <span class="badge bg-light text-dark border" title="Puoi aggiungere documenti e note: chi l'ha in carico viene avvisato"><i class="fa fa-clock-rotate-left me-1" aria-hidden="true"></i>l'hai avuta in carico</span><?php endif; ?></span>
        </div>
        <?php echo html_iter_pratica($conn, $p, $m_p); ?>
        <?php if (!$conclusa): $sugg = operatori_suggeriti($conn, $p, $m_p, $passo_dopo); ?>
        <form method="POST" class="row g-2 align-items-end mt-2">
            <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="itPasso"><?php echo $p['assegnata_a'] ? 'Passa al passo' : 'Smista al passo'; ?></label>
                <select class="form-select form-select-sm" id="itPasso" name="passo"><?php foreach ($passi as $i => $n): if ($i === 0) continue; ?><option value="<?php echo $i; ?>"<?php echo $i === $passo_dopo ? ' selected' : ''; ?>><?php echo $i . '. ' . $h($n); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="itOp">Operatore</label>
                <select class="form-select form-select-sm" id="itOp" name="operatore_id" required><option value="">--</option>
                    <?php foreach ($sugg as $o): ?><option value="<?php echo (int)$o['id']; ?>"><?php echo ($o['_consigliato'] ? '★ ' : '') . $h(etichetta_operatore($o)); ?></option><?php endforeach; ?></select>
                <?php if (!$operatori): ?><div class="form-text text-danger">Aggiungi prima gli operatori in «Ufficio e ricevimento».</div><?php endif; ?></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="itNota">Nota per l'operatore (interna)</label><input type="text" class="form-control form-control-sm" id="itNota" name="nota_passaggio" maxlength="3000" placeholder="facoltativa"></div>
            <div class="col-md-2"><button type="submit" name="assegna_pratica" value="1" class="btn btn-sm btn-primary fw-bold w-100"><i class="fa fa-share me-1" aria-hidden="true"></i><?php echo $p['assegnata_a'] ? 'Passa' : 'Smista'; ?></button></div>
            <div class="col-12 small text-secondary">★ = persona dell'ufficio previsto per quel passo (e che segue il corso della pratica). Chi riceve la pratica ha un'email; lo studente vede il passaggio, non la nota.</div>
        </form>
        <?php endif; ?>
    </div></div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
                    <div><h5 class="fw-bold mb-0"><?php echo $h($p['modulo_titolo']); ?></h5><div class="small text-secondary font-monospace"><?php echo $h($p['codice']); ?> · inviata il <?php echo date('d/m/Y H:i', strtotime($p['creata_il'])); ?><?php echo ($p['protocollo'] ?? '') !== '' ? ' · prot. ' . $h($p['protocollo']) . (!empty($p['protocollo_data']) ? ' del ' . date('d/m/Y', strtotime($p['protocollo_data'])) : '') : ''; ?></div></div>
                    <div class="d-flex gap-1 align-items-start"><?php echo badge_stato_pratica($p['stato']); ?>
                        <a class="btn btn-sm btn-outline-success py-0" href="<?php echo $base; ?>&amp;tab=pratiche&amp;esporta=xlsx&amp;ids=<?php echo (int)$p['id']; ?>" title="Excel"><i class="fa fa-file-excel" aria-hidden="true"></i><span class="visually-hidden">Excel</span></a>
                        <a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $base; ?>&amp;tab=pratiche&amp;esporta=docx&amp;ids=<?php echo (int)$p['id']; ?>" title="Word"><i class="fa fa-file-word" aria-hidden="true"></i><span class="visually-hidden">Word</span></a></div>
                </div>
                <p class="mb-3"><strong><?php echo $h(trim($p['cognome'] . ' ' . $p['nome'])); ?></strong> · <a href="mailto:<?php echo $h($p['email']); ?>"><?php echo $h($p['email']); ?></a><?php echo $p['matricola'] !== '' ? ' · matricola ' . $h($p['matricola']) : ''; ?></p>
                <dl class="row small mb-0">
                    <?php foreach ($risposte as $i => $r): if (!empty($r['nascosto'])) continue; ?>
                        <dt class="col-sm-4"><?php echo $h($r['etichetta']); ?></dt>
                        <dd class="col-sm-8"><?php if (!empty($r['file'])): ?><a href="../allegato_pratica.php?p=<?php echo (int)$p['id']; ?>&amp;r=<?php echo $i; ?>"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><?php echo $h($r['nome_file']); ?></a><?php else: echo html_risposta_pratica($r); endif; ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div></div>

            <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #047857 !important;"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <h6 class="fw-bold"><i class="fa fa-scale-balanced me-1 text-success" aria-hidden="true"></i>Istruttoria e verbale <span class="small text-secondary fw-normal">(non visibile allo studente)</span></h6>
                <?php echo html_datalist_didattica($conn, $c_uff); foreach ($c_uff as $c): $c['obbligatorio'] = false; echo html_campo_pratica($c, $val_uff[mb_strtolower($c['etichetta'])] ?? '', $conn); endforeach; ?>
                <div class="row g-2">
                    <div class="col-md-7"><label class="form-label small fw-bold" for="ddProt">Numero di protocollo</label><input class="form-control form-control-sm" id="ddProt" name="protocollo" value="<?php echo $h($p['protocollo'] ?? ''); ?>" maxlength="100" placeholder="es. 1234/2026"></div>
                    <div class="col-md-5"><label class="form-label small fw-bold" for="ddProtD">Data del protocollo</label><input type="date" class="form-control form-control-sm" id="ddProtD" name="protocollo_data" value="<?php echo $h($p['protocollo_data'] ?? ''); ?>"></div>
                    <div class="col-md-5"><label class="form-label small fw-bold" for="ddSed">Seduta del Consiglio</label>
                        <select class="form-select form-select-sm" id="ddSed" name="seduta_id"><option value="0">Nessuna</option>
                            <?php foreach ($sedute as $s): if (!in_array($s, $sedute_future, true) && (int)$s['id'] !== (int)$p['seduta_id']) continue; ?><option value="<?php echo (int)$s['id']; ?>"<?php echo (int)$p['seduta_id'] === (int)$s['id'] ? ' selected' : ''; ?>><?php echo $h(etichetta_seduta($s)); ?></option><?php endforeach; ?></select>
                        <div class="form-text"><a href="<?php echo $base; ?>&amp;tab=sedute&amp;nuova=1">Nuova seduta</a></div></div>
                    <div class="col-md-7"><label class="form-label small fw-bold" for="ddDel">Delibera nel verbale</label>
                        <textarea class="form-control form-control-sm" id="ddDel" name="delibera" rows="3" maxlength="5000" placeholder="<?php echo $h($v_m['delibera'] ?: 'Testo della delibera'); ?>"><?php echo $h($p['delibera']); ?></textarea>
                        <div class="form-text">Vuoto = testo predefinito del modulo. Puoi usare {STUDENTE}, {MATRICOLA} e le domande tra graffe.</div></div>
                </div>
                <button type="submit" name="salva_istruttoria" value="1" class="btn btn-sm btn-success fw-bold mt-2"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva l'istruttoria</button>
            </div></form>
            <?php echo js_tabelle_pratica(); ?>

            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">Storico, attività e messaggi</h6>
                <?php foreach ($eventi as $e): [$ico_e, $lab_e] = $tipi_ev[$e['tipo']] ?? ['fa-circle', '']; ?>
                    <div class="dd-ev <?php echo $e['autore'] === 'ufficio' ? 'uff' : 'stu'; ?>"<?php echo (int)$e['interno'] ? ' style="background:#fffbeb;"' : ''; ?>>
                        <div class="small text-secondary"><?php echo date('d/m/Y H:i', strtotime($e['creato_il'])); ?> · <?php echo $e['autore'] === 'ufficio' ? $h($e['autore_nome'] ?: 'Ufficio') : 'Studente'; ?>
                            <?php if ($lab_e): ?> · <span class="badge bg-light text-dark border"><i class="fa <?php echo $ico_e; ?> me-1" aria-hidden="true"></i><?php echo $lab_e; ?></span><?php endif; ?>
                            <?php if ((int)$e['interno']): ?> · <span class="badge bg-warning text-dark"><i class="fa fa-lock me-1" aria-hidden="true"></i>interna</span><?php endif; ?>
                            <?php if ($e['stato']): ?> · <?php echo badge_stato_pratica((string)$e['stato']); ?><?php endif; ?></div>
                        <?php if ((string)$e['testo'] !== ''): ?><div class="small"><?php echo nl2br($h($e['testo'])); ?></div><?php endif; ?>
                        <?php if ($e['allegato']): ?><div class="small"><a href="../allegato_pratica.php?p=<?php echo (int)$p['id']; ?>&amp;e=<?php echo (int)$e['id']; ?>"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><?php echo $h($e['nome_allegato']); ?></a></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <form method="POST" enctype="multipart/form-data" class="mt-3 border-top pt-3">
                    <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                    <label class="form-label small fw-bold" for="ddMsg">Messaggio allo studente o nota tra i referenti</label>
                    <textarea class="form-control form-control-sm mb-2" id="ddMsg" name="testo" rows="3" maxlength="5000"></textarea>
                    <div class="d-flex gap-2 flex-wrap align-items-center"><input type="file" name="allegato" class="form-control form-control-sm" style="max-width:280px;" accept=".pdf,.jpg,.jpeg,.png,.p7m" aria-label="Allegato (facoltativo)">
                        <label class="form-check small m-0"><input class="form-check-input" type="checkbox" name="interno" value="1"> <i class="fa fa-lock" aria-hidden="true"></i> nota interna (solo referenti)</label>
                        <button type="submit" name="messaggio_pratica" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia</button></div>
                </form>
            </div></div>
        </div>
        <div class="col-lg-5">
            <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm mb-3"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <h6 class="fw-bold"><i class="fa fa-paperclip me-1" aria-hidden="true"></i>Aggiungi un'attività</h6>
                <p class="small text-secondary mb-2">Es. verbale del corso di studio, parere, documento istruttorio: resta nello storico della pratica.</p>
                <textarea class="form-control form-control-sm mb-2" name="testo" rows="2" maxlength="5000" placeholder="Cosa è stato fatto (es. Verbale del CdL del 15/10/2026)" aria-label="Descrizione dell'attività"></textarea>
                <input type="file" name="allegato" class="form-control form-control-sm mb-2" accept=".pdf,.jpg,.jpeg,.png,.p7m" aria-label="File dell'attività">
                <div class="d-flex flex-wrap gap-2 align-items-center"><label class="form-check small m-0"><input class="form-check-input" type="checkbox" name="visibile" value="1" checked> visibile allo studente</label>
                    <button type="submit" name="attivita_pratica" value="1" class="btn btn-sm btn-outline-primary fw-bold ms-auto"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi</button></div>
            </div></form>

            <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #b45309 !important;"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <h6 class="fw-bold"><i class="fa fa-circle-exclamation me-1" style="color:#b45309;" aria-hidden="true"></i>Chiedi un'integrazione allo studente</h6>
                <?php if ($richiesta): ?><div class="alert alert-warning small py-1 px-2">In attesa: <?php echo $richiesta['tipo'] === 'autodichiarazione' ? 'autodichiarazione' : 'documenti'; ?> – <?php echo $h($richiesta['testo']); ?></div><?php endif; ?>
                <div class="d-flex gap-3 small mb-1">
                    <label class="form-check m-0"><input class="form-check-input" type="radio" name="tipo_richiesta" value="documenti" checked> Documenti</label>
                    <label class="form-check m-0"><input class="form-check-input" type="radio" name="tipo_richiesta" value="autodichiarazione"> Autodichiarazione</label>
                </div>
                <textarea class="form-control form-control-sm mb-2" name="testo_richiesta" rows="3" maxlength="3000" placeholder="Documenti: cosa allegare. Autodichiarazione: il testo che lo studente dichiara (es. di aver sostenuto l'esame di … il …)" aria-label="Testo della richiesta"></textarea>
                <button type="submit" name="richiedi_integrazione" value="1" class="btn btn-sm fw-bold text-white" style="background:#b45309;">Invia la richiesta</button>
                <p class="small text-secondary mt-2 mb-0">Lo studente riceve un'email e risponde dalla pratica allegando i file o rendendo l'autodichiarazione (D.P.R. 445/2000); poi la pratica torna «In lavorazione».</p>
            </div></form>

            <?php $prec = array_values(array_filter(array_map(fn($oid) => operatore_ufficio($conn, $oid), operatori_pratica($conn, (int)$p['id'])), fn($o) => $o && (int)$o['id'] !== (int)$p['assegnata_a']));
            if ($prec): ?>
            <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #7c3aed !important;"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <h6 class="fw-bold"><i class="fa fa-people-arrows me-1" style="color:#7c3aed;" aria-hidden="true"></i>Chiedi un'integrazione a chi l'ha avuta prima</h6>
                <p class="small text-secondary mb-2">Chi ha avuto la pratica nei passi precedenti continua a vederla: riceve un'email e aggiunge documenti o note; chi l'ha in carico viene avvisato.</p>
                <select class="form-select form-select-sm mb-2" name="operatore_prec" aria-label="Operatore"><?php foreach ($prec as $o): ?><option value="<?php echo (int)$o['id']; ?>"><?php echo $h(etichetta_operatore($o)); ?></option><?php endforeach; ?></select>
                <textarea class="form-control form-control-sm mb-2" name="testo_operatore" rows="2" maxlength="3000" placeholder="Cosa serve (es. il verbale del CdL firmato)" aria-label="Cosa serve"></textarea>
                <button type="submit" name="richiedi_operatore" value="1" class="btn btn-sm fw-bold text-white" style="background:#7c3aed;">Invia la richiesta</button>
            </div></form>
            <?php endif; ?>

            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">Cambia stato</h6>
                <form method="POST">
                    <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                    <label class="form-label small fw-bold" for="ddNota">Nota per lo studente (facoltativa)</label>
                    <textarea class="form-control form-control-sm mb-2" id="ddNota" name="nota" rows="3" maxlength="2000" placeholder="Es. esito, motivo, prossimi passi"></textarea>
                    <div class="d-grid gap-1">
                        <?php foreach (STATI_PRATICA as $k => [$n, $col, $ico]): if ($k === $p['stato'] || in_array($k, ['inviata', 'integrazione'], true)) continue; ?>
                            <button type="submit" name="stato_pratica" value="<?php echo $k; ?>" class="btn btn-sm fw-bold text-white text-start" style="background:<?php echo $col; ?>;"><i class="fa <?php echo $ico; ?> me-1" aria-hidden="true"></i><?php echo $n; ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">Lo studente riceve un'email per gli esiti (accolta, respinta, chiusa) e per le richieste di integrazione; i passaggi tra uffici gli arrivano quando la pratica passa di mano.</p>
                </form>
            </div></div>
        </div>
    </div>
    <?php else:
        $elenco = $elenco_pratiche();
        $qs_filtri = http_build_query(['stato' => $f_stato, 'modulo' => $f_mod ?: null, 'q' => $f_q ?: null, 'seduta' => $f_sed ?: null, 'dal' => $f_dal ?: null, 'al' => $f_al ?: null, 'carico' => $f_car ?: null]);
    ?>
    <div class="d-flex flex-wrap gap-1 mb-2">
        <?php foreach (['' => ['fa-inbox', 'Tutte'], 'smistare' => ['fa-shuffle', 'Da smistare'], 'me' => ['fa-user-check', 'Assegnate a me'], 'seguite' => ['fa-clock-rotate-left', 'Passate ad altri uffici']] as $k_c => [$ico_c, $txt_c]): if (in_array($k_c, ['me', 'seguite'], true) && !$io_operatore) continue; ?>
            <a class="btn btn-sm <?php echo $f_car === $k_c ? 'btn-dark' : 'btn-outline-dark'; ?>" href="<?php echo $base; ?>&amp;tab=pratiche&amp;carico=<?php echo $k_c; ?>"><i class="fa <?php echo $ico_c; ?> me-1" aria-hidden="true"></i><?php echo $txt_c; ?></a>
        <?php endforeach; ?>
    </div>
    <form method="GET" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end">
        <input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="tab" value="pratiche"><input type="hidden" name="carico" value="<?php echo $h($f_car); ?>">
        <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="fSt">Stato</label><select class="form-select form-select-sm" id="fSt" name="stato">
            <option value="aperte"<?php echo $f_stato === 'aperte' ? ' selected' : ''; ?>>Aperte</option>
            <?php foreach (STATI_PRATICA as $k => [$n]): ?><option value="<?php echo $k; ?>"<?php echo $f_stato === $k ? ' selected' : ''; ?>><?php echo $n; ?></option><?php endforeach; ?>
            <option value="tutte"<?php echo $f_stato === 'tutte' ? ' selected' : ''; ?>>Tutte</option></select></div>
        <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="fMod">Modulo</label><select class="form-select form-select-sm" id="fMod" name="modulo"><option value="0">Tutti</option>
            <?php foreach ($moduli as $m): if ($m['tipo'] !== 'online') continue; ?><option value="<?php echo (int)$m['id']; ?>"<?php echo $f_mod === (int)$m['id'] ? ' selected' : ''; ?>><?php echo $h($m['titolo']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="fSed">Seduta</label><select class="form-select form-select-sm" id="fSed" name="seduta"><option value="">Qualsiasi</option><option value="nessuna"<?php echo $f_sed === 'nessuna' ? ' selected' : ''; ?>>Senza seduta</option>
            <?php foreach ($sedute as $s): ?><option value="<?php echo (int)$s['id']; ?>"<?php echo $f_sed === (string)$s['id'] ? ' selected' : ''; ?>><?php echo $h(etichetta_seduta($s)); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label small fw-bold mb-0" for="fDal">Dal</label><input type="date" class="form-control form-control-sm" id="fDal" name="dal" value="<?php echo $h($f_dal); ?>"></div>
        <div class="col-md-1"><label class="form-label small fw-bold mb-0" for="fAl">Al</label><input type="date" class="form-control form-control-sm" id="fAl" name="al" value="<?php echo $h($f_al); ?>"></div>
        <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="fQ">Cerca</label><input type="search" class="form-control form-control-sm" id="fQ" name="q" value="<?php echo $h($f_q); ?>" placeholder="Nome, matricola, codice"></div>
        <div class="col-md-1"><button class="btn btn-sm btn-primary fw-bold w-100" aria-label="Filtra"><i class="fa fa-filter" aria-hidden="true"></i></button></div>
    </div></form>
    <form method="POST" id="ddElenco">
        <?php csrf_field(); ?><input type="hidden" name="torna" value="<?php echo $h("$base&tab=pratiche&$qs_filtri"); ?>">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <span class="small text-secondary"><?php echo count($elenco); ?> pratiche</span>
            <a class="btn btn-sm btn-outline-success fw-bold dd-esp" data-formato="xlsx" href="<?php echo $base; ?>&amp;tab=pratiche&amp;<?php echo $h($qs_filtri); ?>&amp;esporta=xlsx"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Excel</a>
            <a class="btn btn-sm btn-outline-primary fw-bold dd-esp" data-formato="docx" href="<?php echo $base; ?>&amp;tab=pratiche&amp;<?php echo $h($qs_filtri); ?>&amp;esporta=docx"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Word (per il verbale)</a>
            <span class="small text-secondary">Esporta le pratiche filtrate o, se ne selezioni alcune, solo quelle.</span>
            <span class="ms-auto d-flex gap-1 align-items-center">
                <label class="small fw-bold" for="ddAss">Porta le selezionate alla seduta</label>
                <select class="form-select form-select-sm" id="ddAss" name="seduta_id" style="max-width:260px;"><option value="0">Nessuna (togli)</option>
                    <?php foreach ($sedute_future as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo $h(etichetta_seduta($s)); ?></option><?php endforeach; ?></select>
                <button type="submit" name="assegna_seduta" value="1" class="btn btn-sm btn-dark fw-bold">Assegna</button>
            </span>
        </div>
        <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
            <thead class="table-light"><tr><th style="width:28px;"><input type="checkbox" class="form-check-input" id="ddTutte" aria-label="Seleziona tutte"></th><th>Pratica</th><th>Studente</th><th>Stato</th><th>In carico a</th><th>Seduta</th><th>Ultimo aggiornamento</th></tr></thead>
            <tbody>
            <?php if (!$elenco): ?><tr><td colspan="7" class="text-center text-muted py-4">Nessuna pratica con questi filtri.<?php echo !$moduli ? ' Crea prima un modulo online nella scheda "Moduli e documenti".' : ''; ?></td></tr><?php endif; ?>
            <?php foreach ($elenco as $x): ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input dd-sel" name="ids[]" value="<?php echo (int)$x['id']; ?>" aria-label="Seleziona <?php echo $h($x['codice']); ?>"></td>
                    <td><a class="fw-bold text-decoration-none" href="<?php echo $base; ?>&amp;tab=pratiche&amp;id=<?php echo (int)$x['id']; ?>"><?php echo $h($x['modulo_titolo']); ?></a><div class="font-monospace text-secondary" style="font-size:.7rem;"><?php echo $h($x['codice']); ?></div></td>
                    <td><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?><div class="text-secondary"><?php echo $h($x['email']); ?><?php echo $x['matricola'] !== '' ? ' · ' . $h($x['matricola']) : ''; ?></div></td>
                    <td><?php echo badge_stato_pratica($x['stato']); ?></td>
                    <td><?php $o_x = $x['assegnata_a'] ? operatore_ufficio($conn, (int)$x['assegnata_a']) : null; echo $o_x ? $h($o_x['nominativo']) . '<div class="text-secondary">' . $h(nome_ufficio_operatore($conn, $o_x)) . '</div>' : (in_array($x['stato'], ['accolta', 'respinta', 'chiusa'], true) ? '—' : '<span class="badge bg-warning text-dark">da smistare</span>'); ?></td>
                    <td class="text-nowrap"><?php echo $x['seduta_data'] ? '<a href="' . $base . '&amp;tab=sedute&amp;id=' . (int)$x['seduta_id'] . '">' . date('d/m/Y', strtotime($x['seduta_data'])) . '</a>' : ($x['seduta_id'] ? 'sì' : '—'); ?></td>
                    <td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($x['aggiornata_il'] ?: $x['creata_il'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </form>
    <script>
    (function () {
        var tutte = document.getElementById('ddTutte');
        if (tutte) tutte.addEventListener('change', function () { document.querySelectorAll('.dd-sel').forEach(function (c) { c.checked = tutte.checked; }); });
        document.querySelectorAll('.dd-esp').forEach(function (a) {
            a.addEventListener('click', function () {
                var ids = Array.prototype.map.call(document.querySelectorAll('.dd-sel:checked'), function (c) { return c.value; });
                a.href = a.href.replace(/&ids=[^&]*/, '') + (ids.length ? '&ids=' + ids.join(',') : '');
            });
        });
    })();
    </script>
    <?php endif; ?>

<?php elseif ($tab === 'sedute'):
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
    <?php if (!$solo_ref): ?>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="consiglio_id" value="<?php echo $cid; ?>">
        <h5 class="fw-bold mb-2"><i class="fa fa-landmark me-1 text-primary" aria-hidden="true"></i><?php echo $cid ? 'Consiglio' : 'Nuovo consiglio'; ?></h5>
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
    </div></form>
    <?php else: ?>
        <h5 class="fw-bold mb-1"><?php echo $h($cons_sel['nome']); ?></h5>
    <?php endif; ?>
    <?php if ($cid): ?>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold"><i class="fa fa-user-shield me-1 text-success" aria-hidden="true"></i>Referenti del consiglio</h6>
                <p class="small text-secondary">Entrano nel pannello Didattica con le credenziali Unical e gestiscono solo le sedute di questo consiglio: componenti, presenze, pratiche e decisioni, verbale.</p>
                <?php foreach ($referenti as $x): ?>
                    <form method="POST" class="d-flex align-items-center gap-2 small border-bottom py-1"><?php csrf_field(); ?>
                        <span class="flex-grow-1"><strong><?php echo $h($x['nominativo']); ?></strong><span class="d-block text-secondary"><?php echo $h($x['email']); ?></span></span>
                        <?php if (!$solo_ref): ?><button type="submit" name="togli_persona_consiglio" value="<?php echo (int)$x['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Togliere <?php echo $h($x['nominativo']); ?> dai referenti?" aria-label="Togli"><i class="fa fa-user-minus" aria-hidden="true"></i></button><?php endif; ?>
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
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold"><i class="fa fa-users me-1 text-primary" aria-hidden="true"></i>Componenti (<?php echo count($componenti); ?>)</h6>
                <p class="small text-secondary">Si inseriscono una volta sola: in ogni seduta si segna solo chi è presente, assente giustificato o ingiustificato. Il gruppo (es. Professori ordinari) è il titolo sotto cui il componente compare nel verbale.</p>
                <?php if ($componenti): ?>
                <form method="POST"><?php csrf_field(); ?><input type="hidden" name="consiglio_id" value="<?php echo $cid; ?>">
                    <datalist id="dlQual"><?php foreach ($qualifiche as $q): ?><option value="<?php echo $h($q); ?>"><?php endforeach; ?></datalist>
                    <div class="table-responsive" style="max-height:480px;overflow:auto;"><table class="table table-sm align-middle small mb-2">
                        <thead class="table-light"><tr><th>Componente</th><th style="width:38%;">Gruppo nel verbale</th><th style="width:70px;">Ordine</th><th style="width:40px;"></th></tr></thead><tbody>
                        <?php foreach ($componenti as $x): ?>
                            <tr><td><strong><?php echo $h($x['nominativo']); ?></strong><?php echo $x['persona_id'] ? ' <i class="fa fa-address-book text-secondary" title="dall\'anagrafe" aria-hidden="true"></i>' : ''; ?><div class="text-secondary"><?php echo $h($x['email']); ?></div></td>
                                <td><input class="form-control form-control-sm" name="qualifica_p[<?php echo (int)$x['id']; ?>]" value="<?php echo $h($x['qualifica']); ?>" list="dlQual" aria-label="Gruppo di <?php echo $h($x['nominativo']); ?>"></td>
                                <td><input type="number" class="form-control form-control-sm" name="ordine_p[<?php echo (int)$x['id']; ?>]" value="<?php echo (int)$x['ordine']; ?>" aria-label="Ordine"></td>
                                <td><button type="submit" name="togli_persona_consiglio" value="<?php echo (int)$x['id']; ?>" class="btn btn-sm btn-link text-danger p-0" data-confirm="Togliere <?php echo $h($x['nominativo']); ?> dai componenti?" aria-label="Togli"><i class="fa fa-times" aria-hidden="true"></i></button></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <button type="submit" name="salva_qualifiche" value="1" class="btn btn-sm btn-outline-primary">Salva gruppi e ordine</button>
                </form>
                <?php endif; ?>
                <div class="row g-2 mt-2">
                    <div class="col-md-7">
                        <form method="POST" class="dd-scelta-persona"><?php csrf_field(); ?><input type="hidden" name="consiglio_id" value="<?php echo $cid; ?>"><input type="hidden" name="ruolo_persona" value="componente"><input type="hidden" name="persona_id" value="">
                            <input type="hidden" name="aggiungi_persona_consiglio" value="1">
                            <label class="form-label small fw-bold mb-0" for="qualAn">Gruppo (vuoto = dal ruolo dell'anagrafe)</label>
                            <input class="form-control form-control-sm mb-1" id="qualAn" name="qualifica" list="dlQual2" placeholder="es. Professori associati">
                            <datalist id="dlQual2"><?php foreach ($qualifiche as $q): ?><option value="<?php echo $h($q); ?>"><?php endforeach; ?></datalist>
                            <?php echo html_ricerca_personale($conn, 'Aggiungi'); ?>
                        </form>
                    </div>
                    <div class="col-md-5">
                        <form method="POST" class="bg-light rounded p-2"><?php csrf_field(); ?><input type="hidden" name="consiglio_id" value="<?php echo $cid; ?>"><input type="hidden" name="ruolo_persona" value="componente">
                            <div class="small fw-bold mb-1">Non è nell'anagrafe (es. rappresentanti degli studenti)</div>
                            <input class="form-control form-control-sm mb-1" name="nominativo" placeholder="Cognome e nome" maxlength="200" aria-label="Cognome e nome">
                            <input class="form-control form-control-sm mb-1" name="email_persona" type="email" placeholder="Email (facoltativa)" aria-label="Email">
                            <input class="form-control form-control-sm mb-2" name="qualifica" list="dlQual2" value="Rappresentanti degli studenti" aria-label="Gruppo nel verbale">
                            <button type="submit" name="aggiungi_persona_consiglio" value="1" class="btn btn-sm btn-success fw-bold"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi</button>
                        </form>
                    </div>
                </div>
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
        $pr_sel = pratiche_per_esportazione($conn, array_column($conn->query("SELECT id FROM pratiche WHERE seduta_id = " . (int)$sel['id'])->fetch_all(MYSQLI_ASSOC), 'id'));
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
        $np = riepilogo_presenze(array_filter($presenze, fn($r) => !empty($r['_salvata'])));
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

    <?php if ($presenze): ?>
    <form method="POST" class="card border-0 shadow-sm mb-3" id="presenze"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="seduta_id" value="<?php echo (int)$sel['id']; ?>">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="fa fa-clipboard-user me-1 text-primary" aria-hidden="true"></i>Presenze</h6>
            <span class="small text-secondary"><?php echo array_filter($presenze, fn($r) => !empty($r['_salvata'])) ? "Presenti {$np['P']} · assenti giustificati {$np['AG']} · ingiustificati {$np['AI']}" : 'Non ancora registrate: tutti proposti presenti.'; ?></span>
            <span class="ms-auto d-flex gap-1">
                <?php foreach (STATI_PRESENZA as $k => $n): ?><button type="button" class="btn btn-sm btn-outline-secondary py-0 dd-tutti" data-stato="<?php echo $k; ?>">Tutti: <?php echo $h(mb_strtolower($n)); ?></button><?php endforeach; ?>
            </span>
        </div>
        <div class="table-responsive" style="max-height:520px;overflow:auto;"><table class="table table-sm align-middle small mb-2">
            <thead class="table-light" style="position:sticky;top:0;"><tr><th>Componente</th><?php foreach (STATI_PRESENZA as $k => $n): ?><th class="text-center" style="width:120px;"><?php echo $h($n); ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php $g_corr = null; foreach ($presenze as $cid_p => $r): if ($r['qualifica'] !== $g_corr): $g_corr = $r['qualifica']; ?><tr class="table-light"><th colspan="4" class="text-uppercase text-secondary" style="font-size:.7rem;"><?php echo $h($g_corr ?: 'Senza gruppo'); ?></th></tr><?php endif; ?>
                <tr><td><?php echo $h($r['nominativo']); ?></td>
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
            <div class="card border-0 shadow-sm"><div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <h6 class="fw-bold mb-0">Pratiche in seduta (<?php echo count($pr_sel); ?>)</h6>
                    <?php if ($con_esito): ?><form method="POST" class="ms-auto m-0"><?php csrf_field(); ?><input type="hidden" name="seduta_id" value="<?php echo (int)$sel['id']; ?>">
                        <button type="submit" name="applica_esiti" value="1" class="btn btn-sm btn-success fw-bold" data-confirm="Applicare gli esiti? Le approvate diventano accolte e le respinte respinte (gli studenti ricevono l'email); le rinviate tornano senza seduta."><i class="fa fa-check-double me-1" aria-hidden="true"></i>Applica gli esiti alle pratiche (<?php echo $con_esito; ?>)</button></form><?php endif; ?>
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
    <?php else: ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="fa fa-landmark me-1 text-primary" aria-hidden="true"></i>Consigli dei corsi di studio</h6>
            <?php if (!$solo_ref): ?><a href="<?php echo $base; ?>&amp;tab=sedute&amp;consiglio=0" class="btn btn-sm btn-outline-primary py-0 ms-auto"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuovo consiglio</a><?php endif; ?>
        </div>
        <p class="small text-secondary"><?php echo $solo_ref ? 'Sei referente di questi consigli: inserisci i componenti una volta sola, crea le sedute, segna le presenze e decidi sulle pratiche.' : "L'Ufficio didattico sceglie i referenti di ogni consiglio; i referenti inseriscono i componenti e gestiscono le sedute."; ?></p>
        <div class="list-group list-group-flush small">
        <?php foreach ($consigli_vis as $k => $c): $nr = count(persone_consiglio($conn, $k, 'referente')); $nc = count(persone_consiglio($conn, $k)); ?>
            <div class="list-group-item d-flex flex-wrap gap-2 align-items-center px-0">
                <span class="flex-grow-1" style="min-width:240px;"><strong><?php echo $h($c['nome']); ?></strong><?php echo (int)$c['attivo'] ? '' : ' <span class="badge bg-secondary">non attivo</span>'; ?>
                    <span class="d-block text-secondary"><?php echo $nr; ?> referenti · <?php echo $nc; ?> componenti</span></span>
                <a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $base; ?>&amp;tab=sedute&amp;consiglio=<?php echo $k; ?>"><i class="fa fa-users me-1" aria-hidden="true"></i><?php echo $solo_ref ? 'Componenti' : 'Referenti e componenti'; ?></a>
                <a class="btn btn-sm btn-primary py-0 fw-bold" href="<?php echo $base; ?>&amp;tab=sedute&amp;nuova=1&amp;consiglio_id=<?php echo $k; ?>"><i class="fa fa-plus me-1" aria-hidden="true"></i>Seduta</a>
            </div>
        <?php endforeach; ?>
        <?php if (!$consigli_vis): ?><div class="text-muted">Nessun consiglio.</div><?php endif; ?>
        </div>
    </div></div>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <?php if (!$solo_ref): ?><a href="<?php echo $base; ?>&amp;tab=sedute&amp;nuova=1" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuova seduta</a><?php endif; ?>
        <span class="small text-secondary">Crea la seduta, segna le presenze, porta le pratiche con le decisioni e scarica il verbale in Word già impaginato (logo, o.d.g., presenze, pratiche, convalide, firme).</span>
    </div>
    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
        <thead class="table-light"><tr><th>Data</th><th>Organo</th><th>Pratiche</th><th class="text-end">Esporta</th></tr></thead><tbody>
        <?php if (!$sedute): ?><tr><td colspan="4" class="text-center text-muted py-4">Nessuna seduta.</td></tr><?php endif; ?>
        <?php foreach ($sedute as $s): ?>
            <tr><td class="text-nowrap fw-bold"><?php echo $s['data'] ? date('d/m/Y', strtotime($s['data'])) : '—'; ?></td>
                <td><a class="text-decoration-none" href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$s['id']; ?>"><?php echo $h(mb_strimwidth($s['organo'], 0, 160, '…')); ?></a></td>
                <td><?php echo (int)$s['n_pratiche']; ?></td>
                <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$s['id']; ?>&amp;esporta=docx"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Word</a>
                    <a class="btn btn-sm btn-outline-success py-0" href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$s['id']; ?>&amp;esporta=xlsx"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Excel</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>

<?php elseif ($tab === 'statistiche'):
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

<?php elseif ($tab === 'ufficio'):
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
                    $orari = $conn->query("SELECT giorno, dalle, alle FROM risorse_orari WHERE risorsa_id = " . (int)$sp['id'] . " ORDER BY giorno, dalle")->fetch_all(MYSQLI_ASSOC);
                    $n_app = (int)$conn->query("SELECT COUNT(*) n FROM prenotazioni_risorse WHERE risorsa_id = " . (int)$sp['id'] . " AND stato IN ('confermata', 'da_approvare') AND fine >= NOW()")->fetch_assoc()['n']; ?>
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

<?php else: // ── MODULI E DOCUMENTI ──
    $mod_m = !empty($_GET['modifica']) ? modulo_didattica($conn, (int)$_GET['modifica']) : null;
    $mostra_form = $mod_m || !empty($_GET['nuovo']);
    $f = $mod_m ?: ['id' => 0, 'titolo' => '', 'categoria' => '', 'descrizione' => '', 'tipo' => 'documento', 'file_path' => null, 'link' => '', 'campi_json' => null, 'verbale_json' => null, 'destinatari' => 'tutti', 'email_ufficio' => '', 'attivo' => 1, 'ordine' => 0, 'aperto_dal' => null, 'aperto_al' => null, 'giorni_promemoria' => 7, 'iter_json' => null];
    $campi_f = json_decode((string)$f['campi_json'], true) ?: [];
    $v_f = verbale_modulo($f + ['titolo' => '']); $v_raw = json_decode((string)($f['verbale_json'] ?? ''), true) ?: [];
    // Modelli pronti: riempiono titolo, categoria, campi e parte del verbale (poi si modifica tutto)
    $modelli = [
        'tesi' => ['Domanda di lavoro finale (tesi)', 'Lauree', 'Assegnazione dell\'argomento del lavoro finale con relatore ed eventuale correlatore.',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Anno accademico', 'anno_accademico', '', 1, 0], ['Titolo provvisorio del lavoro finale', 'text', '', 1, 0], ['Insegnamento di riferimento', 'insegnamento', '', 0, 0], ['Relatore', 'docente', '', 1, 0], ['Correlatore', 'docente', '', 0, 0]],
            ['sezione' => 'Domande lavoro finale', 'stile' => 'elenco', 'colonne' => 'COGNOME, NOME, MATRICOLA, RELATORE, CORRELATORE', 'raggruppa' => 'Corso di studio', 'chiusura' => 'Il Consiglio approva.', 'iter' => ['referente_cdl', 'carriere']]],
        'passaggio' => ['Passaggio di corso, trasferimento o rinuncia/decadenza', 'Carriera', 'Richiesta di passaggio di corso, trasferimento in entrata o iscrizione dopo rinuncia o decadenza, con gli esami da convalidare.',
            [['Tipo di richiesta', 'select', 'passaggio di corso di studio, trasferimento in entrata, iscrizione per rinuncia e decadenza', 1, 0], ['Corso di provenienza', 'text', '', 1, 0], ['Ateneo di provenienza', 'text', '', 1, 0],
             ['Corso di destinazione', 'corso_studio', '', 1, 0], ['Anno accademico', 'anno_accademico', '', 1, 0], ['Esami sostenuti', 'tabella', 'Insegnamento sostenuto:insegnamento, CFU:cfu, Voto:voto, S.S.D.:ssd, Data:data', 1, 0],
             ['Certificato degli esami', 'file', '', 1, 0], ['Quadro delle convalide', 'tabella', 'Insegnamento convalidato, CFU, Voto, Data, S.S.D., Anno insegnamento, Tot CFU insegn., CFU convalidati, CFU da integrare', 0, 1],
             ['Anno di iscrizione deliberato', 'select', 'primo, secondo, terzo', 0, 1]],
            ['sezione' => 'Domande di passaggio, trasferimento e iscrizione', 'stile' => 'scheda',
             'testo' => 'Lo studente {STUDENTE}, matricola {MATRICOLA}, iscritto per l\'a.a. {Anno accademico} al {Corso di provenienza} presso {Ateneo di provenienza}, chiede {Tipo di richiesta} per l\'a.a. {Anno accademico} al {Corso di destinazione}. Valutati i programmi e la loro corrispondenza con gli insegnamenti erogati, il Consiglio approva la richiesta con il seguente quadro di convalide:',
             'delibera' => 'Il Consiglio delibera l\'iscrizione dello studente al {Anno di iscrizione deliberato} anno del {Corso di destinazione}, con attribuzione del piano di studi secondo il regolamento dell\'anno accademico di riferimento.', 'decisione' => 'convalide', 'iter' => ['referente_cdl', 'carriere']]],
        'convalida' => ['Convalida di esami / riconoscimento crediti', 'Carriera', 'Richiesta di convalida di esami sostenuti in altri corsi o atenei (anche parziale).',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Anno accademico', 'anno_accademico', '', 1, 0],
             ['Dove hai sostenuto gli esami', 'radio', 'in questo Ateneo, in un altro Ateneo', 1, 0],
             ['Ateneo', 'text', '', 1, 0, ['campo' => 'Dove hai sostenuto gli esami', 'op' => 'uguale', 'valore' => 'in un altro Ateneo']],
             ['Esami da convalidare', 'tabella', 'Insegnamento sostenuto:insegnamento, CFU:cfu, Voto:voto, S.S.D.:ssd, Data:data', 1, 0, null, 'Con la lente scegli l\'insegnamento dal catalogo di Ateneo (tipo di corso, corso, anno di offerta): CFU e S.S.D. si compilano da soli. Se non lo trovi scrivilo a mano.'],
             ['Certificato degli esami o programmi', 'file', '', 0, 0],
             ['Dichiaro che gli esami indicati sono stati regolarmente sostenuti', 'dichiarazione', '', 1, 0, null, 'Dichiarazione resa ai sensi degli artt. 46 e 47 del D.P.R. 445/2000.']],
            ['sezione' => 'Convalida di esami', 'stile' => 'scheda', 'testo' => 'Lo studente {STUDENTE}, matricola {MATRICOLA}, iscritto al {Corso di studio} per l\'a.a. {Anno accademico}, chiede la convalida degli esami sostenuti {Dove hai sostenuto gli esami} {Ateneo}. Il Consiglio, valutati i programmi, delibera le seguenti convalide:',
             'delibera' => 'Il Consiglio approva.', 'decisione' => 'convalide', 'iter' => ['referente_cdl', 'carriere']]],
        'piano' => ['Piano di studi: insegnamenti in piano o fuori piano', 'Piani di studio', 'Richiesta di inserimento nel piano di studi di insegnamenti a scelta, in piano o in soprannumero (fuori piano).',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Anno accademico', 'anno_accademico', '', 1, 0], ['Anno di corso', 'select', 'primo, secondo, terzo', 1, 0],
             ['Insegnamenti richiesti', 'tabella', 'Insegnamento:insegnamento, CFU:cfu, S.S.D.:ssd, Tipo:scelta(in piano|fuori piano)', 1, 0, null, 'Scegli gli insegnamenti dal catalogo di Ateneo indicando corso e anno accademico di offerta.'],
             ['Motivazione', 'textarea', '', 0, 0]],
            ['sezione' => 'Piani di studio', 'stile' => 'scheda', 'testo' => 'Lo studente {STUDENTE}, matricola {MATRICOLA}, iscritto al {Anno di corso} anno del {Corso di studio}, chiede l\'inserimento nel piano di studi dei seguenti insegnamenti:',
             'delibera' => 'Il Consiglio approva.', 'decisione' => 'piano', 'iter' => ['referente_cdl', 'carriere']]],
        'estero' => ['Autorizzazione ad attività all\'estero', 'Mobilità internazionale', 'Richiesta di autorizzazione allo svolgimento di attività formative all\'estero (Erasmus+ e altri programmi).',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Programma o bando', 'text', '', 1, 0], ['Ente ospitante', 'text', '', 1, 0], ['Paese', 'text', '', 1, 0], ['Dal', 'date', '', 1, 0], ['Al', 'date', '', 1, 0],
             ['Attività', 'select', 'attività di studio, tirocinio, ricerca tesi, tirocinio e ricerca tesi', 1, 0], ['Learning Agreement', 'file', '', 1, 0]],
            ['sezione' => 'Autorizzazione a svolgere attività all\'estero', 'stile' => 'scheda', 'intro' => 'Sono pervenute le richieste di autorizzazione allo svolgimento di attività formative all\'estero da parte degli studenti di seguito elencati.',
             'testo' => '{STUDENTE}, matricola {MATRICOLA}, regolarmente iscritto al {Corso di studio} e vincitore del bando {Programma o bando} presso {Ente ospitante}, {Paese}, orientativamente dal {Dal} al {Al} per {Attività}.', 'delibera' => '',
             'chiusura' => 'Il Consiglio prende atto delle richieste presentate e approva preventivamente le richieste di riconoscimento come da Learning Agreement, previa verifica documentale a fine delle attività.', 'iter' => ['internazionalizzazione', 'referente_cdl', 'carriere']]],
        'rientro' => ['Riconoscimento delle attività svolte all\'estero', 'Mobilità internazionale', 'Richiesta di riconoscimento dei crediti al rientro dalla mobilità.',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Programma o bando', 'text', '', 1, 0], ['Ente ospitante', 'text', '', 1, 0], ['Periodo', 'text', '', 1, 0],
             ['Attività svolte', 'tabella', 'Attività svolta, CFU / ore, Esito', 1, 0], ['Transcript of Records o attestato', 'file', '', 1, 0],
             ['Riconoscimenti', 'tabella', 'Insegnamento riconosciuto, Anno insegnamento, Tot CFU insegn., CFU riconosciuti, Voto, CFU da integrare, Data', 0, 1]],
            ['sezione' => 'Comunicazione fine attività di studio all\'estero', 'stile' => 'scheda', 'intro' => 'Il Consiglio esamina la documentazione presentata dagli studenti rientrati dalle attività svolte all\'estero, ai fini del riconoscimento dei crediti formativi preventivamente autorizzati.',
             'testo' => '{STUDENTE}, matricola {MATRICOLA}, regolarmente iscritto al {Corso di studio} e vincitore del bando {Programma o bando} presso {Ente ospitante} ({Periodo}), chiede il riconoscimento delle attività svolte:',
             'delibera' => 'Il Consiglio prende atto della documentazione prodotta e approva il riconoscimento richiesto.', 'iter' => ['internazionalizzazione', 'referente_cdl', 'carriere']]],
    ];
?>
    <?php if ($mostra_form): ?>
    <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm mb-3" id="ddForm"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="modulo_id" value="<?php echo (int)$f['id']; ?>">
        <h5 class="fw-bold mb-3"><?php echo $f['id'] ? 'Modifica modulo' : 'Nuovo modulo'; ?></h5>
        <?php if (!$f['id']): ?>
        <div class="dd-preset mb-3">
            <div class="small fw-bold mb-1"><i class="fa fa-wand-magic-sparkles me-1 text-success" aria-hidden="true"></i>Parti da un modello pronto (poi modifichi tutto)</div>
            <div class="d-flex flex-wrap gap-1"><?php foreach ($modelli as $k => $mm): ?><button type="button" class="btn btn-sm btn-outline-success dd-modello" data-modello="<?php echo $k; ?>"><?php echo $h($mm[0]); ?></button><?php endforeach; ?></div>
        </div>
        <?php endif; ?>
        <div class="row g-2">
            <div class="col-md-6"><label class="form-label small fw-bold" for="mTit">Titolo <span class="text-danger">*</span></label><input type="text" class="form-control" id="mTit" name="titolo" value="<?php echo $h($f['titolo']); ?>" required maxlength="200"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="mCat">Categoria</label><input type="text" class="form-control" id="mCat" name="categoria" value="<?php echo $h($f['categoria']); ?>" list="mCatList" placeholder="es. Tirocini, Piani di studio" maxlength="100">
                <datalist id="mCatList"><?php foreach ($categorie as $c): ?><option value="<?php echo $h($c); ?>"><?php endforeach; ?></datalist></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="mTipo">Tipo</label><select class="form-select" id="mTipo" name="tipo">
                <option value="documento"<?php echo $f['tipo'] === 'documento' ? ' selected' : ''; ?>>Documento da scaricare</option>
                <option value="online"<?php echo $f['tipo'] === 'online' ? ' selected' : ''; ?>>Modulo online (apre una pratica)</option></select></div>
            <div class="col-12"><label class="form-label small fw-bold" for="mDes">Descrizione e istruzioni</label><textarea class="form-control editor-html" id="mDes" name="descrizione" rows="4"><?php echo $h($f['descrizione']); ?></textarea></div>
            <div class="col-md-6 dd-doc"><label class="form-label small fw-bold" for="mFile">File da scaricare<?php echo $f['file_path'] ? ' (carica solo per sostituirlo)' : ''; ?></label><input type="file" class="form-control" id="mFile" name="file_modulo" accept=".pdf,.doc,.docx,.odt,.xls,.xlsx,.ods,.rtf">
                <?php if ($f['file_path']): ?><div class="form-text"><a href="../<?php echo $h($f['file_path']); ?>" target="_blank" rel="noopener">File attuale</a></div><?php endif; ?></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="mLink">Link esterno (facoltativo)</label><input type="url" class="form-control" id="mLink" name="link" value="<?php echo $h($f['link']); ?>" placeholder="https://www.unical.it/..."></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="mDest">Chi può compilare (moduli online)</label><select class="form-select" id="mDest" name="destinatari"><?php foreach (DESTINATARI_MODULO as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo $f['destinatari'] === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-5"><label class="form-label small fw-bold" for="mEm">Email che riceve le pratiche (facoltativa)</label><input type="text" class="form-control" id="mEm" name="email_ufficio" value="<?php echo $h($f['email_ufficio']); ?>" placeholder="segreteria.didattica@unical.it"><div class="form-text">Vuoto = operatori dell'Ufficio didattico con il compito «Pratiche».</div></div>
            <div class="col-md-1"><label class="form-label small fw-bold" for="mOrd">Ordine</label><input type="number" class="form-control" id="mOrd" name="ordine" value="<?php echo (int)$f['ordine']; ?>"></div>
            <div class="col-md-2 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="attivo" value="1"<?php echo (int)$f['attivo'] ? ' checked' : ''; ?>> Pubblicato</label></div>
            <div class="col-md-3 dd-online"><label class="form-label small fw-bold" for="mDal">Compilabile dal</label><input type="date" class="form-control" id="mDal" name="aperto_dal" value="<?php echo $h($f['aperto_dal'] ?? ''); ?>"></div>
            <div class="col-md-3 dd-online"><label class="form-label small fw-bold" for="mAl">al</label><input type="date" class="form-control" id="mAl" name="aperto_al" value="<?php echo $h($f['aperto_al'] ?? ''); ?>"><div class="form-text">Vuoti = sempre aperto. Fuori dal periodo il modulo resta visibile ma non si compila.</div></div>
            <div class="col-md-4 dd-online"><label class="form-label small fw-bold" for="mGg">Promemoria se la pratica è ferma da (giorni)</label><input type="number" min="0" max="90" class="form-control" id="mGg" name="giorni_promemoria" value="<?php echo (int)($f['giorni_promemoria'] ?? 7); ?>" style="max-width:120px;"><div class="form-text">Email a chi l'ha in carico (o a chi smista); 0 = nessun promemoria.</div></div>
        </div>

        <fieldset class="dd-online mt-3 border rounded p-2">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1">Campi del modulo online</legend>
            <p class="small text-secondary mb-2">Nome, cognome, email e matricola si prendono dall'accesso. <strong>Dalle anagrafi</strong>: «Corso di studio», «Insegnamento del Dipartimento», «Insegnamento di Ateneo» (lo studente sceglie tipo di corso, corso, anno accademico di offerta e insegnamento, o lo scrive a mano) e «Docente». <strong>Tabella a righe</strong>: con <i class="fa fa-sliders" aria-hidden="true"></i> scegli nome e tipo di ogni colonna (es. Insegnamento dal catalogo, CFU e S.S.D. che si compilano da soli, Voto, Data). Con <i class="fa fa-sliders" aria-hidden="true"></i> imposti anche la <strong>logica</strong>: il campo compare solo se un'altra risposta ha un certo valore, oppure si compila in automatico. Spunta <strong>ufficio</strong> per i campi che compila solo l'ufficio nell'istruttoria. Opzioni separate da virgole.</p>
            <div class="dd-campo dd-testa small fw-bold text-secondary d-none d-lg-grid"><span>Domanda</span><span>Tipo</span><span>Opzioni / colonne</span><span></span><span></span><span>Aiuto (facoltativo)</span><span></span><span></span></div>
            <div id="ddCampi">
                <?php foreach ($campi_f ?: [['etichetta' => '', 'tipo' => 'text']] as $c):
                    $cond = is_array($c['cond'] ?? null) ? $c['cond'] : []; $auto = is_array($c['auto'] ?? null) ? $c['auto'] : [];
                    $opz_txt = is_array($c['opzioni'] ?? null) ? implode(', ', $c['opzioni']) : (string)($c['opzioni'] ?? '');
                    $cols_f = ($c['tipo'] ?? '') === 'tabella' ? colonne_tabella(array_values(array_filter(array_map('trim', preg_split('/[,;\n]/', $opz_txt)), 'strlen'))) : []; ?>
                <div class="dd-campo<?php echo !empty($c['ufficio']) ? ' uff' : ''; ?>">
                    <input type="text" class="form-control form-control-sm" name="c_etichetta[]" value="<?php echo $h($c['etichetta'] ?? ''); ?>" placeholder="Domanda" aria-label="Domanda">
                    <select class="form-select form-select-sm dd-tipo" name="c_tipo[]" aria-label="Tipo"><?php foreach (GRUPPI_TIPI_CAMPO as $g => $tt): ?><optgroup label="<?php echo $h($g); ?>"><?php foreach ($tt as $k): ?><option value="<?php echo $k; ?>"<?php echo ($c['tipo'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h(TIPI_CAMPO_PRATICA[$k]); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
                    <input type="text" class="form-control form-control-sm dd-opz" name="c_opzioni[]" value="<?php echo $h($cols_f ? testo_colonne_tabella($cols_f) : $opz_txt); ?>" placeholder="Opzioni o colonne" aria-label="Opzioni o colonne">
                    <span><input type="hidden" name="c_obbl[]" value="<?php echo !empty($c['obbligatorio']) ? '1' : '0'; ?>"><label class="form-check small text-nowrap m-0"><input class="form-check-input dd-chk" type="checkbox"<?php echo !empty($c['obbligatorio']) ? ' checked' : ''; ?>> obbligatorio</label></span>
                    <span><input type="hidden" name="c_uff[]" value="<?php echo !empty($c['ufficio']) ? '1' : '0'; ?>"><label class="form-check small text-nowrap m-0"><input class="form-check-input dd-chk dd-uff" type="checkbox"<?php echo !empty($c['ufficio']) ? ' checked' : ''; ?>> ufficio</label></span>
                    <input type="text" class="form-control form-control-sm" name="c_aiuto[]" value="<?php echo $h($c['aiuto'] ?? ''); ?>" placeholder="Aiuto o testo (facoltativo)" aria-label="Testo di aiuto">
                    <button type="button" class="btn btn-sm <?php echo $cond || $auto ? 'btn-warning' : 'btn-outline-secondary'; ?> dd-logica-btn" aria-label="Colonne e logica del campo" title="Colonne e logica"><i class="fa fa-sliders" aria-hidden="true"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger dd-togli" aria-label="Togli il campo"><i class="fa fa-times" aria-hidden="true"></i></button>
                    <div class="dd-logica" hidden>
                        <div class="dd-colonne mb-2"<?php echo ($c['tipo'] ?? '') === 'tabella' ? '' : ' hidden'; ?>>
                            <div class="small fw-bold mb-1"><i class="fa fa-table-columns me-1" aria-hidden="true"></i>Colonne della tabella</div>
                            <div class="dd-col-righe"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 dd-col-agg"><i class="fa fa-plus me-1" aria-hidden="true"></i>Colonna</button>
                            <span class="small text-secondary ms-2">«Insegnamento (catalogo di Ateneo)» compila da solo CFU e S.S.D. nelle colonne di quel tipo.</span>
                        </div>
                        <div class="row g-1 align-items-center small mb-1">
                            <div class="col-md-2 fw-bold"><i class="fa fa-eye me-1" aria-hidden="true"></i>Mostra solo se</div>
                            <div class="col-md-4"><select class="form-select form-select-sm dd-rif" name="c_cond_campo[]" data-val="<?php echo $h($cond['campo'] ?? ''); ?>" aria-label="Campo della condizione"><option value="">— sempre visibile —</option></select></div>
                            <div class="col-md-2"><select class="form-select form-select-sm" name="c_cond_op[]" aria-label="Condizione"><?php foreach (OPERATORI_CONDIZIONE as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo ($cond['op'] ?? 'uguale') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4"><input class="form-control form-control-sm" name="c_cond_val[]" value="<?php echo $h($cond['valore'] ?? ''); ?>" placeholder="valore (es. trasferimento in entrata)" aria-label="Valore della condizione"></div>
                        </div>
                        <div class="row g-1 align-items-center small">
                            <div class="col-md-2 fw-bold"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i>Compila se</div>
                            <div class="col-md-3"><select class="form-select form-select-sm dd-rif" name="c_auto_campo[]" data-val="<?php echo $h($auto['campo'] ?? ''); ?>" aria-label="Campo del valore automatico"><option value="">— mai —</option></select></div>
                            <div class="col-md-2"><select class="form-select form-select-sm" name="c_auto_op[]" aria-label="Condizione"><?php foreach (OPERATORI_CONDIZIONE as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo ($auto['op'] ?? 'uguale') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-2"><input class="form-control form-control-sm" name="c_auto_val[]" value="<?php echo $h($auto['valore'] ?? ''); ?>" placeholder="valore" aria-label="Valore"></div>
                            <div class="col-md-3"><input class="form-control form-control-sm" name="c_auto_imposta[]" value="<?php echo $h($auto['imposta'] ?? ''); ?>" placeholder="allora questo campo vale…" aria-label="Valore automatico"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="ddAggiungi"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi campo</button>
        </fieldset>

        <fieldset class="dd-online mt-3 border rounded p-2">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1"><i class="fa fa-route me-1 text-primary" aria-hidden="true"></i>Iter della pratica</legend>
            <p class="small text-secondary mb-2">Chi riceve la pratica, in ordine, dopo lo smistamento del manager (es. tutor dell'internazionalizzazione → referente del corso → carriere studenti). Lo studente vede a che punto è. Nessun passo = un solo passo «Operatore».</p>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php $iter_f = json_decode((string)($f['iter_json'] ?? ''), true) ?: []; for ($ip = 0; $ip < 4; $ip++): ?>
                    <?php if ($ip): ?><span class="text-secondary" aria-hidden="true">→</span><?php endif; ?>
                    <select class="form-select form-select-sm dd-iter" name="iter[]" style="max-width:240px;" aria-label="Passo <?php echo $ip + 1; ?>"><option value="">— passo <?php echo $ip + 1; ?> —</option>
                        <?php foreach (uffici_didattica($conn) as $k => $u): if ((int)$u['smista']) continue; ?><option value="<?php echo $k; ?>"<?php echo ufficio_didattica_id($conn, $iter_f[$ip] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h($u['nome']); ?></option><?php endforeach; ?></select>
                <?php endfor; ?>
            </div>
        </fieldset>

        <fieldset class="dd-online mt-3 border rounded p-2">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1"><i class="fa fa-file-word me-1 text-primary" aria-hidden="true"></i>Nel verbale del Consiglio</legend>
            <p class="small text-secondary mb-2">Segnaposto: <code>{STUDENTE}</code> (COGNOME NOME), <code>{NOME}</code>, <code>{COGNOME}</code>, <code>{MATRICOLA}</code>, <code>{MODULO}</code>, <code>{DATA}</code>, <code>{PROTOCOLLO}</code> e ogni domanda tra graffe, es. <code>{Corso di studio}</code>. <code>**testo**</code> = grassetto. Le tabelle a righe compaiono sotto il testo di ogni pratica.</p>
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label small fw-bold" for="vSez">Titolo della sezione</label><input type="text" class="form-control form-control-sm" id="vSez" name="v_sezione" value="<?php echo $h($v_raw['sezione'] ?? ''); ?>" placeholder="Vuoto = titolo del modulo"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="vStile">Impaginazione</label><select class="form-select form-select-sm" id="vStile" name="v_stile">
                    <option value="scheda"<?php echo $v_f['stile'] === 'scheda' ? ' selected' : ''; ?>>Un paragrafo per pratica (con tabelle e delibera)</option>
                    <option value="elenco"<?php echo $v_f['stile'] === 'elenco' ? ' selected' : ''; ?>>Una tabella con una riga per pratica (es. domande di tesi)</option></select></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="vDec">Decisioni in seduta</label><select class="form-select form-select-sm" id="vDec" name="v_decisione">
                    <?php foreach (DECISIONI_SEDUTA as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo $v_f['decisione'] === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select>
                    <div class="form-text">Nella seduta, per ogni insegnamento indicato dallo studente: convalida (con l'insegnamento del Dipartimento, totale o parziale) o inserimento in piano / fuori piano. Va nel verbale come tabella.</div></div>
                <div class="col-12"><label class="form-label small fw-bold" for="vIntro">Testo introduttivo (facoltativo)</label><textarea class="form-control form-control-sm" id="vIntro" name="v_intro" rows="2"><?php echo $h($v_raw['intro'] ?? ''); ?></textarea></div>
                <div class="col-md-7 dd-v-scheda"><label class="form-label small fw-bold" for="vTesto">Testo per ogni pratica</label><textarea class="form-control form-control-sm" id="vTesto" name="v_testo" rows="4" placeholder="<?php echo $h(verbale_modulo(['titolo' => ''])['testo']); ?>"><?php echo $h($v_raw['testo'] ?? ''); ?></textarea></div>
                <div class="col-md-5 dd-v-scheda"><label class="form-label small fw-bold" for="vDel">Delibera predefinita per ogni pratica</label><textarea class="form-control form-control-sm" id="vDel" name="v_delibera" rows="4"><?php echo $h($v_raw['delibera'] ?? 'Il Consiglio approva.'); ?></textarea><div class="form-text">Si può cambiare pratica per pratica nell'istruttoria.</div></div>
                <div class="col-md-7 dd-v-elenco"><label class="form-label small fw-bold" for="vCol">Colonne della tabella</label><input type="text" class="form-control form-control-sm" id="vCol" name="v_colonne" value="<?php echo $h($v_raw['colonne'] ?? ''); ?>" placeholder="COGNOME, NOME, MATRICOLA, RELATORE"><div class="form-text">COGNOME, NOME, MATRICOLA, CODICE, PROTOCOLLO, DELIBERA o le domande del modulo. Vuoto = tutte.</div></div>
                <div class="col-md-5 dd-v-elenco"><label class="form-label small fw-bold" for="vRag">Raggruppa per la domanda</label><input type="text" class="form-control form-control-sm" id="vRag" name="v_raggruppa" value="<?php echo $h($v_raw['raggruppa'] ?? ''); ?>" placeholder="es. Corso di studio"></div>
                <div class="col-12"><label class="form-label small fw-bold" for="vChi">Testo finale della sezione (facoltativo)</label><textarea class="form-control form-control-sm" id="vChi" name="v_chiusura" rows="2" placeholder="es. Il Consiglio approva."><?php echo $h($v_raw['chiusura'] ?? ''); ?></textarea></div>
            </div>
        </fieldset>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" name="salva_modulo" value="1" class="btn btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
            <a href="<?php echo $base; ?>&amp;tab=moduli" class="btn btn-outline-secondary">Annulla</a>
        </div>
    </div></form>
    <script>
    (function () {
        var modelli = <?php foreach ($modelli as &$mm_i) $mm_i[4]['iter'] = array_values(array_filter(array_map(fn($c) => ufficio_didattica_id($conn, $c), $mm_i[4]['iter'] ?? []))); unset($mm_i); echo json_encode($modelli, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var TIPI_COL = <?php echo json_encode(TIPI_COLONNA_TABELLA, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var tipo = document.getElementById('mTipo'), box = document.getElementById('ddCampi'), stile = document.getElementById('vStile');
        function aggiorna() {
            document.querySelectorAll('.dd-online').forEach(function (x) { x.hidden = tipo.value !== 'online'; });
            document.querySelectorAll('.dd-v-scheda').forEach(function (x) { x.hidden = stile.value !== 'scheda'; });
            document.querySelectorAll('.dd-v-elenco').forEach(function (x) { x.hidden = stile.value !== 'elenco'; });
        }
        tipo.addEventListener('change', aggiorna); stile.addEventListener('change', aggiorna); aggiorna();

        // ── Colonne delle tabelle: righe nome + tipo, scritte nelle opzioni come "Nome:tipo" ──
        function leggiColonne(testo) {
            return testo.split(/[,;\n]/).map(function (x) { return x.trim(); }).filter(Boolean).map(function (x) {
                var m = x.match(/^(.*?):\s*([a-z_]+)(?:\((.*)\))?\s*$/);
                if (m && TIPI_COL[m[2]]) return { nome: m[1].trim(), tipo: m[2], scelte: (m[3] || '').split('|').map(function (y) { return y.trim(); }).filter(Boolean).join(' | ') };
                return { nome: x, tipo: 'testo', scelte: '' };
            });
        }
        function rigaCol(c) {
            var d = document.createElement('div'); d.className = 'dd-col-riga';
            var o = Object.keys(TIPI_COL).map(function (k) { return '<option value="' + k + '"' + (k === c.tipo ? ' selected' : '') + '>' + TIPI_COL[k] + '</option>'; }).join('');
            d.innerHTML = '<input class="form-control form-control-sm dc-nome" placeholder="Nome della colonna" aria-label="Nome della colonna"><select class="form-select form-select-sm dc-tipo" aria-label="Tipo della colonna">' + o + '</select>'
                + '<input class="form-control form-control-sm dc-scelte" placeholder="Scelte separate da |" aria-label="Scelte della tendina"><button type="button" class="btn btn-sm btn-link text-danger p-0 dc-togli" aria-label="Togli la colonna"><i class="fa fa-times" aria-hidden="true"></i></button>';
            d.querySelector('.dc-nome').value = c.nome; d.querySelector('.dc-scelte').value = c.scelte || '';
            d.querySelector('.dc-scelte').hidden = c.tipo !== 'scelta';
            return d;
        }
        function disegnaColonne(r) {
            var cont = r.querySelector('.dd-col-righe'); cont.innerHTML = '';
            var cols = leggiColonne(r.querySelector('.dd-opz').value);
            if (!cols.length) cols = [{ nome: 'Insegnamento', tipo: 'insegnamento' }, { nome: 'CFU', tipo: 'cfu' }, { nome: 'Voto', tipo: 'voto' }];
            cols.forEach(function (c) { cont.appendChild(rigaCol(c)); });
            scriviColonne(r);
        }
        function scriviColonne(r) {
            var parti = [];
            r.querySelectorAll('.dd-col-riga').forEach(function (d) {
                var n = d.querySelector('.dc-nome').value.replace(/[,;:]/g, ' ').trim(), t = d.querySelector('.dc-tipo').value;
                d.querySelector('.dc-scelte').hidden = t !== 'scelta';
                if (!n) return;
                parti.push(n + (t !== 'testo' ? ':' + t + (t === 'scelta' ? '(' + d.querySelector('.dc-scelte').value.split('|').map(function (x) { return x.replace(/[,;()]/g, ' ').trim(); }).filter(Boolean).join('|') + ')' : '') : ''));
            });
            r.querySelector('.dd-opz').value = parti.join(', ');
        }
        // ── Logica: tendine con le domande degli altri campi ──
        function aggiornaRif() {
            var et = Array.prototype.map.call(box.querySelectorAll('[name="c_etichetta[]"]'), function (i) { return i.value.trim(); });
            box.querySelectorAll('.dd-campo').forEach(function (r) {
                var mia = r.querySelector('[name="c_etichetta[]"]').value.trim();
                r.querySelectorAll('.dd-rif').forEach(function (s) {
                    var v = s.dataset.val !== undefined ? s.dataset.val : s.value, primo = s.options[0].outerHTML;
                    s.innerHTML = primo + et.filter(function (x) { return x && x !== mia; }).map(function (x) { var o = document.createElement('option'); o.value = x; o.textContent = x; return o.outerHTML; }).join('');
                    if (v && !et.includes(v)) { var o = document.createElement('option'); o.value = v; o.textContent = v + ' (domanda non trovata)'; s.appendChild(o); }
                    s.value = v || ''; s.dataset.val = s.value;
                });
                var attiva = r.querySelector('[name="c_cond_campo[]"]').value || r.querySelector('[name="c_auto_campo[]"]').value;
                var b = r.querySelector('.dd-logica-btn'); b.classList.toggle('btn-warning', !!attiva); b.classList.toggle('btn-outline-secondary', !attiva);
            });
        }
        function preparaRiga(r) {
            var t = r.querySelector('.dd-tipo');
            r.querySelector('.dd-colonne').hidden = t.value !== 'tabella';
            if (t.value === 'tabella') disegnaColonne(r);
        }
        function nuovaRiga() {
            var n = box.lastElementChild.cloneNode(true);
            n.querySelectorAll('input[type=text], .dd-logica input').forEach(function (i) { i.value = ''; });
            n.querySelectorAll('input[type=hidden]').forEach(function (i) { i.value = '0'; });
            n.querySelectorAll('.dd-chk').forEach(function (i) { i.checked = false; });
            n.querySelectorAll('.dd-rif').forEach(function (s) { s.dataset.val = ''; });
            n.querySelector('.dd-col-righe').innerHTML = ''; n.querySelector('.dd-logica').hidden = true;
            n.classList.remove('uff'); n.querySelector('.dd-tipo').value = 'text';
            box.appendChild(n); preparaRiga(n); aggiornaRif(); return n;
        }
        box.querySelectorAll('.dd-campo').forEach(preparaRiga); aggiornaRif();
        document.getElementById('ddAggiungi').addEventListener('click', function () { nuovaRiga().querySelector('input').focus(); });
        box.addEventListener('click', function (e) {
            var b = e.target.closest('.dd-togli');
            if (b) { var r = b.closest('.dd-campo'); if (box.children.length > 1) r.remove(); else r.querySelectorAll('input[type=text]').forEach(function (i) { i.value = ''; }); aggiornaRif(); return; }
            var l = e.target.closest('.dd-logica-btn');
            if (l) { var p = l.closest('.dd-campo').querySelector('.dd-logica'); p.hidden = !p.hidden; aggiornaRif(); return; }
            var ca = e.target.closest('.dd-col-agg');
            if (ca) { var rr = ca.closest('.dd-campo'), nr = rigaCol({ nome: '', tipo: 'testo' }); rr.querySelector('.dd-col-righe').appendChild(nr); nr.querySelector('input').focus(); return; }
            var ct = e.target.closest('.dc-togli');
            if (ct) { var r2 = ct.closest('.dd-campo'); ct.closest('.dd-col-riga').remove(); scriviColonne(r2); }
        });
        box.addEventListener('input', function (e) {
            if (e.target.closest('.dd-col-riga')) scriviColonne(e.target.closest('.dd-campo'));
            if (e.target.classList.contains('dd-opz')) { var r = e.target.closest('.dd-campo'); if (r.querySelector('.dd-tipo').value === 'tabella' && !r.querySelector('.dd-logica').hidden) { var cont = r.querySelector('.dd-col-righe'); cont.innerHTML = ''; leggiColonne(e.target.value).forEach(function (c) { cont.appendChild(rigaCol(c)); }); } }
        });
        box.addEventListener('focusout', function (e) { if (e.target.name === 'c_etichetta[]') aggiornaRif(); });
        box.addEventListener('change', function (e) {
            if (e.target.classList.contains('dd-rif')) { e.target.dataset.val = e.target.value; aggiornaRif(); }
            if (e.target.closest('.dd-col-riga')) scriviColonne(e.target.closest('.dd-campo'));
            if (e.target.classList.contains('dd-tipo')) { var r = e.target.closest('.dd-campo'); preparaRiga(r); if (e.target.value === 'tabella') r.querySelector('.dd-logica').hidden = false; }
            if (!e.target.classList.contains('dd-chk')) return;
            e.target.closest('span').querySelector('input[type=hidden]').value = e.target.checked ? '1' : '0';
            if (e.target.classList.contains('dd-uff')) e.target.closest('.dd-campo').classList.toggle('uff', e.target.checked);
        });
        document.querySelectorAll('.dd-modello').forEach(function (b) {
            b.addEventListener('click', function () {
                var m = modelli[b.dataset.modello]; if (!m) return;
                document.getElementById('mTit').value = m[0]; document.getElementById('mCat').value = m[1]; tipo.value = 'online';
                if (window.tinymce && tinymce.get('mDes')) tinymce.get('mDes').setContent('<p>' + m[2] + '</p>'); else document.getElementById('mDes').value = '<p>' + m[2] + '</p>';
                while (box.children.length > 1) box.lastElementChild.remove();
                m[3].forEach(function (c, i) {
                    var r = i === 0 ? box.firstElementChild : nuovaRiga();
                    r.querySelector('[name="c_etichetta[]"]').value = c[0]; r.querySelector('.dd-tipo').value = c[1]; r.querySelector('[name="c_opzioni[]"]').value = c[2];
                    var chk = r.querySelectorAll('.dd-chk'); chk[0].checked = !!c[3]; chk[1].checked = !!c[4];
                    r.querySelector('[name="c_obbl[]"]').value = c[3] ? '1' : '0'; r.querySelector('[name="c_uff[]"]').value = c[4] ? '1' : '0'; r.classList.toggle('uff', !!c[4]);
                    r.querySelector('[name="c_aiuto[]"]').value = c[6] || '';
                    var cs = r.querySelector('[name="c_cond_campo[]"]'); cs.dataset.val = c[5] ? c[5].campo : '';
                    r.querySelector('[name="c_cond_op[]"]').value = c[5] ? c[5].op : 'uguale'; r.querySelector('[name="c_cond_val[]"]').value = c[5] ? c[5].valore : '';
                    r.querySelector('[name="c_auto_campo[]"]').dataset.val = '';
                    r.querySelector('.dd-col-righe').innerHTML = ''; preparaRiga(r);
                });
                aggiornaRif();
                var v = m[4];
                document.getElementById('vSez').value = v.sezione || ''; stile.value = v.stile || 'scheda';
                document.getElementById('vIntro').value = v.intro || ''; document.getElementById('vTesto').value = v.testo || '';
                document.getElementById('vDel').value = v.delibera !== undefined ? v.delibera : 'Il Consiglio approva.';
                document.getElementById('vCol').value = v.colonne || ''; document.getElementById('vRag').value = v.raggruppa || ''; document.getElementById('vChi').value = v.chiusura || '';
                document.getElementById('vDec').value = v.decisione || '';
                document.querySelectorAll('.dd-iter').forEach(function (s, i) { s.value = (v.iter || [])[i] || ''; });
                aggiorna(); document.getElementById('mTit').focus();
            });
        });
    })();
    </script>
    <?php else: ?>
        <a href="<?php echo $base; ?>&amp;tab=moduli&amp;nuovo=1" class="btn btn-primary btn-sm fw-bold mb-3"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuovo modulo o documento</a>
    <?php endif; ?>

    <?php if (!$moduli): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-muted">Nessun modulo: crea il primo documento da scaricare o il primo modulo online (anche da un modello pronto: tesi, passaggi di corso, attività all'estero).</div></div>
    <?php endif; ?>
    <?php foreach ($categorie as $cat): ?>
        <h6 class="fw-bold text-secondary text-uppercase mt-3 mb-2" style="font-size:.75rem;letter-spacing:.05em;"><?php echo $h($cat); ?></h6>
        <div class="card border-0 shadow-sm"><ul class="list-group list-group-flush small">
        <?php foreach ($moduli as $m): if ($m['categoria'] !== $cat) continue; $cm = campi_modulo($m['campi_json']); ?>
            <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
                <i class="fa <?php echo $m['tipo'] === 'online' ? 'fa-pen-to-square text-success' : 'fa-file-arrow-down text-primary'; ?>" aria-hidden="true"></i>
                <strong><?php echo $h($m['titolo']); ?></strong>
                <span class="text-secondary"><?php echo $m['tipo'] === 'online' ? 'Modulo online · ' . count(campi_studente($cm)) . ' campi' . (count(campi_ufficio($cm)) ? ' + ' . count(campi_ufficio($cm)) . ' dell\'ufficio' : '') . ' · ' . (int)$m['n_pratiche'] . ' pratiche' . ($m['n_aperte'] ? ' (' . (int)$m['n_aperte'] . ' aperte)' : '') : ($m['file_path'] ? 'Documento' : ($m['link'] ? 'Link' : 'Documento senza file')); ?></span>
                <?php if (!(int)$m['attivo']): ?><span class="badge bg-secondary">non pubblicato</span><?php endif; ?>
                <span class="ms-auto d-flex gap-1">
                    <?php if ($m['tipo'] === 'online' && $m['n_pratiche']): ?><a class="btn btn-sm btn-outline-dark py-0" href="<?php echo $base; ?>&amp;tab=pratiche&amp;modulo=<?php echo (int)$m['id']; ?>&amp;stato=tutte">Pratiche</a><?php endif; ?>
                    <?php if ($m['tipo'] === 'online'): ?><a class="btn btn-sm btn-outline-secondary py-0" href="../modulo.php?id=<?php echo (int)$m['id']; ?>" target="_blank" rel="noopener">Anteprima</a><?php endif; ?>
                    <a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $base; ?>&amp;tab=moduli&amp;modifica=<?php echo (int)$m['id']; ?>">Modifica</a>
                    <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="elimina_modulo" value="<?php echo (int)$m['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare il modulo? Se ha pratiche viene solo nascosto." aria-label="Elimina"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
                </span>
            </li>
        <?php endforeach; ?>
        </ul></div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
