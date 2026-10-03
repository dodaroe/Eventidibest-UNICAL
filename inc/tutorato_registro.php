<?php
// inc/tutorato_registro.php - Tutorato dopo la firma della lettera di incarico:
// - registro delle attività: il tutor segna giorno, ore e attività (registro_tutorato.php), il docente responsabile approva o respinge;
// - fine attività: il tutor dichiara concluse le attività, il docente conferma e il portale prepara la «dichiarazione di fine
//   attività» (modello del Dipartimento) con le ore approvate; il docente la firma in PAdES (firma_incarico.php, come la lettera),
//   poi l'operatore riceve l'avviso «attività completate» con il riepilogo e il PDF e registra il protocollo;
// - promemoria (cron): al tutor per aggiornare il registro e, verso la fine del periodo, per chiuderlo; al docente per le ore
//   da approvare; solleciti per le firme ferme (docente, direttore, dichiarazione di fine attività) ogni FIRME_GIORNI_SOLLECITO giorni.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('STATI_REGISTRO')) define('STATI_REGISTRO', ['inviata' => ['Da approvare', '#b45309'], 'approvata' => ['Approvata', '#15803d'], 'respinta' => ['Respinta', '#b91c1c']]);
if (!defined('STATI_FINE_ATTIVITA')) define('STATI_FINE_ATTIVITA', [
    ''            => ['In corso', '#0056B3'],
    'richiesta'   => ['Il tutor ha dichiarato concluse le attività: conferma del docente', '#7c3aed'],
    'da_firmare'  => ['Dichiarazione di fine attività da firmare (docente)', '#b45309'],
    'firmata'     => ['Attività completate: dichiarazione firmata, da protocollare', '#15803d'],
    'protocollata' => ['Attività completate e protocollate', '#334155'],
]);

if (!function_exists('registro_incarico')) {
    // Righe del registro della lettera, in ordine di data
    function registro_incarico($conn, int $id): array {
        return db_righe($conn, "SELECT * FROM tutorato_registro WHERE incarico_id = ? ORDER BY data, id", [$id]);
    }
    // Ore per stato: ['inviata' => …, 'approvata' => …, 'respinta' => …, 'totale' => inviate + approvate]
    function ore_registro(array $righe): array {
        $o = ['inviata' => 0.0, 'approvata' => 0.0, 'respinta' => 0.0];
        foreach ($righe as $r) $o[$r['stato']] = ($o[$r['stato']] ?? 0) + (float)$r['ore'];
        return $o + ['totale' => $o['inviata'] + $o['approvata']];
    }
    function ore_testo($v): string { return rtrim(rtrim(number_format((float)$v, 1, ',', '.'), '0'), ','); }
    // Il registro si compila dopo la firma del direttore e finché il docente non conferma la fine delle attività
    function registro_aperto(array $i): bool {
        return in_array($i['stato'], ['firmata', 'protocollata'], true) && in_array((string)$i['fine_stato'], ['', 'richiesta'], true);
    }
    // Incarichi visibili nel registro: come tutor (codice fiscale dell'accesso) o come docente responsabile
    function incarichi_registro($conn, ?array $u): array {
        if (!$u || empty($u['id'])) return [];
        $out = [];
        $cf = strtoupper(trim((string)($u['codice_fiscale'] ?? '')));
        foreach (db_righe($conn, "SELECT id FROM tutorato_incarichi WHERE stato IN ('firmata', 'protocollata') ORDER BY creata_il DESC") as $x) {
            $i = incarico_tutorato($conn, (int)$x['id']);
            if (!$i) continue;
            if ($cf !== '' && $cf === strtoupper($i['codice_fiscale'])) $out[] = $i + ['_ruolo' => 'tutor'];
            elseif (firmatario_incarico($i, 'docente', $u)) $out[] = $i + ['_ruolo' => 'docente'];
        }
        return $out;
    }
}

