<?php
// =========================================================================
// Prove automatiche del portale, da lanciare prima di ogni caricamento sul server:
//   /c/xampp/php/php.exe -d extension=zip strumenti/prove/esegui.php
// 1. Prove delle funzioni su un database di prova usa e getta (eventi_prova, ricreato ogni volta da
//    database/schema.sql + migrazioni): convenzioni, periodi, verifica FSL, valutazioni, documenti precompilati…
// 2. Se l'ambiente locale è acceso (strumenti/locale/avvia.sh), prove delle pagine via HTTP: pagine pubbliche,
//    file riservati bloccati, accesso al pannello, iscrizione completa a un progetto FSL.
// Nessuna email parte: l'invio è sostituito da una funzione che le conta. Esce con codice 1 se una prova fallisce.
// =========================================================================
if (PHP_SAPI !== 'cli') exit;
$SITO = realpath(__DIR__ . '/../..');
chdir($SITO);
$EMAIL = [];
function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) { global $EMAIL; $EMAIL[] = ['a' => $to, 'oggetto' => $subject, 'corpo' => $body_html]; return true; }
$_SERVER['HTTP_HOST'] = 'dibest2.unical.it'; $_SERVER['PHP_SELF'] = '/eventi/index.php';

$OK = 0; $KO = 0;
function prova(bool $esito, string $nome, string $dettaglio = ''): void {
    global $OK, $KO;
    if ($esito) { $OK++; echo "  \e[32mOK\e[0m  $nome\n"; } else { $KO++; echo "  \e[31mKO\e[0m  $nome" . ($dettaglio !== '' ? "  → $dettaglio" : '') . "\n"; }
}
function sezione(string $t): void { echo "\n== $t\n"; }

// ---------------------------------------------------------------------------
// Database di prova
$MYSQL = getenv('MYSQL_BIN') ?: 'C:/xampp/mysql/bin/mysql.exe';
$conn = @new mysqli('127.0.0.1', 'root', '');
if ($conn->connect_error) { fwrite(STDERR, "Database non raggiungibile: avvia MariaDB (bash strumenti/locale/avvia.sh).\n"); exit(2); }
$conn->query("DROP DATABASE IF EXISTS eventi_prova");
$conn->query("CREATE DATABASE eventi_prova CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
exec('"' . $MYSQL . '" -u root eventi_prova < "' . $SITO . '/database/schema.sql" 2>&1', $out_sql, $rc);
if ($rc !== 0) { fwrite(STDERR, "Import dello schema non riuscito: " . implode("\n", $out_sql) . "\n"); exit(2); }
$conn->select_db('eventi_prova');
$conn->set_charset('utf8mb4');
require $SITO . '/functions.php';
@unlink($SITO . '/cache/schema_v29.ok');
$marker = glob($SITO . '/cache/schema_v*.ok');
foreach ($marker as $m) rename($m, $m . '.bak');     // non toccare l'ambiente locale: si ripristina alla fine
assicura_schema($conn);
foreach (glob($SITO . '/cache/schema_v*.ok') as $m) @unlink($m);
foreach ($marker as $m) rename($m . '.bak', $m);

$q = function (string $sql) use ($conn) { if (!$conn->query($sql)) { fwrite(STDERR, "SQL: " . $conn->error . "\n$sql\n"); exit(2); } };
$st = fn(int $id) => $conn->query("SELECT stato, convenzione, conv_promemoria FROM prenotazioni WHERE id = $id")->fetch_assoc();
$giorni = fn(int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));

$q("INSERT INTO pagine_eventi (id, titolo, slug) VALUES (1, 'OPENLAB', 'openlab'), (2, 'FSL', 'fsl')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (10, 1, 'Laboratorio', 'evento', 'Esperienze di laboratorio.'), (20, 2, 'Progetto FSL', 'progetto', NULL)");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, dedicata_scuole, per_scuole, attestati) VALUES (10, 1, 1, 1, 0)");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, data_inizio, data_fine, ore_totali, referenti_json, obiettivi) VALUES (20, 1, 1, '" . $giorni(10) . "', '" . $giorni(60) . "', 30, '[{\"nome\":\"Tutor Dipartimento\"}]', '<p>Obiettivi del percorso.</p>')");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, richiede_approvazione) VALUES
    (100, 10, 'Turno A', '" . $giorni(20) . "', '09:00', '12:00', 5, 0), (200, 20, 'Edizione 1', NULL, NULL, NULL, 1, 0), (201, 20, 'Edizione 2', NULL, NULL, NULL, 1, 1)");
$q("INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, tipo, comune, provincia, regione, indirizzo, cap) VALUES
    ('CSPS00001A', 'LICEO UNO', 'CSIS00001A', 'IIS UNO', 'LICEO', 'COSENZA', 'COSENZA', 'CALABRIA', 'VIA ROMA 1', '87100'),
    ('CSPS00002B', 'LICEO DUE', NULL, NULL, 'LICEO', 'RENDE', 'COSENZA', 'CALABRIA', '', '')");

// ---------------------------------------------------------------------------
sezione("Periodi e validità delle convenzioni");
prova(periodo_attivita('2026-10-10', '2026-12-10') === ['2026-10-10', '2026-12-10'], "periodo di un progetto (dal/al)");
prova(periodo_attivita(null, null, '2026-11-02') === ['2026-11-02', '2026-11-02'], "periodo di un evento (giorno del turno)");
prova(periodo_attivita(null, null, null) === [date('Y-m-d'), date('Y-m-d')], "senza date: oggi");
salva_convenzione($conn, ['scuola_codice' => 'CSPS00001A', 'data_stipula' => $giorni(-300), 'scadenza' => $giorni(30), 'docenti' => [['nome' => 'Docente Uno', 'email' => 'uno@example.org'], ['nome' => '', 'email' => '']]]);
prova(convenzione_valida($conn, 'CSPS00001A', true, $giorni(20), $giorni(20)) !== null, "convenzione copre il giorno del turno");
prova(convenzione_valida($conn, 'CSPS00001A', true, $giorni(10), $giorni(60)) === null, "convenzione che scade prima della fine del progetto non basta");
prova(convenzione_valida($conn, 'CSPS00002B', true) === null, "scuola senza convenzione");
prova(salva_convenzione($conn, ['scuola_codice' => 'CSPS00002B', 'data_stipula' => '2027-01-01', 'scadenza' => '2026-01-01']) === null, "date invertite rifiutate");
prova(salva_convenzione($conn, ['scuola_codice' => 'NONESISTE1', 'data_stipula' => $giorni(0)]) === null, "scuola fuori anagrafe rifiutata");
$doc = json_decode((string)$conn->query("SELECT docenti_json FROM convenzioni_scuole WHERE scuola_codice = 'CSPS00001A'")->fetch_assoc()['docenti_json'], true);
prova(count($doc) === 1 && $doc[0]['email'] === 'uno@example.org', "docenti dell'Allegato A (righe vuote scartate)");
$cs = cerca_scuole($conn, 'liceo uno');
prova(isset($cs[0]['conv'][0]) && $cs[0]['conv'][0][1] === $giorni(30), "ricerca scuole con i periodi di validità");
prova(testo_validita_convenzione(['data_stipula' => '2026-01-01', 'scadenza' => '2026-12-31']) === 'dal 01/01/2026 al 31/12/2026', "testo della validità");

sezione("Verifica delle iscrizioni FSL e registrazione");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, scuola_codice, convenzione, dati_custom_json) VALUES
    (1, 200, 'FS-1', 'confermata', 'Anna', 'Rossi', 'anna@example.org', 'CSPS00001A', NULL, '{\"numero_partecipanti\":\"20\"}'),
    (2, 100, 'OL-2', 'confermata', 'Anna', 'Rossi', 'anna@example.org', 'CSPS00001A', NULL, NULL),
    (3, 100, 'OL-3', 'confermata', 'Bruno', 'Verdi', 'bruno@example.org', NULL, NULL, '{\"scuola\":\"Scuola scritta a mano\"}'),
    (4, 201, 'FS-4', 'da_approvare', 'Carla', 'Neri', 'carla@example.org', 'CSPS00002B', 'no', NULL)");
$EMAIL = [];
$v = verifica_convenzioni_fsl($conn);
prova($v == ['coperte' => 1, 'da_stipulare' => 2, 'nuove_da_stipulare' => 1, 'senza_codice' => 1], "verifica: coperte / da stipulare / scritte a mano", json_encode($v));
prova($st(1) == ['stato' => 'confermata', 'convenzione' => 'no', 'conv_promemoria' => '3'], "prenotazione confermata non coperta: da stipulare, stato invariato, senza promemoria", json_encode($st(1)));
prova($st(2)['convenzione'] === 'ricevuta', "prenotazione coperta: ricevuta");
prova(count($EMAIL) === 0, "la verifica non manda email");
[$id_c, $n] = salva_convenzione($conn, ['scuola_codice' => 'CSPS00001A', 'data_stipula' => $giorni(0), 'scadenza' => $giorni(364)]);
prova($n === 1 && $st(1)['convenzione'] === 'ricevuta', "rinnovo: la prenotazione al progetto diventa coperta");
$EMAIL = [];
prova(convenzione_ricevuta_da_gestore($conn, 4, 'prova') === true, "convenzione ricevuta dal gestore");
prova($st(4)['stato'] === 'da_approvare', "turno con approvazione: resta da approvare");
prova(convenzione_valida($conn, 'CSPS00002B', true, $giorni(10), $giorni(60)) !== null, "convenzione registrata dal gestore copre il periodo del progetto");
prova(count($EMAIL) === 1 && str_contains($EMAIL[0]['corpo'], 'valutazione degli organizzatori'), "email alla scuola: resta in valutazione");

sezione("Email e documenti per la scuola");
$istr = html_istruzioni_convenzione([], true, 'FS-1');
prova(str_contains($istr, 'convenzione_online.php?code=FS-1') && str_contains($istr, 'dipartimento.best@pec.unical.it'), "istruzioni con la convenzione da compilare online e PEC");
prova(!str_contains(html_istruzioni_convenzione(['conv_url_modello' => 'https://example.org/mio.docx'], true, 'FS-1'), 'convenzione_precompilata.php?code='), "area con modello di convenzione proprio: niente convenzione precompilata");
$dc = dati_convenzione([]);
prova(str_ends_with($dc['modello'], '/eventi/assets/modelli/Convenzione_FSL_DiBEST.doc'), "modello servito dal portale");
prova(!str_contains(dati_convenzione(['conv_url_modello' => '../../etc/passwd'])['modello'], 'passwd'), "percorso non valido ignorato");
$EMAIL = [];
prova(email_richiesta_convenzione($conn, 2) && count($EMAIL) === 1, "richiesta della convenzione per email");
if (class_exists('ZipArchive')) {
    $f = genera_convenzione_precompilata($conn, 1);
    $xml = $f ? (string)(new class { function leggi($f) { $z = new ZipArchive(); $z->open($f); $x = $z->getFromName('word/document.xml'); $z->close(); return $x; } })->leggi($f) : '';
    $testo = strip_tags($xml);
    prova($f && !preg_match('/\{\{\w+\}\}/', $xml), "convenzione precompilata senza segnaposto");
    prova(str_contains($testo, 'IIS Uno (codice meccanografico CSIS00001A)') && str_contains($testo, 'Via Roma 1, 87100'), "istituto, codice e indirizzo dall'anagrafe", mb_substr($testo, 0, 0));
    prova(str_contains($testo, 'Numero di studenti: 20') && str_contains($testo, 'Durata: 30 ore') && str_contains($testo, 'Tutor Dipartimento'), "studenti, durata e tutor");
    prova(str_contains($testo, 'Obiettivi del percorso') && !str_contains($testo, 'percorso..'), "descrizione dal progetto, senza doppio punto");
    if ($f) @unlink($f);
    $fa = genera_convenzione_precompilata($conn, 1, 'allegato');
    $xa = $fa ? (string)(new class { function leggi($f) { $z = new ZipArchive(); $z->open($f); $x = $z->getFromName('word/document.xml'); $z->close(); return $x; } })->leggi($fa) : '';
    $ta = strip_tags($xa);
    prova($fa && !preg_match('/\{\{\w+\}\}/', $xa) && str_contains($ta, 'Numero di studenti: 20') && str_contains($ta, 'Tutor Dipartimento') && str_contains($ta, '2026/2027'), "Allegato A precompilato (A.A. 2026/2027)");
    if ($fa) @unlink($fa);
    prova(str_contains(html_istruzioni_convenzione([], true, 'FS-1'), 'Allegato A') && file_exists(RADICE_SITO . '/convenzione_precompilata.php'), "Allegato A nel modulo online (restano validi i link precompilati già inviati)");
} else prova(false, "ZipArchive non disponibile: lancia con -d extension=zip");

sezione("Scheda di valutazione FSL");
$EMAIL = [];
prova(invia_invito_valutazione($conn, 1) && count($EMAIL) === 1, "invito alla scheda");
$tok = (string)$conn->query("SELECT valutazione_token FROM prenotazioni WHERE id = 1")->fetch_assoc()['valutazione_token'];
$p_v = prenotazione_da_valutazione($conn, $tok);
prova($p_v !== null && prenotazione_da_valutazione($conn, str_repeat('0', 40)) === null, "link personale valido / non valido");
$voti = array_fill_keys(array_keys(VALUTAZIONE_FSL_ASPETTI), 4);
prova(salva_valutazione_fsl($conn, $p_v, ['voto' => array_merge($voti, ['tutor' => 9]), 'ripeterebbe' => 'si']) !== null, "voto fuori scala rifiutato");
prova(salva_valutazione_fsl($conn, $p_v, ['voto' => $voti]) !== null, "risposta obbligatoria mancante rifiutata");
prova(salva_valutazione_fsl($conn, $p_v, ['voto' => $voti, 'ripeterebbe' => 'si', 'testo' => ['punti_forza' => 'Ottimo']]) === null, "scheda salvata");
prova(salva_valutazione_fsl($conn, prenotazione_da_valutazione($conn, $tok), ['voto' => $voti, 'ripeterebbe' => 'si']) !== null, "seconda compilazione rifiutata");
prova((float)$conn->query("SELECT media FROM valutazioni_fsl WHERE prenotazione_id = 1")->fetch_assoc()['media'] === 4.0, "media calcolata");

