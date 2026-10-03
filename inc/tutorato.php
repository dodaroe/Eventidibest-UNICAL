<?php
// inc/tutorato.php - Tutorato: lettere di incarico dei vincitori dei bandi di tutorato.
// L'operatore (Ufficio didattico, compito "Bandi") inserisce il bando (decreto del bando e della commissione, direttore
// dall'anagrafe) e per ogni vincitore dati anagrafici, attività, ore, periodo, compenso e docente responsabile
// (dall'anagrafe o scritto a mano). I dati precompilano il modello Word del Dipartimento e la lettera in PDF.
// Iter (le email partono solo quando la lettera passa alla persona successiva):
//   1. l'operatore invia la lettera allo studente → email con il link (incarico.php);
//   2. lo studente entra con SPID o CIE, controlla i dati e conferma: nel PDF finiscono i dati dell'autenticazione
//      (metodo, livello, IdP, spidCode, codice fiscale, data e ora, impronta dei dati) → email al docente;
//   3. il docente firma digitalmente in PAdES (firma remota Aruba dal portale, oppure carica il PDF firmato) → email al direttore;
//   4. il direttore firma in PAdES allo stesso modo → email all'operatore, che scarica il PDF con tutte le firme e lo protocolla.
// Solo PAdES: le firme stanno dentro il PDF (/ByteRange) e ogni firma si aggiunge alla precedente senza toccarla
// (il file firmato deve cominciare esattamente con la versione precedente). I .p7m (CAdES) sono rifiutati.
// I PDF stanno in uploads/incarichi/ (bloccata al web) e si scaricano solo dal pannello e dalle pagine con il link personale.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('STATI_INCARICO')) define('STATI_INCARICO', [
    'bozza'           => ['Bozza', '#64748b', 'fa-pen'],
    'inviata'         => ['Allo studente per la conferma', '#0056B3', 'fa-paper-plane'],
    'confermata'      => ['Al docente per la firma', '#7c3aed', 'fa-user-check'],
    'firmata_docente' => ['Al direttore per la firma', '#b45309', 'fa-file-signature'],
    'firmata'         => ['Firmata: da protocollare', '#15803d', 'fa-circle-check'],
    'protocollata'    => ['Protocollata', '#334155', 'fa-box-archive'],
    'annullata'       => ['Annullata', '#b91c1c', 'fa-ban'],
]);
if (!defined('DIR_INCARICHI')) define('DIR_INCARICHI', 'uploads/incarichi/');
if (!defined('MODELLO_LETTERA_INCARICO')) define('MODELLO_LETTERA_INCARICO', 'modelli_documenti/lettera_incarico_tutorato.docx');
if (!defined('LOGO_LETTERA_INCARICO')) define('LOGO_LETTERA_INCARICO', 'assets/modelli/logo_lettera_incarico.jpg');
if (!defined('METODI_ACCESSO')) define('METODI_ACCESSO', ['spid' => 'SPID', 'cie' => 'CIE (Carta d\'Identità Elettronica)', 'ateneo' => 'credenziali di Ateneo (Unical ID)']);

// ==============================================================================
// BANDI E LETTERE
// ==============================================================================

if (!function_exists('bando_tutorato')) {
    function bando_tutorato($conn, int $id): ?array {
        return db_riga($conn, "SELECT * FROM tutorato_bandi WHERE id = ?", [$id]);
    }
    function bandi_tutorato($conn): array {
        $r = @$conn->query("SELECT b.*, (SELECT COUNT(*) FROM tutorato_incarichi i WHERE i.bando_id = b.id AND i.stato <> 'annullata') AS n_incarichi,
                                   (SELECT COUNT(*) FROM tutorato_incarichi i WHERE i.bando_id = b.id AND i.stato IN ('firmata', 'protocollata')) AS n_firmate
                            FROM tutorato_bandi b ORDER BY b.creato_il DESC");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }
    // Lettera con i dati del bando (decreti, direttore, operatore)
    function incarico_tutorato($conn, int $id): ?array {
        $r = @$conn->query("SELECT i.*, b.titolo AS bando_titolo, b.anno_accademico, b.decreto_bando, b.decreto_bando_data, b.decreto_commissione, b.decreto_commissione_data,
                                   b.direttore_persona_id, b.direttore_nome, b.direttore_email, b.direttore_cf, b.operatore_id, b.luogo
                            FROM tutorato_incarichi i JOIN tutorato_bandi b ON b.id = i.bando_id WHERE i.id = $id");
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }
    // Lettera dal link personale (studente, docente o direttore)
    function incarico_per_token($conn, string $ruolo, string $token): ?array {
        if (!preg_match('/^[a-f0-9]{40}$/', $token) || !in_array($ruolo, ['studente', 'docente', 'direttore', 'fine'], true)) return null;
        $id = (int)db_valore($conn, "SELECT id FROM tutorato_incarichi WHERE token_$ruolo = ? LIMIT 1", [$token]);
        return $id ? incarico_tutorato($conn, $id) : null;
    }
}

if (!function_exists('evento_incarico')) {
    // Storico della lettera (chi, cosa, quando, da quale indirizzo)
    function evento_incarico($conn, int $id, string $tipo, string $testo, string $autore = ''): void {
        $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $st = $conn->prepare("INSERT INTO tutorato_eventi (incarico_id, tipo, testo, autore, ip) VALUES (?, ?, ?, ?, ?)");
        $st->bind_param("issss", $id, $tipo, $testo, $autore, $ip); $st->execute();
        db_esegui($conn, "UPDATE tutorato_incarichi SET aggiornata_il = NOW() WHERE id = ?", [$id]);
    }
}

if (!function_exists('decreto_testo')) {
    // "123/2026 del 15/09/2026"
    function decreto_testo(?string $num, ?string $data): string {
        $num = trim((string)$num);
        return $num . ($data ? ($num !== '' ? ' del ' : '') . date('d/m/Y', strtotime($data)) : '');
    }
}

if (!function_exists('salva_bando_tutorato')) {
    // Bando: titolo, a.a., decreti, direttore (dall'anagrafe o a mano), operatore che riceve le lettere firmate. Ritorna [id, errore].
    function salva_bando_tutorato($conn, array $d, int $uid): array {
        $id = (int)($d['id'] ?? 0);
        $f = [];
        foreach (['titolo' => 255, 'anno_accademico' => 20, 'decreto_bando' => 100, 'decreto_commissione' => 100, 'direttore_nome' => 200, 'direttore_email' => 150, 'direttore_cf' => 16, 'luogo' => 100] as $k => $max)
            $f[$k] = mb_substr(trim((string)($d[$k] ?? '')), 0, $max);
        $f['direttore_cf'] = strtoupper($f['direttore_cf']);
        $data_b = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['decreto_bando_data'] ?? '')) ? $d['decreto_bando_data'] : null;
        $data_c = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['decreto_commissione_data'] ?? '')) ? $d['decreto_commissione_data'] : null;
        $dir_pid = trim((string)($d['direttore_persona_id'] ?? '')) ?: null;
        if ($dir_pid && ($p = persona_ateneo($conn, $dir_pid))) {
            $f['direttore_nome'] = trim($p['nome'] . ' ' . $p['cognome']);
            if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) $f['direttore_email'] = strtolower($p['email']);
        } elseif ($dir_pid) $dir_pid = null;
        if ($f['titolo'] === '') return [0, "Scrivi il titolo del bando."];
        if ($f['decreto_bando'] === '') return [0, "Indica il decreto del bando (D.D. n.)."];
        if ($f['direttore_nome'] === '' || !filter_var($f['direttore_email'], FILTER_VALIDATE_EMAIL)) return [0, "Scegli il direttore dall'anagrafe o scrivi nome ed email."];
        if ($f['direttore_cf'] !== '' && !preg_match('/^[A-Z0-9]{16}$/', $f['direttore_cf'])) return [0, "Codice fiscale del direttore non valido."];
        $op = (int)($d['operatore_id'] ?? 0) ?: null;
        if ($f['luogo'] === '') $f['luogo'] = 'Rende';
        if ($id) {
            $st = $conn->prepare("UPDATE tutorato_bandi SET titolo=?, anno_accademico=?, decreto_bando=?, decreto_bando_data=?, decreto_commissione=?, decreto_commissione_data=?, direttore_persona_id=?, direttore_nome=?, direttore_email=?, direttore_cf=?, operatore_id=?, luogo=?, aggiornato_il=NOW() WHERE id=?");
            $st->bind_param("ssssssssssisi", $f['titolo'], $f['anno_accademico'], $f['decreto_bando'], $data_b, $f['decreto_commissione'], $data_c, $dir_pid, $f['direttore_nome'], $f['direttore_email'], $f['direttore_cf'], $op, $f['luogo'], $id);
            $st->execute();
        } else {
            $st = $conn->prepare("INSERT INTO tutorato_bandi (titolo, anno_accademico, decreto_bando, decreto_bando_data, decreto_commissione, decreto_commissione_data, direttore_persona_id, direttore_nome, direttore_email, direttore_cf, operatore_id, luogo, creato_da) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->bind_param("ssssssssssisi", $f['titolo'], $f['anno_accademico'], $f['decreto_bando'], $data_b, $f['decreto_commissione'], $data_c, $dir_pid, $f['direttore_nome'], $f['direttore_email'], $f['direttore_cf'], $op, $f['luogo'], $uid);
            $st->execute();
            $id = (int)$conn->insert_id;
        }
        return [$id, null];
    }
}

