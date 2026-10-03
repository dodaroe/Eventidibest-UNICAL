<?php
// inc/fsl.php - Prenotazioni di classe, convenzioni con le scuole (registro, verifica, documenti precompilati) e scheda di valutazione FSL.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('prenotazione_di_classe')) {
    // Prenotazione fatta da un docente per una classe/gruppo: si chiede il numero di studenti (con min/max)
    // e il docente può inserire l'elenco degli studenti.
    // Progetti: quelli dedicati alle scuole. Eventi: "Dedicato alle scuole", "Attività di Formazione Scuola Lavoro"
    // o "Attestati per gli studenti della classe". $dett = riga di progetti_dettagli (null se assente).
    function prenotazione_di_classe(bool $is_progetto, ?array $dett): bool {
        if ($is_progetto) return (int)($dett['per_scuole'] ?? 1) === 1;
        return (int)($dett['attestati'] ?? 0) === 1 || (int)($dett['dedicata_scuole'] ?? 0) === 1 || (int)($dett['convenzione'] ?? 0) === 1;
    }
}

// Convenzione scuola-Dipartimento per la Formazione Scuola Lavoro: modelli (scaricati dal portale) e PEC predefiniti,
// sostituibili per ogni area in Impostazioni area (file caricato in uploads/modelli_convenzione/ oppure link)
if (!defined('CONV_URL_MODELLO'))  define('CONV_URL_MODELLO', 'assets/modelli/Convenzione_FSL_DiBEST.doc');
if (!defined('CONV_URL_ALLEGATO')) define('CONV_URL_ALLEGATO', 'assets/modelli/Allegato_A_FSL_DiBEST.doc');
if (!defined('CONV_PEC'))          define('CONV_PEC', 'dipartimento.best@pec.unical.it');

if (!function_exists('dati_convenzione')) {
    // $cfg = riga di pagine_eventi dell'area: campi vuoti o non validi → valori predefiniti
    // Indirizzi sempre completi (servono anche nelle email): i file del portale diventano https://…/eventi/…
    function dati_convenzione(array $cfg): array {
        $assoluto = fn($v) => preg_match('#^https?://#i', $v) ? $v : rtrim(url_base_sito(), '/') . '/' . ltrim($v, '/');
        $url = function ($v, $def) use ($assoluto) {
            $v = trim((string)$v);
            $ok = preg_match('#^https?://#i', $v) || preg_match('#^(uploads/modelli_convenzione|assets/modelli)/[A-Za-z0-9._-]+$#', $v);
            return $assoluto($ok ? $v : $def);
        };
        $pec = trim((string)($cfg['conv_pec'] ?? ''));
        return [
            'modello'  => $url($cfg['conv_url_modello'] ?? '', CONV_URL_MODELLO),
            'allegato' => $url($cfg['conv_url_allegato'] ?? '', CONV_URL_ALLEGATO),
            'pec'      => filter_var($pec, FILTER_VALIDATE_EMAIL) ? $pec : CONV_PEC,
        ];
    }
}

if (!function_exists('html_istruzioni_convenzione')) {
    // Cosa fare quando la scuola non ha ancora la convenzione (pagina, email, Area personale)
    // $in_attesa = false: prenotazione già confermata a cui si chiede comunque la convenzione
    // $codice (della prenotazione): se l'area usa il modello del Dipartimento si offre la convenzione già compilata
    // con i dati della prenotazione (convenzione_precompilata.php); il codice non va scritto nella PEC.
    function html_istruzioni_convenzione(array $cfg, bool $per_email = false, string $codice = '', bool $in_attesa = true): string {
        $c = dati_convenzione($cfg);
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $a = $per_email ? " style='color:#B30000;font-weight:bold;'" : " target='_blank' rel='noopener' class='fw-bold'";
        $nome_file = fn($u) => strtoupper(pathinfo((string)parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION));
        $fmt = fn($u) => in_array($nome_file($u), ['DOC', 'DOCX', 'PDF', 'ODT'], true) ? ' <span style="font-weight:normal;">(' . $nome_file($u) . ')</span>' : '';
        $frase = $in_attesa ? "La prenotazione resta <strong>in attesa</strong> finché la scuola non stipula la convenzione con il Dipartimento."
                            : "Per partecipare la scuola deve stipulare la <strong>convenzione</strong> con il Dipartimento.";
        $precompilata = $codice !== '' && empty($cfg['conv_url_modello']) && is_file(RADICE_SITO . '/modelli_documenti/convenzione_precompilabile.docx');
        $allegato_pre = $codice !== '' && empty($cfg['conv_url_allegato']) && is_file(RADICE_SITO . '/modelli_documenti/allegato_a_precompilabile.docx');
        $voce_all = $allegato_pre
            ? "<a href='" . $h(url_base_sito() . '/convenzione_precompilata.php?doc=allegato&code=' . urlencode($codice)) . "'$a>Scarica l'Allegato A già compilato</a> <span style='font-weight:normal;'>(DOCX)</span>"
              . " · <a href='" . $h($c['allegato']) . "'" . ($per_email ? " style='color:#B30000;'" : " target='_blank' rel='noopener'") . ">modello vuoto</a>"
            : "<a href='" . $h($c['allegato']) . "'$a>Scarica l'Allegato A</a>" . $fmt($c['allegato']);
        $voce_conv = $precompilata
            ? "<a href='" . $h(url_base_sito() . '/convenzione_precompilata.php?code=' . urlencode($codice)) . "'$a>Scarica la Convenzione già compilata</a> <span style='font-weight:normal;'>(DOCX, con i dati della scuola e della prenotazione: completa i campi evidenziati in giallo)</span>"
              . " · <a href='" . $h($c['modello']) . "'" . ($per_email ? " style='color:#B30000;'" : " target='_blank' rel='noopener'") . ">modello vuoto</a>"
            : "<a href='" . $h($c['modello']) . "'$a>Scarica il modello di Convenzione</a>" . $fmt($c['modello']);
        // Con il modello del Dipartimento la scuola compila Convenzione e Allegato A online (convenzione_online.php):
        // nel modulo di prenotazione (senza codice) si annuncia, dopo la prenotazione c'è il link
        $online = empty($cfg['conv_url_modello']) && is_file(RADICE_SITO . '/modelli_documenti/convenzione_precompilabile.docx');
        if ($online && $codice === '')
            return "<p style='margin:0 0 6px;'>$frase</p><p style='margin:0;'><strong>Al termine della prenotazione</strong> potrai compilare online la <strong>Convenzione</strong> e l'<strong>Allegato A</strong> con un modulo guidato: trovi il link nella pagina di conferma e nell'email. Il Dirigente li firma digitalmente in PAdES (PDF firmato, non .p7m) e la scuola li invia via PEC a <a href='mailto:" . $h($c['pec']) . "'$a>" . $h($c['pec']) . "</a>.</p>";
        if ($online) {
            $url_on = url_base_sito() . '/convenzione_online.php?code=' . urlencode($codice);
            $bottone = $per_email ? "<p style='margin:12px 0;'><a href='" . $h($url_on) . "' style='background:#B30000;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Compila online la Convenzione e l'Allegato A</a></p>"
                                  : "<p style='margin:8px 0;'><a href='" . $h($url_on) . "' class='btn btn-danger btn-sm fw-bold'><i class='fa fa-file-signature me-1'></i>Compila online la Convenzione e l'Allegato A</a></p>";
            return "<p style='margin:0 0 6px;'>$frase Compila online i documenti con un modulo guidato (dati della scuola e del Dirigente, attività da inserire nell'Allegato A, logo della scuola): li scarichi già pronti in Word.</p>"
                 . $bottone
                 . "<p style='margin:0;'>Poi il Dirigente li <strong>firma digitalmente in PAdES</strong> (PDF firmato, non .p7m) e la scuola li invia via PEC a <a href='mailto:" . $h($c['pec']) . "'$a>" . $h($c['pec']) . "</a>. "
                 . ($in_attesa ? "Appena riceviamo la convenzione confermiamo la prenotazione e ti avvisiamo per email." : "Se la scuola l'ha già inviata, puoi ignorare questo messaggio.")
                 . " <span style='font-size:.9em;'>Preferisci i modelli vuoti? <a href='" . $h($c['modello']) . "'" . ($per_email ? " style='color:#B30000;'" : '') . ">Convenzione</a> · <a href='" . $h($c['allegato']) . "'" . ($per_email ? " style='color:#B30000;'" : '') . ">Allegato A</a></span></p>";
        }
        return "<p style='margin:0 0 6px;'>$frase Compila i modelli:</p>"
             . "<ul style='margin:0 0 6px;'><li>$voce_conv</li><li>$voce_all</li></ul>"
             . "<p style='margin:0;'>e inviali <strong>firmati digitalmente in PAdES</strong> (PDF firmato, non .p7m) alla PEC <a href='mailto:" . $h($c['pec']) . "'$a>" . $h($c['pec']) . "</a>. "
             . ($in_attesa ? "Appena riceviamo la convenzione confermiamo la prenotazione e ti avvisiamo per email.</p>" : "Se la scuola l'ha già inviata, puoi ignorare questo messaggio.</p>");
    }
}