if (!function_exists('aggiungi_registro')) {
    // Il tutor segna un giorno di attività. Ritorna un errore o null.
    function aggiungi_registro($conn, int $id, string $data, $ore, string $attivita): ?string {
        $i = incarico_tutorato($conn, $id);
        if (!$i || !registro_aperto($i)) return "Il registro non è aperto per questo incarico.";
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data > date('Y-m-d')) return "Indica la data (non nel futuro).";
        if ($i['data_inizio'] && $data < $i['data_inizio']) return "La data è prima dell'inizio dell'incarico (" . date('d/m/Y', strtotime($i['data_inizio'])) . ").";
        $ore = numero_italiano($ore);
        if ($ore === null || $ore <= 0 || $ore > 12 || fmod($ore * 2, 1) != 0) return "Indica le ore (da 0,5 a 12, a mezz'ore).";
        $attivita = mb_substr(trim($attivita), 0, 1000);
        if ($attivita === '') return "Scrivi l'attività svolta.";
        $tot = ore_registro(registro_incarico($conn, $id))['totale'];
        if ($i['ore'] !== null && $tot + $ore > (float)$i['ore'] + 0.001) return "Con queste ore superi le " . ore_testo($i['ore']) . " ore dell'incarico (già segnate: " . ore_testo($tot) . ").";
        db_esegui($conn, "INSERT INTO tutorato_registro (incarico_id, data, ore, attivita) VALUES (?, ?, ?, ?)", [$id, $data, $ore, $attivita]);
        return null;
    }
    function togli_registro($conn, int $id, int $riga): ?string {
        $i = incarico_tutorato($conn, $id);
        if (!$i || !registro_aperto($i)) return "Il registro non è aperto.";
        return db_esegui($conn, "DELETE FROM tutorato_registro WHERE id = ? AND incarico_id = ? AND stato = 'inviata'", [$riga, $id]) > 0 ? null : "Si possono togliere solo le righe non ancora approvate.";
    }
    // Il docente approva o respinge righe del registro ($righe = id; vuoto = tutte quelle da approvare)
    function decidi_registro($conn, int $id, string $esito, array $righe = [], string $nota = ''): int {
        $esito = $esito === 'respinta' ? 'respinta' : 'approvata';
        $n = 0;
        foreach (registro_incarico($conn, $id) as $r) {
            if ($r['stato'] !== 'inviata' || ($righe && !in_array((int)$r['id'], array_map('intval', $righe), true))) continue;
            $n += max(0, db_esegui($conn, "UPDATE tutorato_registro SET stato = ?, nota_docente = ?, decisa_il = NOW() WHERE id = ?", [$esito, mb_substr(trim($nota), 0, 500), (int)$r['id']]));
        }
        return $n;
    }
}

if (!function_exists('richiedi_fine_attivita')) {
    // Il tutor dichiara concluse le attività: il docente riceve l'email per approvare le ore e confermare
    function richiedi_fine_attivita($conn, int $id): ?string {
        $i = incarico_tutorato($conn, $id);
        if (!$i || !registro_aperto($i) || $i['fine_stato'] !== '') return "Le attività non si possono dichiarare concluse ora.";
        if (!registro_incarico($conn, $id)) return "Prima segna nel registro le attività svolte.";
        db_esegui($conn, "UPDATE tutorato_incarichi SET fine_stato = 'richiesta', fine_richiesta_il = NOW(), aggiornata_il = NOW() WHERE id = ?", [$id]);
        evento_incarico($conn, $id, 'fine_richiesta', 'Il tutor ha dichiarato concluse le attività', trim($i['nome'] . ' ' . $i['cognome']));
        $o = ore_registro(registro_incarico($conn, $id)); $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        email_incarico($conn, $i['docente_email'], "Tutorato: fine attività di " . trim($i['cognome'] . ' ' . $i['nome']),
            "<p>Gentile " . $h($i['docente_titolo'] . ' ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome'])) . ",</p><p>" . $h(trim($i['nome'] . ' ' . $i['cognome'])) . " ha dichiarato concluse le attività di tutorato (" . $h($i['bando_titolo']) . ") di cui è responsabile.</p>"
            . "<p>Ore nel registro: <strong>" . ore_testo($o['totale']) . "</strong> su " . ore_testo($i['ore']) . ($o['inviata'] > 0 ? " (" . ore_testo($o['inviata']) . " da approvare)" : '') . ".</p>"
            . "<p>Controlli il registro, approvi le ore e confermi la fine delle attività: il portale prepara la dichiarazione di fine attività da firmare in PAdES.</p>",
            url_base_sito() . '/registro_tutorato.php?id=' . $id, 'Apri il registro');
        return null;
    }
}