if (!function_exists('salva_incarico_tutorato')) {
    // Lettera di un vincitore (solo in bozza: dopo l'invio i dati non cambiano). Docente dall'anagrafe ($d['docente_persona_id'])
    // o scritto a mano (nome, cognome, email, codice fiscale). Ritorna [id, errore].
    function salva_incarico_tutorato($conn, array $d): array {
        $id = (int)($d['id'] ?? 0);
        $vecchio = $id ? incarico_tutorato($conn, $id) : null;
        if ($id && (!$vecchio || $vecchio['stato'] !== 'bozza')) return [$id, "La lettera è già stata inviata: per cambiarla annullala e preparane una nuova."];
        $bando = (int)($d['bando_id'] ?? ($vecchio['bando_id'] ?? 0));
        if (!bando_tutorato($conn, $bando)) return [0, "Bando non trovato."];
        $f = [];
        foreach (['cognome' => 100, 'nome' => 100, 'luogo_nascita' => 150, 'comune_residenza' => 150, 'indirizzo' => 255, 'civico' => 20, 'codice_fiscale' => 16, 'email' => 255, 'telefono' => 40,
                  'attivita' => 3000, 'periodo' => 255, 'docente_nome' => 100, 'docente_cognome' => 100, 'docente_email' => 150, 'docente_cf' => 16] as $k => $max)
            $f[$k] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($d[$k] ?? ''))), 0, $max);
        $f['attivita'] = mb_substr(trim((string)($d['attivita'] ?? '')), 0, 3000);
        $f['codice_fiscale'] = strtoupper(str_replace(' ', '', $f['codice_fiscale'])); $f['docente_cf'] = strtoupper(str_replace(' ', '', $f['docente_cf']));
        $f['email'] = strtolower($f['email']); $f['docente_email'] = strtolower($f['docente_email']);
        $genere = ($d['genere'] ?? 'M') === 'F' ? 'F' : 'M';
        $nascita = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['data_nascita'] ?? '')) ? $d['data_nascita'] : null;
        $data_l = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['data_lettera'] ?? '')) ? $d['data_lettera'] : null;
        $ore = numero_italiano($d['ore'] ?? ''); $compenso = numero_italiano($d['compenso'] ?? '');
        $doc_pid = trim((string)($d['docente_persona_id'] ?? '')) ?: null;
        if ($doc_pid && ($p = persona_ateneo($conn, $doc_pid))) {
            $f['docente_nome'] = $p['nome']; $f['docente_cognome'] = $p['cognome'];
            if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) $f['docente_email'] = strtolower($p['email']);
        } elseif ($doc_pid) $doc_pid = null;
        $err = [];
        if ($f['cognome'] === '' || $f['nome'] === '') $err[] = "cognome e nome del vincitore";
        if (!preg_match('/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/', $f['codice_fiscale'])) $err[] = "codice fiscale del vincitore (16 caratteri)";
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err[] = "email del vincitore";
        if ($f['attivita'] === '') $err[] = "attività da svolgere";
        if ($ore === null || $ore <= 0) $err[] = "numero di ore";
        if ($f['periodo'] === '') $err[] = "periodo";
        if ($compenso === null || $compenso < 0) $err[] = "compenso";
        if ($f['docente_cognome'] === '' || !filter_var($f['docente_email'], FILTER_VALIDATE_EMAIL)) $err[] = "docente responsabile (dall'anagrafe o con nome, cognome ed email)";
        if (!$doc_pid && $f['docente_cf'] !== '' && !preg_match('/^[A-Z0-9]{16}$/', $f['docente_cf'])) $err[] = "codice fiscale del docente";
        if ($err) return [$id, "Controlla: " . implode(', ', $err) . "."];
        if ($id) {
            $st = $conn->prepare("UPDATE tutorato_incarichi SET genere=?, cognome=?, nome=?, luogo_nascita=?, data_nascita=?, comune_residenza=?, indirizzo=?, civico=?, codice_fiscale=?, email=?, telefono=?, attivita=?, ore=?, periodo=?, compenso=?,
                                  docente_persona_id=?, docente_nome=?, docente_cognome=?, docente_email=?, docente_cf=?, data_lettera=?, aggiornata_il=NOW() WHERE id=?");
            $st->bind_param("ssssssssssssdsdssssssi", $genere, $f['cognome'], $f['nome'], $f['luogo_nascita'], $nascita, $f['comune_residenza'], $f['indirizzo'], $f['civico'], $f['codice_fiscale'], $f['email'], $f['telefono'], $f['attivita'], $ore, $f['periodo'], $compenso,
                            $doc_pid, $f['docente_nome'], $f['docente_cognome'], $f['docente_email'], $f['docente_cf'], $data_l, $id);
            $st->execute();
        } else {
            $codice = 'TU-' . strtoupper(bin2hex(random_bytes(4)));
            $st = $conn->prepare("INSERT INTO tutorato_incarichi (bando_id, codice, genere, cognome, nome, luogo_nascita, data_nascita, comune_residenza, indirizzo, civico, codice_fiscale, email, telefono, attivita, ore, periodo, compenso,
                                  docente_persona_id, docente_nome, docente_cognome, docente_email, docente_cf, data_lettera, aggiornata_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $st->bind_param("isssssssssssssdsdssssss", $bando, $codice, $genere, $f['cognome'], $f['nome'], $f['luogo_nascita'], $nascita, $f['comune_residenza'], $f['indirizzo'], $f['civico'], $f['codice_fiscale'], $f['email'], $f['telefono'], $f['attivita'], $ore, $f['periodo'], $compenso,
                            $doc_pid, $f['docente_nome'], $f['docente_cognome'], $f['docente_email'], $f['docente_cf'], $data_l);
            $st->execute();
            $id = (int)$conn->insert_id;
            evento_incarico($conn, $id, 'creata', 'Lettera preparata');
        }
        return [$id, null];
    }
}