if (!defined('CONV_DURATA_ANNI')) define('CONV_DURATA_ANNI', 1); // durata proposta: il modello del Dipartimento vale un anno dalla stipula (art. 8)

if (!function_exists('periodo_attivita')) {
    // Periodo da coprire con la convenzione: progetto dal/al, evento il giorno del turno; senza date: oggi
    function periodo_attivita(?string $inizio, ?string $fine, ?string $data_turno = null): array {
        $dal = $inizio ?: ($data_turno ?: ($fine ?: null));
        $al  = $fine ?: ($data_turno ?: $dal);
        if (!$dal) $dal = $al = date('Y-m-d');
        if ($al < $dal) $al = $dal;
        return [$dal, $al];
    }
}

if (!function_exists('periodo_prenotazione')) {
    // $p con pd_inizio, pd_fine (progetti_dettagli) e data_turno
    function periodo_prenotazione(array $p): array {
        return periodo_attivita($p['pd_inizio'] ?? null, $p['pd_fine'] ?? null, $p['data_turno'] ?? null);
    }
}

if (!function_exists('convenzione_valida')) {
    // Convenzione del registro (pannello Formazione Scuola Lavoro) valida per TUTTO il periodo $dal-$al (default: oggi), null se non c'è.
    // Valida dal (data_stipula) vuoto = da sempre; valida fino al (scadenza) vuoto = senza scadenza.
    // $rileggi = true dopo averne registrata o modificata una nella stessa richiesta.
    function convenzione_valida($conn, ?string $codice, bool $rileggi = false, ?string $dal = null, ?string $al = null): ?array {
        static $cache = [];
        $codice = strtoupper(trim((string)$codice));
        if (!preg_match('/^[A-Z0-9]{10}$/', $codice)) return null;
        $dal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dal) ? $dal : date('Y-m-d');
        $al  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$al) && $al >= $dal ? $al : $dal;
        $k = "$codice|$dal|$al";
        if ($rileggi) $cache = [];
        if (!array_key_exists($k, $cache)) {
            $st = $conn->prepare("SELECT * FROM convenzioni_scuole WHERE scuola_codice = ? AND (data_stipula IS NULL OR data_stipula <= ?) AND (scadenza IS NULL OR scadenza >= ?)
                                  ORDER BY (scadenza IS NULL) DESC, scadenza DESC LIMIT 1");
            if (!$st) return null;
            $st->bind_param("sss", $codice, $dal, $al); $st->execute();
            $cache[$k] = $st->get_result()->fetch_assoc() ?: null;
        }
        return $cache[$k];
    }
}