sezione("Scheda di Ateneo modificabile dalla persona");
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, telefono, ufficio, ruolo, gruppo, dettaglio_json, dettaglio_il) VALUES ('mario.rossi', 'Rossi', 'Mario', 'mario.rossi@example.org', '0984 111', 'Cubo 1', 'Personale Tecnico Amministrativo', 'pta', '{\"ricevimento\":\"Lunedi 9-11\",\"siti\":[]}', NOW())");
$p_ate = persona_ateneo($conn, 'mario.rossi');
$sch = scheda_persona($conn, $p_ate);
prova($sch['valori']['telefono'] === '0984 111' && $sch['valori']['ricevimento'] === 'Lunedi 9-11' && !$sch['modificati'], "senza modifiche: dati del portale");
prova(salva_modifiche_persona($conn, 'mario.rossi', ['telefono' => 'abc<script>']) !== null, "telefono non valido rifiutato");
prova(salva_modifiche_persona($conn, 'mario.rossi', ['ufficio' => 'Cubo 4B', 'sito' => 'example.org/mario', 'bio' => '<b>Ciao</b>']) === null, "modifiche salvate");
$sch = scheda_persona($conn, $p_ate);
prova($sch['valori']['ufficio'] === 'Cubo 4B' && $sch['valori']['telefono'] === '0984 111' && $sch['valori']['sito'] === 'https://example.org/mario' && $sch['valori']['bio'] === 'Ciao', "modifiche sopra i dati del portale (sito https, niente HTML)");
prova(in_array('ufficio', $sch['modificati'], true) && !in_array('telefono', $sch['modificati'], true), "campi modificati riconosciuti");
salva_modifiche_persona($conn, 'mario.rossi', []);
prova(!scheda_persona($conn, $p_ate)['modificati'], "ripristino dei dati del portale");

sezione("Macroaree e tipi di area");
prova(tipo_area(['tipo_area' => 'fsl']) === 'fsl' && tipo_area(['tipo_area' => 'boh']) === '' && tipo_area([]) === '', "tipo dell'area (valido / sconosciuto / assente)");
prova(sezione_area(['tipo_area' => 'gruppi']) === 'calendari' && sezione_area(['tipo_area' => 'eventi']) === 'orientamento' && sezione_area([]) === '', "macroarea dal tipo (gruppi in Prenotazioni e risorse)");
$gr = raggruppa_aree_per_sezione([['id' => 1, 'tipo_area' => 'gruppi'], ['id' => 2, 'tipo_area' => 'fsl'], ['id' => 3, 'tipo_area' => ''], ['id' => 4, 'tipo_area' => 'eventi']]);
prova(array_keys($gr) === ['orientamento', 'calendari', ''] && array_column($gr['orientamento'], 'id') === [2, 4], "aree raggruppate nell'ordine delle macroaree, non assegnate in fondo");
prova(str_contains(html_scelta_tipo_area('t', 'fsl'), 'value="fsl" selected') && str_contains(html_scelta_tipo_area('t', 'calendario'), 'value="calendario" selected'), "tendina del tipo (anche Calendari e risorse)");
prova(sezione_area(['tipo_area' => 'calendario']) === 'calendari', "Calendari e risorse nella sua macroarea");

sezione("Moduli");
prova(modulo_di_area(['tipo_area' => '']) === 'orientamento' && modulo_di_area(['tipo_area' => 'fsl']) === 'orientamento' && modulo_di_area(['tipo_area' => 'gruppi']) === 'calendari' && modulo_di_area(['tipo_area' => 'calendario']) === 'calendari', "modulo dell'area (senza tipo: Orientamento)");
prova(array_keys(MODULI_PORTALE) === ['orientamento', 'calendari', 'didattica', 'portale'] && PAGINE_MODULO['fsl.php'] === 'orientamento' && PAGINE_MODULO['utenti.php'] === 'portale', "moduli e pagine dei moduli");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (96, 'MODUL96XXXXXXXXX', 'Mara', 'Moduli', 'mara@unical.it', 4)");
$q("UPDATE pagine_eventi SET tipo_area = 'fsl' WHERE id = 2");
$q("UPDATE pagine_eventi SET tipo_area = 'calendario' WHERE id = 1");
assegna_ambito($conn, 96, 'modulo_orientamento');
$a81 = aree_da_ambiti($conn, 96);
prova(in_array(2, $a81, true) && attivita_da_ambiti($conn, 96, 1) === [10], "modulo Orientamento: le sue aree; in Prenotazioni e risorse solo le attività FSL");
prova(area_nel_modulo_utente($conn, 96, ['tipo_area' => 'fsl']) && !area_nel_modulo_utente($conn, 96, ['tipo_area' => 'calendario']), "area nel modulo dell'utente");
prova(utente_gestisce_attivita($conn, 96, 20), "modulo intero: gestisce le attività delle sue aree");
$q("UPDATE pagine_eventi SET tipo_area = '' WHERE id IN (1, 2)");

sezione("Vista a calendario delle risorse");
prova(periodo_calendario_risorse('settimana', '2026-10-08') === ['2026-10-05', '2026-10-11'] && periodo_calendario_risorse('mese', '2026-02-10') === ['2026-02-01', '2026-02-28'] && periodo_calendario_risorse('giorno', '2026-10-08') === ['2026-10-08', '2026-10-08'], "periodi di giorno, settimana e mese");
$cal_html = html_calendario_risorse($conn, [], ['vista' => 'giorno', 'data' => '2026-10-05', 'url' => fn($c) => '?', 'url_risorsa' => fn($i, $g) => '?']);
prova(str_contains($cal_html, 'Nessuna risorsa') && str_contains($cal_html, 'Non prenotabile'), "senza risorse: legenda e avviso");

sezione("Aule collegate agli eventi");
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, durata_slot, max_slot, attiva) VALUES (901, 1, 'Aula prova', 'aula', 60, 4, 1)");
$q("INSERT INTO turni (id, evento_id, data_turno, orario_inizio, orario_fine, max_posti, risorsa_id) VALUES (901, 10, '" . $giorni(30) . "', '10:00:00', '12:00:00', 30, 901), (902, 10, '" . $giorni(30) . "', '11:00:00', '13:00:00', 30, 901)");
$avv = [];
sincronizza_aula_turno($conn, 901, $avv);
$b901 = $conn->query("SELECT * FROM prenotazioni_risorse WHERE turno_id = 901 AND stato = 'confermata'")->fetch_assoc();
prova($b901 && $b901['inizio'] === $giorni(30) . ' 10:00:00' && $b901['fine'] === $giorni(30) . ' 12:00:00' && !$avv, "il turno occupa l'aula");
sincronizza_aula_turno($conn, 902, $avv);
prova(count($avv) === 1 && str_contains($avv[0], 'già occupata') && !$conn->query("SELECT 1 FROM prenotazioni_risorse WHERE turno_id = 902 AND stato = 'confermata'")->num_rows, "aula già occupata: niente doppia prenotazione, avviso");
$q("UPDATE turni SET orario_inizio = '14:00:00', orario_fine = '15:00:00' WHERE id = 901");
$avv = []; sincronizza_aula_turno($conn, 901, $avv);
prova((int)$conn->query("SELECT COUNT(*) n FROM prenotazioni_risorse WHERE turno_id = 901 AND stato = 'confermata' AND TIME(inizio) = '14:00:00'")->fetch_assoc()['n'] === 1, "cambio di orario: la stessa prenotazione si sposta");
$q("UPDATE turni SET risorsa_id = NULL WHERE id = 901");
sincronizza_aula_turno($conn, 901, $avv);
prova(!$conn->query("SELECT 1 FROM prenotazioni_risorse WHERE turno_id = 901 AND stato = 'confermata'")->num_rows, "aula tolta dal turno: torna libera");

sezione("Scelta guidata della scuola");
prova(array_column(luoghi_scuole($conn, 'regioni'), 'valore') === ['CALABRIA'], "regioni dell'anagrafe");
prova(array_column(luoghi_scuole($conn, 'province', 'Calabria'), 'n', 'valore') === ['COSENZA' => 2], "province della regione con il numero di scuole");
prova(array_column(luoghi_scuole($conn, 'comuni', 'CALABRIA', 'COSENZA'), 'nome') === ['Cosenza', 'Rende'], "comuni della provincia");
prova(luoghi_scuole($conn, 'boh') === [], "livello sconosciuto: niente");
$cs_c = cerca_scuole($conn, '', 200, ['regione' => 'CALABRIA', 'provincia' => 'COSENZA', 'comune' => 'RENDE']);
prova(count($cs_c) === 1 && $cs_c[0]['codice'] === 'CSPS00002B', "scuole del comune senza scrivere nulla");
prova(cerca_scuole($conn, 'liceo', 20, ['comune' => 'COSENZA'])[0]['codice'] === 'CSPS00001A' && cerca_scuole($conn, '', 20, ['regione' => 'CALABRIA']) === [], "ricerca nel comune; solo la regione non basta");
prova(cerca_scuole($conn, 'liceo uno')[0]['istituto'] === 'IIS Uno', "istituto di appartenenza nei risultati");

sezione("Calendari e risorse");
// Area 5 di tipo calendario; risorsa 1: lunedì 9-11 e 14-16, slot da 60', fino a 2 di seguito, ripetibile
$lun = date('Y-m-d', strtotime('monday next week'));
$sett = fn(int $n) => date('Y-m-d', strtotime("+$n week", strtotime($lun)));
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area) VALUES (5, 'Aule', 'aule', 'calendario')");
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, luogo, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, ripetizione) VALUES (1, 5, 'Laboratorio', 'laboratorio', 'Cubo 4B', 60, 2, 0, 60, 'tutti', 0, 1)");
$q("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES (1, 1, '09:00', '11:00'), (1, 1, '14:00', '16:00')");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (81, 'RISOR81XXXXXXXXX', 'Ugo', 'Uno', 'ugo@unical.it', 5), (82, 'RISOR82XXXXXXXXX', 'Eva', 'Due', 'eva@unical.it', 5)");
$ris = risorsa($conn, 1); $u81 = $conn->query("SELECT * FROM utenti WHERE id = 81")->fetch_assoc(); $u82 = $conn->query("SELECT * FROM utenti WHERE id = 82")->fetch_assoc();
$sl = slot_risorsa($conn, $ris, $lun);
prova(count($sl) === 4 && array_unique(array_column($sl, 'stato')) === ['libero'] && $sl[2]['inizio'] === "$lun 14:00:00" && $sl[2]['fascia'] === 1, "slot del giorno nelle due fasce");
prova(slot_risorsa($conn, $ris, date('Y-m-d', strtotime("$lun +1 day"))) === [], "giorno senza orari: nessuno slot");
$e1 = prenota_risorsa($conn, $ris, $u81, "$lun 09:00:00", 2, 'Esercitazione');
prova(!$e1['errore'] && count($e1['codici']) === 1 && $e1['stato'] === 'confermata', "prenotazione di due slot", (string)$e1['errore']);
$p1 = prenotazione_risorsa($conn, $e1['codici'][0] ?? '');
prova($p1 && $p1['fine'] === "$lun 11:00:00" && $p1['email'] === 'ugo@unical.it', "la prenotazione copre 9-11");
prova(slot_risorsa($conn, $ris, $lun)[1]['stato'] === 'occupato', "slot occupato dopo la prenotazione");
prova((bool)prenota_risorsa($conn, $ris, $u82, "$lun 10:00:00", 1)['errore'], "sovrapposizione rifiutata");
prova((bool)prenota_risorsa($conn, $ris, $u82, "$lun 15:00:00", 2)['errore'], "durata oltre la fascia oraria rifiutata");
prova((bool)prenota_risorsa($conn, $ris, $u82, "$lun 14:30:00", 1)['errore'], "orario fuori dagli slot rifiutato");
prova((bool)prenota_risorsa($conn, $ris, $u82, $sett(12) . " 09:00:00", 1)['errore'], "oltre i giorni prenotabili rifiutato");
$e5 = prenota_risorsa($conn, $ris, $u82, "$lun 14:00:00", 5);
prova(!$e5['errore'] && prenotazione_risorsa($conn, $e5['codici'][0])['fine'] === "$lun 16:00:00", "numero di slot limitato al massimo consentito");
$q("INSERT INTO risorse_chiusure (pagina_id, risorsa_id, dal, al, motivo) VALUES (5, NULL, '" . $sett(2) . "', '" . $sett(2) . "', 'Ponte')");
prova(chiusura_risorsa($conn, $ris, $sett(2)) === 'Ponte' && slot_risorsa($conn, $ris, $sett(2))[0]['stato'] === 'chiuso', "chiusura di tutta l'area");
$er = prenota_risorsa($conn, $ris, $u82, $sett(1) . " 09:00:00", 1, 'Corso', $sett(3));
prova(count($er['codici']) === 2 && array_keys($er['saltate']) === [$sett(2)] && $er['serie'] !== null, "ripetizione settimanale: la settimana chiusa viene saltata", json_encode($er));
$q("UPDATE risorse SET ripetizione = 0 WHERE id = 1"); $ris = risorsa($conn, 1);
prova(count(prenota_risorsa($conn, $ris, $u82, $sett(4) . " 09:00:00", 1, '', $sett(6))['codici']) === 1, "ripetizione ignorata se la risorsa non la consente");
$q("UPDATE risorse SET accesso = 'docenti' WHERE id = 1"); $ris = risorsa($conn, 1);
prova(!puo_prenotare_risorsa($conn, $ris, $u82) && puo_prenotare_risorsa($conn, $ris, ['id' => 1, 'ruolo_id' => 1]) && !puo_prenotare_risorsa($conn, $ris, null), "chi può prenotare (solo docenti; amministratori sempre)");
$q("UPDATE risorse SET accesso = 'tutti', approvazione = 1 WHERE id = 1"); $ris = risorsa($conn, 1);
$EMAIL = [];
$ea = prenota_risorsa($conn, $ris, $u82, $sett(5) . " 10:00:00", 1, 'Tesi');
$pa = prenotazione_risorsa($conn, $ea['codici'][0] ?? '');
prova($ea['stato'] === 'da_approvare' && $pa['stato'] === 'da_approvare' && slot_risorsa($conn, $ris, $sett(5))[1]['stato'] === 'occupato', "richiesta da approvare: lo slot resta riservato");
prova(cambia_stato_prenotazione_risorsa($conn, (int)$pa['id'], 'confermata') && !cambia_stato_prenotazione_risorsa($conn, (int)$pa['id'], 'confermata'), "approvazione (una sola volta)");
prova(count($EMAIL) === 1 && $EMAIL[0]['a'] === 'eva@unical.it' && str_contains($EMAIL[0]['oggetto'], 'approvata'), "email di approvazione a chi ha prenotato", json_encode(array_column($EMAIL, 'oggetto')));
prova(cambia_stato_prenotazione_risorsa($conn, (int)$pa['id'], 'annullata', false) && slot_risorsa($conn, $ris, $sett(5))[1]['stato'] === 'libero', "annullamento: lo slot torna libero");
prova(str_contains(ics_prenotazione_risorsa($p1), 'DTSTART:' . gmdate('Ymd\THis\Z', strtotime("$lun 09:00:00"))) && str_contains(ics_prenotazione_risorsa($p1), 'LOCATION:Cubo 4B'), "file .ics della prenotazione");
prova(quando_risorsa($p1) === 'Lunedì ' . date('d/m/Y', strtotime($lun)) . ', 09:00–11:00', "testo di data e orario");