if (!function_exists('numero_italiano')) {
    // "1.250,50", "1250.5", "€ 300" → numero (null se non è un numero)
    function numero_italiano($v): ?float {
        $v = trim(str_replace(['€', ' ', "\u{00A0}"], '', (string)$v));
        if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
        return $v !== '' && is_numeric($v) ? (float)$v : null;
    }
}

if (!function_exists('dati_lettera_incarico')) {
    // Valori della lettera (segnaposti del modello Word e testo del PDF)
    function dati_lettera_incarico(array $i): array {
        $f = $i['genere'] === 'F';
        $num = fn($v, int $dec) => rtrim(rtrim(number_format((float)$v, $dec, ',', '.'), '0'), ',');
        $ind = trim($i['indirizzo'] . ($i['civico'] !== '' ? ', n. ' . $i['civico'] : ''));
        return [
            'TITOLO' => $f ? 'Dott.ssa' : 'Dott.', 'NOMINATIVO' => trim($i['nome'] . ' ' . $i['cognome']), 'NATO' => $f ? 'nata' : 'nato', 'RESIDENTE' => 'residente',
            'VINCITORE' => $f ? 'vincitrice' : 'vincitore', 'LUOGO_NASCITA' => $i['luogo_nascita'] ?: '________', 'DATA_NASCITA' => $i['data_nascita'] ? date('d/m/Y', strtotime($i['data_nascita'])) : '________',
            'COMUNE_RESIDENZA' => $i['comune_residenza'] ?: '________', 'INDIRIZZO' => $ind !== '' ? $ind : '________', 'CODICE_FISCALE' => $i['codice_fiscale'],
            'DECRETO_BANDO' => decreto_testo($i['decreto_bando'], $i['decreto_bando_data']) ?: '________', 'DECRETO_COMMISSIONE' => decreto_testo($i['decreto_commissione'], $i['decreto_commissione_data']) ?: '________',
            'ATTIVITA' => (string)$i['attivita'], 'ORE' => $i['ore'] !== null ? $num($i['ore'], 1) : '', 'PERIODO' => (string)$i['periodo'],
            'COMPENSO' => $i['compenso'] !== null ? '€ ' . number_format((float)$i['compenso'], 2, ',', '.') : '',
            'LUOGO' => $i['luogo'] ?: 'Rende', 'DATA_LETTERA' => date('d/m/Y', strtotime($i['data_lettera'] ?: ($i['inviata_il'] ?: 'now'))),
            'FIRMA_DOCENTE' => 'Prof. ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome']), 'FIRMA_STUDENTE' => trim($i['nome'] . ' ' . $i['cognome']), 'FIRMA_DIRETTORE' => 'Prof. ' . $i['direttore_nome'],
        ];
    }
}

if (!function_exists('docx_lettera_incarico')) {
    // Word precompilato dal modello del Dipartimento (modelli_documenti/lettera_incarico_tutorato.docx): percorso temporaneo o null
    function docx_lettera_incarico(array $i): ?string {
        $modello = RADICE_SITO . '/' . MODELLO_LETTERA_INCARICO;
        if (!class_exists('ZipArchive') || !is_file($modello)) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'inc');
        copy($modello, $tmp);
        $z = new ZipArchive();
        if ($z->open($tmp) !== true) return null;
        $xml = (string)$z->getFromName('word/document.xml');
        foreach (dati_lettera_incarico($i) as $k => $v) {
            // a capo nelle attività: interruzione di riga di Word
            $x = str_replace("\n", '</w:t><w:br/><w:t xml:space="preserve">', xml_testo(str_replace("\r", '', $v)));
            $xml = str_replace('{{' . $k . '}}', $x, $xml);
        }
        $z->addFromString('word/document.xml', $xml);
        $z->close();
        return $tmp;
    }
}