if (!function_exists('dati_fine_attivita')) {
    // Dati della dichiarazione di fine attività (modello del Dipartimento)
    function dati_fine_attivita(array $i, ?float $ore = null): array {
        $d = dati_lettera_incarico($i);
        $tit = in_array($i['docente_titolo'] ?? '', ['Prof.', 'Prof.ssa', 'Dott.', 'Dott.ssa'], true) ? $i['docente_titolo'] : 'Prof.';
        $f_doc = in_array($tit, ['Prof.ssa', 'Dott.ssa'], true);
        return [
            'DOCENTE' => $tit . ' ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome']), 'SOTTOSCRITTO' => $f_doc ? 'La sottoscritta' : 'Il sottoscritto',
            'INSEGNAMENTO' => trim((string)$i['insegnamento_docente']) ?: '________________', 'CORSO' => trim((string)$i['corso_laurea']) ?: '________________',
            'TUTOR' => $d['TITOLO'] . ' ' . $d['NOMINATIVO'], 'VINCITORE' => $d['VINCITORE'], 'DECRETO_BANDO' => $d['DECRETO_BANDO'],
            'ORE' => $ore !== null ? ore_testo($ore) : ($i['ore_approvate'] !== null ? ore_testo($i['ore_approvate']) : '____'),
            'LUOGO' => $d['LUOGO'], 'DATA' => date('d/m/Y'),
        ];
    }
}

if (!function_exists('pdf_fine_attivita')) {
    // Dichiarazione di fine attività in PDF (con il riepilogo del registro). Ritorna [contenuto PDF, segnaposti della firma].
    function pdf_fine_attivita(array $i, array $registro, ?float $ore = null): array {
        $d = dati_fine_attivita($i, $ore);
        $pdf = new PdfSemplice(['logo' => RADICE_SITO . '/' . LOGO_LETTERA_INCARICO, 'logo_larghezza' => 210,
                                'piede' => "Dichiarazione di fine attività di tutorato – lettera di incarico {$i['codice']}",
                                'info' => ['Title' => 'Fine attività – ' . $d['TUTOR'], 'Author' => $d['DOCENTE'], 'Subject' => 'Dichiarazione di fine attività di tutorato ' . $i['codice']]]);
        $pdf->paragrafo("Al Direttore del Dipartimento di Biologia,\nEcologia e Scienze della Terra\nUNIVERSITÀ DELLA CALABRIA\nSEDE", ['al' => 'destra', 'dopo' => 22]);
        $pdf->paragrafo('**Oggetto**: fine attività ' . $d['TUTOR'], ['dopo' => 16, 'al' => 'sinistra']);
        $pdf->paragrafo($d['SOTTOSCRITTO'] . ' ' . $d['DOCENTE'] . ', docente titolare di ' . $d['INSEGNAMENTO'] . ' del corso di laurea in ' . $d['CORSO'] . ' dell\'UniCal,', ['dopo' => 12]);
        $pdf->paragrafo('**DICHIARA**', ['al' => 'centro', 'dopo' => 12]);
        $pdf->paragrafo('che ' . ($i['genere'] === 'F' ? 'la' : 'il') . ' ' . $d['TUTOR'] . ', ' . $d['VINCITORE'] . ' del bando emanato con D.D. n. ' . $d['DECRETO_BANDO'] . ', ha regolarmente svolto le attività per un totale di n. ore **' . $d['ORE'] . '**.', ['dopo' => 18]);
        $pdf->paragrafo($d['LUOGO'] . ', ' . $d['DATA'], ['al' => 'sinistra', 'dopo' => 6]);
        $l = ($pdf->larg - $pdf->sx - $pdf->dx) / 3;
        $pdf->serve(130);
        $pdf->spazioFirma('docente', 'Firma', $d['DOCENTE'] . "\n(firma digitale PAdES)", $pdf->sx + 2 * $l, $l);
        $pdf->spazio(14);
        $appr = array_values(array_filter($registro, fn($r) => $r['stato'] === 'approvata'));
        if ($appr) {
            $pdf->paragrafo('**Riepilogo del registro delle attività** (ore approvate dal docente responsabile)', ['sz' => 10, 'dopo' => 6, 'prima' => 6]);
            $pdf->tabella(['Data', 'Ore', 'Attività svolta'], array_map(fn($r) => [date('d/m/Y', strtotime($r['data'])), ore_testo($r['ore']), (string)$r['attivita']], $appr), ['larghezze' => [1.2, 0.7, 6], 'sz' => 9, 'al' => [1 => 'centro']]);
            $pdf->paragrafo('Totale ore approvate: **' . $d['ORE'] . '** su ' . ore_testo($i['ore']) . ' previste dalla lettera di incarico.', ['sz' => 10]);
        }
        return [$pdf->pdf(), $pdf->segnaposti];
    }
}