sezione("Anagrafe degli insegnamenti e gruppi");
$aa_p = anno_accademico_corrente();
$q("INSERT INTO insegnamenti (id, codice, nome, cds_cod, cds_nome, anno_corso, anno_accademico, coorte, semestre, docente, partizione, dipartimento_cod) VALUES
    (900001, 'A1', 'Anatomia umana', '0827', 'Scienze motorie', 1, $aa_p, $aa_p, 'Primo Semestre', 'Rossi Mario', '', '002014'),
    (900002, 'B2', 'Fisiologia', '0827', 'Scienze motorie', 2, $aa_p, " . ($aa_p - 1) . ", 'Secondo Semestre', 'Bianchi Anna', 'A-L', '002014'),
    (900003, 'C3', 'Botanica', '0724', 'Biologia', 1, " . ($aa_p + 1) . ", " . ($aa_p + 1) . ", 'Primo Semestre', '', '', '002014')");
$ipc = insegnamenti_per_corso($conn);
prova(array_keys($ipc) === ['Scienze motorie'] && count($ipc['Scienze motorie']) === 2, "insegnamenti dell'anno accademico in corso, per corso");
prova(etichetta_insegnamento(insegnamento($conn, 900002)) === 'Fisiologia (A-L) · 2° anno · Secondo Semestre · Bianchi Anna', "etichetta dell'insegnamento");
$_POST = ['insegnamento_id' => '900001']; salva_insegnamento_evento($conn, 10);
prova((int)$conn->query("SELECT insegnamento_id FROM progetti_dettagli WHERE evento_id = 10")->fetch_assoc()['insegnamento_id'] === 900001, "evento collegato all'insegnamento");
$_POST = ['insegnamento_id' => '123']; salva_insegnamento_evento($conn, 10);
prova($conn->query("SELECT insegnamento_id FROM progetti_dettagli WHERE evento_id = 10")->fetch_assoc()['insegnamento_id'] === null, "insegnamento inesistente: nessun collegamento");
$_POST = [];
prova(prenotazione_di_classe(false, ['convenzione' => 1]), "il collegamento non tocca gli interruttori dell'evento (FSL ancora attivo)");

sezione("Altre regole");
prova(prenotazione_di_classe(false, ['convenzione' => 1]) && prenotazione_di_classe(false, ['dedicata_scuole' => 1]) && !prenotazione_di_classe(false, []), "eventi: classe con FSL o dedicato alle scuole");
prova(prenotazione_di_classe(true, ['per_scuole' => 1]) && !prenotazione_di_classe(true, ['per_scuole' => 0]), "progetti: classe se dedicati alle scuole");
prova(annullamento_scaduto(['annullabile_fino' => date('Y-m-d H:i:s', time() - 60)]) && !annullamento_scaduto(['annullabile_fino' => null]), "termine per annullare");
prova(colore_valido('#abc') === '#AABBCC' && colore_valido('red') === '#B30000', "colori validati");

sezione("Abilitazioni per perimetro");
// Area 2: progetto FSL 20 + evento 21 non FSL; area 1: evento FSL 10
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo) VALUES (21, 2, 'Evento FSL no', 'evento')");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (71, 'PERIM71XXXXXXXXX', 'Pia', 'Progetti', 'pia@unical.it', 4), (72, 'PERIM72XXXXXXXXX', 'Fabio', 'Fsl', 'fabio@unical.it', 4)");
assegna_ambito($conn, 71, 'progetti', 2);
assegna_ambito($conn, 72, 'fsl');
prova(attivita_da_ambiti($conn, 71, 2) === [20], "tutti i progetti: solo i progetti dell'area");
prova(attivita_da_ambiti($conn, 71, 1) === [], "tutti i progetti: niente nelle altre aree");
$a72 = aree_da_ambiti($conn, 72); sort($a72);
prova($a72 === [1, 2] && attivita_da_ambiti($conn, 72, 2) === [20], "FSL: attività FSL di tutte le aree, non le altre");
prova(utente_gestisce_attivita($conn, 71, 20) && !utente_gestisce_attivita($conn, 71, 21) && !utente_gestisce_attivita($conn, 71, 10), "gestione della singola attività");
prova(in_array(71, ids_ambito_attivita($conn, 20, false), true) && !in_array(72, ids_ambito_attivita($conn, 20, false), true), "notifiche: perimetri dell'area sì, FSL di tutte le aree no");
$q("INSERT INTO abilitazioni_attesa (email, nominativo, pagina_id, permessi, eventi_ids, ambito) VALUES ('nuovo@unical.it', 'Nuovo', 2, 'full', '', 'eventi'), ('nuovo@unical.it', 'Nuovo', 0, 'full', '', 'fsl_scuole')");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (73, 'PERIM73XXXXXXXXX', 'Nuovo', 'Arrivato', 'nuovo@unical.it', 4)");
collega_utente_anagrafe($conn, 73, 'nuovo@unical.it');
prova(ha_ambito($conn, 73, 'eventi', 2) && ha_ambito($conn, 73, 'fsl_scuole') && (int)$conn->query("SELECT COUNT(*) n FROM abilitazioni_attesa")->fetch_assoc()['n'] === 0, "abilitazioni in attesa attivate al primo accesso");
revoca_ambito($conn, 71, null, 2);
prova(!ha_ambito($conn, 71, 'progetti', 2), "revoca");

sezione("Convenzione chiesta in un'attività non FSL");
// Evento 21 (non FSL): convenzione chiesta da Iscrizioni ('no'), poi la convenzione nel registro copre il giorno del turno
$q("INSERT INTO turni (id, evento_id, data_turno, max_posti) VALUES (210, 21, '" . $giorni(20) . "', 30)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, scuola_codice, convenzione) VALUES (210, 210, 'OP-NONFSL1', 'confermata', 'Ada', 'Docente', 'ada@scuola.it', 'CSPS020009', 'no')");
$q("INSERT INTO convenzioni_scuole (scuola_codice, data_stipula, scadenza) VALUES ('CSPS020009', '" . $giorni(-30) . "', '" . $giorni(300) . "')");
convenzione_valida($conn, 'CSPS020009', true);
verifica_convenzioni_fsl($conn);
prova($conn->query("SELECT convenzione FROM prenotazioni WHERE id = 210")->fetch_assoc()['convenzione'] === 'ricevuta', "la verifica la segna ricevuta anche fuori dalle attività FSL");

sezione("Didattica: moduli online e pratiche");
$cm = campi_modulo(json_encode([['etichetta' => 'Corso', 'tipo' => 'select', 'opzioni' => 'Biologia; Geologia, Ecologia', 'obbligatorio' => true], ['etichetta' => 'Note', 'tipo' => 'textarea'], ['etichetta' => '', 'tipo' => 'text'], ['etichetta' => 'Strano', 'tipo' => 'script']]));
prova(count($cm) === 3 && $cm[0]['nome'] === 'c1' && $cm[0]['opzioni'] === ['Biologia', 'Geologia', 'Ecologia'] && $cm[2]['tipo'] === 'text', "campi del modulo normalizzati (opzioni con virgola o punto e virgola)", json_encode($cm));
$_POST = ['campo_c1' => 'Fisica', 'campo_c2' => ' Testo '];
[$ris, $err] = leggi_risposte_modulo($cm);
prova(count($err) === 1 && str_contains($err[0], 'Corso') && $ris[1]['valore'] === 'Testo', "opzione non prevista scartata, campo obbligatorio segnalato", json_encode($err));
$_POST = ['campo_c1' => 'Geologia'];
[$ris, $err] = leggi_risposte_modulo($cm);
$_POST = [];
prova(!$err && $ris[0]['valore'] === 'Geologia', "risposte valide");
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, destinatari, email_ufficio, attivo) VALUES (500, 'Riconoscimento crediti', 'Carriera', 'online', '" . $conn->real_escape_string(json_encode([['etichetta' => 'Corso', 'tipo' => 'select', 'opzioni' => 'Biologia, Geologia', 'obbligatorio' => true]])) . "', 'tutti', 'segreteria@unical.it', 1)");
$u73 = $conn->query("SELECT * FROM utenti WHERE id = 73")->fetch_assoc();
prova(utente_destinatario_modulo($conn, modulo_didattica($conn, 500), $u73) && !utente_destinatario_modulo($conn, modulo_didattica($conn, 500), null), "modulo per tutti: serve l'accesso");
$EMAIL = [];
$idp = crea_pratica($conn, modulo_didattica($conn, 500), $u73, $ris);
$pp = pratica($conn, $idp);
prova($idp > 0 && $pp['stato'] === 'inviata' && str_starts_with($pp['codice'], 'PR-') && $pp['modulo_titolo'] === 'Riconoscimento crediti', "pratica creata");
$dest = array_column($EMAIL, 'a');
prova(in_array('nuovo@unical.it', $dest, true) && in_array('segreteria@unical.it', $dest, true), "email di conferma allo studente e all'ufficio", json_encode($dest));
$EMAIL = [];
cambia_stato_pratica($conn, $idp, 'integrazione', 'Manca il piano di studi', 10);
prova(pratica($conn, $idp)['stato'] === 'integrazione' && ($EMAIL[0]['a'] ?? '') === 'nuovo@unical.it' && str_contains($EMAIL[0]['corpo'] ?? '', 'Manca il piano di studi'), "integrazione richiesta: email allo studente con la nota");
prova(messaggio_pratica($conn, $idp, 'studente', 73, '') !== null, "messaggio vuoto rifiutato");
prova(messaggio_pratica($conn, $idp, 'studente', 73, 'Ecco il piano') === null && pratica($conn, $idp)['stato'] === 'in_lavorazione', "la risposta dello studente riporta la pratica in lavorazione");
prova((int)$conn->query("SELECT COUNT(*) n FROM pratiche_eventi WHERE pratica_id = $idp")->fetch_assoc()['n'] === 4, "storico completo della pratica");
prova(!cambia_stato_pratica($conn, $idp, 'inventato', '', 10), "stato non previsto rifiutato");