if (!function_exists('pdf_lettera_incarico')) {
    // Lettera in PDF (stesso testo del modello Word). $firma = conferma dello studente con SPID/CIE (riquadro e firma per accettazione).
    // Ritorna [contenuto PDF, segnaposti delle firme].
    function pdf_lettera_incarico(array $i, ?array $firma = null): array {
        $d = dati_lettera_incarico($i);
        $pdf = new PdfSemplice(['logo' => RADICE_SITO . '/' . LOGO_LETTERA_INCARICO, 'logo_larghezza' => 210,
                                'piede' => "Dipartimento di Biologia, Ecologia e Scienze della Terra – Università della Calabria – Via P. Bucci, Cubo 4B – 87036 Rende (CS) – dipartimento.best@pec.unical.it – lettera {$i['codice']}",
                                'info' => ['Title' => 'Lettera di incarico di tutorato – ' . $d['NOMINATIVO'], 'Author' => 'Dipartimento DiBEST – Università della Calabria', 'Subject' => 'Conferimento incarico di tutorato ' . $i['codice']]]);
        $pdf->paragrafo($d['TITOLO'] . ' ' . $d['NOMINATIVO'] . "\n" . $i['email'], ['al' => 'destra', 'dopo' => 14]);
        $pdf->paragrafo('**OGGETTO**: Conferimento incarico riguardante attività di tutorato, nonché attività didattico integrative, propedeutiche e di recupero (ART. 1 Co. 1°, lett. b) L. 170/2003) – Bando di selezione di cui al D.D. n. ' . $d['DECRETO_BANDO'] . '.', ['dopo' => 12]);
        $pdf->paragrafo('Gentile ' . $d['NOMINATIVO'] . ', ' . $d['NATO'] . ' a ' . $d['LUOGO_NASCITA'] . ' il ' . $d['DATA_NASCITA'] . ' e residente a ' . $d['COMUNE_RESIDENZA'] . ' in ' . $d['INDIRIZZO'] . ', codice fiscale ' . $d['CODICE_FISCALE']
                       . ', ' . $d['VINCITORE'] . ' della procedura selettiva, i cui atti sono stati approvati con D.D. n. ' . $d['DECRETO_COMMISSIONE'] . ', alla S. V. viene conferito il seguente incarico di “supporto alle attività didattiche, in qualità di tutor”:', ['dopo' => 8]);
        $pdf->tabella(['ATTIVITÀ DA SVOLGERE', 'N. ORE', 'PERIODO', "COMPENSO ASSEGNO\n(al netto degli oneri a carico dell'Ente)"], [[$d['ATTIVITA'], $d['ORE'], $d['PERIODO'], $d['COMPENSO']]], ['larghezze' => [4, 1.2, 2.4, 2.4], 'sz' => 9.5, 'al' => [1 => 'centro', 2 => 'centro', 3 => 'centro']]);
        foreach ([
            'L’incarico deve essere eseguito personalmente dalla S.V. la quale non potrà quindi valersi di sostituti.',
            'Il compenso forfetario lordo sarà erogato in unica soluzione a fine prestazione, previa notifica del completamento delle attività svolte da parte del Responsabile delle attività.',
            'Qualora si dovessero verificare riduzioni o sospensioni dell’attività oggetto della presente lettera d’incarico, per motivi didattici e/o organizzativi, la misura del corrispettivo sarà rapportata alle ore di collaborazione effettivamente svolte, tramite verifica dell’apposito registro delle attività, ove previsto.',
            'All’assegno, si applicano le disposizioni dell’art. 10 bis del decreto legislativo 15 dicembre 1997, n° 446, nonché quelle dell’art. 4 della Legge 13 agosto 1984, n° 476, e successive modificazioni, ed in materia previdenziale quelle dell’art. 2, commi 26 e seguenti, della Legge 8 agosto 1995, n° 335, e successive modificazioni (Gestione Separata INPS).',
            'I compensi per gli incarichi suddetti sono esenti dall’applicazione delle aliquote IRAP ed IRPEF, mentre scontano il contributo INPS – Gestione Separata (art. 2 commi 26 e s.s. L. 335/95).',
            'Durante l’intero periodo della collaborazione sarà cura dell’Università coprire, a proprie spese, con assicurazione il rischio da infortuni.',
            'La prestazione non dà luogo a diritti in ordine all’accesso nei ruoli delle Università e degli Istituti di Istruzione Universitaria Statali.',
            'L’assegno per attività di tutorato, nonché per le attività didattico – integrative, propedeutiche e di recupero, di cui sopra, è compatibile con la fruizione delle borse di studio di cui all’art. 8 della legge 2 dicembre 1991, n. 390.',
        ] as $t) $pdf->paragrafo($t, ['sz' => 10.5, 'dopo' => 5]);
        $pdf->paragrafo($d['LUOGO'] . ', ' . $d['DATA_LETTERA'], ['prima' => 6, 'dopo' => 14, 'al' => 'sinistra']);
        // Firme: presa visione del docente e firma del direttore (PAdES, aspetto visibile negli spazi riservati), accettazione dello studente (SPID/CIE)
        $pdf->serve(150);
        $l3 = ($pdf->larg - $pdf->sx - $pdf->dx) / 3; $top = $pdf->y;
        $pdf->spazioFirma('docente', "PRESA VISIONE\nDEL RESPONSABILE DELL’ATTIVITÀ", $d['FIRMA_DOCENTE'] . "\n(firma digitale PAdES)", $pdf->sx, $l3);
        $y1 = $pdf->y; $pdf->y = $top;
        $pdf->spazioFirma('studente', "PER ACCETTAZIONE\n ", $firma ? $d['FIRMA_STUDENTE'] . "\nconfermata con " . (METODI_ACCESSO[$firma['metodo']] ?? $firma['metodo']) . ' il ' . date('d/m/Y H:i', strtotime($firma['confermata_il'])) : $d['FIRMA_STUDENTE'], $pdf->sx + $l3, $l3);
        $y2 = $pdf->y; $pdf->y = $top;
        $pdf->spazioFirma('direttore', "IL DIRETTORE\n ", $d['FIRMA_DIRETTORE'] . "\n(firma digitale PAdES)", $pdf->sx + 2 * $l3, $l3);
        $pdf->y = max($y1, $y2, $pdf->y) + 14;
        if ($firma) {
            $pdf->riquadro('**Accettazione dell’incarico con identità digitale.** ' . $d['NOMINATIVO'] . ' (codice fiscale ' . $i['codice_fiscale'] . ') ha preso visione della lettera e ha confermato l’accettazione '
                . 'dopo l’accesso al portale Didattica DiBEST con ' . (METODI_ACCESSO[$firma['metodo']] ?? $firma['metodo']) . ($firma['livello'] ? ' di livello ' . (int)$firma['livello'] : '') . '.' . "\n"
                . 'Identity provider: ' . ($firma['idp'] ?: 'n.d.') . ($firma['spid_code'] !== '' ? ' – spidCode: ' . $firma['spid_code'] : '') . ($firma['contesto'] !== '' ? ' – contesto: ' . $firma['contesto'] : '') . "\n"
                . 'Autenticazione: ' . date('d/m/Y H:i:s', strtotime($firma['istante'])) . ' – conferma: ' . date('d/m/Y H:i:s', strtotime($firma['confermata_il'])) . ' – indirizzo IP: ' . ($firma['ip'] ?: 'n.d.') . "\n"
                . 'Impronta SHA-256 dei dati della lettera: ' . $firma['impronta'], ['sz' => 7.8, 'dopo' => 4]);
        }
        $pdf->paragrafo('Documento firmato digitalmente ai sensi del Codice dell’Amministrazione Digitale (D.Lgs. 82/2005) e norme ad esso connesse.', ['sz' => 8, 'font' => 'H', 'al' => 'centro', 'prima' => 4]);
        return [$pdf->pdf(), $pdf->segnaposti];
    }
}

if (!function_exists('impronta_dati_incarico')) {
    // Impronta dei dati della lettera (cosa ha confermato lo studente): cambia se cambia un solo dato
    function impronta_dati_incarico(array $i): string {
        $d = dati_lettera_incarico($i); unset($d['DATA_LETTERA']);
        return hash('sha256', json_encode([$i['codice'], $d], JSON_UNESCAPED_UNICODE));
    }
}

// ==============================================================================
// FILE DELLA LETTERA
// ==============================================================================

if (!function_exists('salva_file_incarico')) {
    // Salva una versione del PDF in uploads/incarichi/ e la rende quella corrente. Ritorna il percorso relativo.
    function salva_file_incarico($conn, array $i, string $pdf, string $passo): ?string {
        $dir = RADICE_SITO . '/' . DIR_INCARICHI;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_file($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "# Lettere di incarico: si scaricano solo dal pannello o con il link personale\nRequire all denied\n");
        $nome = preg_replace('/[^A-Za-z0-9_-]/', '', $i['codice']) . '_' . $passo . '_' . bin2hex(random_bytes(4)) . '.pdf';
        if (@file_put_contents($dir . $nome, $pdf) === false) return null;
        $rel = DIR_INCARICHI . $nome;
        $st = $conn->prepare("UPDATE tutorato_incarichi SET file_pdf = ?, aggiornata_il = NOW() WHERE id = ?");
        $id = (int)$i['id']; $st->bind_param("si", $rel, $id); $st->execute();
        return $rel;
    }
    function pdf_corrente_incarico(array $i): ?string {
        if (empty($i['file_pdf'])) return null;
        $base = realpath(RADICE_SITO . '/' . DIR_INCARICHI); $p = realpath(RADICE_SITO . '/' . $i['file_pdf']);
        return ($base && $p && strpos($p, $base . DIRECTORY_SEPARATOR) === 0 && is_file($p)) ? $p : null;
    }
    // Nome del file da scaricare: LETTERA_INCARICO_COGNOME_NOME[_firmata].pdf
    function nome_file_incarico(array $i, string $suffisso = ''): string {
        $n = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $i['cognome'] . '_' . $i['nome']) ?: $i['codice']));
        return 'LETTERA_INCARICO_' . trim($n, '_') . ($suffisso !== '' ? '_' . $suffisso : '') . '.pdf';
    }
}