if (!function_exists('convenzioni_della_scuola')) {
    // Tutte le convenzioni della scuola nel registro, dalla più recente
    function convenzioni_della_scuola($conn, ?string $codice): array {
        $codice = strtoupper(trim((string)$codice));
        if (!preg_match('/^[A-Z0-9]{10}$/', $codice)) return [];
        $st = $conn->prepare("SELECT * FROM convenzioni_scuole WHERE scuola_codice = ? ORDER BY (scadenza IS NULL) DESC, scadenza DESC, id DESC");
        if (!$st) return [];
        $st->bind_param("s", $codice); $st->execute();
        return $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('testo_validita_convenzione')) {
    // "dal 01/10/2026 al 30/09/2029", "fino al …", "senza scadenza"
    function testo_validita_convenzione(array $c): string {
        $d = fn($x) => date('d/m/Y', strtotime($x));
        if (!empty($c['data_stipula']) && !empty($c['scadenza'])) return 'dal ' . $d($c['data_stipula']) . ' al ' . $d($c['scadenza']);
        if (!empty($c['scadenza'])) return 'fino al ' . $d($c['scadenza']);
        return !empty($c['data_stipula']) ? 'dal ' . $d($c['data_stipula']) . ', senza scadenza' : 'senza scadenza';
    }
}

if (!function_exists('dati_prenotazione_convenzione')) {
    // Prenotazione con turno, evento, periodo dell'attività e impostazioni dell'area (modelli e PEC della convenzione)
    function dati_prenotazione_convenzione($conn, int $pr_id): ?array {
        $r = $conn->query("SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.richiede_approvazione, t.evento_id,
                                  e.titolo AS evento_titolo, e.pagina_id, pe.conv_url_modello, pe.conv_url_allegato, pe.conv_pec,
                                  pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine, IFNULL(pd.convenzione, 0) AS fsl
                           FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                           JOIN pagine_eventi pe ON e.pagina_id = pe.id LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
                           WHERE pr.id = " . (int)$pr_id . " LIMIT 1");
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }
}

if (!function_exists('email_richiesta_convenzione')) {
    // Email alla scuola con modelli e PEC. $tipo: 'richiesta' (pulsante in Iscrizioni) | 'promemoria' (cron)
    function email_richiesta_convenzione($conn, int $pr_id, string $tipo = 'richiesta'): bool {
        $p = dati_prenotazione_convenzione($conn, $pr_id);
        if (!$p || empty($p['email']) || !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) return false;
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $in_attesa = in_array($p['stato'], ['da_approvare', 'in_attesa', 'richiesta_conferma'], true);
        $att = $h($p['evento_titolo']) . (etichetta_turno($p) !== '' ? ' (' . $h(etichetta_turno($p)) . ')' : '');
        [$dal, $al] = periodo_prenotazione($p);
        $periodo = $dal === $al ? 'il ' . date('d/m/Y', strtotime($dal)) : 'dal ' . date('d/m/Y', strtotime($dal)) . ' al ' . date('d/m/Y', strtotime($al));
        // Convenzione già registrata ma che non copre il periodo: va stipulata una nuova
        $ultima = !empty($p['scuola_codice']) ? (convenzioni_della_scuola($conn, $p['scuola_codice'])[0] ?? null) : null;
        $nota = $ultima ? "<p>La convenzione della scuola che risulta al Dipartimento (valida " . $h(testo_validita_convenzione($ultima)) . ") <strong>non copre il periodo dell'attività</strong> ($periodo): va stipulata una <strong>nuova convenzione</strong>.</p>" : '';
        $intro = $tipo === 'promemoria'
            ? "<p>ti ricordiamo che per la prenotazione di <strong>$att</strong> non abbiamo ancora ricevuto la convenzione della scuola con il Dipartimento.</p>"
            : "<p>per la prenotazione di <strong>$att</strong> ($periodo) la scuola deve avere una convenzione con il Dipartimento per la Formazione Scuola Lavoro valida per tutto il periodo dell'attività.</p>";
        $corpo = "<p>Gentile <strong>" . $h(trim($p['nome'] . ' ' . $p['cognome'])) . "</strong>,</p>" . $intro . $nota
               . "<p>🎟️ Codice della prenotazione: <strong>" . $h($p['codice_prenotazione']) . "</strong></p>"
               . html_istruzioni_convenzione($p, true, (string)$p['codice_prenotazione'], $in_attesa);
        $oggetto = ($tipo === 'promemoria' ? "Promemoria: convenzione da inviare - " : "Convenzione con il Dipartimento - ") . $p['evento_titolo'];
        return (bool)inviaNotificaEmail($p['email'], $oggetto, $corpo, $conn, colore_area_turno($conn, (int)$p['turno_id']));
    }
}

if (!function_exists('segna_convenzione_ricevuta')) {
    // La convenzione della scuola è arrivata (o è nel registro): la prenotazione passa a "ricevuta" e, se era
    // da approvare solo per la convenzione (turno senza approvazione), diventa confermata con l'email alla scuola.
    function segna_convenzione_ricevuta($conn, int $pr_id, bool $email = true): bool {
        $p = dati_prenotazione_convenzione($conn, $pr_id);
        if (!$p || ($p['convenzione'] ?? '') === 'ricevuta') return false;
        $conn->query("UPDATE prenotazioni SET convenzione = 'ricevuta' WHERE id = " . (int)$pr_id);
        $confermata = false;
        if ($p['stato'] === 'da_approvare' && (int)$p['richiede_approvazione'] === 0) {
            $conn->query("UPDATE prenotazioni SET stato = 'confermata' WHERE id = " . (int)$pr_id . " AND stato = 'da_approvare'");
            if ($conn->affected_rows > 0) { $confermata = true; decadi_attese_vincolate($conn, $pr_id); }
        }
        // Email solo a chi aspettava la convenzione ('no'); chi l'aveva dichiarata non ha nulla da fare
        if ($email && ($p['convenzione'] ?? '') === 'no' && !empty($p['email']) && in_array($p['stato'], ['da_approvare', 'confermata', 'in_attesa', 'richiesta_conferma'], true)) {
            $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $att = $h($p['evento_titolo']) . (etichetta_turno($p) !== '' ? ' (' . $h(etichetta_turno($p)) . ')' : '');
            $link = url_base_sito() . "/stampa_ricevuta.php?code=" . urlencode($p['codice_prenotazione']);
            $stato_txt = $confermata || $p['stato'] === 'confermata' ? "La prenotazione per <strong>$att</strong> è <strong>CONFERMATA</strong>."
                       : ($p['stato'] === 'da_approvare' ? "La richiesta per <strong>$att</strong> resta in valutazione degli organizzatori: riceverai l'esito per email."
                       : "La prenotazione per <strong>$att</strong> resta in lista d'attesa: se si libera un posto ti avvisiamo per email.");
            $corpo = "<p>Gentile <strong>" . $h(trim($p['nome'] . ' ' . $p['cognome'])) . "</strong>,</p>"
                   . "<p>abbiamo ricevuto la <strong>convenzione</strong> della scuola con il Dipartimento. $stato_txt</p>"
                   . "<p style='margin-top:15px;'><a href='" . $h($link) . "' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica Ricevuta PDF</a></p>";
            inviaNotificaEmail($p['email'], "Convenzione ricevuta: " . $p['evento_titolo'], $corpo, $conn, colore_area_turno($conn, (int)$p['turno_id']));
        }
        return true;
    }
}

if (!function_exists('applica_convenzioni_scuola')) {
    // Dopo una registrazione o modifica nel registro: le prenotazioni della scuola in attesa (o dichiarate) il cui
    // periodo è coperto da una convenzione diventano "ricevuta". Ritorna quante sono state aggiornate.
    function applica_convenzioni_scuola($conn, string $codice): int {
        $n = 0;
        $st_p = $conn->prepare("SELECT pr.id, t.data_turno, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine
                                FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id LEFT JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
                                WHERE pr.scuola_codice = ? AND pr.convenzione IN ('no', 'si')
                                  AND IFNULL(pr.stato, 'confermata') IN ('da_approvare', 'confermata', 'in_attesa', 'richiesta_conferma')");
        if (!$st_p) return 0;
        $st_p->bind_param("s", $codice); $st_p->execute();
        $r = $st_p->get_result();
        while ($r && $x = $r->fetch_assoc()) {
            [$dal, $al] = periodo_prenotazione($x);
            if (convenzione_valida($conn, $codice, true, $dal, $al) && segna_convenzione_ricevuta($conn, (int)$x['id'])) $n++;
        }
        return $n;
    }
}

if (!function_exists('salva_convenzione')) {
    // Registra (id = 0) o modifica una convenzione. $d: scuola_codice, data_stipula (valida dal), scadenza (valida fino al),
    // protocollo, note, docenti (array di ['nome' =>, 'email' =>]), file_convenzione, file_allegato (percorsi già salvati; null = invariati).
    // Ritorna [id, prenotazioni aggiornate] oppure null (dati non validi).
    function salva_convenzione($conn, array $d, int $id = 0, string $autore = ''): ?array {
        $codice = strtoupper(trim((string)($d['scuola_codice'] ?? '')));
        if (!scuola_per_codice($conn, $codice)) return null;
        $data_ok = fn($x) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$x) ? $x : null;
        $dal = $data_ok($d['data_stipula'] ?? null); $al = $data_ok($d['scadenza'] ?? null);
        if ($dal && $al && $al < $dal) return null;
        $prot = mb_substr(trim((string)($d['protocollo'] ?? '')), 0, 100); $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 500);
        $docenti = [];
        foreach ((array)($d['docenti'] ?? []) as $doc) {
            $nome = mb_substr(trim((string)($doc['nome'] ?? '')), 0, 150); $email = strtolower(trim((string)($doc['email'] ?? '')));
            if ($nome === '' && $email === '') continue;
            $docenti[] = ['nome' => $nome, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ''];
        }
        $doc_json = $docenti ? json_encode($docenti, JSON_UNESCAPED_UNICODE) : null;
        $autore = mb_substr($autore, 0, 255);
        if ($id > 0) {
            $st = $conn->prepare("UPDATE convenzioni_scuole SET scuola_codice = ?, data_stipula = ?, scadenza = ?, protocollo = ?, note = ?, docenti_json = ?, avviso_scadenza_inviato = 0 WHERE id = ?");
            if (!$st) return null;
            $st->bind_param("ssssssi", $codice, $dal, $al, $prot, $note, $doc_json, $id);
            if (!$st->execute()) return null;
        } else {
            $st = $conn->prepare("INSERT INTO convenzioni_scuole (scuola_codice, data_stipula, scadenza, protocollo, note, docenti_json, registrata_da) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if (!$st) return null;
            $st->bind_param("sssssss", $codice, $dal, $al, $prot, $note, $doc_json, $autore);
            if (!$st->execute()) return null;
            $id = (int)$conn->insert_id;
        }
        foreach (['file_convenzione', 'file_allegato'] as $col) {
            if (!empty($d[$col])) {
                $st_f = $conn->prepare("UPDATE convenzioni_scuole SET $col = ? WHERE id = ?");
                $st_f->bind_param("si", $d[$col], $id); $st_f->execute();
            }
        }
        convenzione_valida($conn, $codice, true);
        return [$id, applica_convenzioni_scuola($conn, $codice)];
    }
}

if (!function_exists('registra_convenzione')) {
    // Scorciatoia per una convenzione senza file (conferma da Iscrizioni, pulsante "Ricevuta")
    function registra_convenzione($conn, string $codice, ?string $dal, ?string $al, string $protocollo = '', string $note = '', string $autore = ''): ?array {
        return salva_convenzione($conn, ['scuola_codice' => $codice, 'data_stipula' => $dal, 'scadenza' => $al, 'protocollo' => $protocollo, 'note' => $note], 0, $autore);
    }
}

if (!function_exists('periodo_nuova_convenzione')) {
    // Validità proposta per una convenzione appena arrivata: da oggi (o dall'inizio dell'attività, se prima)
    // per la durata predefinita, allungata se l'attività finisce dopo
    function periodo_nuova_convenzione(?string $att_dal = null, ?string $att_al = null): array {
        $dal = date('Y-m-d'); $al = date('Y-m-d', strtotime('+' . CONV_DURATA_ANNI . ' years -1 day'));
        if ($att_dal && $att_dal < $dal) $dal = $att_dal;
        if ($att_al && $att_al > $al) $al = $att_al;
        return [$dal, $al];
    }
}

if (!function_exists('convenzione_ricevuta_da_gestore')) {
    // Un gestore conferma che la convenzione è arrivata: se la scuola è dell'anagrafe e nel registro non c'è una convenzione
    // che copre il periodo dell'attività, la si registra, così vale anche per le altre prenotazioni e le prossime iscrizioni.
    function convenzione_ricevuta_da_gestore($conn, int $pr_id, string $autore = ''): bool {
        $p = dati_prenotazione_convenzione($conn, $pr_id);
        if (!$p) return false;
        $cod = (string)($p['scuola_codice'] ?? '');
        [$att_dal, $att_al] = periodo_prenotazione($p);
        if ($cod !== '' && !convenzione_valida($conn, $cod, false, $att_dal, $att_al)) {
            [$dal, $al] = periodo_nuova_convenzione($att_dal, $att_al);
            registra_convenzione($conn, $cod, $dal, $al, '', 'Registrata alla conferma della prenotazione ' . $p['codice_prenotazione'] . ': completa con i file e i docenti dell\'Allegato A', $autore);
        }
        // Se la registrazione ha già aggiornato la prenotazione, segna_... non ha altro da fare: conta lo stato finale
        return segna_convenzione_ricevuta($conn, $pr_id) || ((dati_prenotazione_convenzione($conn, $pr_id)['convenzione'] ?? '') === 'ricevuta');
    }
}

if (!function_exists('dati_convenzione_precompilata')) {
    // Dati per la convenzione precompilata (modelli_documenti/convenzione_precompilabile.docx): scuola dall'anagrafe
    // (istituto principale se c'è), attività, studenti, periodo, durata e tutor. Vuoto = campo lasciato da compilare.
    function dati_convenzione_precompilata($conn, array $p): array {
        $s = !empty($p['scuola_codice']) ? scuola_per_codice($conn, $p['scuola_codice']) : null;
        $ist = $s && !empty($s['istituto_codice']) ? scuola_per_codice($conn, $s['istituto_codice']) : null;
        $sede = $ist ?: $s;
        $nome_ist = $s ? maiuscole_scuola((string)($s['istituto_denominazione'] ?: $s['denominazione'])) : '';
        $cod_ist = $s ? (string)($s['istituto_codice'] ?: $s['codice']) : '';
        $dett = get_dettagli_progetti($conn, [(int)$p['evento_id']])[(int)$p['evento_id']] ?? [];
        $r_ev = $conn->query("SELECT descrizione, descrizione_breve, tipo FROM eventi WHERE id = " . (int)$p['evento_id']);
        $ev = $r_ev ? ($r_ev->fetch_assoc() ?: []) : [];
        $testo = fn($html) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', (string)$html)), ENT_QUOTES, 'UTF-8')));
        $descr = $testo($ev['descrizione_breve'] ?? '') ?: $testo($dett['obiettivi'] ?? '') ?: $testo($ev['descrizione'] ?? '');
        if (mb_strlen($descr) > 600) $descr = rtrim(mb_substr($descr, 0, 597)) . '…';
        $descr = rtrim($descr, " .");   // nel modello dopo la descrizione c'è già il punto
        [$dal, $al] = periodo_prenotazione($p);
        $periodo = $dal === $al ? date('d/m/Y', strtotime($dal)) : 'dal ' . date('d/m/Y', strtotime($dal)) . ' al ' . date('d/m/Y', strtotime($al));
        $durata = '';
        if (!empty($dett['ore_totali'])) $durata = (int)$dett['ore_totali'] . ' ore';
        elseif (!empty($p['orario_inizio']) && !empty($p['orario_fine'])) {
            $min = (strtotime($p['orario_fine']) - strtotime($p['orario_inizio'])) / 60;
            if ($min > 0) $durata = rtrim(rtrim(number_format($min / 60, 1, ',', ''), '0'), ',') . ' ore (' . orario_turno($p) . ')';
        }
        $tutor_dip = '';
        foreach ((array)($dett['referenti'] ?? []) as $ref) if (!empty($ref['nome'])) { $tutor_dip = $ref['nome']; break; }
        $custom = json_decode((string)($p['dati_custom_json'] ?? ''), true) ?: [];
        $tutor_sc = trim((string)($custom['docente_riferimento'] ?? '')) ?: trim($p['nome'] . ' ' . $p['cognome']);
        return [
            'ISTITUTO'       => $nome_ist !== '' ? $nome_ist . ' (codice meccanografico ' . $cod_ist . ')' : '',
            'COMUNE'         => $sede ? maiuscole_scuola((string)$sede['comune']) . (!empty($sede['provincia']) ? ' (' . maiuscole_scuola((string)$sede['provincia']) . ')' : '') : '',
            'INDIRIZZO'      => $sede && !empty($sede['indirizzo']) ? maiuscole_scuola((string)$sede['indirizzo']) . (!empty($sede['cap']) ? ', ' . $sede['cap'] : '') : '',
            'ISTITUTO_FIRMA' => $nome_ist,
            'TITOLO'         => (string)$p['evento_titolo'] . (etichetta_turno($p) !== '' && ($ev['tipo'] ?? '') !== 'progetto' ? ' – ' . etichetta_turno($p) : ''),
            'DESCRIZIONE'    => $descr,
            'STUDENTI'       => (int)($custom[CAMPO_PARTECIPANTI] ?? 0) > 0 ? (string)(int)$custom[CAMPO_PARTECIPANTI] : '',
            'PERIODO'        => $periodo,
            'DURATA'         => $durata,
            'TUTOR_DIBEST'   => $tutor_dip,
            'TUTOR_SCUOLA'   => $tutor_sc,
        ];
    }
}

if (!defined('CONV_SEGNAPOSTI')) define('CONV_SEGNAPOSTI', [
    // Testo originale del modello per i segnaposto lasciati vuoti (resta evidenziato in giallo da completare)
    'ISTITUTO' => 'Denominazione Istituzione Scolastica', 'COMUNE' => 'xxxx', 'INDIRIZZO' => 'xxx', 'ISTITUTO_FIRMA' => '…………………………',
    'CF_ISTITUTO' => 'xxxxxx', 'DIRIGENTE' => 'Dott./Dott.ssa xxxxxx XXXX', 'DIR_LUOGO_NASCITA' => 'xxxx', 'DIR_DATA_NASCITA' => 'xx/xx/xxxx',
    'DIR_CF' => 'XXXXXXXXXXXXXXXX', 'DIRIGENTE_FIRMA' => 'Dott./Dott.ssa………………..',
    'TITOLO' => '……………………', 'DESCRIZIONE' => '…………………………………', 'STUDENTI' => '……………', 'PERIODO' => '…', 'DURATA' => '……',
    'TUTOR_DIBEST' => 'Prof./Prof.ssa ___________________', 'TUTOR_SCUOLA' => '___________________',
]);

if (!function_exists('genera_docx_convenzione')) {
    // Documento (.docx) dal modello del Dipartimento ('convenzione' o 'allegato'): $scuola = segnaposto della scuola e del Dirigente,
    // $attivita = una riga per attività (TITOLO, DESCRIZIONE, STUDENTI, PERIODO, DURATA, TUTOR_DIBEST, TUTOR_SCUOLA): il blocco
    // dell'Allegato A ("Titolo corso" … riga tratteggiata) si ripete per ogni attività. $logo = immagine della scuola in testa.
    // I campi compilati perdono l'evidenziazione gialla; quelli vuoti restano evidenziati con il testo originale.
    function genera_docx_convenzione(string $doc, array $scuola, array $attivita, ?string $logo = null, string $protocollo = ''): ?string {
        $modello = RADICE_SITO . '/modelli_documenti/' . ($doc === 'allegato' ? 'allegato_a' : 'convenzione') . '_precompilabile.docx';
        if (!is_file($modello) || !class_exists('ZipArchive')) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'conv') . '.docx';
        if (!@copy($modello, $tmp)) return null;
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) { @unlink($tmp); return null; }
        $xml = (string)$zip->getFromName('word/document.xml');
        $sostituisci = function (string $xml, array $valori) {
            foreach (CONV_SEGNAPOSTI as $k => $orig) {
                if (!array_key_exists($k, $valori)) continue;
                $xml = preg_replace_callback('#<w:r\b(?:(?!</w:r>).)*?\{\{' . $k . '\}\}(?:(?!</w:r>).)*?</w:r>#s', function ($m) use ($k, $orig, $valori) {
                    $v = trim((string)($valori[$k] ?? ''));
                    $run = $m[0];
                    if ($v === '') return str_replace('{{' . $k . '}}', htmlspecialchars($orig, ENT_XML1, 'UTF-8'), $run);
                    $run = preg_replace('#<w:highlight [^>]*/>#', '', $run);
                    $run = str_replace('w:val="FF0000"', 'w:val="000000"', $run);
                    return str_replace('{{' . $k . '}}', htmlspecialchars($v, ENT_XML1, 'UTF-8'), $run);
                }, $xml);
            }
            return $xml;
        };
        // Blocco dell'Allegato A ripetuto per ogni attività
        if (preg_match_all('#<w:p\b(?:(?!<w:p\b).)*?</w:p>#s', $xml, $mm, PREG_OFFSET_CAPTURE)) {
            $ini = $fin = null;
            foreach ($mm[0] as [$p, $pos]) {
                if ($ini === null && str_contains($p, '{{TITOLO}}')) $ini = $pos;
                elseif ($ini !== null && preg_match('/-{10,}/', strip_tags($p))) { $fin = $pos + strlen($p); break; }
            }
            if ($ini !== null && $fin !== null) {
                $blocco = substr($xml, $ini, $fin - $ini);
                $nuovi = '';
                $vuoti = array_fill_keys(['TITOLO', 'DESCRIZIONE', 'STUDENTI', 'PERIODO', 'DURATA', 'TUTOR_DIBEST', 'TUTOR_SCUOLA'], '');
                foreach ($attivita ?: [[]] as $a) {
                    $b = $sostituisci($blocco, $a + $vuoti);
                    // Attività compilata: le etichette ("Titolo corso", "Periodo"…) non restano evidenziate; restano gialli solo i campi vuoti
                    if (trim((string)($a['TITOLO'] ?? '')) !== '') {
                        $originali = array_values(CONV_SEGNAPOSTI);
                        $b = preg_replace_callback('#<w:pPr>.*?</w:pPr>#s', fn($m) => preg_replace('#<w:highlight [^>]*/>#', '', $m[0]), $b);
                        $b = preg_replace_callback('#<w:r\b(?:(?!</w:r>).)*?</w:r>#s', function ($m) use ($originali) {
                            if (!str_contains($m[0], '<w:highlight')) return $m[0];
                            preg_match_all('#<w:t(?: [^>]*)?>(.*?)</w:t>#s', $m[0], $tt);
                            $t = trim(html_entity_decode(implode('', $tt[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            foreach ($originali as $o) if ($t !== '' && str_contains($t, $o)) return $m[0];
                            return preg_replace('#<w:highlight [^>]*/>#', '', $m[0]);
                        }, $b);
                    }
                    $nuovi .= $b;
                }
                $xml = substr($xml, 0, $ini) . $nuovi . substr($xml, $fin);
            }
        }
        $xml = $sostituisci($xml, $scuola + array_fill_keys(array_keys(CONV_SEGNAPOSTI), ''));
        // Protocollo (assegnato dal Dipartimento): in alto a destra, prima del testo
        if (trim($protocollo) !== '') $xml = preg_replace('#<w:body>#', '<w:body><w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:rPr><w:sz w:val="20"/></w:rPr><w:t xml:space="preserve">' . htmlspecialchars('Prot. n. ' . trim($protocollo), ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>', $xml, 1);
        // Firma compilata: "Il Dirigente Scolastico dell'Istituto" non resta evidenziato
        if (trim((string)($scuola['ISTITUTO_FIRMA'] ?? '')) !== '')
            $xml = preg_replace_callback('#<w:r\b(?:(?!</w:r>).)*?</w:r>#s', fn($m) => preg_match('#<w:t(?: [^>]*)?>[^<]*(Il Dirigente Scolastico|dell.Istituto)[^<]*</w:t>#u', $m[0]) ? preg_replace('#<w:highlight [^>]*/>#', '', $m[0]) : $m[0], $xml);
        // Logo della scuola nell'intestazione, al posto della scritta "Logo/intestazione Istituzione Scolastica" (alto 1,5 cm)
        if ($logo && is_file($logo) && ($dim = @getimagesize($logo))) {
            $ext = $dim[2] === IMAGETYPE_PNG ? 'png' : 'jpeg';
            $zip->addFile($logo, 'word/media/logo_scuola.' . $ext);
            $ct = (string)$zip->getFromName('[Content_Types].xml');
            if (!preg_match('/Extension="' . $ext . '"/i', $ct)) $zip->addFromString('[Content_Types].xml', str_replace('</Types>', '<Default Extension="' . $ext . '" ContentType="image/' . $ext . '"/></Types>', $ct));
            $cy = 540000; $cx = (int)round($cy * $dim[0] / max(1, $dim[1])); if ($cx > 2340000) { $cy = (int)round($cy * 2340000 / $cx); $cx = 2340000; }
            $disegno = '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="9001" name="Logo della scuola"/>'
                 . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name="logo_scuola"/><pic:cNvPicPr/></pic:nvPicPr>'
                 . '<pic:blipFill><a:blip r:embed="rIdLogoScuola" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>';
            $nel_header = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nome = $zip->getNameIndex($i);
                if (!preg_match('#^word/header\d+\.xml$#', $nome)) continue;
                $hx = (string)$zip->getFromName($nome);
                if (!str_contains($hx, 'Logo/intestazione Istituzione Scolastica')) continue;
                $hx = preg_replace('#(<w:r\b(?:(?!</w:r>).)*?)<w:t>Logo/intestazione Istituzione Scolastica</w:t>(</w:r>)#s', '$1' . $disegno . '$2', $hx, 1);
                $zip->addFromString($nome, $hx);
                $rn = 'word/_rels/' . basename($nome) . '.rels';
                $rels = (string)$zip->getFromName($rn) ?: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
                $zip->addFromString($rn, str_replace('</Relationships>', '<Relationship Id="rIdLogoScuola" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo_scuola.' . $ext . '"/></Relationships>', $rels));
                $nel_header = true;
            }
            if (!$nel_header) {
                // Modello senza la scritta nell'intestazione: logo in testa al documento
                $rels = (string)$zip->getFromName('word/_rels/document.xml.rels');
                $zip->addFromString('word/_rels/document.xml.rels', str_replace('</Relationships>', '<Relationship Id="rIdLogoScuola" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo_scuola.' . $ext . '"/></Relationships>', $rels));
                $xml = preg_replace('#<w:body>#', '<w:body><w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r>' . $disegno . '</w:r></w:p>', $xml, 1);
            }
        }
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        return $tmp;
    }
}

if (!function_exists('genera_convenzione_precompilata')) {
    // Convenzione o Allegato A precompilati con i dati di una prenotazione (link "già compilata" delle email precedenti)
    function genera_convenzione_precompilata($conn, int $pr_id, string $doc = 'convenzione'): ?string {
        $p = dati_prenotazione_convenzione($conn, $pr_id);
        if (!$p) return null;
        $v = dati_convenzione_precompilata($conn, $p);
        $att = array_intersect_key($v, array_flip(['TITOLO', 'DESCRIZIONE', 'STUDENTI', 'PERIODO', 'DURATA', 'TUTOR_DIBEST', 'TUTOR_SCUOLA']));
        return genera_docx_convenzione($doc, array_diff_key($v, $att), [$att]);
    }
}

if (!function_exists('attivita_fsl_scuola')) {
    // Per la convenzione online: attività FSL prenotate dalla scuola (dalla prenotazione $pr_id e, se la scuola è dell'anagrafe,
    // tutte le sue prenotazioni FSL attive non concluse) e attività FSL ancora prenotabili. Ritorna [prenotate, prenotabili].
    function attivita_fsl_scuola($conn, int $pr_id, ?string $scuola_codice): array {
        $sc = strtoupper(trim((string)$scuola_codice));
        $where = preg_match('/^[A-Z0-9]{10}$/', $sc) ? "(pr.id = $pr_id OR pr.scuola_codice = '" . $conn->real_escape_string($sc) . "')" : "pr.id = $pr_id";
        $prenotate = [];
        $r = $conn->query("SELECT pr.id FROM prenotazioni pr JOIN turni t ON t.id = pr.turno_id JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
                           WHERE $where AND pd.convenzione = 1 AND IFNULL(pr.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')
                             AND (pr.id = $pr_id OR COALESCE(pd.data_fine, t.data_turno, CURDATE()) >= CURDATE() - INTERVAL 30 DAY) ORDER BY t.data_turno, pr.id");
        while ($r && $x = $r->fetch_assoc()) if ($p = dati_prenotazione_convenzione($conn, (int)$x['id'])) $prenotate[(int)$x['id']] = $p;
        $ev_prenotati = array_map(fn($p) => (int)$p['evento_id'], $prenotate);
        $prenotabili = [];
        $r = $conn->query("SELECT e.id AS evento_id, e.titolo AS evento_titolo, e.tipo, e.pagina_id, pe.slug, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine,
                                  MIN(t.data_turno) AS data_turno
                           FROM eventi e JOIN progetti_dettagli pd ON pd.evento_id = e.id JOIN pagine_eventi pe ON pe.id = e.pagina_id LEFT JOIN turni t ON t.evento_id = e.id AND t.data_turno >= CURDATE()
                           WHERE pd.convenzione = 1 AND IFNULL(pe.visibile, 1) = 1 AND (t.id IS NOT NULL OR IFNULL(pd.data_fine, CURDATE()) >= CURDATE())
                           GROUP BY e.id ORDER BY MIN(t.data_turno), e.titolo LIMIT 60");
        while ($r && $x = $r->fetch_assoc()) if (!in_array((int)$x['evento_id'], $ev_prenotati, true)) $prenotabili[(int)$x['evento_id']] = $x + ['nome_turno' => '', 'orario_inizio' => null, 'orario_fine' => null, 'nome' => '', 'cognome' => '', 'dati_custom_json' => null, 'scuola_codice' => null];
        return [$prenotate, $prenotabili];
    }
}

if (!function_exists('verifica_convenzioni_fsl')) {
    // Controllo delle iscrizioni delle attività di Formazione Scuola Lavoro non ancora concluse, anche già confermate:
    // - scuola dell'anagrafe con una convenzione che copre il periodo → "ricevuta" (chi era in attesa viene confermato e avvisato);
    // - nessuna convenzione valida per il periodo → "da stipulare" (lo stato della prenotazione non cambia e non parte
    //   nessuna email: la richiesta la invia il gestore da Iscrizioni, poi seguono i promemoria);
    // - scuola scritta a mano → non verificabile (va abbinata all'anagrafe).
    // Ritorna ['coperte' => n, 'da_stipulare' => n, 'nuove_da_stipulare' => n, 'senza_codice' => n].
    function verifica_convenzioni_fsl($conn): array {
        $out = ['coperte' => 0, 'da_stipulare' => 0, 'nuove_da_stipulare' => 0, 'senza_codice' => 0];
        // Attività FSL e, in qualsiasi attività, le iscrizioni a cui è stata chiesta la convenzione (es. da Iscrizioni in OpenLab)
        $r = @$conn->query("SELECT pr.id, pr.scuola_codice, pr.convenzione, t.data_turno, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine, IFNULL(pd.convenzione, 0) AS fsl
                            FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                            LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
                            WHERE (pd.convenzione = 1 OR pr.convenzione IN ('no', 'si')) AND e.archiviato = 0
                              AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'da_approvare', 'in_attesa', 'richiesta_conferma')
                              AND COALESCE(pd.data_fine, t.data_turno, CURDATE()) >= CURDATE()");
        while ($r && $x = $r->fetch_assoc()) {
            if (empty($x['scuola_codice'])) { if (($x['convenzione'] ?? '') !== 'ricevuta') $out['senza_codice']++; continue; }
            [$dal, $al] = periodo_prenotazione($x);
            if (convenzione_valida($conn, $x['scuola_codice'], false, $dal, $al)) {
                if (($x['convenzione'] ?? '') !== 'ricevuta') segna_convenzione_ricevuta($conn, (int)$x['id']);
                $out['coperte']++;
            } elseif ((int)$x['fsl'] !== 1) {
                // Attività non FSL: si aggiorna solo quando la convenzione arriva, lo stato resta com'è
                continue;
            } else {
                if (($x['convenzione'] ?? '') !== 'no') {
                    // Senza promemoria automatici finché il gestore non invia la richiesta (conv_promemoria = 3)
                    $conn->query("UPDATE prenotazioni SET convenzione = 'no', conv_promemoria = 3 WHERE id = " . (int)$x['id']);
                    $out['nuove_da_stipulare']++;
                }
                $out['da_stipulare']++;
            }
        }
        return $out;
    }
}

// =======================================================================
// SCHEDA DI VALUTAZIONE DELLA STRUTTURA OSPITANTE (FSL)
// La convenzione (art. 3) prevede che la scuola valuti la struttura ospitante: a fine attività FSL il docente
// che ha prenotato riceve un link personale (valutazione_fsl.php?t=…). Le risposte sono legate alla scuola.
// =======================================================================
if (!defined('VALUTAZIONE_FSL_ASPETTI')) define('VALUTAZIONE_FSL_ASPETTI', [
    'accoglienza'    => "Accoglienza e organizzazione delle attività",
    'coerenza'       => "Coerenza delle attività con il percorso concordato (Allegato A)",
    'tutor'          => "Disponibilità e competenza del tutor del Dipartimento",
    'spazi'          => "Adeguatezza di spazi, laboratori e attrezzature",
    'sicurezza'      => "Informazione e formazione sulla salute e sicurezza",
    'coinvolgimento' => "Coinvolgimento e interesse degli studenti",
    'competenze'     => "Competenze acquisite dagli studenti",
    'orientamento'   => "Utilità per l'orientamento degli studenti",
]);
if (!defined('VALUTAZIONE_FSL_APERTE')) define('VALUTAZIONE_FSL_APERTE', [
    'punti_forza'  => "Punti di forza dell'esperienza",
    'criticita'    => "Criticità riscontrate",
    'suggerimenti' => "Suggerimenti per le prossime edizioni",
]);

if (!function_exists('prenotazione_da_valutazione')) {
    // Prenotazione (con attività, periodo e scuola) dal link personale della scheda di valutazione
    function prenotazione_da_valutazione($conn, string $token): ?array {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) return null;
        $st = $conn->prepare("SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.id AS evento_id, e.titolo AS evento_titolo, e.pagina_id,
                                     pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine, v.id AS valutazione_id
                              FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                              LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id LEFT JOIN valutazioni_fsl v ON v.prenotazione_id = pr.id
                              WHERE pr.valutazione_token = ? LIMIT 1");
        if (!$st) return null;
        $st->bind_param("s", $token); $st->execute();
        return $st->get_result()->fetch_assoc() ?: null;
    }
}

if (!function_exists('invia_invito_valutazione')) {
    // Email al docente con il link alla scheda. $promemoria = true per il secondo invio.
    function invia_invito_valutazione($conn, int $pr_id, bool $promemoria = false): bool {
        $p = dati_prenotazione_convenzione($conn, $pr_id);
        if (!$p || empty($p['email']) || !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) return false;
        $token = (string)($p['valutazione_token'] ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            $token = bin2hex(random_bytes(20));
            $st = $conn->prepare("UPDATE prenotazioni SET valutazione_token = ? WHERE id = ?");
            $st->bind_param("si", $token, $pr_id); $st->execute();
        }
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $link = url_base_sito() . '/valutazione_fsl.php?t=' . $token;
        $s_sc = !empty($p['scuola_codice']) ? scuola_per_codice($conn, $p['scuola_codice']) : null;
        $corpo = "<p>Gentile <strong>" . $h(trim($p['nome'] . ' ' . $p['cognome'])) . "</strong>,</p>"
               . ($promemoria ? "<p>ti ricordiamo che la scheda di valutazione non è ancora stata compilata.</p>" : "<p>grazie per aver partecipato con la tua classe all'attività di Formazione Scuola Lavoro <strong>" . $h($p['evento_titolo']) . "</strong>" . ($s_sc ? " (" . $h(etichetta_scuola($s_sc)) . ")" : '') . ".</p>")
               . "<p>Come previsto dalla convenzione, ti chiediamo di compilare la breve <strong>scheda di valutazione della struttura ospitante</strong>: bastano 3 minuti e ci aiuta a migliorare i percorsi.</p>"
               . "<p style='text-align:center; margin:26px 0;'><a href='" . $h($link) . "' style='background:#B30000; color:#fff; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold;'>Compila la scheda di valutazione</a></p>";
        $ok = (bool)inviaNotificaEmail($p['email'], ($promemoria ? "Promemoria: " : "") . "Scheda di valutazione - " . $p['evento_titolo'], $corpo, $conn, colore_area_turno($conn, (int)$p['turno_id']));
        if ($ok) $conn->query("UPDATE prenotazioni SET " . ($promemoria ? "valutazione_promemoria = 1" : "valutazione_inviata = NOW()") . " WHERE id = " . (int)$pr_id);
        return $ok;
    }
}

if (!function_exists('salva_valutazione_fsl')) {
    // Salva la scheda (una sola per prenotazione). Ritorna null se va bene, altrimenti il messaggio d'errore.
    function salva_valutazione_fsl($conn, array $p, array $post): ?string {
        if (!empty($p['valutazione_id'])) return "La scheda di valutazione è già stata compilata. Grazie!";
        $voti = [];
        foreach (VALUTAZIONE_FSL_ASPETTI as $k => $etichetta) {
            $v = (int)($post['voto'][$k] ?? 0);
            if ($v < 1 || $v > 5) return "Indica un voto da 1 a 5 per: " . $etichetta . ".";
            $voti[$k] = $v;
        }
        $rip = in_array($post['ripeterebbe'] ?? '', ['si', 'forse', 'no'], true) ? $post['ripeterebbe'] : '';
        if ($rip === '') return "Indica se riproporresti l'attività ad altre classi.";
        $testi = [];
        foreach (VALUTAZIONE_FSL_APERTE as $k => $_) $testi[$k] = mb_substr(trim((string)($post['testo'][$k] ?? '')), 0, 2000);
        $compilata_da = mb_substr(trim((string)($post['compilata_da'] ?? '')), 0, 150) ?: trim($p['nome'] . ' ' . $p['cognome']);
        $json = json_encode(['voti' => $voti, 'testi' => $testi], JSON_UNESCAPED_UNICODE);
        $media = round(array_sum($voti) / count($voti), 2);
        $st = $conn->prepare("INSERT IGNORE INTO valutazioni_fsl (prenotazione_id, evento_id, scuola_codice, compilata_da, risposte_json, media, ripeterebbe) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $pr = (int)$p['id']; $ev = (int)$p['evento_id']; $sc = $p['scuola_codice'] ?: null;
        $st->bind_param("iisssds", $pr, $ev, $sc, $compilata_da, $json, $media, $rip);
        if (!$st->execute() || $st->affected_rows === 0) return "La scheda di valutazione è già stata compilata. Grazie!";
        return null;
    }
}

if (!function_exists('attestati_di_classe')) {
    // Attestati per ogni studente dell'elenco inserito da chi ha prenotato.
    // $p = riga con evento_tipo (o tipo), per_scuole e attestati (es. prenotazione_per_attestati).
    function attestati_di_classe(array $p): bool {
        $is_progetto = ($p['evento_tipo'] ?? $p['tipo'] ?? 'evento') === 'progetto';
        return (int)($p['attestati'] ?? 0) === 1 && prenotazione_di_classe($is_progetto, $p);
    }
}

if (!function_exists('attivita_conclusa_classe')) {
    // Quando si possono emettere gli attestati della classe: progetti dopo la data di fine,
    // eventi dopo il giorno del turno (turno senza data: subito, cioè dopo il check-in).
    function attivita_conclusa_classe(array $p): bool {
        if (($p['evento_tipo'] ?? 'evento') === 'progetto') return !empty($p['data_fine']) && $p['data_fine'] < date('Y-m-d');
        return empty($p['data_turno']) || $p['data_turno'] < date('Y-m-d');
    }
}

if (!function_exists('campo_form_visibile')) {
    // Il campo "numero di partecipanti" vale solo per le prenotazioni di classe (progetti per le scuole,
    // eventi con attestati per gli studenti): altrove non va mostrato né richiesto.
    // $dett = riga di progetti_dettagli dell'evento/progetto (null se assente).
    function campo_form_visibile(array $cf, bool $is_progetto, ?array $dett): bool {
        if (($cf['nome_campo'] ?? '') !== CAMPO_PARTECIPANTI) return true;
        return prenotazione_di_classe($is_progetto, $dett);
    }
}