if (!function_exists('conferma_fine_attivita')) {
    // Il docente conferma la fine: ore approvate, dichiarazione in PDF da firmare (link firma_incarico.php?t=token_fine)
    function conferma_fine_attivita($conn, int $id, string $autore = ''): array {
        $i = incarico_tutorato($conn, $id);
        if (!$i || !in_array($i['stato'], ['firmata', 'protocollata'], true) || !in_array((string)$i['fine_stato'], ['', 'richiesta'], true)) return [null, "La fine delle attività non si può confermare ora."];
        $reg = registro_incarico($conn, $id); $o = ore_registro($reg);
        if ($o['inviata'] > 0) return [null, "Ci sono ancora " . ore_testo($o['inviata']) . " ore da approvare o respingere."];
        if ($o['approvata'] <= 0) return [null, "Nel registro non ci sono ore approvate."];
        [$pdf] = pdf_fine_attivita($i, $reg, $o['approvata']);
        $dir = RADICE_SITO . '/' . DIR_INCARICHI;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_file($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "# Lettere di incarico: si scaricano solo dal pannello o con il link personale\nRequire all denied\n");
        $nome = preg_replace('/[^A-Za-z0-9_-]/', '', $i['codice']) . '_fine_' . bin2hex(random_bytes(4)) . '.pdf';
        if (@file_put_contents($dir . $nome, $pdf) === false) return [null, "Non è stato possibile salvare la dichiarazione."];
        $tok = bin2hex(random_bytes(20));
        db_esegui($conn, "UPDATE tutorato_incarichi SET fine_stato = 'da_firmare', fine_pdf = ?, token_fine = ?, ore_approvate = ?, fine_richiesta_il = COALESCE(fine_richiesta_il, NOW()), solleciti = 0, sollecito_il = NULL, aggiornata_il = NOW() WHERE id = ?",
                  [DIR_INCARICHI . $nome, $tok, $o['approvata'], $id]);
        evento_incarico($conn, $id, 'fine_confermata', 'Fine attività confermata dal docente: ' . ore_testo($o['approvata']) . ' ore approvate; dichiarazione da firmare', $autore);
        return [$tok, null];
    }
    function pdf_fine_corrente(array $i): ?string {
        if (empty($i['fine_pdf'])) return null;
        $base = realpath(RADICE_SITO . '/' . DIR_INCARICHI); $p = realpath(RADICE_SITO . '/' . $i['fine_pdf']);
        return ($base && $p && strpos($p, $base . DIRECTORY_SEPARATOR) === 0 && is_file($p)) ? $p : null;
    }
}