// ==============================================================================
// ITER: STUDENTE (SPID/CIE) → DOCENTE (PAdES) → DIRETTORE (PAdES) → OPERATORE (protocollo)
// ==============================================================================

if (!function_exists('email_incarico')) {
    function email_incarico($conn, string $a, string $oggetto, string $corpo, string $link = '', string $bottone = '', array $allegati = []): void {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        inviaNotificaEmail($a, $oggetto, $corpo . ($link !== '' ? "<p style='margin-top:18px;'><a href='" . $h($link) . "' style='background:#047857;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>" . $h($bottone) . "</a></p><p style='font-size:12px;color:#64748b;'>Il link è personale: non inoltrarlo.</p>" : ''), $conn, '#047857', $allegati);
    }
    // Chi riceve la lettera firmata: l'operatore scelto nel bando, altrimenti chi ha il compito "Bandi", altrimenti gli amministratori
    function email_operatori_incarico($conn, array $i): array {
        $o = !empty($i['operatore_id']) ? operatore_ufficio($conn, (int)$i['operatore_id']) : null;
        if ($o) return [$o['email']];
        $e = array_column(operatori_ufficio($conn, 'bandi'), 'email');
        return $e ?: (function_exists('email_amministratori') ? email_amministratori($conn) : []);
    }
}

if (!function_exists('invia_incarico_studente')) {
    // Passo 1: la lettera va allo studente (email con il link personale). Ritorna un errore o null.
    function invia_incarico_studente($conn, int $id, string $autore = ''): ?string {
        $i = incarico_tutorato($conn, $id);
        if (!$i) return "Lettera non trovata.";
        if (!in_array($i['stato'], ['bozza', 'inviata'], true)) return "La lettera è già stata confermata dallo studente.";
        $tok = $i['token_studente'] ?: bin2hex(random_bytes(20));
        $data_l = $i['data_lettera'] ?: date('Y-m-d');
        $st = $conn->prepare("UPDATE tutorato_incarichi SET stato = 'inviata', token_studente = ?, inviata_il = NOW(), data_lettera = ? WHERE id = ?");
        $st->bind_param("ssi", $tok, $data_l, $id); $st->execute();
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        email_incarico($conn, $i['email'], "Lettera di incarico di tutorato: controlla e conferma",
            "<p>Gentile " . $h($i['nome'] . ' ' . $i['cognome']) . ",</p><p>è pronta la tua <strong>lettera di incarico</strong> per le attività di tutorato (" . $h($i['bando_titolo']) . ").</p>"
            . "<p>Accedi al portale <strong>con SPID o CIE</strong>, controlla i dati e clicca su <strong>Confermo e accetto</strong>: la conferma, con i dati della tua identità digitale, vale come accettazione dell'incarico. Poi la lettera passa alla firma del docente responsabile e del Direttore.</p>"
            . "<p>Se trovi un errore nei dati segnalalo dalla stessa pagina.</p>", url_base_sito() . '/incarico.php?t=' . $tok, 'Controlla e conferma la lettera');
        evento_incarico($conn, $id, 'inviata', ($i['stato'] === 'inviata' ? 'Email di nuovo allo studente: ' : 'Inviata allo studente: ') . $i['email'], $autore);
        return null;
    }
}

if (!function_exists('accesso_valido_incarico')) {
    // Lo studente può confermare solo se è proprio lui (codice fiscale dell'accesso = codice fiscale della lettera) e, se richiesto, con SPID o CIE.
    // Ritorna un errore o null.
    function accesso_valido_incarico(array $i, ?array $utente, array $meta): ?string {
        $cf_utente = strtoupper(trim((string)($utente['codice_fiscale'] ?? '')));
        if ($cf_utente === '' || $cf_utente !== strtoupper($i['codice_fiscale'])) return "La lettera è intestata a un'altra persona: accedi con l'identità digitale di " . $i['nome'] . ' ' . $i['cognome'] . '.';
        if (solo_spid_cie_incarichi() && !in_array($meta['metodo'] ?? '', ['spid', 'cie'], true)) return "Per confermare serve l'accesso con SPID o CIE: esci e rientra scegliendo SPID o CIE.";
        return null;
    }
    function solo_spid_cie_incarichi(): bool {
        $v = function_exists('env_valore') ? env_valore('INCARICHI_SOLO_SPID_CIE') : null;
        return $v === null || !in_array(strtolower($v), ['0', 'no', 'false'], true);
    }
}

if (!function_exists('conferma_incarico_studente')) {
    // Passo 2: lo studente conferma. Si genera il PDF definitivo con i dati dell'autenticazione e la lettera va al docente.
    function conferma_incarico_studente($conn, int $id, array $utente, array $meta): ?string {
        $i = incarico_tutorato($conn, $id);
        if (!$i || $i['stato'] !== 'inviata') return "La lettera non è in attesa della tua conferma.";
        if ($err = accesso_valido_incarico($i, $utente, $meta)) return $err;
        $firma = ['metodo' => $meta['metodo'] ?? 'ateneo', 'livello' => $meta['livello'] ?? null, 'idp' => (string)($meta['idp'] ?? ''), 'contesto' => (string)($meta['contesto'] ?? ''),
                  'spid_code' => (string)($meta['spid_code'] ?? ''), 'cf' => strtoupper((string)($utente['codice_fiscale'] ?? '')), 'istante' => (string)($meta['istante'] ?? date('c')),
                  'sessione' => (string)($meta['sessione'] ?? ''), 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ($meta['ip'] ?? '')), 'confermata_il' => date('Y-m-d H:i:s'),
                  'impronta' => impronta_dati_incarico($i), 'utente_id' => (int)($utente['id'] ?? 0)];
        [$pdf] = pdf_lettera_incarico($i, $firma);
        $firma['sha256_pdf'] = hash('sha256', $pdf);
        if (!salva_file_incarico($conn, $i, $pdf, 'confermata')) return "Non è stato possibile salvare la lettera: riprova.";
        $tok = bin2hex(random_bytes(20)); $fj = json_encode($firma, JSON_UNESCAPED_UNICODE);
        $st = $conn->prepare("UPDATE tutorato_incarichi SET stato = 'confermata', studente_firma_json = ?, confermata_il = NOW(), token_docente = ? WHERE id = ?");
        $st->bind_param("ssi", $fj, $tok, $id); $st->execute();
        evento_incarico($conn, $id, 'confermata', 'Confermata dallo studente con ' . (METODI_ACCESSO[$firma['metodo']] ?? $firma['metodo']) . ($firma['livello'] ? ' livello ' . $firma['livello'] : '') . ($firma['spid_code'] ? ' (spidCode ' . $firma['spid_code'] . ')' : ''), trim($i['nome'] . ' ' . $i['cognome']));
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        email_incarico($conn, $i['docente_email'], "Lettera di incarico da firmare: " . trim($i['cognome'] . ' ' . $i['nome']),
            "<p>Gentile Prof. " . $h(trim($i['docente_nome'] . ' ' . $i['docente_cognome'])) . ",</p><p>" . $h(trim($i['nome'] . ' ' . $i['cognome'])) . " ha accettato l'incarico di tutorato (" . $h($i['bando_titolo']) . ") di cui è <strong>responsabile dell'attività</strong>.</p>"
            . "<p>Apra la lettera e la firmi digitalmente <strong>in formato PAdES</strong>: con la firma remota Aruba direttamente dal portale, oppure scaricandola e caricando il PDF firmato. Dopo la sua firma la lettera passa al Direttore.</p>",
            url_base_sito() . '/firma_incarico.php?t=' . $tok, 'Apri e firma la lettera');
        return null;
    }
}