sezione("Ufficio didattico, campi guidati, verbale ed Excel");
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, gruppo, attivo) VALUES ('op.prova', 'Operatrice', 'Olga', 'olga.op@unical.it', 'pta', 1)");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (97, 'OPERAT97XXXXXXXX', 'Olga', 'Operatrice', 'olga.op@unical.it', 5)");
$u97 = $conn->query("SELECT * FROM utenti WHERE id = 97")->fetch_assoc();
prova(!utente_gestisce_didattica($conn, $u97), "prima di essere nell'ufficio non gestisce la Didattica");
prova(aggiungi_operatore_ufficio($conn, 'op.prova', 'Responsabile', ['pratiche', 'ricevimento', 'inventato']) === null, "operatore aggiunto dall'anagrafe");
prova(utente_gestisce_didattica($conn, $u97) && utente_operatore_ufficio($conn, $u97, 'ricevimento') && !utente_operatore_ufficio($conn, $u97, 'sedute'), "operatore: gestisce la Didattica, compiti rispettati");
prova(aggiungi_operatore_ufficio($conn, 'nessuno', '', []) !== null, "persona inesistente rifiutata");
prova(email_ufficio_didattica($conn, '') === ['olga.op@unical.it'] && email_ufficio_didattica($conn, 'uff@unical.it') === ['uff@unical.it'], "avvisi: email del modulo, altrimenti gli operatori");
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area) VALUES (950, 'Sportelli', 'sportelli-prova', 'calendario')");
$rid_u = crea_sportello_ufficio($conn, 950, '', 'Cubo 4B');
prova($rid_u > 0 && array_column(sportelli_utente($conn, $u97), 'id') == [$rid_u] && risorsa($conn, $rid_u)['email_notifiche'] === 'olga.op@unical.it', "sportello dell'ufficio gestito dall'operatore");
$ct = campi_modulo(json_encode([['etichetta' => 'Esami sostenuti', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento, CFU, Voto', 'obbligatorio' => true],
                                 ['etichetta' => 'Anno accademico', 'tipo' => 'anno_accademico'], ['etichetta' => 'Convalide', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento convalidato, CFU', 'ufficio' => true],
                                 ['etichetta' => 'Dal', 'tipo' => 'date']]));
prova(count(campi_studente($ct)) === 3 && count(campi_ufficio($ct)) === 1 && campi_ufficio($ct)[0]['etichetta'] === 'Convalide', "campi dello studente e dell'ufficio separati");
$_POST = ['campo_c1' => [['Zoologia', '', 'Botanica'], ['9', '', '6'], ['28/30', '', '30/30']], 'campo_c2' => '2025/2026', 'campo_c4' => '2026-03-01'];
[$rt, $et] = leggi_risposte_modulo(campi_studente($ct), $conn);
$_POST = [];
prova(!$et && $rt[0]['righe'] === [['Zoologia', '9', '28/30'], ['Botanica', '6', '30/30']] && $rt[0]['colonne'] === ['Insegnamento', 'CFU', 'Voto'] && $rt[1]['valore'] === '2025/2026', "tabella a righe (righe vuote scartate) e anno accademico", json_encode($rt));
$_POST = ['campo_c2' => '2025-26'];
[, $et2] = leggi_risposte_modulo(campi_studente($ct), $conn);
$_POST = [];
prova(in_array('Esami sostenuti: campo obbligatorio', $et2, true), "tabella obbligatoria vuota segnalata");
prova(str_contains(html_campo_pratica($ct[0], [['A', '1', '2']]), 'name="campo_c1[0][]" value="A"') && str_contains(html_campo_pratica($ct[0]), 'list="dl_insegnamento"'), "tabella nel modulo con l'elenco degli insegnamenti");
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, verbale_json, attivo) VALUES (501, 'Passaggio di corso', 'Carriera', 'online', '" . $conn->real_escape_string(json_encode([['etichetta' => 'Esami sostenuti', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento, CFU, Voto'], ['etichetta' => 'Anno accademico', 'tipo' => 'anno_accademico'], ['etichetta' => 'Dal', 'tipo' => 'date']])) . "',
    '" . $conn->real_escape_string(json_encode(['testo' => "Lo studente {STUDENTE}, matricola {MATRICOLA}, per l'a.a. {anno accademico} dal {Dal} chiede il passaggio.", 'delibera' => 'Il Consiglio approva.'])) . "', 1),
    (502, 'Lavoro finale', 'Lauree', 'online', '" . $conn->real_escape_string(json_encode([['etichetta' => 'Corso di studio', 'tipo' => 'text'], ['etichetta' => 'Relatore', 'tipo' => 'docente']])) . "',
    '" . $conn->real_escape_string(json_encode(['sezione' => 'Domande lavoro finale', 'stile' => 'elenco', 'colonne' => 'COGNOME, NOME, MATRICOLA, RELATORE', 'raggruppa' => 'Corso di studio'])) . "', 1)");
$u73['matricola_studente'] = '254671';
$pa = crea_pratica($conn, modulo_didattica($conn, 501), $u73, $rt);
$_POST = ['campo_c1' => 'Scienze naturali', 'campo_c2' => 'Sperone Emilio'];
[$rt2] = leggi_risposte_modulo(campi_modulo(modulo_didattica($conn, 502)['campi_json']), $conn);
$_POST = [];
$pb = crea_pratica($conn, modulo_didattica($conn, 502), $u73, $rt2);
$conn->query("UPDATE pratiche SET ufficio_json = '" . $conn->real_escape_string(json_encode([['etichetta' => 'Convalide', 'tipo' => 'tabella', 'colonne' => ['Insegnamento convalidato', 'CFU'], 'righe' => [['Zoologia generale', '9']], 'valore' => 'Zoologia generale | 9']])) . "' WHERE id = $pa");
$ppa = pratiche_per_esportazione($conn, [$pa])[0];
prova(testo_segnaposti_pratica(verbale_modulo($ppa)['testo'], $ppa) === "Lo studente ARRIVATO NUOVO, matricola 254671, per l'a.a. 2025/2026 dal 01/03/2026 chiede il passaggio.", "segnaposti del verbale (maiuscole, date, campi)", testo_segnaposti_pratica(verbale_modulo($ppa)['testo'], $ppa));
$q("INSERT INTO didattica_sedute (id, organo, anno_accademico, data, ora_inizio, ora_fine, luogo, odg, presenze, segretario, coordinatore) VALUES (60, 'Consiglio del Corso di Laurea in Scienze Naturali', '2026/2027', '2026-10-15', '15:30', '17:00', 'aula L3', 'Comunicazioni\nPratiche studenti\nVarie', 'Professori\nProf. Rossi | PRESENTE', 'la Dott.ssa Bianchi', 'Prof. Rossi')");
$conn->query("UPDATE pratiche SET seduta_id = 60 WHERE id IN ($pa, $pb)");
$tutte = pratiche_per_esportazione($conn, [$pa, $pb]);
$doc = genera_verbale_pratiche($conn, seduta_didattica($conn, 60), $tutte);
$xml_doc = ''; $logo_ok = false;
if ($doc && ($z = new ZipArchive())->open($doc) === true) { $xml_doc = (string)$z->getFromName('word/document.xml'); $logo_ok = $z->locateName('word/media/logo.jpg') !== false; $z->close(); }
$testo_doc = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml_doc)));
prova($xml_doc !== '' && (new DOMDocument())->loadXML($xml_doc) && $logo_ok, "verbale Word valido con il logo");
prova(str_contains($testo_doc, 'Il giorno 15 del mese di ottobre 2026 alle ore 15:30') && str_contains($testo_doc, '2. Pratiche studenti') && str_contains($testo_doc, 'Zoologia generale')
      && str_contains($testo_doc, 'Domande lavoro finale') && str_contains($testo_doc, 'Scienze naturali:') && str_contains($testo_doc, 'Il Consiglio approva.') && str_contains($testo_doc, 'si scioglie alle ore 17:00'), "verbale: intestazione, pratiche nel punto giusto, tabelle, elenco per corso, chiusura");
prova(strpos($testo_doc, 'Passaggio') < strpos($testo_doc, 'Domande lavoro finale'), "sezioni nell'ordine delle categorie");
$xls = genera_excel_pratiche($conn, $tutte);
$fogli = []; $s1 = '';
if ($xls && ($z = new ZipArchive())->open($xls) === true) { preg_match_all('/sheet name="([^"]+)"/', (string)$z->getFromName('xl/workbook.xml'), $mm); $fogli = $mm[1]; $s1 = (string)$z->getFromName('xl/worksheets/sheet1.xml'); $z->close(); }
prova(count($fogli) === 3 && $fogli[0] === 'Tutte le pratiche' && (new DOMDocument())->loadXML($s1) && str_contains($s1, 'Zoologia | 9 | 28/30') && str_contains($s1, 'Sperone Emilio') && str_contains($s1, '15/10/2026'), "Excel: foglio con tutte e uno per modulo, tabelle e seduta", json_encode($fogli));
@unlink($doc); @unlink($xls);

sezione("Iter delle pratiche");
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, gruppo, attivo) VALUES ('man.prova', 'Manager', 'Marta', 'marta.man@unical.it', 'pta', 1), ('cdl.prova', 'Referente', 'Carlo', 'carlo.cdl@unical.it', 'docenti', 1), ('car.prova', 'Carriere', 'Carla', 'carla.car@unical.it', 'pta', 1), ('int.prova', 'Tutor', 'Irene', 'irene.int@unical.it', 'docenti', 1)");
$uff = []; foreach (uffici_didattica($conn, true) as $id_u => $u) $uff[$u['chiave']] = $id_u;
prova(count($uff) === 5 && (int)uffici_didattica($conn)[$uff['manager']]['smista'] === 1 && (int)uffici_didattica($conn)[$uff['referente_cdl']]['segue_corsi'] === 1, "uffici di partenza dell'Ufficio didattico");
aggiungi_operatore_ufficio($conn, 'man.prova', '', ['pratiche'], $uff['manager']);
aggiungi_operatore_ufficio($conn, 'cdl.prova', '', ['pratiche'], $uff['referente_cdl'], ['Corso di laurea in Scienze naturali']);
aggiungi_operatore_ufficio($conn, 'car.prova', '', ['pratiche'], $uff['carriere']);
aggiungi_operatore_ufficio($conn, 'int.prova', '', ['pratiche'], $uff['internazionalizzazione']);
$op = []; foreach (operatori_ufficio($conn) as $o) if ($o['ufficio_id']) $op[uffici_didattica($conn)[(int)$o['ufficio_id']]['chiave']] = (int)$o['id'];
prova(isset($op['manager'], $op['referente_cdl'], $op['carriere'], $op['internazionalizzazione']), "personale assegnato agli uffici");
$q("INSERT INTO didattica_uffici (nome, smista, segue_corsi, ordine) VALUES ('Tirocini', 0, 0, 9)");
$id_tir = (int)$conn->insert_id;
prova(ufficio_didattica_id($conn, $id_tir) === null && ufficio_didattica_id($conn, 'carriere') === $uff['carriere'], "ufficio nuovo letto dopo l'aggiornamento; chiavi dei modelli riconosciute");
uffici_didattica($conn, true);
prova(ufficio_didattica_id($conn, $id_tir) === $id_tir, "ufficio creato dal pannello disponibile per l'iter");
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, iter_json, attivo) VALUES (503, 'Attività all\'estero', 'Mobilità', 'online', '" . $conn->real_escape_string(json_encode([['etichetta' => 'Corso di studio', 'tipo' => 'corso_studio']])) . "', '[\"internazionalizzazione\",\"referente_cdl\",\"carriere\"]', 1)");
$m503 = modulo_didattica($conn, 503);
prova(passi_pratica($m503) === [0 => 'Ricevuta e da smistare', 1 => 'Internazionalizzazione', 2 => 'Referenti dei corsi di studio', 3 => 'Carriere studenti'], "passi dell'iter dal modulo");
prova(iter_modulo(['iter_json' => '["manager","inventato"]']) === [0] && iter_modulo(['iter_json' => json_encode([$id_tir, 'carriere'])]) === [$id_tir, $uff['carriere']], "iter: uffici per id o chiave; chi smista e uffici inesistenti esclusi");
$EMAIL = [];
$pi = crea_pratica($conn, $m503, $u73, [['etichetta' => 'Corso di studio', 'tipo' => 'corso_studio', 'valore' => 'Corso di laurea in Scienze naturali', 'file' => null, 'nome_file' => null]]);
prova(in_array('marta.man@unical.it', array_column($EMAIL, 'a'), true) && !in_array('carlo.cdl@unical.it', array_column($EMAIL, 'a'), true), "nuova pratica: avviso al manager che smista");
$pp = pratica($conn, $pi);
prova((int)operatori_suggeriti($conn, $pp, $m503, 1)[0]['id'] === $op['internazionalizzazione'] && (int)operatori_suggeriti($conn, $pp, $m503, 2)[0]['id'] === $op['referente_cdl'], "operatore proposto per il passo (e il referente del corso della pratica)");
$EMAIL = [];
prova(assegna_pratica($conn, $pi, $op['internazionalizzazione'], 1, 'Controlla il Learning Agreement', 50, 'Manager Marta') === null, "smistamento al tutor dell'internazionalizzazione");
$pp = pratica($conn, $pi);
prova((int)$pp['assegnata_a'] === $op['internazionalizzazione'] && (int)$pp['passo'] === 1 && $pp['stato'] === 'in_lavorazione' && ($EMAIL[0]['a'] ?? '') === 'irene.int@unical.it', "pratica in carico, in lavorazione, email all'operatore");
$ev_int = $conn->query("SELECT tipo, interno FROM pratiche_eventi WHERE pratica_id = $pi ORDER BY id")->fetch_all(MYSQLI_ASSOC);
prova(in_array(['tipo' => 'passaggio', 'interno' => '0'], $ev_int) && in_array(['tipo' => 'messaggio', 'interno' => '1'], $ev_int), "passaggio visibile allo studente, nota del manager interna");
$EMAIL = [];
messaggio_pratica($conn, $pi, 'ufficio', 50, 'Parere favorevole', null, true, 'messaggio', 'Tutor Irene');
prova(!in_array('nuovo@unical.it', array_column($EMAIL, 'a'), true), "nota interna: nessuna email allo studente");
messaggio_pratica($conn, $pi, 'ufficio', 50, 'Verbale del CdL', null, false, 'attivita', 'Referente Carlo');
prova((int)$conn->query("SELECT COUNT(*) n FROM pratiche_eventi WHERE pratica_id = $pi AND tipo = 'attivita' AND interno = 0")->fetch_assoc()['n'] === 1, "attività visibile allo studente");
assegna_pratica($conn, $pi, $op['carriere'], 9, '', 50);
prova((int)pratica($conn, $pi)['passo'] === 3, "il passo non va oltre l'ultimo");
cambia_stato_pratica($conn, $pi, 'integrazione', '', 50, 'Carriere Carla', ['tipo' => 'autodichiarazione', 'testo' => "di aver sostenuto l'esame di Zoologia"]);
prova((json_decode((string)pratica($conn, $pi)['richiesta_json'], true)['tipo'] ?? '') === 'autodichiarazione', "richiesta di autodichiarazione registrata");
prova(messaggio_pratica($conn, $pi, 'studente', 73, 'ecco', null) === null && pratica($conn, $pi)['stato'] === 'integrazione', "un messaggio non chiude la richiesta di autodichiarazione");
prova(autodichiarazione_pratica($conn, $pi, 73, '', false) !== null, "autodichiarazione senza conferma rifiutata");
prova(autodichiarazione_pratica($conn, $pi, 73, 'Voto 28/30', true) === null && pratica($conn, $pi)['stato'] === 'in_lavorazione' && pratica($conn, $pi)['richiesta_json'] === null, "autodichiarazione resa: la pratica torna in lavorazione");
$ad = $conn->query("SELECT testo FROM pratiche_eventi WHERE pratica_id = $pi AND tipo = 'autodich'")->fetch_assoc()['testo'] ?? '';
prova(str_contains($ad, "di aver sostenuto l'esame di Zoologia") && str_contains($ad, 'D.P.R. 445/2000') && str_contains($ad, 'Voto 28/30'), "testo dell'autodichiarazione nello storico");
cambia_stato_pratica($conn, $pi, 'integrazione', '', 50, '', ['tipo' => 'documenti', 'testo' => 'Allega il transcript']);
messaggio_pratica($conn, $pi, 'studente', 73, 'Allegato', null);
prova(pratica($conn, $pi)['stato'] === 'in_lavorazione', "integrazione di documenti: la risposta dello studente la chiude");
prova(str_contains(html_iter_pratica($conn, pratica($conn, $pi), $m503), 'aria-current="step"') && str_contains(html_iter_pratica($conn, pratica($conn, $pi), $m503), 'Carriere Carla'), "iter visibile con chi ha in carico la pratica");