if (!function_exists('registra_firma_fine')) {
    // Firma PAdES del docente sulla dichiarazione di fine attività: avviso «attività completate» all'operatore (con il PDF) e al tutor
    function registra_firma_fine($conn, int $id, string $pdf, string $come = 'caricamento'): ?string {
        $i = incarico_tutorato($conn, $id);
        if (!$i || $i['fine_stato'] !== 'da_firmare' || !($cor = pdf_fine_corrente($i))) return "La dichiarazione non è in attesa di firma.";
        [$err, $info] = verifica_pdf_firmato((string)file_get_contents($cor), $pdf, (string)$i['docente_cf']);
        if ($err) return $err;
        $nome = preg_replace('/[^A-Za-z0-9_-]/', '', $i['codice']) . '_fine_firmata_' . bin2hex(random_bytes(4)) . '.pdf';
        if (@file_put_contents(RADICE_SITO . '/' . DIR_INCARICHI . $nome, $pdf) === false) return "Non è stato possibile salvare il PDF firmato.";
        db_esegui($conn, "UPDATE tutorato_incarichi SET fine_stato = 'firmata', fine_pdf = ?, fine_firmata_il = NOW(), solleciti = 0, sollecito_il = NULL, aggiornata_il = NOW() WHERE id = ?", [DIR_INCARICHI . $nome, $id]);
        evento_incarico($conn, $id, 'fine_firmata', "Dichiarazione di fine attività firmata in PAdES ($come" . ($info['nome'] !== '' ? ', certificato di ' . $info['nome'] : '') . ')', $i['docente_titolo'] . ' ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome']));
        $i = incarico_tutorato($conn, $id);
        $reg = registro_incarico($conn, $id); $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $tab = "<table style='border-collapse:collapse;font-size:13px;'><tr><th style='text-align:left;padding:3px 8px;border-bottom:1px solid #cbd5e1;'>Data</th><th style='padding:3px 8px;border-bottom:1px solid #cbd5e1;'>Ore</th><th style='text-align:left;padding:3px 8px;border-bottom:1px solid #cbd5e1;'>Attività</th></tr>";
        foreach ($reg as $r) if ($r['stato'] === 'approvata') $tab .= "<tr><td style='padding:3px 8px;'>" . date('d/m/Y', strtotime($r['data'])) . "</td><td style='padding:3px 8px;text-align:center;'>" . ore_testo($r['ore']) . "</td><td style='padding:3px 8px;'>" . $h($r['attivita']) . "</td></tr>";
        $tab .= "</table>";
        $file = pdf_fine_corrente($i);
        foreach (email_operatori_incarico($conn, $i) as $e)
            email_incarico($conn, $e, "Attività completate: " . trim($i['cognome'] . ' ' . $i['nome']) . " – " . ore_testo($i['ore_approvate']) . " ore",
                "<p>Il tutor <strong>" . $h(trim($i['nome'] . ' ' . $i['cognome'])) . "</strong> (" . $h($i['bando_titolo']) . ") ha completato le attività: <strong>" . ore_testo($i['ore_approvate']) . " ore</strong> approvate su " . ore_testo($i['ore']) . ".</p>"
                . "<p>La dichiarazione di fine attività è firmata in PAdES dal docente responsabile: la trovi in allegato e nel pannello, da protocollare.</p>" . $tab,
                url_base_sito() . '/admin/tutorato.php?incarico=' . $id, 'Apri nel pannello', $file ? [['path' => $file, 'nome' => 'FINE_ATTIVITA_' . str_replace('LETTERA_INCARICO_', '', nome_file_incarico($i))]] : []);
        email_incarico($conn, $i['email'], "Tutorato: attività completate",
            "<p>Gentile " . $h($i['nome'] . ' ' . $i['cognome']) . ",</p><p>il docente responsabile ha confermato la fine delle tue attività di tutorato: <strong>" . ore_testo($i['ore_approvate']) . " ore</strong>. L'Ufficio procede con gli adempimenti per il compenso.</p>");
        return null;
    }
    function protocolla_fine_attivita($conn, int $id, string $prot, string $autore = ''): ?string {
        $i = incarico_tutorato($conn, $id); $prot = mb_substr(trim($prot), 0, 100);
        if (!$i || !in_array($i['fine_stato'], ['firmata', 'protocollata'], true)) return "La dichiarazione non è ancora firmata.";
        if ($prot === '') return "Scrivi il numero di protocollo.";
        db_esegui($conn, "UPDATE tutorato_incarichi SET fine_stato = 'protocollata', fine_protocollo = ?, aggiornata_il = NOW() WHERE id = ?", [$prot, $id]);
        evento_incarico($conn, $id, 'fine_protocollo', 'Dichiarazione di fine attività protocollata: ' . $prot, $autore);
        return null;
    }
}