if (!function_exists('segnala_errore_incarico')) {
    // Lo studente segnala un errore nei dati: l'operatore riceve un'email (la lettera resta da confermare)
    function segnala_errore_incarico($conn, int $id, string $testo): ?string {
        $i = incarico_tutorato($conn, $id);
        $testo = mb_substr(trim($testo), 0, 2000);
        if (!$i || $i['stato'] !== 'inviata') return "La lettera non è in attesa della tua conferma.";
        if ($testo === '') return "Scrivi cosa non va.";
        $st = $conn->prepare("UPDATE tutorato_incarichi SET nota_studente = ? WHERE id = ?"); $st->bind_param("si", $testo, $id); $st->execute();
        evento_incarico($conn, $id, 'errore', 'Segnalazione dello studente: ' . $testo, trim($i['nome'] . ' ' . $i['cognome']));
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        foreach (email_operatori_incarico($conn, $i) as $e)
            email_incarico($conn, $e, "Lettera di incarico: segnalazione di " . trim($i['cognome'] . ' ' . $i['nome']),
                "<p>Lo studente segnala un errore nei dati della lettera <strong>" . $h($i['codice']) . "</strong>:</p><p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br($h($testo)) . "</p><p>Annulla la lettera e preparane una corretta (puoi duplicarla), poi inviala di nuovo.</p>",
                url_base_sito() . '/admin/tutorato.php?incarico=' . (int)$i['id'], 'Apri nel pannello');
        return null;
    }
}

if (!function_exists('analizza_firma_pdf')) {
    // Verifica crittografica dell'ultima firma del PDF (integrità del documento firmato) e dati del firmatario dal certificato:
    // ['integra' => true|false|null (null = controllo non disponibile), 'nome' => CN, 'cf' => codice fiscale (serialNumber TINIT-…), 'emittente' => …]
    // La catena dei certificati (elenco dei certificatori qualificati) la verifica chi protocolla, con gli strumenti di Ateneo.
    function analizza_firma_pdf(string $pdf): array {
        $out = ['integra' => null, 'nome' => '', 'cf' => '', 'emittente' => ''];
        if (!preg_match_all('#/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', $pdf, $mm, PREG_SET_ORDER)) return $out;
        $m = end($mm);
        [$a, $b, $c, $d] = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]];
        $der = @hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', substr($pdf, $a + $b, $c - $a - $b)));
        if (!$der || strlen($der) < 4 || ord($der[0]) !== 0x30) return $out;
        // Lunghezza DER: il contenuto è riempito di zeri fino alla dimensione riservata
        $len = ord($der[1]); $hl = 2;
        if ($len & 0x80) { $n = $len & 0x7f; $len = 0; for ($k = 0; $k < $n; $k++) $len = ($len << 8) | ord($der[2 + $k]); $hl = 2 + $n; }
        $der = substr($der, 0, $hl + $len);
        if (!function_exists('openssl_cms_verify')) return $out;
        $fd = tempnam(sys_get_temp_dir(), 'pd'); $fs = tempnam(sys_get_temp_dir(), 'ps'); $fc = tempnam(sys_get_temp_dir(), 'pc');
        file_put_contents($fd, substr($pdf, $a, $b) . substr($pdf, $c, $d)); file_put_contents($fs, $der);
        $out['integra'] = @openssl_cms_verify($fd, OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOVERIFY, $fc, [], null, null, null, $fs, OPENSSL_ENCODING_DER) === true;
        while (openssl_error_string() !== false) {}
        $pem = (string)@file_get_contents($fc);
        if ($pem !== '' && preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $cc)) {
            foreach ($cc[0] as $cert) {
                $x = @openssl_x509_parse($cert);
                if (!$x) continue;
                // Il certificato di firma (non quello di una CA): con codice fiscale o il primo
                $sn = (string)($x['subject']['serialNumber'] ?? '');
                $out['nome'] = (string)(is_array($x['subject']['CN'] ?? null) ? end($x['subject']['CN']) : ($x['subject']['CN'] ?? ''));
                $out['cf'] = preg_match('/^(?:TINIT-)?([A-Z0-9]{16})$/i', $sn, $q) ? strtoupper($q[1]) : '';
                $out['emittente'] = (string)($x['issuer']['O'] ?? ($x['issuer']['CN'] ?? ''));
                if ($out['cf'] !== '') break;
            }
        }
        @unlink($fd); @unlink($fs); @unlink($fc);
        return $out;
    }
}

if (!function_exists('verifica_pdf_firmato')) {
    // Controlla il PDF firmato rispetto alla versione precedente: deve essere un PDF (non un .p7m CAdES), cominciare esattamente
    // con la versione precedente (firma aggiunta senza modificare il documento), avere una firma PAdES in più che copre tutto il file,
    // integra; se il certificato ha il codice fiscale e quello di chi deve firmare è noto, devono coincidere.
    // Ritorna [errore | null, dati della firma].
    function verifica_pdf_firmato(string $prima, string $dopo, string $cf_atteso = ''): array {
        if (strncmp($dopo, '%PDF-', 5) !== 0) return ["Il file non è un PDF: la firma deve essere PAdES (PDF firmato), non CAdES (.p7m).", []];
        if (strlen($dopo) <= strlen($prima) || strncmp($dopo, $prima, strlen($prima)) !== 0) return ["Il PDF firmato non corrisponde alla lettera: deve essere la stessa lettera scaricata dal portale, con la firma aggiunta (senza modifiche o nuovi salvataggi).", []];
        $f0 = firme_pades_pdf($prima); $f1 = firme_pades_pdf($dopo);
        if (count($f1) !== count($f0) + 1) return ["Nel PDF non c'è una nuova firma digitale PAdES.", []];
        $nuova = end($f1);
        if (!in_array($nuova['subfilter'], ['ETSI.CAdES.detached', 'adbe.pkcs7.detached'], true)) return ["La firma non è nel formato PAdES (" . ($nuova['subfilter'] ?: 'formato sconosciuto') . ").", []];
        if (!$nuova['valida_struttura'] || !$nuova['copre_tutto']) return ["La firma non copre tutto il documento.", []];
        $info = analizza_firma_pdf($dopo);
        if ($info['integra'] === false) return ["La firma digitale non è integra: il documento risulta modificato dopo la firma.", $info];
        $cf_atteso = strtoupper(trim($cf_atteso));
        if ($cf_atteso !== '' && $info['cf'] !== '' && $info['cf'] !== $cf_atteso) return ["La firma è di " . ($info['nome'] ?: 'un\'altra persona') . " (codice fiscale " . $info['cf'] . "), non di chi deve firmare la lettera.", $info];
        return [null, $info];
    }
}