sezione("Convenzione FSL compilata online");
$scuola_cv = ['ISTITUTO' => 'Liceo Prova (codice meccanografico CSPS020009)', 'ISTITUTO_FIRMA' => 'Liceo Prova', 'COMUNE' => 'Cosenza (CS)', 'INDIRIZZO' => 'Via Roma 1',
              'CF_ISTITUTO' => '80004560787', 'DIRIGENTE' => 'Dott.ssa Maria Rossi', 'DIRIGENTE_FIRMA' => 'Dott.ssa Maria Rossi', 'DIR_LUOGO_NASCITA' => 'Cosenza', 'DIR_DATA_NASCITA' => '01/01/1970', 'DIR_CF' => 'RSSMRA70A41D086X'];
$att_cv = [['TITOLO' => 'Laboratorio A', 'DESCRIZIONE' => 'Descrizione A', 'STUDENTI' => '20', 'PERIODO' => '12/11/2026', 'DURATA' => '5 ore', 'TUTOR_DIBEST' => 'Prof. X', 'TUTOR_SCUOLA' => 'Luca Bianchi'],
           ['TITOLO' => 'Laboratorio B', 'DESCRIZIONE' => '', 'STUDENTI' => '', 'PERIODO' => '13/11/2026', 'DURATA' => '3 ore', 'TUTOR_DIBEST' => 'Prof. Y', 'TUTOR_SCUOLA' => '']];
$png = tempnam(sys_get_temp_dir(), 'lg') . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEUlEQVR4nGP4z8DwHwQYGBgAJ+UE/O7Q3vYAAAAASUVORK5CYII='));
$fd = genera_docx_convenzione('convenzione', $scuola_cv, $att_cv, $png);
$xd = ''; $hd = ''; $media = false;
if ($fd && ($z = new ZipArchive())->open($fd) === true) { $xd = (string)$z->getFromName('word/document.xml'); $hd = (string)$z->getFromName('word/header1.xml'); $media = $z->locateName('word/media/logo_scuola.png') !== false; $z->close(); }
$td = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xd)));
prova($xd !== '' && (new DOMDocument())->loadXML($xd) && (new DOMDocument())->loadXML($hd), "convenzione: documento e intestazione validi");
prova(str_contains($td, 'codice fiscale 80004560787') && str_contains($td, 'Dott.ssa Maria Rossi, nata/o a Cosenza, il 01/01/1970, codice fiscale RSSMRA70A41D086X') && !str_contains($td, '{{'), "dati della scuola e del Dirigente, nessun segnaposto rimasto");
prova(substr_count($td, 'Titolo corso:') === 2 && str_contains($td, 'Laboratorio A') && str_contains($td, 'Laboratorio B'), "Allegato A ripetuto per ogni attività");
prova(str_contains($td, 'Numero di studenti: ……………'), "campo vuoto: torna il testo originale da completare");
prova($media && str_contains($hd, 'rIdLogoScuola') && !str_contains($hd, 'Logo/intestazione Istituzione Scolastica'), "logo della scuola nell'intestazione");
@unlink($fd); @unlink($png);
$fa = genera_docx_convenzione('allegato', $scuola_cv, $att_cv);
$xa = ''; if ($fa && ($z = new ZipArchive())->open($fa) === true) { $xa = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", (string)$z->getFromName('word/document.xml')))); $z->close(); } @unlink($fa);
prova(substr_count($xa, 'Titolo corso:') === 2 && str_contains($xa, 'Laboratorio B'), "Allegato A da solo con le due attività");
$istr = html_istruzioni_convenzione([], true, 'FS-PROVA01');
prova(str_contains($istr, 'convenzione_online.php?code=FS-PROVA01') && str_contains($istr, 'firma digitalmente'), "le istruzioni portano al modulo guidato");
prova(str_contains(html_istruzioni_convenzione([]), 'Al termine della prenotazione'), "nel modulo di prenotazione si annuncia la compilazione online");

sezione("Scadenze, promemoria, protocollo e statistiche");
$ieri = date('Y-m-d', strtotime('-1 day')); $domani = date('Y-m-d', strtotime('+1 day'));
prova(periodo_modulo([])[0] && periodo_modulo(['aperto_dal' => $ieri, 'aperto_al' => $domani])[0] && !periodo_modulo(['aperto_al' => $ieri])[0] && !periodo_modulo(['aperto_dal' => $domani])[0], "modulo compilabile solo nel periodo");
prova(str_starts_with(periodo_modulo(['aperto_dal' => $domani])[1], 'Si compila dal '), "testo del periodo per chi non può ancora compilare");
// Pratica ferma: assegnata, senza movimenti da 10 giorni (promemoria del modulo a 7)
$conn->query("UPDATE didattica_moduli SET giorni_promemoria = 7 WHERE id = 503");
$conn->query("UPDATE pratiche SET stato = 'in_lavorazione', aggiornata_il = NOW() - INTERVAL 10 DAY, promemoria_il = NULL WHERE id = $pi");
$EMAIL = [];
prova(promemoria_pratiche_ferme($conn) >= 1 && in_array('carla.car@unical.it', array_column($EMAIL, 'a'), true), "promemoria a chi ha in carico la pratica ferma");
$EMAIL = [];
promemoria_pratiche_ferme($conn);
prova(!in_array('carla.car@unical.it', array_column($EMAIL, 'a'), true), "un solo promemoria per periodo di attesa");
$conn->query("UPDATE pratiche SET protocollo = '1234/2026', protocollo_data = '2026-10-01' WHERE id = $pi");
$ppi = pratiche_per_esportazione($conn, [$pi])[0];
prova(testo_segnaposti_pratica('Prot. {PROTOCOLLO}', $ppi) === 'Prot. 1234/2026 del 01/10/2026', "protocollo nel testo del verbale");
$xls_p = genera_excel_pratiche($conn, [$ppi]); $s_p = '';
if ($xls_p && ($z = new ZipArchive())->open($xls_p) === true) { $s_p = (string)$z->getFromName('xl/worksheets/sheet1.xml'); $z->close(); } @unlink($xls_p);
prova(str_contains($s_p, '>Protocollo<') && str_contains($s_p, '1234/2026 del 01/10/2026'), "protocollo nell'Excel");
$stp = statistiche_pratiche($conn, date('Y-m-d', strtotime('-1 year')), date('Y-m-d'));
prova($stp['totale'] >= 1 && isset($stp['per_modulo']["Attività all'estero"]) && isset($stp['per_corso']['Corso di laurea in Scienze naturali']), "statistiche per modulo e per corso");
prova(isset($stp['tempi_passi']['Smistamento'], $stp['tempi_passi']['Internazionalizzazione']), "tempi medi per passo dell'iter", json_encode(array_keys($stp['tempi_passi'])));
// Statistiche delle risorse: area 950 con lo sportello dell'ufficio, orari lun-ven 9-13, una prenotazione confermata e una annullata
$q("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES ($rid_u, 1, '09:00:00', '13:00:00'), ($rid_u, 2, '09:00:00', '13:00:00')");
$lun_s = date('Y-m-d', strtotime('monday this week'));
$q("INSERT INTO prenotazioni_risorse (risorsa_id, inizio, fine, stato, codice) VALUES ($rid_u, '$lun_s 09:00:00', '$lun_s 11:00:00', 'confermata', 'ST-1'), ($rid_u, '$lun_s 11:00:00', '$lun_s 12:00:00', 'annullata', 'ST-2')");
$sr = statistiche_risorse($conn, 950, $lun_s, date('Y-m-d', strtotime($lun_s . ' +6 days')));
$sx = $sr['risorse'][$rid_u] ?? [];
prova(($sx['prenotazioni'] ?? 0) === 2 && ($sx['annullate'] ?? 0) === 1 && ($sx['ore'] ?? 0) == 2 && ($sx['ore_aperte'] ?? 0) == 8 && ($sx['utilizzo'] ?? 0) === 25, "statistiche della risorsa: ore, ore aperte, utilizzo, annullate", json_encode($sx));
prova(($sr['fasce'][1][9] ?? 0) === 1 && ($sr['fasce'][1][10] ?? 0) === 1 && empty($sr['fasce'][1][11]), "fasce più richieste (le annullate non contano)");
$fdp = genera_docx_convenzione('allegato', $scuola_cv, $att_cv, null, '456/2026 del 02/10/2026'); $xp = '';
if ($fdp && ($z = new ZipArchive())->open($fdp) === true) { $xp = (string)$z->getFromName('word/document.xml'); $z->close(); } @unlink($fdp);
prova(str_contains($xp, 'Prot. n. 456/2026 del 02/10/2026'), "protocollo nei documenti della convenzione");

sezione("Moduli: tipi di campo, colonne delle tabelle e logica condizionale");
$cl = campi_modulo(json_encode([
    ['etichetta' => 'Dove hai sostenuto gli esami', 'tipo' => 'radio', 'opzioni' => 'in questo Ateneo, in un altro Ateneo', 'obbligatorio' => true],
    ['etichetta' => 'Ateneo', 'tipo' => 'text', 'obbligatorio' => true, 'cond' => ['campo' => 'dove hai sostenuto gli esami', 'op' => 'uguale', 'valore' => 'in un altro Ateneo']],
    ['etichetta' => 'Esami', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento:insegnamento, CFU:cfu, Data:data, Esito:scelta(Sì|No)'],
    ['etichetta' => 'Tipo di iscrizione', 'tipo' => 'select', 'opzioni' => 'tempo pieno, part-time'],
    ['etichetta' => 'Tasse', 'tipo' => 'text', 'auto' => ['campo' => 'Tipo di iscrizione', 'op' => 'uguale', 'valore' => 'part-time', 'imposta' => 'ridotte']],
    ['etichetta' => 'Lingue', 'tipo' => 'multicheck', 'opzioni' => 'inglese, francese, tedesco', 'obbligatorio' => true],
    ['etichetta' => 'Codice fiscale del genitore', 'tipo' => 'codice_fiscale'],
    ['etichetta' => 'Dichiaro il vero', 'tipo' => 'dichiarazione', 'obbligatorio' => true, 'aiuto' => 'ai sensi del D.P.R. 445/2000'],
    ['etichetta' => 'Insegnamento a scelta', 'tipo' => 'insegnamento_ateneo'],
    ['etichetta' => 'Sezione', 'tipo' => 'titolo'],
    ['etichetta' => 'Condizione rotta', 'tipo' => 'text', 'cond' => ['campo' => 'Inesistente', 'op' => 'uguale', 'valore' => 'x']],
]));
prova($cl[1]['cond']['nome'] === 'c1' && $cl[4]['auto']['nome'] === 'c4' && $cl[10]['cond'] === null, "condizioni collegate al campo indicato con la domanda (maiuscole indifferenti, campo inesistente ignorato)");
prova(array_column($cl[2]['colonne'], 'tipo') === ['insegnamento', 'cfu', 'data', 'scelta'] && $cl[2]['colonne'][3]['scelte'] === ['Sì', 'No'] && $cl[2]['opzioni'] === ['Insegnamento', 'CFU', 'Data', 'Esito'], "colonne della tabella con nome e tipo");
prova(array_column(colonne_tabella(['Insegnamento convalidato', 'Anno insegnamento', 'Tot CFU insegn.', 'CFU da integrare', 'Voto', 'S.S.D.', 'Relatore']), 'tipo') === ['insegnamento', 'testo', 'cfu', 'testo', 'voto', 'ssd', 'docente'], "tipi delle colonne scritte prima, riconosciuti dal nome");
prova(testo_colonne_tabella($cl[2]['colonne']) === 'Insegnamento:insegnamento, CFU:cfu, Data:data, Esito:scelta(Sì|No)', "colonne di nuovo in testo per il costruttore");
$_POST = ['campo_c1' => 'in questo Ateneo', 'campo_c3' => [['Chimica', ''], ['6', ''], ['2026-02-15', ''], ['Forse', '']], 'campo_c4' => 'part-time', 'campo_c5' => 'piene', 'campo_c6' => ['inglese', 'cinese'],
          'campo_c7' => 'abc', 'campo_c9' => 'Chimica generale – Biologia (a.a. 2024/2025)', 'campo_c9_meta' => json_encode(['id' => 7, 'nome' => 'Chimica generale', 'corso' => 'Biologia', 'aa' => '2024/2025', 'cfu' => 6, 'ssd' => 'CHIM/03'])];
[$rl, $el] = leggi_risposte_modulo($cl, $conn);
$_POST = [];
$per = []; foreach ($rl as $r) $per[$r['etichetta']] = $r;
prova(!empty($per['Ateneo']['nascosto']) && !in_array('Ateneo: campo obbligatorio', $el, true), "campo nascosto dalla condizione: non obbligatorio, resta vuoto", json_encode($el));
prova($per['Tasse']['valore'] === 'ridotte', "valore automatico imposto dal server");
prova($per['Esami']['righe'] === [['Chimica', '6', '15/02/2026', '']], "tabella: date in formato italiano, scelta non prevista scartata", json_encode($per['Esami']['righe']));
prova($per['Lingue']['valore'] === 'inglese' && in_array('Codice fiscale del genitore: codice fiscale non valido', $el, true) && in_array('Dichiaro il vero: devi accettare la dichiarazione', $el, true), "scelta multipla, codice fiscale e dichiarazione controllati", json_encode($el));
prova(($per['Insegnamento a scelta']['meta']['cfu'] ?? null) === 6.0 && !isset($per['Sezione']), "insegnamento dal catalogo con corso, a.a., CFU e S.S.D.; i titoli non sono risposte");
$_POST = ['campo_c1' => 'in un altro Ateneo', 'campo_c6' => ['tedesco'], 'campo_c8' => '1', 'campo_c4' => 'tempo pieno', 'campo_c5' => 'piene'];
[$rl2, $el2] = leggi_risposte_modulo($cl, $conn);
$_POST = [];
prova($el2 === ['Ateneo: campo obbligatorio'] && $rl2[4]['valore'] === 'piene', "campo mostrato dalla condizione: torna obbligatorio; senza condizione il valore è quello scritto", json_encode($el2));
$hc = html_campo_pratica($cl[1]) . html_campo_pratica($cl[2]) . html_campo_pratica($cl[8]);
prova(str_contains($hc, 'data-cond="') && str_contains($hc, 'ins-scegli') && str_contains($hc, 'data-col="cfu"') && str_contains($hc, 'type="date"'), "campi con la logica e la scelta dal catalogo nel modulo");
prova(str_contains(js_tabelle_pratica(), 'assets/js/campi-pratica.js'), "script dei campi nella pagina del modulo");

sezione("Catalogo di Ateneo degli insegnamenti");
$q("INSERT INTO ateneo_cds (codice, anno, nome, tipo, tipo_descrizione, dipartimento) VALUES ('0723', 2024, 'Biologia', 'L', 'Laurea', 'DiBEST'), ('0723', 2025, 'Biologia', 'L', 'Laurea', 'DiBEST'), ('0999', 2025, 'Ingegneria civile', 'LM', 'Laurea Magistrale', 'DIMES')");
prova(array_keys(catalogo_tipi_corso($conn)) === ['L', 'LM'], "tipi di corso del catalogo");
$cc = catalogo_corsi($conn, 'L');
prova(count($cc) === 1 && $cc[0]['anni'] === [2025, 2024], "corsi del tipo con gli anni di offerta");
$ni = salva_catalogo_insegnamenti($conn, '0723', 2024, [['StudyActivityID' => 91, 'StudyActivityName' => 'CHIMICA GENERALE', 'StudyActivityCdSCod' => '0723', 'StudyActivityYear' => 1, 'StudyActivityCFU' => '9', 'StudyActivitySSDCod' => 'CHIM/03'],
                                                         ['StudyActivityID' => 92, 'StudyActivityName' => 'ALTRO CORSO', 'StudyActivityCdSCod' => '0999']]);
$ci = catalogo_insegnamenti($conn, '0723', 2024, false);
prova($ni === 1 && count($ci) === 1 && (float)$ci[0]['cfu'] === 9.0 && $ci[0]['ssd_cod'] === 'CHIM/03', "insegnamenti del corso e dell'anno di offerta (solo quel corso)");
prova(catalogo_insegnamenti($conn, '0723', 2023, false) === [], "anno senza insegnamenti");

sezione("Consigli: referenti, componenti, presenze e decisioni in seduta");
$cons = consigli_didattica($conn);
prova(count($cons) === 5 && str_contains(reset($cons)['nome'], 'Scienze Naturali e Ambientali'), "i 5 consigli dei corsi di studio del Dipartimento");
$c1 = (int)array_key_first($cons);
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, ruolo, gruppo, docente, attivo) VALUES ('ref.prova', 'Referente', 'Rita', 'rita.ref@unical.it', 'Professore Associato', 'docenti', 1, 1), ('ord.prova', 'Ordinario', 'Otto', 'otto.ord@unical.it', 'Professore Ordinario', 'docenti', 1, 1)");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (98, 'REFRTA80XXXXXXXX', 'Rita', 'Referente', 'rita.ref@unical.it', 5)");
$u98 = $conn->query("SELECT * FROM utenti WHERE id = 98")->fetch_assoc();
prova(aggiungi_persona_consiglio($conn, $c1, 'referente', 'ref.prova') === null && consigli_referente($conn, $u98) === [$c1] && !utente_gestisce_didattica($conn, $u98), "referente dall'anagrafe: vede il suo consiglio, non tutta la Didattica");
prova(aggiungi_persona_consiglio($conn, $c1, 'referente', 'ref.prova') !== null, "referente già presente non duplicato");
prova(aggiungi_persona_consiglio($conn, $c1, 'componente', 'ord.prova') === null && aggiungi_persona_consiglio($conn, $c1, 'componente', 'ref.prova') === null
      && aggiungi_persona_consiglio($conn, $c1, 'componente', '', 'Rappresentanti degli studenti', 'Studente Sara') === null, "componenti dall'anagrafe e scritti a mano");