if (!function_exists('salva_dati_fine_attivita')) {
    // Dati che servono dopo l'invio della lettera: insegnamento e corso del docente, titolo, periodo (per i promemoria)
    function salva_dati_fine_attivita($conn, int $id, array $d): ?string {
        $i = incarico_tutorato($conn, $id);
        if (!$i || in_array($i['fine_stato'], ['da_firmare', 'firmata', 'protocollata'], true)) return "La dichiarazione di fine attività è già stata preparata.";
        $dt = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d[$k] ?? '')) ? $d[$k] : null;
        $tit = in_array($d['docente_titolo'] ?? '', ['Prof.', 'Prof.ssa', 'Dott.', 'Dott.ssa'], true) ? $d['docente_titolo'] : 'Prof.';
        if ($dt('data_inizio') && $dt('data_fine') && $dt('data_fine') < $dt('data_inizio')) return "La fine del periodo è prima dell'inizio.";
        db_esegui($conn, "UPDATE tutorato_incarichi SET insegnamento_docente = ?, corso_laurea = ?, docente_titolo = ?, data_inizio = ?, data_fine = ?, aggiornata_il = NOW() WHERE id = ?",
                  [mb_substr(trim((string)($d['insegnamento_docente'] ?? '')), 0, 255), mb_substr(trim((string)($d['corso_laurea'] ?? '')), 0, 255), $tit, $dt('data_inizio'), $dt('data_fine'), $id]);
        return null;
    }
}