if (!function_exists('registra_firma_incarico')) {
    // Passo 3 o 4: firma PAdES del docente (stato 'confermata') o del direttore (stato 'firmata_docente'). $pdf = file firmato.
    function registra_firma_incarico($conn, int $id, string $ruolo, string $pdf, string $come = 'caricamento'): ?string {
        $i = incarico_tutorato($conn, $id);
        $atteso = ['docente' => 'confermata', 'direttore' => 'firmata_docente'][$ruolo] ?? null;
        if (!$i || !$atteso || $i['stato'] !== $atteso) return "La lettera non è in attesa di questa firma.";
        $cor = pdf_corrente_incarico($i);
        if (!$cor) return "Manca il PDF della lettera.";
        [$err, $info] = verifica_pdf_firmato((string)file_get_contents($cor), $pdf, $ruolo === 'docente' ? (string)$i['docente_cf'] : (string)$i['direttore_cf']);
        if ($err) return $err;
        $come .= ($info['nome'] !== '' ? ', certificato di ' . $info['nome'] . ($info['cf'] !== '' ? ' (' . $info['cf'] . ')' : '') . ($info['emittente'] !== '' ? ' rilasciato da ' . $info['emittente'] : '') : '')
               . ($info['integra'] === true ? ', firma integra' : '');
        if (!salva_file_incarico($conn, $i, $pdf, 'firmata_' . $ruolo)) return "Non è stato possibile salvare il PDF firmato.";
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $chi = $ruolo === 'docente' ? 'Prof. ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome']) : 'Prof. ' . $i['direttore_nome'];
        if ($ruolo === 'docente') {
            $tok = bin2hex(random_bytes(20));
            $st = $conn->prepare("UPDATE tutorato_incarichi SET stato = 'firmata_docente', firmata_docente_il = NOW(), token_direttore = ?, solleciti = 0, sollecito_il = NULL WHERE id = ?");
            $st->bind_param("si", $tok, $id); $st->execute();
            evento_incarico($conn, $id, 'firma_docente', "Firmata in PAdES dal docente ($come)", $chi);
            email_incarico($conn, $i['direttore_email'], "Lettera di incarico di tutorato da firmare: " . trim($i['cognome'] . ' ' . $i['nome']),
                "<p>Gentile Direttore,</p><p>la lettera di incarico di tutorato di <strong>" . $h(trim($i['nome'] . ' ' . $i['cognome'])) . "</strong> (" . $h($i['bando_titolo']) . ") è stata accettata dallo studente e firmata dal responsabile dell'attività, " . $h($chi) . ".</p>"
                . "<p>La firmi digitalmente <strong>in formato PAdES</strong> (firma remota Aruba dal portale o caricando il PDF firmato): poi la lettera va all'Ufficio per il protocollo.</p>",
                url_base_sito() . '/firma_incarico.php?t=' . $tok, 'Apri e firma la lettera');
        } else {
            db_esegui($conn, "UPDATE tutorato_incarichi SET stato = 'firmata', firmata_direttore_il = NOW(), solleciti = 0, sollecito_il = NULL WHERE id = ?", [$id]);
            evento_incarico($conn, $id, 'firma_direttore', "Firmata in PAdES dal direttore ($come)", $chi);
            // L'incarico è perfezionato: il tutor può segnare le ore nel registro delle attività
            email_incarico($conn, $i['email'], "Tutorato: lettera di incarico firmata, registro delle attività aperto",
                "<p>Gentile " . $h($i['nome'] . ' ' . $i['cognome']) . ",</p><p>la tua lettera di incarico è firmata dal docente responsabile e dal Direttore. Da ora segna nel <strong>registro delle attività</strong> i giorni, le ore e le attività svolte: il docente le approva e a fine incarico dichiari concluse le attività.</p>",
                url_base_sito() . '/registro_tutorato.php?id=' . $id, 'Apri il registro');
            foreach (email_operatori_incarico($conn, $i) as $e)
                email_incarico($conn, $e, "Lettera di incarico firmata: da protocollare – " . trim($i['cognome'] . ' ' . $i['nome']),
                    "<p>La lettera di incarico <strong>" . $h($i['codice']) . "</strong> di " . $h(trim($i['nome'] . ' ' . $i['cognome'])) . " (" . $h($i['bando_titolo']) . ") ha tutte le firme: accettazione dello studente con identità digitale, firma PAdES del responsabile dell'attività e del Direttore.</p>"
                    . "<p>Scarica il PDF firmato e protocollalo, poi registra il numero di protocollo nel pannello.</p>",
                    url_base_sito() . '/admin/tutorato.php?incarico=' . $id, 'Scarica e protocolla');
        }
        return null;
    }
}

if (!function_exists('protocolla_incarico')) {
    // Ultimo passo: l'operatore registra il protocollo. $invia_copia = la lettera protocollata va anche allo studente.
    function protocolla_incarico($conn, int $id, string $prot, ?string $data, bool $invia_copia, string $autore = ''): ?string {
        $i = incarico_tutorato($conn, $id);
        $prot = mb_substr(trim($prot), 0, 100);
        if (!$i || !in_array($i['stato'], ['firmata', 'protocollata'], true)) return "La lettera non ha ancora tutte le firme.";
        if ($prot === '') return "Scrivi il numero di protocollo.";
        $data = $data && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : date('Y-m-d');
        $st = $conn->prepare("UPDATE tutorato_incarichi SET stato = 'protocollata', protocollo = ?, protocollo_data = ?, protocollata_il = NOW() WHERE id = ?");
        $st->bind_param("ssi", $prot, $data, $id); $st->execute();
        evento_incarico($conn, $id, 'protocollata', 'Protocollo ' . $prot . ' del ' . date('d/m/Y', strtotime($data)), $autore);
        if ($invia_copia && ($f = pdf_corrente_incarico($i))) {
            $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            email_incarico($conn, $i['email'], "Lettera di incarico di tutorato firmata (prot. " . $prot . ")",
                "<p>Gentile " . $h($i['nome'] . ' ' . $i['cognome']) . ",</p><p>in allegato la tua lettera di incarico con tutte le firme, protocollata con il n. " . $h($prot) . " del " . date('d/m/Y', strtotime($data)) . ".</p>", '', '',
                [['path' => $f, 'nome' => nome_file_incarico($i, 'firmata')]]);
            evento_incarico($conn, $id, 'copia', 'Copia della lettera firmata inviata allo studente', $autore);
        }
        return null;
    }
}

if (!function_exists('annulla_incarico')) {
    function annulla_incarico($conn, int $id, string $motivo, string $autore = ''): ?string {
        $i = incarico_tutorato($conn, $id);
        if (!$i || in_array($i['stato'], ['protocollata', 'annullata'], true)) return "La lettera non si può annullare.";
        db_esegui($conn, "UPDATE tutorato_incarichi SET stato = 'annullata', token_studente = NULL, token_docente = NULL, token_direttore = NULL, token_fine = NULL WHERE id = ?", [$id]);
        evento_incarico($conn, $id, 'annullata', 'Annullata' . (trim($motivo) !== '' ? ': ' . mb_substr(trim($motivo), 0, 500) : ''), $autore);
        return null;
    }
    // Copia di una lettera (es. dopo un errore segnalato): nuova bozza con gli stessi dati
    function duplica_incarico($conn, int $id): int {
        $i = incarico_tutorato($conn, $id);
        if (!$i) return 0;
        [$nuovo] = salva_incarico_tutorato($conn, array_merge($i, ['id' => 0]));
        return (int)$nuovo;
    }
}