prova(aggiungi_persona_consiglio($conn, $c1, 'referente', '', '', 'Senza Email') !== null, "referente a mano senza email rifiutato");
$comp = persone_consiglio($conn, $c1);
prova(array_column($comp, 'qualifica') === ['Professori ordinari', 'Professori associati', 'Rappresentanti degli studenti'], "gruppi del verbale dal ruolo dell'anagrafe, in ordine", json_encode(array_column($comp, 'qualifica')));
$q("INSERT INTO didattica_sedute (id, consiglio_id, organo, data, odg) VALUES (61, $c1, '" . $conn->real_escape_string($cons[$c1]['nome']) . "', '2026-11-05', 'Comunicazioni\nPratiche studenti')");
$s61 = seduta_didattica($conn, 61);
$pres = presenze_seduta($conn, $s61);
prova(count($pres) === 3 && !array_filter($pres, fn($r) => $r['_salvata']) && array_unique(array_column($pres, 'stato')) === ['P'], "presenze proposte: tutti i componenti presenti, non ancora registrate");
$ids_c = array_keys($pres);
salva_presenze_seduta($conn, $s61, [$ids_c[0] => 'P', $ids_c[1] => 'AG', $ids_c[2] => 'AI']);
prova(riepilogo_presenze(presenze_seduta($conn, $s61)) === ['P' => 1, 'AG' => 1, 'AI' => 1], "presenze salvate: presente, assente giustificato, ingiustificato");
// Pratica di convalida in seduta, con l'insegnamento del Dipartimento dall'anagrafe
$q("INSERT INTO insegnamenti (id, nome, cds_nome, anno_accademico, presente, cfu, ssd_cod) VALUES (501, 'Chimica generale e inorganica', 'Biologia', " . anno_accademico_corrente() . ", 1, 9, 'CHIM/03'), (502, 'Botanica', 'Biologia', " . anno_accademico_corrente() . ", 1, 6, 'BIO/01')");
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, verbale_json, attivo) VALUES (504, 'Convalida esami', 'Carriera', 'online', '" . $conn->real_escape_string(json_encode([['etichetta' => 'Esami da convalidare', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento sostenuto:insegnamento, CFU:cfu, Voto:voto, S.S.D.:ssd']])) . "',
    '" . $conn->real_escape_string(json_encode(['testo' => '{STUDENTE} chiede la convalida.', 'decisione' => 'convalide'])) . "', 1)");
$pc = crea_pratica($conn, modulo_didattica($conn, 504), $u73, [['etichetta' => 'Esami da convalidare', 'tipo' => 'tabella', 'valore' => '', 'colonne' => ['Insegnamento sostenuto', 'CFU', 'Voto', 'S.S.D.'], 'righe' => [['Chimica', '8', '27/30', 'CHIM/03'], ['Botanica sistematica', '6', '30/30', 'BIO/02']]]]);
$conn->query("UPDATE pratiche SET seduta_id = 61 WHERE id = $pc");
prova(utente_vede_pratica($conn, $u98, pratica($conn, $pc)) && !utente_vede_pratica($conn, $u98, pratica($conn, $pi)), "il referente vede le pratiche delle sedute del suo consiglio, non le altre");
$pcx = pratiche_per_esportazione($conn, [$pc])[0];
$dp = decisioni_pratica($pcx, 'convalide', campi_modulo($pcx['campi_json']));
prova(!empty($dp['_proposte']) && count($dp['righe']) === 2 && $dp['righe'][0]['richiesto'] === 'Chimica' && $dp['righe'][0]['voto'] === '27/30', "convalide proposte dagli esami indicati dallo studente");
$ins_d = insegnamenti_dipartimento_scelta($conn);
$et_chim = etichetta_insegnamento_scelta($ins_d[501]);
$dec = leggi_decisioni_post($conn, 'convalide', ['d_richiesto' => ['Chimica', 'Botanica sistematica', ''], 'd_cfu' => ['8', '6', ''], 'd_voto' => ['27/30', '30/30', ''], 'd_ins' => [$et_chim, 'Botanica', ''],
                                                 'd_ins_cfu' => ['', '6', ''], 'd_esito' => ['parziale', 'totale', 'totale'], 'd_cfu_ric' => ['8', '', ''], 'd_cfu_int' => ['', '', '']]);
prova(count($dec['righe']) === 2 && $dec['righe'][0]['ins_id'] === 501 && $dec['righe'][0]['ins_cfu'] === '9' && $dec['righe'][0]['cfu_int'] === '1' && $dec['righe'][1]['cfu_ric'] === '6' && $dec['righe'][1]['cfu_int'] === '0',
      "convalida parziale (CFU da integrare calcolati) e totale, insegnamento riconosciuto nell'anagrafe", json_encode($dec['righe']));