if (!function_exists('promemoria_tutorato')) {
    // Cron giornaliero (admin/cron_reminders.php): promemoria del registro e solleciti delle firme ferme. Ritorna le email inviate.
    function promemoria_tutorato($conn): int {
        $n = 0; $oggi = date('Y-m-d'); $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $gg = max(1, (int)(env_valore('FIRME_GIORNI_SOLLECITO') ?? 5));
        foreach (db_righe($conn, "SELECT id FROM tutorato_incarichi WHERE stato IN ('confermata', 'firmata_docente', 'firmata', 'protocollata') AND anonimizzata = 0") as $x) {
            $i = incarico_tutorato($conn, (int)$x['id']);
            if (!$i) continue;
            $id = (int)$i['id']; $link_reg = url_base_sito() . '/registro_tutorato.php?id=' . $id;
            // 1. Tutor: registro da aggiornare, chiusura verso la fine del periodo (al massimo un promemoria ogni 7 giorni)
            if (registro_aperto($i) && $i['fine_stato'] === '' && (!$i['promemoria_tutor_il'] || strtotime($i['promemoria_tutor_il']) < time() - 7 * 86400)) {
                $reg = registro_incarico($conn, $id);
                $ultima = $reg ? max(array_column($reg, 'data')) : null;
                $testo = null;
                if ($i['data_fine'] && $oggi >= date('Y-m-d', strtotime($i['data_fine'] . ' -7 days')))
                    $testo = ($oggi > $i['data_fine'] ? "il periodo dell'incarico è terminato il " : "il periodo dell'incarico termina il ") . date('d/m/Y', strtotime($i['data_fine']))
                           . ": completa il registro delle attività e, quando hai finito, clicca su <strong>«Ho concluso le attività»</strong>. Il docente responsabile confermerà le ore.";
                elseif ((!$i['data_inizio'] || $oggi >= $i['data_inizio']) && (!$ultima || $ultima < date('Y-m-d', strtotime('-14 days'))) && (strtotime($i['firmata_direttore_il'] ?? 'now') < time() - 14 * 86400))
                    $testo = "non segni attività nel registro da più di due settimane: aggiornalo con i giorni, le ore e le attività svolte.";
                if ($testo) {
                    email_incarico($conn, $i['email'], "Tutorato: registro delle attività", "<p>Gentile " . $h($i['nome'] . ' ' . $i['cognome']) . ",</p><p>" . $testo . "</p><p>Ore segnate: " . ore_testo(ore_registro($reg)['totale']) . " su " . ore_testo($i['ore']) . ".</p>", $link_reg, 'Apri il registro');
                    db_esegui($conn, "UPDATE tutorato_incarichi SET promemoria_tutor_il = NOW() WHERE id = ?", [$id]); $n++;
                }
            }
            // 2. Docente: ore da approvare da più di 7 giorni o fine attività dichiarata dal tutor da più di 3 giorni
            if (registro_aperto($i) && (!$i['promemoria_docente_il'] || strtotime($i['promemoria_docente_il']) < time() - 7 * 86400)) {
                $vecchie = (int)db_valore($conn, "SELECT COUNT(*) FROM tutorato_registro WHERE incarico_id = ? AND stato = 'inviata' AND creata_il < NOW() - INTERVAL 7 DAY", [$id]);
                $fine = $i['fine_stato'] === 'richiesta' && strtotime($i['fine_richiesta_il']) < time() - 3 * 86400;
                if ($vecchie || $fine) {
                    email_incarico($conn, $i['docente_email'], "Tutorato: registro di " . trim($i['cognome'] . ' ' . $i['nome']) . " da controllare",
                        "<p>Nel registro delle attività di tutorato di <strong>" . $h(trim($i['nome'] . ' ' . $i['cognome'])) . "</strong> " . ($fine ? "il tutor ha dichiarato concluse le attività: approvi le ore e confermi la fine." : "ci sono ore da approvare.") . "</p>", $link_reg, 'Apri il registro');
                    db_esegui($conn, "UPDATE tutorato_incarichi SET promemoria_docente_il = NOW() WHERE id = ?", [$id]); $n++;
                }
            }
            // 3. Solleciti delle firme ferme: docente e direttore sulla lettera, docente sulla dichiarazione di fine attività
            $ferma = match (true) {
                $i['stato'] === 'confermata' => ['docente', $i['confermata_il'], $i['docente_email'], url_base_sito() . '/firma_incarico.php?t=' . $i['token_docente'], 'la lettera di incarico'],
                $i['stato'] === 'firmata_docente' => ['direttore', $i['firmata_docente_il'], $i['direttore_email'], url_base_sito() . '/firma_incarico.php?t=' . $i['token_direttore'], 'la lettera di incarico'],
                $i['fine_stato'] === 'da_firmare' => ['fine', $i['aggiornata_il'], $i['docente_email'], url_base_sito() . '/firma_incarico.php?t=' . $i['token_fine'], 'la dichiarazione di fine attività'],
                default => null,
            };
            if ($ferma && $ferma[1]) {
                $dal = strtotime((string)($i['sollecito_il'] ?: $ferma[1]));
                if ($dal < time() - $gg * 86400 && (int)$i['solleciti'] < 3) {
                    email_incarico($conn, $ferma[2], "Sollecito: " . $ferma[4] . " di " . trim($i['cognome'] . ' ' . $i['nome']) . " è in attesa della sua firma",
                        "<p>Gentile,</p><p>" . $h($ferma[4]) . " di tutorato di <strong>" . $h(trim($i['nome'] . ' ' . $i['cognome'])) . "</strong> (" . $h($i['bando_titolo']) . ") aspetta la sua firma digitale in PAdES dal " . date('d/m/Y', strtotime($ferma[1])) . ".</p>",
                        $ferma[3], 'Apri e firma');
                    db_esegui($conn, "UPDATE tutorato_incarichi SET sollecito_il = NOW(), solleciti = solleciti + 1 WHERE id = ?", [$id]); $n++;
                    evento_incarico($conn, $id, 'sollecito', 'Sollecito della firma inviato a ' . $ferma[2]);
                    // Al terzo sollecito lo sa anche l'operatore
                    if ((int)$i['solleciti'] + 1 >= 3) foreach (email_operatori_incarico($conn, $i) as $e)
                        email_incarico($conn, $e, "Firma ferma: " . $ferma[4] . " di " . trim($i['cognome'] . ' ' . $i['nome']),
                            "<p>Dopo tre solleciti " . $h($ferma[4]) . " non è ancora firmata da " . $h($ferma[2]) . ": serve un contatto diretto.</p>", url_base_sito() . '/admin/tutorato.php?incarico=' . $id, 'Apri nel pannello');
                }
            }
        }
        return $n;
    }
}