if (!function_exists('firmatario_incarico')) {
    // Chi deve firmare con il link: il docente o il direttore riconosciuti dall'accesso (email, codice fiscale o scheda dell'anagrafe)
    function firmatario_incarico(array $i, string $ruolo, ?array $u): bool {
        if (!$u || empty($u['id'])) return false;
        $email = strtolower(trim((string)($u['email'] ?? ''))); $cf = strtoupper(trim((string)($u['codice_fiscale'] ?? ''))); $pid = (string)($u['persona_id'] ?? '');
        [$e, $c, $p] = $ruolo === 'docente' ? [$i['docente_email'], $i['docente_cf'], $i['docente_persona_id']] : [$i['direttore_email'], $i['direttore_cf'], $i['direttore_persona_id']];
        return ($email !== '' && $email === strtolower((string)$e)) || ($cf !== '' && $cf === strtoupper((string)$c)) || ($pid !== '' && $pid === (string)$p);
    }
}

// ==============================================================================
// FIRMA REMOTA ARUBA (ArubaSignService, ARSS) – PAdES
// Configurazione nel .env: ARUBA_ARSS_URL (servizio SOAP), ARUBA_ARSS_DOMINIO (dominio di autenticazione OTP, es. frRESIGN
// o quello dell'Ateneo), ARUBA_ARSS_CERTID (di solito AS0). Utente, password e OTP li scrive chi firma: non si salvano.
// ==============================================================================

if (!function_exists('firma_remota_disponibile')) {
    function firma_remota_disponibile(): bool {
        return function_exists('curl_init') && function_exists('env_valore') && (string)env_valore('ARUBA_ARSS_URL') !== '';
    }
}

if (!function_exists('firma_remota_aruba')) {
    // Firma PAdES (pdfsignatureV2, profilo PADESBES) del PDF con la firma remota Aruba. $aspetto = segnaposto della firma visibile
    // (pagina, x, y, l, a in punti). Ritorna [PDF firmato, null] oppure [null, messaggio di errore].
    function firma_remota_aruba(string $pdf, string $utente, string $password, string $otp, ?array $aspetto = null, string $motivo = ''): array {
        $url = (string)env_valore('ARUBA_ARSS_URL');
        if ($url === '' || !function_exists('curl_init')) return [null, "La firma remota non è configurata sul portale: scarica il PDF, firmalo in PAdES e caricalo."];
        $utente = trim($utente); $otp = preg_replace('/\s+/', '', $otp);
        if ($utente === '' || $password === '' || $otp === '') return [null, "Scrivi utente, password (PIN) e codice OTP della firma remota."];
        $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $app = '';
        if ($aspetto) {
            $app = '<Apparence><leftx>' . (int)$aspetto['x'] . '</leftx><lefty>' . (int)$aspetto['y'] . '</lefty><page>' . (int)$aspetto['pagina'] . '</page>'
                 . '<reason>' . $x($motivo) . '</reason><rightx>' . (int)($aspetto['x'] + $aspetto['l']) . '</rightx><righty>' . (int)($aspetto['y'] + $aspetto['a']) . '</righty>'
                 . '<testo>' . $x($motivo) . '</testo><bScaleFont>true</bScaleFont><bShowDateTime>true</bShowDateTime></Apparence>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:arub="http://arubasignservice.arubapec.it/"><soapenv:Header/><soapenv:Body>'
             . '<arub:pdfsignatureV2><SignRequestV2><binaryinput>' . base64_encode($pdf) . '</binaryinput><certID>' . $x(env_valore('ARUBA_ARSS_CERTID') ?? 'AS0') . '</certID>'
             . '<identity><otpPwd>' . $x($otp) . '</otpPwd><typeOtpAuth>' . $x(env_valore('ARUBA_ARSS_DOMINIO') ?? 'frRESIGN') . '</typeOtpAuth><user>' . $x($utente) . '</user><userPWD>' . $x($password) . '</userPWD></identity>'
             . '<profile>PADESBES</profile><requiredmark>false</requiredmark><transport>BYNARYNET</transport></SignRequestV2>' . $app . '<pdfprofile>PADESBES</pdfprofile></arub:pdfsignatureV2>'
             . '</soapenv:Body></soapenv:Envelope>';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $xml, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15,
                                CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""']]);
        $risp = curl_exec($ch); $cod = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
        curl_close($ch);
        if ($risp === false || $risp === '') return [null, "Il servizio di firma Aruba non risponde" . ($err !== '' ? " ($err)" : '') . ": riprova o carica il PDF firmato."];
        $stato = preg_match('#<status>([^<]*)</status>#', (string)$risp, $m) ? $m[1] : '';
        $descr = preg_match('#<description>([^<]*)</description>#', (string)$risp, $m) ? html_entity_decode($m[1]) : '';
        $codice = preg_match('#<return_code>([^<]*)</return_code>#', (string)$risp, $m) ? $m[1] : '';
        if ($stato !== 'OK' || !preg_match('#<binaryoutput>([^<]+)</binaryoutput>#', (string)$risp, $m)) {
            $fault = preg_match('#<faultstring>([^<]*)</faultstring>#', (string)$risp, $f) ? html_entity_decode($f[1]) : '';
            return [null, "Firma non riuscita" . ($descr !== '' || $fault !== '' ? ': ' . ($descr ?: $fault) : '') . ($codice !== '' ? " (codice $codice)" : '') . ($cod !== 200 && !$descr && !$fault ? " (HTTP $cod)" : '') . ". Controlla credenziali e OTP."];
        }
        $firmato = base64_decode($m[1], true);
        return $firmato ? [$firmato, null] : [null, "Risposta del servizio di firma non leggibile."];
    }
}

if (!function_exists('badge_stato_incarico')) {
    function badge_stato_incarico(string $stato): string {
        [$n, $col, $ico] = STATI_INCARICO[$stato] ?? [$stato, '#64748b', 'fa-circle'];
        return '<span class="badge" style="background:' . $col . ';"><i class="fa ' . $ico . ' me-1" aria-hidden="true"></i>' . htmlspecialchars($n) . '</span>';
    }
    // Passi della lettera per le pagine: [nome, fatto?, attuale?]
    function passi_incarico(array $i): array {
        $ordine = ['bozza', 'inviata', 'confermata', 'firmata_docente', 'firmata', 'protocollata'];
        $nomi = ['Preparata', 'Conferma dello studente (SPID/CIE)', 'Firma del docente (PAdES)', 'Firma del direttore (PAdES)', 'Protocollo', 'Protocollata'];
        $pos = array_search($i['stato'], $ordine, true);
        $out = [];
        foreach ($nomi as $k => $n) $out[] = [$n, $pos !== false && $k < $pos, $pos !== false && $k === $pos];
        return $out;
    }
}