salva_decisioni_seduta($conn, $pc, 'approvata', $dec, '');
$pcx = pratiche_per_esportazione($conn, [$pc])[0];
$doc61 = genera_verbale_pratiche($conn, $s61, [$pcx]); $x61 = '';
if ($doc61 && ($z = new ZipArchive())->open($doc61) === true) { $x61 = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", (string)$z->getFromName('word/document.xml')))); $z->close(); } @unlink($doc61);
prova(str_contains($x61, 'Professori ordinari') && str_contains($x61, 'ASSENTE GIUSTIFICATO') && str_contains($x61, 'ASSENTE INGIUSTIFICATO') && str_contains($x61, 'Presenti: 1'), "verbale: presenze per gruppo con il riepilogo");
prova(str_contains($x61, 'Quadro delle convalide') && str_contains($x61, 'Convalida parziale') && str_contains($x61, 'Chimica generale e inorganica') && substr_count($x61, 'Botanica sistematica') === 1, "verbale: quadro delle convalide al posto della tabella dello studente");
$xls61 = genera_excel_pratiche($conn, [$pcx]); $sx61 = '';
if ($xls61 && ($z = new ZipArchive())->open($xls61) === true) { $sx61 = (string)$z->getFromName('xl/worksheets/sheet1.xml'); $z->close(); } @unlink($xls61);
prova(str_contains($sx61, 'Esito in seduta') && str_contains($sx61, '>Approvata<') && str_contains($sx61, 'Convalida parziale'), "Excel con esito e decisioni");
$dpi = leggi_decisioni_post($conn, 'piano', ['d_richiesto' => ['Ecologia', 'Geologia'], 'd_cfu' => ['6', '9'], 'd_esito' => ['fuori_piano', 'inventato']]);
prova(array_column($dpi['righe'], 'esito') === ['fuori_piano', 'in_piano'] && tabella_decisioni($dpi)[1][0] === ['Ecologia', '6', 'Approvato fuori piano'], "piano di studi: in piano / fuori piano");
$EMAIL = [];
prova(applica_esiti_seduta($conn, $s61, 50, 'Referente Rita') === [1, 0, 0] && pratica($conn, $pc)['stato'] === 'accolta' && in_array('nuovo@unical.it', array_column($EMAIL, 'a'), true), "esiti applicati: pratica accolta, email allo studente");
$conn->query("UPDATE pratiche SET stato = 'in_lavorazione', esito_seduta = 'rinviata' WHERE id = $pc");
applica_esiti_seduta($conn, $s61, 50);
prova(pratica($conn, $pc)['seduta_id'] === null && pratica($conn, $pc)['stato'] === 'in_lavorazione', "pratica rinviata: torna senza seduta per la prossima");

sezione("Iter: chi ha avuto la pratica la vede e la integra; email solo ai passaggi");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (99, 'TUTIRE80XXXXXXXX', 'Irene', 'Tutor', 'irene.int@unical.it', 5)");
$pj = crea_pratica($conn, $m503, $u73, [['etichetta' => 'Corso di studio', 'tipo' => 'corso_studio', 'valore' => 'Corso di laurea in Scienze naturali', 'file' => null, 'nome_file' => null]]);
$EMAIL = [];
assegna_pratica($conn, $pj, $op['internazionalizzazione'], 1, '', 50, 'Manager Marta');
prova(array_column($EMAIL, 'a') === ['irene.int@unical.it', 'nuovo@unical.it'] && str_contains($EMAIL[1]['oggetto'], 'passata a Internazionalizzazione'), "passaggio: email all'operatore e allo studente", json_encode(array_column($EMAIL, 'oggetto')));
assegna_pratica($conn, $pj, $op['referente_cdl'], 2, '', 50, 'Tutor Irene');
prova(operatori_pratica($conn, $pj) === [$op['internazionalizzazione'], $op['referente_cdl']], "gli operatori dei passi precedenti restano sulla pratica");
$EMAIL = [];
prova(richiedi_a_operatore($conn, $pj, $op['internazionalizzazione'], 'Manca il Learning Agreement firmato', 51, 'Referente Carlo') === null && array_column($EMAIL, 'a') === ['irene.int@unical.it'], "richiesta di integrazione all'operatore precedente");
prova(!in_array('nuovo@unical.it', array_column($EMAIL, 'a'), true) && (int)$conn->query("SELECT interno FROM pratiche_eventi WHERE pratica_id = $pj AND tipo = 'richiesta'")->fetch_assoc()['interno'] === 1, "la richiesta resta interna");
$EMAIL = [];
messaggio_pratica($conn, $pj, 'ufficio', 99, 'Ecco il Learning Agreement', null, false, 'attivita', 'Tutor Irene');
prova(in_array('carlo.cdl@unical.it', array_column($EMAIL, 'a'), true), "l'operatore precedente integra: avvisato chi ha la pratica in carico");
$EMAIL = [];
messaggio_pratica($conn, $pj, 'ufficio', 51, 'Nota tra noi', null, true, 'messaggio', 'Referente Carlo');
cambia_stato_pratica($conn, $pj, 'in_lavorazione', '', 51);
prova($EMAIL === [], "note interne e ritorno in lavorazione: nessuna email");
cambia_stato_pratica($conn, $pj, 'accolta', 'Approvata', 51);
prova(array_column($EMAIL, 'a') === ['nuovo@unical.it'], "esito: email allo studente");

sezione("Tutorato: lettera di incarico, conferma con SPID/CIE, firme PAdES, protocollo");
// Firma PAdES di prova: revisione incrementale del PDF con il dizionario /Sig e una firma CMS staccata (come i programmi di firma)
$crea_cert = function (string $cn, string $cf) {
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $c = openssl_csr_sign(openssl_csr_new(['commonName' => $cn, 'serialNumber' => 'TINIT-' . $cf], $k, ['digest_alg' => 'sha256']), null, $k, 30, ['digest_alg' => 'sha256']);
    openssl_x509_export($c, $pem); openssl_pkey_export($k, $kp);
    return [$pem, $kp];
};
$firma_pades = function (string $pdf, array $cert) {
    preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $pdf, $m); $prev = (int)$m[1];
    preg_match_all('#/Size (\d+)#', $pdf, $mm); $n = (int)end($mm[1]); preg_match('#/Root (\d+) 0 R#', $pdf, $r);
    $ph = str_repeat('0', 12000);
    $obj = "$n 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /ByteRange [0 AAAAAAAAAA BBBBBBBBBB CCCCCCCCCC] /Contents <$ph> /M (D:" . date('YmdHis') . ") >>\nendobj\n";
    $off = strlen($pdf); $nuovo = $pdf . $obj;
    $xref = strlen($nuovo);
    $nuovo .= "xref\n0 1\n0000000000 65535 f \n$n 1\n" . sprintf('%010d', $off) . " 00000 n \ntrailer\n<< /Size " . ($n + 1) . " /Root {$r[1]} 0 R /Prev $prev >>\nstartxref\n$xref\n%%EOF\n";
    $b = strpos($nuovo, '<' . $ph); $c = $b + strlen($ph) + 2; $d = strlen($nuovo) - $c;
    $nuovo = str_replace(['AAAAAAAAAA', 'BBBBBBBBBB', 'CCCCCCCCCC'], [sprintf('%010d', $b), sprintf('%010d', $c), sprintf('%010d', $d)], $nuovo);
    $fi = tempnam(sys_get_temp_dir(), 'pi'); $fo = tempnam(sys_get_temp_dir(), 'po');
    file_put_contents($fi, substr($nuovo, 0, $b) . substr($nuovo, $c));
    openssl_cms_sign($fi, $fo, $cert[0], $cert[1], [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
    $hex = bin2hex((string)file_get_contents($fo)); @unlink($fi); @unlink($fo);
    return substr_replace($nuovo, str_pad($hex, strlen($ph), '0'), $b + 1, strlen($ph));
};
[$bt, $eb] = salva_bando_tutorato($conn, ['titolo' => 'Tutorato I semestre', 'anno_accademico' => '2026/2027', 'decreto_bando' => '123/2026', 'decreto_bando_data' => '2026-09-01', 'decreto_commissione' => '150/2026', 'decreto_commissione_data' => '2026-09-20',
                                          'direttore_persona_id' => 'ord.prova', 'direttore_cf' => 'RDNTTO60A01D086Z', 'operatore_id' => $op['carriere']], 1);
prova($bt > 0 && $eb === null && bando_tutorato($conn, $bt)['direttore_email'] === 'otto.ord@unical.it' && bando_tutorato($conn, $bt)['direttore_nome'] === 'Otto Ordinario', "bando con il direttore dall'anagrafe");
prova(salva_bando_tutorato($conn, ['titolo' => 'Senza decreto'], 1)[1] !== null, "bando senza decreto rifiutato");
$dati_inc = ['bando_id' => $bt, 'genere' => 'F', 'cognome' => 'Nuovo', 'nome' => 'Arrivata', 'luogo_nascita' => 'Cosenza', 'data_nascita' => '2001-04-04', 'comune_residenza' => 'Rende', 'indirizzo' => 'Via Roma', 'civico' => '3',
             'codice_fiscale' => 'nvorrv01d44d086x', 'email' => 'nuovo@unical.it', 'attivita' => "Tutorato di Chimica\nprimo anno", 'ore' => '40', 'periodo' => 'dal 01/11/2026 al 28/02/2027', 'compenso' => '1.250,50',
             'docente_persona_id' => 'ref.prova', 'data_lettera' => '2026-10-10'];
prova(salva_incarico_tutorato($conn, ['codice_fiscale' => 'XYZ'] + $dati_inc)[1] !== null, "codice fiscale del vincitore controllato");
[$it, $ei] = salva_incarico_tutorato($conn, $dati_inc);
$inc = incarico_tutorato($conn, $it);
prova($it > 0 && $ei === null && $inc['stato'] === 'bozza' && $inc['codice_fiscale'] === 'NVORRV01D44D086X' && (float)$inc['compenso'] === 1250.5 && $inc['docente_email'] === 'rita.ref@unical.it', "lettera in bozza con il docente dall'anagrafe", (string)$ei);
$dl = dati_lettera_incarico($inc);
prova($dl['TITOLO'] === 'Dott.ssa' && $dl['NATO'] === 'nata' && $dl['VINCITORE'] === 'vincitrice' && $dl['COMPENSO'] === '€ 1.250,50' && $dl['DECRETO_BANDO'] === '123/2026 del 01/09/2026', "dati della lettera al femminile, decreti e compenso");
$wd = docx_lettera_incarico($inc); $xw = '';
if ($wd && ($z = new ZipArchive())->open($wd) === true) { $xw = (string)$z->getFromName('word/document.xml'); $z->close(); } @unlink($wd);
prova($xw !== '' && (new DOMDocument())->loadXML($xw) && !str_contains($xw, '{{') && str_contains($xw, 'Arrivata Nuovo') && str_contains($xw, '150/2026 del 20/09/2026') && str_contains($xw, '<w:br/>'), "Word precompilato dal modello del Dipartimento, nessun segnaposto rimasto");
[$pdf0, $spazi] = pdf_lettera_incarico($inc);
prova(str_starts_with($pdf0, '%PDF-1.7') && str_ends_with(rtrim($pdf0), '%%EOF') && isset($spazi['docente'], $spazi['direttore']) && firme_pades_pdf($pdf0) === [], "PDF della lettera con gli spazi per le firme visibili");
$EMAIL = [];
prova(invia_incarico_studente($conn, $it, 'Carla') === null && incarico_tutorato($conn, $it)['stato'] === 'inviata' && array_column($EMAIL, 'a') === ['nuovo@unical.it'] && str_contains($EMAIL[0]['corpo'], 'incarico.php?t='), "lettera inviata: email allo studente con il link");
prova(salva_incarico_tutorato($conn, ['id' => $it] + $dati_inc)[1] !== null, "dopo l'invio i dati non cambiano");
$inc = incarico_tutorato($conn, $it);
prova(incarico_per_token($conn, 'studente', $inc['token_studente'])['id'] == $it && incarico_per_token($conn, 'docente', $inc['token_studente']) === null && incarico_per_token($conn, 'studente', 'x') === null, "link personale dello studente");
$stud = ['id' => 73, 'codice_fiscale' => 'NVORRV01D44D086X'];
prova(conferma_incarico_studente($conn, $it, $stud, ['metodo' => 'ateneo']) !== null, "con le credenziali di Ateneo non si conferma (serve SPID o CIE)");
prova(conferma_incarico_studente($conn, $it, ['id' => 5, 'codice_fiscale' => 'ALTRAPERSONA0000'], ['metodo' => 'spid']) !== null, "un'altra persona non conferma");
$EMAIL = [];
prova(segnala_errore_incarico($conn, $it, 'Il civico è 5') === null && in_array('carla.car@unical.it', array_column($EMAIL, 'a'), true), "segnalazione di un errore: email all'operatore del bando");
$EMAIL = [];
$meta_spid = ['metodo' => 'spid', 'livello' => 2, 'idp' => 'https://id.lepida.it/idp/shibboleth', 'contesto' => 'https://www.spid.gov.it/SpidL2', 'spid_code' => 'LEPI0001ABCD', 'istante' => date('c'), 'sessione' => '_abc', 'ip' => '10.0.0.1'];
prova(conferma_incarico_studente($conn, $it, $stud, $meta_spid) === null, "conferma con SPID");
$inc = incarico_tutorato($conn, $it); $fs = json_decode($inc['studente_firma_json'], true);
$pdf1 = (string)file_get_contents(pdf_corrente_incarico($inc));
prova($inc['stato'] === 'confermata' && $fs['spid_code'] === 'LEPI0001ABCD' && $fs['livello'] === 2 && $fs['sha256_pdf'] === hash('sha256', $pdf1) && $fs['impronta'] === impronta_dati_incarico($inc), "dati SPID e impronte registrati con la conferma");
prova(array_column($EMAIL, 'a') === ['rita.ref@unical.it'] && str_contains($EMAIL[0]['corpo'], 'firma_incarico.php?t='), "email al docente responsabile per la firma");
prova(conferma_incarico_studente($conn, $it, $stud, $meta_spid) !== null, "una lettera si conferma una volta sola");
prova(firmatario_incarico($inc, 'docente', $u98) && !firmatario_incarico($inc, 'direttore', $u98) && !firmatario_incarico($inc, 'docente', $u73), "la pagina di firma riconosce il docente dall'accesso");
// Firme: un .p7m (CAdES), un PDF senza firma e un PDF modificato sono rifiutati; il PAdES del docente passa
$cert_doc = $crea_cert('Rita Referente', 'RFRRTI80A41D086K');
prova(registra_firma_incarico($conn, $it, 'docente', "0\x82\x01\x00p7m") !== null && registra_firma_incarico($conn, $it, 'docente', $pdf1) !== null, "CAdES (.p7m) e PDF senza firma rifiutati");
prova(registra_firma_incarico($conn, $it, 'direttore', $firma_pades($pdf1, $cert_doc)) !== null, "il direttore non firma prima del docente");
$pdf_doc = $firma_pades($pdf1, $cert_doc);
$alterato = $pdf_doc; $alterato[strlen($pdf1) + 20] = 'X';
prova(registra_firma_incarico($conn, $it, 'docente', $firma_pades(str_replace('%%EOF', '%%EOF ', $pdf1), $cert_doc)) !== null && verifica_pdf_firmato($pdf1, $alterato)[0] !== null, "PDF modificato o firma non integra rifiutati");
prova(analizza_firma_pdf($pdf_doc)['integra'] === true && analizza_firma_pdf($pdf_doc)['cf'] === 'RFRRTI80A41D086K' && analizza_firma_pdf($pdf_doc)['nome'] === 'Rita Referente', "firma PAdES integra, firmatario e codice fiscale dal certificato");
$EMAIL = [];
prova(registra_firma_incarico($conn, $it, 'docente', $pdf_doc, 'caricato') === null && incarico_tutorato($conn, $it)['stato'] === 'firmata_docente' && array_column($EMAIL, 'a') === ['otto.ord@unical.it'], "firma del docente: la lettera va al direttore", json_encode(array_column($EMAIL, 'a')));
$inc = incarico_tutorato($conn, $it);
prova(registra_firma_incarico($conn, $it, 'direttore', $firma_pades($pdf_doc, $crea_cert('Altra Persona', 'LTRPRS70A01D086Q'))) !== null, "firma di un certificato diverso da quello del direttore rifiutata");
$pdf_fin = $firma_pades($pdf_doc, $crea_cert('Otto Ordinario', 'RDNTTO60A01D086Z'));
$EMAIL = [];
prova(registra_firma_incarico($conn, $it, 'direttore', $pdf_fin, 'firma remota Aruba') === null && incarico_tutorato($conn, $it)['stato'] === 'firmata' && array_column($EMAIL, 'a') === ['carla.car@unical.it'], "firma del direttore: email all'operatore per il protocollo");
prova(count(firme_pades_pdf((string)file_get_contents(pdf_corrente_incarico(incarico_tutorato($conn, $it))))) === 2, "PDF finale con le due firme PAdES");
$EMAIL = [];
prova(protocolla_incarico($conn, $it, '7777/2026', '2026-10-20', true, 'Carla') === null && incarico_tutorato($conn, $it)['stato'] === 'protocollata' && ($EMAIL[0]['a'] ?? '') === 'nuovo@unical.it', "protocollo registrato e copia firmata allo studente");
$ev_t = array_column($conn->query("SELECT tipo FROM tutorato_eventi WHERE incarico_id = $it ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'tipo');
prova($ev_t === ['creata', 'inviata', 'errore', 'confermata', 'firma_docente', 'firma_direttore', 'protocollata', 'copia'], "storico completo della lettera", json_encode($ev_t));
$nd = duplica_incarico($conn, $it);
prova($nd > 0 && incarico_tutorato($conn, $nd)['stato'] === 'bozza' && annulla_incarico($conn, $nd, 'prova') === null && incarico_tutorato($conn, $nd)['token_studente'] === null, "copia in bozza e annullamento");
prova(firma_remota_aruba('%PDF', 'u', 'p', '1')[0] === null, "firma remota non configurata: messaggio, nessun invio");
foreach (glob(RADICE_SITO . '/' . DIR_INCARICHI . 'TU-*.pdf') ?: [] as $f_t) @unlink($f_t);

// ---------------------------------------------------------------------------
// Prove delle pagine sull'ambiente locale (se acceso)
$BASE = getenv('BASE_LOCALE') ?: 'http://127.0.0.1:8080';
$http = function (string $url, ?array $post = null, string $jar = '') use ($BASE) {
    $ch = curl_init(str_starts_with($url, 'http') ? $url : $BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20]);
    if ($jar !== '') curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $corpo = (string)curl_exec($ch);
    $r = ['codice' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'corpo' => $corpo, 'dove' => (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
    curl_close($ch);
    return $r;
};
if (!function_exists('curl_init') || $http('/eventi/')['codice'] !== 200) {
    echo "\n(ambiente locale spento: prove delle pagine saltate — avvia bash strumenti/locale/avvia.sh)\n";
} else {
    sezione("Pagine dell'ambiente locale ($BASE)");
    foreach (['/eventi/' => 200, '/eventi/privacy.php' => 200, '/eventi/fsl.php' => 200, '/eventi/openlab' => 200, '/eventi/verifica_attestato.php' => 200,
              '/eventi/assets/modelli/Convenzione_FSL_DiBEST.doc' => 200, '/eventi/assets/modelli/Allegato_A_FSL_DiBEST.doc' => 200, '/eventi/.env.locale' => 403, '/eventi/config.php' => 403,
              '/eventi/cache/' => 403, '/eventi/uploads/convenzioni/' => 403, '/eventi/modelli_documenti/convenzione_precompilabile.docx' => 403,
              '/eventi/strumenti/prove/esegui.php' => 403, '/eventi/inc/base.php' => 403, '/eventi/cron_background.php' => 403] as $u => $atteso) {
        $r = $http($u);
        $err_php = preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']);
        prova($r['codice'] === $atteso && !$err_php, "$u → $atteso", "risposta " . $r['codice'] . ($err_php ? ' con errore PHP' : ''));
    }
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $http('/__accesso?u=1', null, $jar);
    foreach (['inizio.php', 'inizio.php?sezione=orientamento', 'dashboard.php', 'utenti.php?p_id=1', 'aree.php', 'nuova_area.php', 'anagrafe_insegnamenti.php', 'anagrafe_personale.php?vista=corsi', 'eventi.php?p_id=1&azione=nuovo', 'progetti.php?p_id=2&azione=nuovo', 'iscritti.php?p_id=1', 'eventi.php?p_id=1', 'progetti.php?p_id=2', 'scuole.php', 'fsl.php', 'fsl.php?tab=convenzioni', 'fsl.php?tab=verifica', 'fsl.php?tab=valutazioni', 'fsl.php?tab=convenzioni&conv_mod=1', 'fsl.php?tab=convenzioni&vista=archivio', 'fsl.php?tab=convenzioni&vista=archivio&conv_rinnova=1', 'sistema.php', 'impostazioni_area.php?p_id=1', 'statistiche.php?p_id=1'] as $pag) {
        $r = $http('/eventi/admin/' . $pag, null, $jar);
        $err_php = preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']);
        prova($r['codice'] === 200 && !$err_php && str_contains($r['corpo'], '</html>'), "pannello: $pag", "risposta " . $r['codice'] . ($err_php ? ' con errore PHP' : ''));
    }
    $r = $http('/eventi/area_personale.php', null, $jar);
    prova($r['codice'] === 200 && !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']) && str_contains($r['corpo'], 'pills-profilo-tab'), "Area personale (con la scheda Profilo)");
    // Iscrizione completa a un progetto FSL (docente di prova, scuola dall'anagrafe) e pulizia
    // (dati di esempio di strumenti/locale: utente 4, progetto 20 edizione 2 = turno 201; scuola di prova ZZPR00000P sempre senza convenzione)
    $loc = new mysqli('127.0.0.1', 'root', '', (parse_ini_file($SITO . '/.env.locale')['DB_NAME'] ?? 'eventi_locale'));
    $loc->query("INSERT IGNORE INTO scuole (codice, denominazione, comune, provincia, regione, tipo) VALUES ('ZZPR00000P', 'SCUOLA DELLE PROVE AUTOMATICHE', 'COSENZA', 'COSENZA', 'CALABRIA', 'LICEO')");
    $loc->query("DELETE FROM convenzioni_scuole WHERE scuola_codice = 'ZZPR00000P'");
    $jar2 = tempnam(sys_get_temp_dir(), 'jar');
    $http('/__accesso?u=4', null, $jar2);
    $pag = $http('/eventi/fsl.php?progetto=20', null, $jar2);
    preg_match('/name="csrf_token" value="([^"]+)"/', $pag['corpo'], $m_csrf);
    if (empty($m_csrf[1]) || !str_contains($pag['corpo'], 'name="turno_id" value="201"')) prova(false, "modulo di iscrizione presente nella scheda del progetto");
    else {
        $r = $http('/eventi/fsl.php?progetto=20', ['csrf_token' => $m_csrf[1], 'invia_prenotazione' => 1, 'turno_id' => 201, 'nome' => 'Luca', 'cognome' => 'Insegnante',
                   'email' => 'prova.automatica@example.org', 'email_conferma' => 'prova.automatica@example.org', 'custom_scuola' => 'Liceo', 'scuola_codice' => ['scuola' => 'ZZPR00000P'],
                   'custom_numero_partecipanti' => 15, 'convenzione' => 'no', 'accetta_privacy' => 'on'], $jar2);
        prova(in_array($r['codice'], [302, 303], true) && str_contains($r['dove'], 'st_tipo=convenzione'), "iscrizione FSL senza convenzione: in attesa", $r['codice'] . ' ' . $r['dove']);
        $pr = $loc->query("SELECT id, stato, convenzione FROM prenotazioni WHERE email = 'prova.automatica@example.org' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        prova(($pr['stato'] ?? '') === 'da_approvare' && ($pr['convenzione'] ?? '') === 'no', "prenotazione salvata da approvare, convenzione da stipulare", json_encode($pr));
        if ($pr) $loc->query("DELETE FROM prenotazioni WHERE id = " . (int)$pr['id']);
    }
    // Scelta guidata della scuola (tendine e scuole del comune)
    $r = $http('/eventi/cerca_scuole.php?elenco=regioni');
    prova($r['codice'] === 200 && str_contains($r['corpo'], '"CALABRIA"'), "cerca_scuole: regioni");
    $r = $http('/eventi/cerca_scuole.php?q=&regione=CALABRIA&provincia=COSENZA&comune=COSENZA');
    prova($r['codice'] === 200 && str_contains($r['corpo'], 'ZZPR00000P'), "cerca_scuole: scuole del comune");

    // Calendari e risorse: area di prova, pagina pubblica, prenotazione, pannello e pulizia
    $loc->query("DELETE FROM pagine_eventi WHERE slug = 'prove-calendario'");
    $loc->query("INSERT INTO pagine_eventi (titolo, slug, tipo_area, visibile) VALUES ('Prove calendario', 'prove-calendario', 'calendario', 1)");
    $pid_c = (int)$loc->insert_id;
    $loc->query("INSERT INTO risorse (pagina_id, nome, tipo, durata_slot, max_slot, anticipo_ore, max_giorni, chiede_motivo) VALUES ($pid_c, 'Aula delle prove', 'aula', 60, 2, 0, 30, 1)");
    $rid_c = (int)$loc->insert_id;
    for ($g = 1; $g <= 7; $g++) $loc->query("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES ($rid_c, $g, '08:00', '20:00')");
    $dom = date('Y-m-d', strtotime('+3 days'));
    $r = $http('/eventi/prove-calendario', null, $jar2);
    prova($r['codice'] === 200 && str_contains($r['corpo'], 'Aula delle prove') && !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']), "pagina pubblica dell'area calendario");
    $r = $http("/eventi/prove-calendario?risorsa=$rid_c&dal=$dom", null, $jar2);
    preg_match('/name="csrf_token" value="([^"]+)"/', $r['corpo'], $m_csrf);
    prova(str_contains($r['corpo'], 'data-inizio="' . $dom . ' 10:00:00"'), "settimana con gli slot liberi prenotabili");
    $r = $http("/eventi/prove-calendario?risorsa=$rid_c", ['csrf_token' => $m_csrf[1] ?? '', 'prenota_risorsa' => 1, 'risorsa_id' => $rid_c, 'inizio' => "$dom 10:00:00", 'n_slot' => 2, 'motivo' => 'Prova automatica', 'settimana' => $dom], $jar2);
    $pc = $loc->query("SELECT * FROM prenotazioni_risorse WHERE risorsa_id = $rid_c")->fetch_assoc();
    prova(in_array($r['codice'], [302, 303], true) && ($pc['stato'] ?? '') === 'confermata' && ($pc['fine'] ?? '') === "$dom 12:00:00", "prenotazione dalla pagina pubblica", json_encode($pc));
    $r = $http('/eventi/risorsa_ics.php?code=' . urlencode($pc['codice'] ?? ''));
    prova($r['codice'] === 200 && str_contains($r['corpo'], 'BEGIN:VCALENDAR'), "file .ics della prenotazione");
    $r = $http('/eventi/area_personale.php', null, $jar2);
    prova(str_contains($r['corpo'], 'Aula delle prove'), "prenotazione nell'Area personale");
    foreach (["dashboard.php?p_id=$pid_c", "prenotazioni_risorse.php?p_id=$pid_c&periodo=tutte", "risorse.php?p_id=$pid_c", "risorse.php?p_id=$pid_c&modifica=$rid_c"] as $pag) {
        $r = $http('/eventi/admin/' . $pag, null, $jar);
        $err_php = preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']);
        prova($r['codice'] === 200 && !$err_php && str_contains($r['corpo'], '</html>') && str_contains($r['corpo'], 'Aula delle prove'), "pannello calendario: $pag", "risposta " . $r['codice'] . ($err_php ? ' con errore PHP' : ''));
    }
    $r = $http("/eventi/admin/prenotazioni_risorse.php?p_id=$pid_c&periodo=tutte&csv=1", null, $jar);
    prova(str_starts_with($r['corpo'], "\xEF\xBB\xBF") && str_contains($r['corpo'], 'Prova automatica'), "esportazione CSV delle prenotazioni");
    $loc->query("DELETE FROM prenotazioni_risorse WHERE risorsa_id = $rid_c");
    $loc->query("DELETE FROM risorse_orari WHERE risorsa_id = $rid_c");
    $loc->query("DELETE FROM risorse WHERE id = $rid_c");
    $loc->query("DELETE FROM pagine_eventi WHERE id = $pid_c");
    // Didattica: sedute e consigli, costruttore dei moduli, tutorato; catalogo degli insegnamenti e pagine con link personale
    $c_loc = (int)($loc->query("SELECT MIN(id) n FROM didattica_consigli")->fetch_assoc()['n'] ?? 0);
    foreach (['didattica.php?tab=sedute', "didattica.php?tab=sedute&consiglio=$c_loc", "didattica.php?tab=sedute&nuova=1&consiglio_id=$c_loc", 'didattica.php?tab=moduli&nuovo=1', 'didattica.php?tab=pratiche&carico=seguite',
              'tutorato.php', 'tutorato.php?nuovo_bando=1'] as $pag) {
        $r = $http('/eventi/admin/' . $pag, null, $jar);
        $err_php = preg_match('/<b>(Fatal error|Parse error|Warning|Deprecated|Notice)<\/b>|Uncaught /', $r['corpo']);
        prova($r['codice'] === 200 && !$err_php && str_contains($r['corpo'], '</html>'), "pannello Didattica: $pag", "risposta " . $r['codice'] . ($err_php ? ' con errore PHP' : ''));
    }
    $r = $http('/eventi/cerca_insegnamenti.php?azione=tipi', null, $jar);
    prova($r['codice'] === 200 && is_array(json_decode($r['corpo'], true)), "catalogo degli insegnamenti (JSON)");
    prova($http('/eventi/cerca_insegnamenti.php?azione=tipi')['codice'] === 401, "catalogo degli insegnamenti: serve l'accesso");
    foreach (['/eventi/incarico.php?t=' . str_repeat('a', 40) => 'Lettera non disponibile', '/eventi/firma_incarico.php?t=' . str_repeat('b', 40) => 'Lettera non disponibile'] as $u => $testo) {
        $r = $http($u, null, $jar2);
        prova($r['codice'] === 200 && str_contains($r['corpo'], $testo), "link personale non valido: $u");
    }
    prova($http('/eventi/uploads/incarichi/')['codice'] === 403 && $http('/eventi/modelli_documenti/lettera_incarico_tutorato.docx')['codice'] === 403, "lettere di incarico e modello Word non raggiungibili dal web");
    @unlink($jar); @unlink($jar2);
}

$conn->query("DROP DATABASE IF EXISTS eventi_prova");
echo "\n" . ($KO ? "\e[31m$KO prove fallite\e[0m, $OK superate.\n" : "\e[32mTutte le $OK prove superate.\e[0m\n");
exit($KO ? 1 : 0);