if (!function_exists('conserva_dati_tutorato')) {
    // Conservazione (cron_background.php, CONSERVAZIONE_INCARICHI_MESI nel .env, 0 = mai; durata da concordare con il DPO):
    // le lettere protocollate (con la fine attività protocollata, se c'è) o annullate da più di $mesi mesi perdono i dati personali
    // (nascita, residenza, contatti, metadati dell'accesso SPID/CIE), i PDF e il registro. Restano codice, nome e cognome,
    // bando, ore, compenso, protocolli e storico: gli originali firmati sono nel protocollo di Ateneo.
    function conserva_dati_tutorato($conn, int $mesi): int {
        if ($mesi <= 0) return 0;
        $n = 0;
        foreach (db_righe($conn, "SELECT id FROM tutorato_incarichi WHERE anonimizzata = 0 AND COALESCE(aggiornata_il, creata_il) < NOW() - INTERVAL ? MONTH
                                  AND (stato = 'annullata' OR (stato = 'protocollata' AND fine_stato IN ('', 'protocollata'))) LIMIT 500", [$mesi]) as $x) {
            $i = incarico_tutorato($conn, (int)$x['id']);
            if (!$i) continue;
            foreach (['file_pdf', 'fine_pdf'] as $k) {
                $p = !empty($i[$k]) ? realpath(RADICE_SITO . '/' . $i[$k]) : false; $base = realpath(RADICE_SITO . '/' . DIR_INCARICHI);
                if ($p && $base && strpos($p, $base . DIRECTORY_SEPARATOR) === 0) @unlink($p);
            }
            // Le versioni intermedie (firmata dal docente, ecc.) hanno lo stesso codice nel nome
            foreach (glob(RADICE_SITO . '/' . DIR_INCARICHI . preg_replace('/[^A-Za-z0-9_-]/', '', $i['codice']) . '_*.pdf') ?: [] as $f) @unlink($f);
            db_esegui($conn, "DELETE FROM tutorato_registro WHERE incarico_id = ?", [(int)$i['id']]);
            db_esegui($conn, "UPDATE tutorato_incarichi SET luogo_nascita = '', data_nascita = NULL, comune_residenza = '', indirizzo = '', civico = '', codice_fiscale = '', email = '', telefono = '',
                              studente_firma_json = NULL, nota_studente = NULL, file_pdf = NULL, fine_pdf = NULL, token_studente = NULL, token_docente = NULL, token_direttore = NULL, token_fine = NULL, anonimizzata = 1 WHERE id = ?", [(int)$i['id']]);
            db_esegui($conn, "UPDATE tutorato_eventi SET ip = '' WHERE incarico_id = ?", [(int)$i['id']]);
            evento_incarico($conn, (int)$i['id'], 'conservazione', "Dati personali, PDF e registro cancellati dopo $mesi mesi (conservazione)");
            $n++;
        }
        return $n;
    }
}
