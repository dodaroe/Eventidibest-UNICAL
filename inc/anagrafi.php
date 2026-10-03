<?php
// inc/anagrafi.php - Anagrafe delle scuole (Ministero) e del personale di Ateneo, corsi di studio.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// ANAGRAFE DELLE SCUOLE (open data del Ministero, tabella scuole): ricerca,
// nome ufficiale e campo "Scuola" dei moduli con ricerca guidata
// =======================================================================
if (!function_exists('maiuscole_scuola')) {
    // "LICEO SCIENTIFICO E. FERMI" -> "Liceo Scientifico E. Fermi" (l'anagrafe usa il maiuscolo)
    function maiuscole_scuola(string $s): string {
        $s = mb_convert_case(mb_strtolower(trim($s)), MB_CASE_TITLE, 'UTF-8');
        $s = preg_replace_callback("/(?<=['’])\p{Ll}/u", fn($m) => mb_strtoupper($m[0]), $s);
        // Sigle delle scuole in maiuscolo, articoli e preposizioni in minuscolo (non all'inizio)
        $sigle = ['Iis', 'Iiss', 'Is', 'Isis', 'Iti', 'Itis', 'Itc', 'Itcg', 'Itg', 'Ite', 'Ita', 'Itt', 'Ipsia', 'Ipsar', 'Ipssar', 'Ipsseoa', 'Ipc', 'Ips', 'Ic', 'Cpia', 'Sm', 'Smd', 'Ss', 'Ls', 'Lc', 'Ee', 'Ctp', 'Ipseoa', 'Ssig'];
        // Solo preposizioni: gli articoli ("I Girasoli", "La Salle") fanno spesso parte del nome
        $minuscole = ['Di', 'Del', 'Della', 'Delle', 'Dei', 'Degli', 'Dello', 'Da', 'Dal', 'Dalla', 'In', 'E', 'Ed', 'Per', 'Con', 'Su', 'Sul', 'Sulla', 'A', 'Al', 'Alla', 'Allo', 'Ai', 'Agli'];
        $parole = preg_split('/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parole as $i => $p) {
            $nudo = trim($p, '"«».,()');
            if (in_array($nudo, $sigle, true)) $parole[$i] = str_replace($nudo, mb_strtoupper($nudo), $p);
            elseif ($i > 0 && in_array($nudo, $minuscole, true) && $nudo === $p) $parole[$i] = mb_strtolower($p);
            elseif ($i > 0 && preg_match("/^(Dell|Dall|All|Nell|Sull|Dagl|Degl)(['’])/u", $p)) $parole[$i] = mb_strtolower(mb_substr($p, 0, 1)) . mb_substr($p, 1);
        }
        return implode('', $parole);
    }
}

if (!function_exists('etichetta_scuola')) {
    // Nome da mostrare: denominazione e comune, es. "Liceo Scientifico E. Fermi – Cosenza"
    function etichetta_scuola(array $s): string {
        $nome = maiuscole_scuola((string)($s['denominazione'] ?? ''));
        $comune = maiuscole_scuola((string)($s['comune'] ?? ''));
        return $comune !== '' && mb_stripos($nome, $comune) === false ? "$nome – $comune" : $nome;
    }
}

if (!function_exists('scuola_per_codice')) {
    function scuola_per_codice($conn, ?string $codice): ?array {
        static $cache = [];
        $codice = strtoupper(trim((string)$codice));
        if (!preg_match('/^[A-Z0-9]{10}$/', $codice)) return null;
        if (!array_key_exists($codice, $cache)) {
            $st = $conn->prepare("SELECT * FROM scuole WHERE codice = ? LIMIT 1");
            if (!$st) return null;
            $st->bind_param("s", $codice); $st->execute();
            $cache[$codice] = $st->get_result()->fetch_assoc() ?: null;
        }
        return $cache[$codice];
    }
}

if (!function_exists('cerca_scuole')) {
    // Ricerca per parole (nome, comune, istituto o codice meccanografico): tutte le parole devono comparire.
    // Prima il codice esatto, poi le scuole della Calabria, poi le altre in ordine di nome.
    // $filtri: ['regione' => ..., 'provincia' => ..., 'comune' => ...] (finestra guidata del campo Scuola);
    // con il comune o la provincia scelti la ricerca può anche essere vuota (tutte le scuole del luogo).
    function cerca_scuole($conn, string $q, int $limite = 15, array $filtri = []): array {
        $q = trim(preg_replace('/\s+/u', ' ', $q));
        $parole = array_slice(array_values(array_filter(explode(' ', $q), fn($p) => mb_strlen($p) >= 2)), 0, 6);
        $where = []; $tipi = ''; $par = [];
        foreach (['regione', 'provincia', 'comune'] as $f) {
            $v = mb_strtoupper(trim((string)($filtri[$f] ?? '')));
            if ($v === '') continue;
            $where[] = "$f = ?"; $par[] = $v; $tipi .= 's';
        }
        if (!$parole && empty($filtri['comune']) && empty($filtri['provincia'])) return [];
        foreach ($parole as $p) {
            $where[] = "(denominazione LIKE ? OR comune LIKE ? OR istituto_denominazione LIKE ? OR codice LIKE ?)";
            $like = '%' . addcslashes($p, '%_\\') . '%';
            array_push($par, $like, $like, $like, $like); $tipi .= 'ssss';
        }
        $codice_esatto = strtoupper(str_replace(' ', '', $q));
        $sql = "SELECT codice, denominazione, istituto_codice, istituto_denominazione, tipo, comune, provincia, regione, statale
                FROM scuole WHERE " . implode(' AND ', $where) . "
                ORDER BY (codice = ?) DESC, (regione = 'CALABRIA') DESC, denominazione ASC LIMIT " . max(1, min(200, $limite));
        $par[] = $codice_esatto; $tipi .= 's';
        $st = $conn->prepare($sql);
        if (!$st) return [];
        $st->bind_param($tipi, ...$par); $st->execute();
        $out = [];
        $r = $st->get_result();
        while ($r && $s = $r->fetch_assoc()) {
            $out[] = ['codice' => $s['codice'], 'nome' => etichetta_scuola($s), 'tipo' => maiuscole_scuola((string)$s['tipo']),
                      'comune' => maiuscole_scuola((string)$s['comune']), 'provincia' => maiuscole_scuola((string)$s['provincia']),
                      'istituto' => !empty($s['istituto_codice']) && $s['istituto_codice'] !== $s['codice'] ? maiuscole_scuola((string)$s['istituto_denominazione']) : ''];
        }
        // Convenzioni con il Dipartimento (registro): 'conv' = periodi di validità [dal, al] ('' = senza limite).
        // Il modulo controlla se uno copre il periodo dell'attività.
        if ($out) {
            $in = implode(',', array_map(fn($x) => "'" . $conn->real_escape_string($x['codice']) . "'", $out));
            $r_cv = @$conn->query("SELECT scuola_codice, data_stipula, scadenza FROM convenzioni_scuole WHERE scuola_codice IN ($in)");
            $conv = [];
            while ($r_cv && $x = $r_cv->fetch_assoc()) $conv[$x['scuola_codice']][] = [(string)$x['data_stipula'], (string)$x['scadenza']];
            foreach ($out as &$o) $o['conv'] = $conv[$o['codice']] ?? [];
            unset($o);
        }
        return $out;
    }
}

if (!function_exists('luoghi_scuole')) {
    // Regioni, province di una regione o comuni di una provincia presenti nell'anagrafe delle scuole,
    // con il numero di scuole: [['valore' => 'COSENZA', 'nome' => 'Cosenza', 'n' => 412], ...]
    function luoghi_scuole($conn, string $livello, string $regione = '', string $provincia = ''): array {
        $campo = ['regioni' => 'regione', 'province' => 'provincia', 'comuni' => 'comune'][$livello] ?? null;
        if (!$campo) return [];
        $where = ["$campo IS NOT NULL", "$campo <> ''"]; $par = []; $tipi = '';
        if ($campo !== 'regione') { $where[] = 'regione = ?'; $par[] = mb_strtoupper($regione); $tipi .= 's'; }
        if ($campo === 'comune') { $where[] = 'provincia = ?'; $par[] = mb_strtoupper($provincia); $tipi .= 's'; }
        $st = $conn->prepare("SELECT $campo AS v, COUNT(*) AS n FROM scuole WHERE " . implode(' AND ', $where) . " GROUP BY $campo ORDER BY $campo");
        if (!$st) return [];
        if ($par) $st->bind_param($tipi, ...$par);
        $st->execute();
        $out = [];
        $r = $st->get_result();
        while ($r && $x = $r->fetch_assoc()) $out[] = ['valore' => $x['v'], 'nome' => maiuscole_scuola((string)$x['v']), 'n' => (int)$x['n']];
        return $out;
    }
}

if (!function_exists('applica_scuola_scelta')) {
    // Moduli con il campo "Scuola": se è stata scelta una scuola dell'anagrafe (scuola_codice[nome_campo]),
    // nel campo si salva il nome ufficiale. Ritorna il codice meccanografico (il primo valido) o null
    // se la scuola è stata scritta a mano ("non è in elenco").
    function applica_scuola_scelta($conn, array &$custom_data, $codici_post): ?string {
        $scelto = null;
        foreach ((array)$codici_post as $campo => $codice) {
            $s = scuola_per_codice($conn, is_string($codice) ? $codice : '');
            if (!$s || !array_key_exists((string)$campo, $custom_data)) continue;
            $custom_data[(string)$campo] = etichetta_scuola($s);
            if ($scelto === null) $scelto = $s['codice'];
        }
        return $scelto;
    }
}

if (!function_exists('html_campo_scuola')) {
    // Campo "Scuola" con ricerca nell'anagrafe: testo visibile (name=custom_<campo>) + codice nascosto
    // (name=scuola_codice[<campo>]). Se non si sceglie dall'elenco resta il testo scritto a mano.
    // Il comportamento è in assets/js/campo-scuola.js (footer pubblico se serve, sempre nel pannello).
    function html_campo_scuola(string $campo, string $valore = '', string $codice = '', string $attr = '', string $classi = 'form-control form-control-sm'): string {
        $GLOBALS['usa_campo_scuola'] = true;
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $id = 'scu_' . bin2hex(random_bytes(4));
        $out = '<div class="scuola-campo position-relative" data-endpoint="' . $h(rtrim((string)parse_url(url_base_sito(), PHP_URL_PATH), '/') . '/cerca_scuole.php') . '">'
             . '<input type="text" name="custom_' . $h($campo) . '" id="' . $id . '" class="' . $h($classi) . ' scuola-testo" value="' . $h($valore) . '" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . $id . '_lista" placeholder="Clicca per scegliere la scuola" ' . $attr . '>'
             . '<input type="hidden" name="scuola_codice[' . $h($campo) . ']" class="scuola-codice" value="' . $h($codice) . '">'
             . '<ul class="scuola-lista list-group position-absolute w-100 shadow" id="' . $id . '_lista" role="listbox" style="z-index:1080;display:none;max-height:260px;overflow-y:auto;"></ul>'
             . '<div class="form-text scuola-stato">' . ($codice !== '' ? '<i class="fa fa-circle-check text-success me-1"></i>Scuola dall\'anagrafe del Ministero' : 'Clicca nel campo: scegli regione, provincia e comune, poi la scuola. Se non è in elenco potrai scriverla a mano.') . '</div>'
             . '</div>';
        return $out;
    }
}

if (!function_exists('nome_scuola_prenotazione')) {
    // Nome della scuola da una prenotazione: quello ufficiale se è stata scelta dall'anagrafe,
    // altrimenti il primo campo del form che parla di scuola/istituto
    function nome_scuola_prenotazione(?array $pr): string {
        global $conn;
        if (!empty($pr['scuola_codice']) && $conn instanceof mysqli && ($s = scuola_per_codice($conn, $pr['scuola_codice']))) return etichetta_scuola($s);
        $custom = json_decode((string)($pr['dati_custom_json'] ?? ''), true) ?: [];
        foreach ($custom as $k => $val) {
            if (preg_match('/scuol|istitut/i', (string)$k) && is_string($val) && trim($val) !== '' && !preg_match('/^\d+$/', trim($val))) return trim($val);
        }
        return '';
    }
}

// =======================================================================
// ANAGRAFE DEL PERSONALE DI ATENEO E CORSI DI STUDIO (API pubbliche del portale Unical)
// Si sincronizzano solo le strutture scelte in Anagrafe personale (tabella anagrafe_strutture, DiBEST
// di partenza). Il login si collega alla persona per email; i gruppi Docenti / Personale tecnico
// amministrativo / Altro personale di Ateneo si assegnano da soli come gruppi secondari.
// =======================================================================
if (!defined('API_UNICAL')) define('API_UNICAL', 'https://storage.portale.unical.it/api/ricerca/');
if (!defined('GRUPPI_PERSONALE')) define('GRUPPI_PERSONALE', ['docenti' => 'Docenti', 'pta' => 'Personale tecnico amministrativo', 'altro' => 'Altro personale di Ateneo']);
// Codici ruolo dell'Ateneo (API roles): docenti e ricercatori, personale tecnico amministrativo e dirigenti
if (!defined('RUOLI_DOCENTI')) define('RUOLI_DOCENTI', ['PO', 'PA', 'RU', 'RD', 'RM', 'PD', 'PF', 'SC', 'AS']);
if (!defined('RUOLI_PTA')) define('RUOLI_PTA', ['ND', 'NM', 'NT', 'NC', 'D0', 'DC', 'D6', 'NG', 'OA', 'NB']);

if (!function_exists('api_unical_get')) {
    // Una chiamata GET alle API del portale. null se la rete o la risposta non vanno.
    function api_unical_get(string $percorso, array $query = [], int $timeout = 25): ?array {
        $url = API_UNICAL . ltrim($percorso, '/') . ($query ? '?' . http_build_query($query) : '');
        $corpo = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
                                    CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_HTTPHEADER => ['Accept: application/json'],
                                    CURLOPT_USERAGENT => 'DidatticaDiBEST/1.0']);
            $corpo = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $corpo = false;
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "Accept: application/json\r\nUser-Agent: DidatticaDiBEST/1.0\r\n"]]);
            $corpo = @file_get_contents($url, false, $ctx);
        }
        if ($corpo === false || $corpo === '') return null;
        $j = json_decode($corpo, true);
        return is_array($j) ? $j : null;
    }
}

if (!function_exists('api_unical_tutte')) {
    // Tutte le pagine di un elenco (page_size 500): null se una pagina non arriva, così non si scambia un errore per "nessuno"
    function api_unical_tutte(string $percorso, array $query = []): ?array {
        $out = [];
        for ($pag = 1; $pag <= 40; $pag++) {
            $j = api_unical_get($percorso, $query + ['page_size' => 500, 'page' => $pag]);
            if ($j === null || !isset($j['results']) || !is_array($j['results'])) return null;
            array_push($out, ...$j['results']);
            if (empty($j['next'])) break;
        }
        return $out;
    }
}

if (!function_exists('maiuscole_nome')) {
    // "D'AMICO MARIA" -> "D'Amico Maria"
    function maiuscole_nome(string $s): string {
        $s = mb_convert_case(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s))), MB_CASE_TITLE, 'UTF-8');
        return preg_replace_callback("/(?<=['’-])\p{Ll}/u", fn($m) => mb_strtoupper($m[0]), $s);
    }
}

if (!function_exists('separa_cognome_nome')) {
    // L'elenco del portale dà "COGNOME NOME" e l'ID "nome.cognome": il nome è la parte finale che, senza
    // spazi e accenti, coincide con la prima parte dell'ID. Altrimenti: prima parola = cognome.
    function separa_cognome_nome(string $nominativo, string $id): array {
        $parole = array_values(array_filter(explode(' ', trim(preg_replace('/\s+/u', ' ', $nominativo))), 'strlen'));
        $pulisci = function (string $s): string {
            $a = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
            return preg_replace('/[^a-z]/', '', strtolower($a !== false ? $a : $s));
        };
        $id_nome = $pulisci(explode('.', $id)[0] ?? '');
        for ($k = 1; $k < count($parole); $k++) {
            if ($id_nome !== '' && $pulisci(implode('', array_slice($parole, $k))) === $id_nome) {
                return [maiuscole_nome(implode(' ', array_slice($parole, 0, $k))), maiuscole_nome(implode(' ', array_slice($parole, $k)))];
            }
        }
        return [maiuscole_nome($parole[0] ?? ''), maiuscole_nome(implode(' ', array_slice($parole, 1)))];
    }
}

if (!function_exists('gruppo_personale')) {
    function gruppo_personale(string $ruolo_cod, bool $docente = false): string {
        if (in_array($ruolo_cod, RUOLI_DOCENTI, true)) return 'docenti';
        if (in_array($ruolo_cod, RUOLI_PTA, true)) return 'pta';
        // Docenti a contratto (autonomi, professionisti, incaricati…) se sono nell'elenco dei docenti del dipartimento;
        // borsisti, dottorandi, assegnisti e contratti di ricerca restano in "Altro" anche se tengono lezioni
        $contratti = ['AU', 'PR', 'IE', 'II', 'BG', 'CB', 'CC', 'PE', 'LC', 'LS', 'CL'];
        return $docente && in_array($ruolo_cod, $contratti, true) ? 'docenti' : 'altro';
    }
}

if (!function_exists('maiuscole_corso')) {
    // "SCIENZE GEOLOGICHE" -> "Scienze geologiche"; i nomi già scritti in minuscolo restano come sono
    function maiuscole_corso(string $s): string {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if ($s === '' || mb_strtoupper($s) !== $s) return $s;
        $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_strtolower(mb_substr($s, 1));
        // Codici delle classi di concorso (A028, A050…) e numeri romani ("II grado") in maiuscolo
        return preg_replace_callback('/\b([a-z]\d{2,3}|ii|iii|iv|vi|vii)\b/u', fn($m) => mb_strtoupper($m[0]), $s);
    }
}

if (!function_exists('sincronizza_anagrafe')) {
    // Scarica personale (addressbook per struttura, con i docenti del dipartimento per settore disciplinare)
    // e corsi di studio delle strutture scelte. Chi non compare più resta segnato come "non più in Ateneo"
    // (per gli avvisi) e viene cancellato dopo 12 mesi. Ritorna un riepilogo per struttura.
    function sincronizza_anagrafe($conn, ?string $solo_struttura = null): array {
        @set_time_limit(300);
        // Inizio dell'aggiornamento (ora del database): chi non viene aggiornato da qui in poi non compare più
        $inizio = (string)($conn->query("SELECT NOW() AS n")->fetch_assoc()['n'] ?? date('Y-m-d H:i:s'));
        $riepilogo = []; $visti = []; $tutte_ok = true;
        $strutture = [];
        $res = $conn->query("SELECT codice FROM anagrafe_strutture ORDER BY aggiunta_il");
        while ($res && $r = $res->fetch_assoc()) if ($solo_struttura === null || $r['codice'] === $solo_struttura) $strutture[] = $r['codice'];

        $up = $conn->prepare("INSERT INTO personale_ateneo (id, cognome, nome, email, telefono, ufficio, ruolo_cod, ruolo, struttura_cod, struttura, gruppo, docente, ssd_cod, ssd, origine, attivo, aggiornata_il, uscita_il)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NULL)
                              ON DUPLICATE KEY UPDATE cognome=VALUES(cognome), nome=VALUES(nome), email=VALUES(email), telefono=VALUES(telefono), ufficio=VALUES(ufficio),
                                ruolo_cod=VALUES(ruolo_cod), ruolo=VALUES(ruolo), struttura_cod=VALUES(struttura_cod), struttura=VALUES(struttura), gruppo=VALUES(gruppo),
                                docente=VALUES(docente), ssd_cod=VALUES(ssd_cod), ssd=VALUES(ssd), origine=VALUES(origine), attivo=1, aggiornata_il=NOW(), uscita_il=NULL");

        foreach ($strutture as $cod) {
            $persone = api_unical_tutte('addressbook/', ['structuretree' => $cod]);
            $docenti = api_unical_tutte('teachers/', ['department' => $cod]);
            $corsi   = api_unical_tutte('cds/', ['departmentcod' => $cod]);
            if ($persone === null || $docenti === null) {
                $tutte_ok = false;
                $esito = 'API non raggiungibili: dati precedenti conservati';
                $st = $conn->prepare("UPDATE anagrafe_strutture SET esito = ? WHERE codice = ?");
                $st->bind_param("ss", $esito, $cod); $st->execute();
                $riepilogo[$cod] = ['ok' => false, 'esito' => $esito];
                continue;
            }
            // Docenti del dipartimento: settore disciplinare e ruolo, anche per chi non è nella rubrica della struttura
            $doc = [];
            foreach ($docenti as $d) if (!empty($d['TeacherID'])) $doc[$d['TeacherID']] = $d;
            $righe = [];
            foreach ($persone as $p) {
                $id = trim((string)($p['ID'] ?? ''));
                if ($id === '' || strlen($id) > 80) continue;
                $ruoli = (array)($p['Roles'] ?? []);
                usort($ruoli, fn($a, $b) => (int)($b['Priority'] ?? 0) <=> (int)($a['Priority'] ?? 0));
                $r0 = $ruoli[0] ?? [];
                $righe[$id] = ['nominativo' => (string)($p['Name'] ?? ''), 'email' => (array)($p['Email'] ?? []), 'tel' => (array)($p['TelOffice'] ?? []),
                               'ufficio' => implode(', ', (array)($p['OfficeReference'] ?? [])), 'ruolo_cod' => (string)($r0['Role'] ?? ''),
                               'ruolo' => (string)($r0['RoleDescription'] ?? ''), 'struttura_cod' => (string)($r0['StructureCod'] ?? ''),
                               'struttura' => trim((string)($r0['Structure'] ?? '')), 'origine' => 'rubrica'];
            }
            foreach ($doc as $id => $d) {
                if (isset($righe[$id]) || strlen($id) > 80) continue;
                $righe[$id] = ['nominativo' => (string)($d['TeacherName'] ?? ''), 'email' => (array)($d['Email'] ?? []), 'tel' => [], 'ufficio' => '',
                               'ruolo_cod' => (string)($d['TeacherRole'] ?? ''), 'ruolo' => (string)($d['TeacherRoleDescription'] ?? ''),
                               'struttura_cod' => (string)($d['TeacherDepartmentCod'] ?? ''), 'struttura' => trim((string)($d['TeacherDepartmentName'] ?? '')), 'origine' => 'docenti'];
            }
            $conn->begin_transaction();
            foreach ($righe as $id => $r) {
                $id = (string)$id;
                [$cognome, $nome] = separa_cognome_nome($r['nominativo'], $id);
                $email = '';
                foreach ($r['email'] as $e) { $e = strtolower(trim((string)$e)); if (filter_var($e, FILTER_VALIDATE_EMAIL)) { $email = $e; break; } }
                $tel = mb_substr(trim((string)($r['tel'][0] ?? '')), 0, 60);
                $d = $doc[$id] ?? null;
                $docente = $d ? 1 : 0;
                $ssd_cod = $d && !preg_match('/^0+$/', (string)($d['TeacherSSDCod'] ?? '')) ? (string)$d['TeacherSSDCod'] : '';
                $ssd = $ssd_cod !== '' ? (string)($d['TeacherSSDDescription'] ?? '') : '';
                $gruppo = gruppo_personale($r['ruolo_cod'], (bool)$docente);
                $ufficio = mb_substr($r['ufficio'], 0, 255); $ruolo = mb_substr($r['ruolo'], 0, 150); $strutt = mb_substr($r['struttura'], 0, 255);
                $up->bind_param("sssssssssssisss", $id, $cognome, $nome, $email, $tel, $ufficio, $r['ruolo_cod'], $ruolo, $r['struttura_cod'], $strutt, $gruppo, $docente, $ssd_cod, $ssd, $cod);
                $up->execute();
                $visti[$id] = true;
            }
            $conn->commit();

            // Corsi di studio: i più recenti visibili; quelli vecchi (anno di 3+ anni prima del più recente) o doppioni di un corso più nuovo nascosti
            $n_corsi = 0;
            if ($corsi !== null) {
                usort($corsi, fn($a, $b) => (int)($b['AcademicYear'] ?? 0) <=> (int)($a['AcademicYear'] ?? 0));
                $anno_max = (int)($corsi[0]['AcademicYear'] ?? 0);
                // Solo i corsi della prima struttura (il proprio dipartimento) sono proposti subito; quelli delle strutture
                // aggiunte dopo arrivano nascosti e si attivano da "Corsi di studio"
                $r_prima = $conn->query("SELECT codice FROM anagrafe_strutture ORDER BY aggiunta_il, codice LIMIT 1");
                $propri = $r_prima && ($x_prima = $r_prima->fetch_assoc()) && $x_prima['codice'] === $cod;
                $gia = [];
                $ins_c = $conn->prepare("INSERT INTO corsi_studio (codice, nome, tipo, tipo_descrizione, classe, anno, lingua, durata, dipartimento_cod, visibile, regdid_id, presente, aggiornato_il)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                                         ON DUPLICATE KEY UPDATE nome=VALUES(nome), tipo=VALUES(tipo), tipo_descrizione=VALUES(tipo_descrizione), classe=VALUES(classe),
                                           anno=VALUES(anno), lingua=VALUES(lingua), durata=VALUES(durata), dipartimento_cod=VALUES(dipartimento_cod), regdid_id=VALUES(regdid_id), presente=1, aggiornato_il=NOW()");
                $codici = [];
                foreach ($corsi as $c) {
                    $cc = trim((string)($c['CdSCod'] ?? ''));
                    if ($cc === '' || strlen($cc) > 20) continue;
                    $nome_c = mb_substr(maiuscole_corso((string)($c['CdSName'] ?? '')), 0, 255);
                    $tipo = (string)($c['CourseType'] ?? ''); $tipo_d = (string)($c['CourseTypeDescription'] ?? '');
                    $classe = mb_substr(trim((string)($c['CourseClassName'] ?? '')), 0, 255);
                    $anno = (int)($c['AcademicYear'] ?? 0) ?: null; $durata = (int)($c['CdSDuration'] ?? 0) ?: null;
                    $lingua = mb_substr(implode(', ', (array)($c['CdSLanguage'] ?? [])), 0, 100);
                    $chiave = mb_strtolower($nome_c) . '|' . $tipo;
                    $vis = ($propri && !isset($gia[$chiave]) && ($anno ?? 0) >= $anno_max - 2) ? 1 : 0;
                    $gia[$chiave] = true;
                    $regdid = (int)($c['RegDidId'] ?? 0) ?: null;
                    $ins_c->bind_param("sssssisisii", $cc, $nome_c, $tipo, $tipo_d, $classe, $anno, $lingua, $durata, $cod, $vis, $regdid);
                    $ins_c->execute();
                    $codici[] = "'" . $conn->real_escape_string($cc) . "'";
                    $n_corsi++;
                }
                $conn->query("UPDATE corsi_studio SET presente = 0 WHERE dipartimento_cod = '" . $conn->real_escape_string($cod) . "'" . ($codici ? " AND codice NOT IN (" . implode(',', $codici) . ")" : ''));
            }

            $n = count($righe);
            $nome_s = '';
            foreach ($persone as $p) foreach ((array)($p['Roles'] ?? []) as $ro) if (($ro['StructureCod'] ?? '') === $cod) { $nome_s = trim((string)$ro['Structure']); break 2; }
            if ($nome_s === '') foreach ($docenti as $d) { $nome_s = trim((string)($d['TeacherDepartmentName'] ?? '')); break; }
            $esito = "$n persone" . ($corsi !== null ? ", $n_corsi corsi di studio" : ', corsi non disponibili');
            $st = $conn->prepare("UPDATE anagrafe_strutture SET persone = ?, corsi = ?, ultima_sync = NOW(), esito = ?, nome = IF(? <> '', ?, nome) WHERE codice = ?");
            $st->bind_param("iissss", $n, $n_corsi, $esito, $nome_s, $nome_s, $cod);
            $st->execute();
            $riepilogo[$cod] = ['ok' => true, 'esito' => $esito];
        }

        // Corsi di studio di tutto l'Ateneo per i moduli della Didattica (catalogo: gli insegnamenti si scaricano quando servono)
        if ($solo_struttura === null && function_exists('sincronizza_catalogo_cds')) sincronizza_catalogo_cds($conn);

        // Insegnamenti dei corsi del proprio dipartimento (la prima struttura dell'anagrafe)
        $prima = (string)($conn->query("SELECT codice FROM anagrafe_strutture ORDER BY aggiunta_il, codice LIMIT 1")->fetch_assoc()['codice'] ?? '');
        if ($prima !== '' && ($solo_struttura === null || $solo_struttura === $prima) && isset($riepilogo[$prima]) && $riepilogo[$prima]['ok']) {
            $n_ins = sincronizza_insegnamenti($conn, $prima);
            $riepilogo[$prima]['esito'] .= $n_ins !== null ? ", $n_ins insegnamenti" : ', insegnamenti non disponibili';
        }

        // Chi non compare più in nessuna struttura (solo se le API hanno risposto per tutte)
        if ($tutte_ok && $solo_struttura === null) {
            $st = $conn->prepare("UPDATE personale_ateneo SET attivo = 0, uscita_il = IFNULL(uscita_il, NOW()) WHERE aggiornata_il IS NULL OR aggiornata_il < ?");
            $st->bind_param("s", $inizio); $st->execute();
            $conn->query("DELETE FROM personale_ateneo WHERE attivo = 0 AND uscita_il < NOW() - INTERVAL 12 MONTH");
            scollega_utenti_senza_persona($conn);
        }
        // Data dell'ultimo aggiornamento per il cron settimanale: se le API non hanno risposto si riprova tra un giorno
        $f_sync = RADICE_SITO . '/cache/anagrafe_sync.txt';
        if (@file_put_contents($f_sync, date('c')) !== false && !$tutte_ok) @touch($f_sync, time() - 6 * 86400);
        return $riepilogo;
    }
}

if (!function_exists('scollega_utenti_senza_persona')) {
    // Utenti collegati a una persona tolta dall'anagrafe: collegamento azzerato. Il confronto si fa in PHP
    // (le tabelle possono avere collation diverse e MySQL rifiuterebbe il confronto diretto)
    function scollega_utenti_senza_persona($conn): void {
        $ids = [];
        $r = $conn->query("SELECT id FROM personale_ateneo");
        while ($r && $x = $r->fetch_assoc()) $ids[$x['id']] = true;
        $r = $conn->query("SELECT id, persona_id FROM utenti WHERE persona_id IS NOT NULL");
        while ($r && $u = $r->fetch_assoc()) if (!isset($ids[$u['persona_id']])) $conn->query("UPDATE utenti SET persona_id = NULL WHERE id = " . (int)$u['id']);
    }
}

if (!function_exists('persona_ateneo')) {
    function persona_ateneo($conn, ?string $id): ?array {
        static $cache = [];
        $id = trim((string)$id);
        if ($id === '' || !preg_match('/^[A-Za-z0-9._\'-]{2,80}$/', $id)) return null;
        if (!array_key_exists($id, $cache)) {
            $st = $conn->prepare("SELECT * FROM personale_ateneo WHERE id = ? LIMIT 1");
            if (!$st) return null;
            $st->bind_param("s", $id); $st->execute();
            $cache[$id] = $st->get_result()->fetch_assoc() ?: null;
        }
        return $cache[$id];
    }
}

if (!function_exists('anno_accademico_corrente')) {
    // Anno accademico in corso come lo usano le API (2026 = 2026/2027): da settembre quello nuovo
    function anno_accademico_corrente(): int { return (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1; }
}

if (!function_exists('sincronizza_insegnamenti')) {
    // Insegnamenti del dipartimento $dip (API activities) tenuti nell'anno accademico in corso e nel successivo.
    // Nelle API "academic_year" è la coorte (anno di immatricolazione) e ogni coorte elenca tutto il suo percorso:
    // l'insegnamento si tiene nell'anno coorte + anno di corso - 1. Si scaricano le coorti degli ultimi 6 anni.
    // Ritorna quanti ne sono arrivati, null se le API non rispondono (restano i dati precedenti).
    function sincronizza_insegnamenti($conn, string $dip): ?int {
        $aa = anno_accademico_corrente();
        $tutti = [];
        for ($coorte = $aa - 5; $coorte <= $aa + 1; $coorte++) {
            $el = api_unical_tutte('activities/', ['department' => $dip, 'academic_year' => $coorte]);
            if ($el === null) return null;
            foreach ($el as $r) {
                $anno_c = max(1, (int)($r['StudyActivityYear'] ?? 1));
                $erog = $coorte + $anno_c - 1;
                if ($erog === $aa || $erog === $aa + 1) { $r['_coorte'] = $coorte; $r['_erog'] = $erog; $tutti[] = $r; }
            }
        }
        $up = $conn->prepare("INSERT INTO insegnamenti (id, codice, nome, cds_cod, cds_nome, anno_corso, anno_accademico, coorte, semestre, ssd_cod, ssd, lingua, docente, docente_id, partizione, padre_id, dipartimento_cod, cfu, presente, aggiornato_il)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                              ON DUPLICATE KEY UPDATE codice=VALUES(codice), cfu=VALUES(cfu), nome=VALUES(nome), cds_cod=VALUES(cds_cod), cds_nome=VALUES(cds_nome), anno_corso=VALUES(anno_corso),
                                anno_accademico=VALUES(anno_accademico), coorte=VALUES(coorte), semestre=VALUES(semestre), ssd_cod=VALUES(ssd_cod), ssd=VALUES(ssd), lingua=VALUES(lingua), docente=VALUES(docente),
                                docente_id=VALUES(docente_id), partizione=VALUES(partizione), padre_id=VALUES(padre_id), dipartimento_cod=VALUES(dipartimento_cod), presente=1, aggiornato_il=NOW()");
        $visti = [];
        foreach ($tutti as $r) {
            $id = (int)($r['StudyActivityID'] ?? 0);
            $nome = trim((string)($r['StudyActivityName'] ?? ''));
            if ($id <= 0 || $nome === '') continue;
            $cod = mb_substr((string)($r['StudyActivityCod'] ?? ''), 0, 30);
            $nome = mb_substr(maiuscole_corso($nome), 0, 255);
            $cds_cod = mb_substr((string)($r['StudyActivityCdSCod'] ?? ''), 0, 20);
            $cds_nome = mb_substr(maiuscole_corso((string)($r['StudyActivityCdSName'] ?? '')), 0, 255);
            $anno_c = (int)($r['StudyActivityYear'] ?? 0) ?: null;
            $aa_r = (int)$r['_erog']; $coorte_r = (int)$r['_coorte'];
            $sem = mb_substr((string)($r['StudyActivitySemester'] ?? ''), 0, 60);
            $ssd_cod = mb_substr((string)($r['StudyActivitySSDCod'] ?? ''), 0, 20);
            $ssd = mb_substr(maiuscole_corso((string)($r['StudyActivitySSD'] ?? '')), 0, 150);
            $lingua = mb_substr((string)($r['StudyActivityLanguage'] ?? ''), 0, 60);
            $docente = mb_substr(maiuscole_nome((string)($r['StudyActivityTeacherName'] ?? '')), 0, 150);
            $doc_id = mb_substr((string)($r['StudyActivityTeacherID'] ?? ''), 0, 30);
            $part = mb_substr(trim((string)($r['StudyActivityPartitionDes'] ?? $r['StudyActivityExtendedPartitionDes'] ?? '')), 0, 150);
            $padri = (array)($r['StudyActivityFathers'] ?? []);
            $padre = null;
            if ($padri) { $p0 = reset($padri); $padre = (int)(is_array($p0) ? ($p0['StudyActivityID'] ?? $p0['id'] ?? 0) : $p0) ?: null; }
            $dip_r = mb_substr((string)($r['DepartmentCod'] ?? $dip), 0, 20);
            $cfu = function_exists('cfu_attivita_api') ? cfu_attivita_api($r) : null;
            $up->bind_param("issssiiisssssssisd", $id, $cod, $nome, $cds_cod, $cds_nome, $anno_c, $aa_r, $coorte_r, $sem, $ssd_cod, $ssd, $lingua, $docente, $doc_id, $part, $padre, $dip_r, $cfu);
            $up->execute();
            $visti[] = $id;
        }
        // Non più presenti negli anni scaricati; quelli di anni accademici precedenti si conservano un anno
        $dip_sql = $conn->real_escape_string($dip);
        $conn->query("UPDATE insegnamenti SET presente = 0 WHERE dipartimento_cod = '$dip_sql' AND anno_accademico >= $aa" . ($visti ? " AND id NOT IN (" . implode(',', array_map('intval', $visti)) . ")" : ''));
        $conn->query("DELETE FROM insegnamenti WHERE anno_accademico < " . ($aa - 1));
        return count($visti);
    }
}

if (!function_exists('insegnamento')) {
    function insegnamento($conn, ?int $id): ?array {
        if (!$id) return null;
        $r = @$conn->query("SELECT * FROM insegnamenti WHERE id = " . (int)$id);
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }
}

if (!function_exists('etichetta_insegnamento')) {
    // "Fondamenti di informatica · 1° anno · Primo Semestre · Masciari Elio"
    function etichetta_insegnamento(array $i, bool $con_corso = false): string {
        return implode(' · ', array_filter([
            $i['nome'] . ($i['partizione'] !== '' ? ' (' . $i['partizione'] . ')' : ''),
            $con_corso ? $i['cds_nome'] : '',
            !empty($i['anno_corso']) ? (int)$i['anno_corso'] . '° anno' : '',
            $i['semestre'], $i['docente'],
        ]));
    }
}

if (!function_exists('insegnamenti_per_corso')) {
    // Insegnamenti presenti di un anno accademico, raggruppati per corso di studio
    function insegnamenti_per_corso($conn, ?int $anno = null): array {
        // Default: l'anno accademico in corso; se non ci sono ancora dati, il più recente disponibile
        if ($anno === null) {
            $anno = anno_accademico_corrente();
            if (!(int)(@$conn->query("SELECT COUNT(*) n FROM insegnamenti WHERE presente = 1 AND anno_accademico = $anno")->fetch_assoc()['n'] ?? 0))
                $anno = (int)(@$conn->query("SELECT MAX(anno_accademico) a FROM insegnamenti WHERE presente = 1")->fetch_assoc()['a'] ?? 0);
        }
        if (!$anno) return [];
        $out = [];
        $r = @$conn->query("SELECT * FROM insegnamenti WHERE presente = 1 AND anno_accademico = " . (int)$anno . " ORDER BY cds_nome, anno_corso, nome, partizione");
        while ($r && $x = $r->fetch_assoc()) $out[$x['cds_nome'] ?: 'Altri insegnamenti'][] = $x;
        return $out;
    }
}

if (!defined('CAMPI_SCHEDA_PERSONA')) define('CAMPI_SCHEDA_PERSONA', [
    'telefono' => 'Telefono', 'ufficio' => 'Ufficio', 'ricevimento' => 'Orari di ricevimento', 'bio' => 'Profilo', 'sito' => 'Sito web',
]);

if (!function_exists('modifiche_persona')) {
    // Campi della scheda modificati dalla persona (Area personale); [] se non ne ha
    function modifiche_persona($conn, ?string $id): array {
        $id = trim((string)$id);
        if ($id === '') return [];
        $st = @$conn->prepare("SELECT * FROM personale_modifiche WHERE persona_id = ? LIMIT 1");
        if (!$st) return [];
        $st->bind_param("s", $id); $st->execute();
        return $st->get_result()->fetch_assoc() ?: [];
    }
}

if (!function_exists('scheda_persona')) {
    // Scheda da mostrare: dati del portale di Ateneo con sopra le modifiche della persona (campo vuoto = dato del portale).
    // Ritorna ['valori' => […], 'portale' => […], 'modificati' => [campi]] con i campi di CAMPI_SCHEDA_PERSONA.
    function scheda_persona($conn, array $p, ?array $det = null): array {
        $det = $det ?? dettaglio_persona($conn, $p, false);
        $portale = [
            'telefono'    => !empty($det['telefoni']) ? implode(', ', $det['telefoni']) : (string)($p['telefono'] ?? ''),
            'ufficio'     => ($det['ufficio'] ?? '') !== '' ? $det['ufficio'] : (string)($p['ufficio'] ?? ''),
            'ricevimento' => (string)($det['ricevimento'] ?? ''),
            'bio'         => ($det['bio'] ?? '') !== '' ? $det['bio'] : (string)($det['cv_breve'] ?? ''),
            'sito'        => (string)($det['siti'][0] ?? ''),
        ];
        $mod = modifiche_persona($conn, $p['id'] ?? '');
        $valori = []; $modificati = [];
        foreach ($portale as $k => $v) {
            $m = trim((string)($mod[$k] ?? ''));
            if ($m !== '') { $valori[$k] = $m; $modificati[] = $k; } else $valori[$k] = $v;
        }
        return ['valori' => $valori, 'portale' => $portale, 'modificati' => $modificati];
    }
}

if (!function_exists('salva_modifiche_persona')) {
    // Salva i campi modificati dalla persona (testi ripuliti, sito solo https). Ritorna null o il messaggio d'errore.
    function salva_modifiche_persona($conn, string $id, array $post): ?string {
        $lim = ['telefono' => 60, 'ufficio' => 255, 'ricevimento' => 1000, 'bio' => 3000, 'sito' => 255];
        $v = [];
        foreach ($lim as $k => $max) $v[$k] = mb_substr(trim(strip_tags(str_replace("\r", '', (string)($post[$k] ?? '')))), 0, $max);
        if ($v['telefono'] !== '' && !preg_match('/^[0-9+().\/ ,-]{4,60}$/', $v['telefono'])) return "Il telefono può contenere solo numeri, spazi, + - / ( ) e virgole.";
        if ($v['sito'] !== '' && !preg_match('#^https://#i', $v['sito'])) $v['sito'] = 'https://' . preg_replace('#^https?://#i', '', $v['sito']);
        if ($v['sito'] !== '' && !filter_var($v['sito'], FILTER_VALIDATE_URL)) return "Il sito web non è un indirizzo valido.";
        $st = $conn->prepare("INSERT INTO personale_modifiche (persona_id, telefono, ufficio, ricevimento, bio, sito, aggiornata_il) VALUES (?, ?, ?, ?, ?, ?, NOW())
                              ON DUPLICATE KEY UPDATE telefono = VALUES(telefono), ufficio = VALUES(ufficio), ricevimento = VALUES(ricevimento),
                                                      bio = VALUES(bio), sito = VALUES(sito), aggiornata_il = NOW()");
        if (!$st) return "Salvataggio non riuscito.";
        $st->bind_param("ssssss", $id, $v['telefono'], $v['ufficio'], $v['ricevimento'], $v['bio'], $v['sito']);
        return $st->execute() ? null : "Salvataggio non riuscito.";
    }
}

if (!function_exists('nome_persona')) {
    function nome_persona(array $p): string { return trim(($p['nome'] ?? '') . ' ' . ($p['cognome'] ?? '')); }
}

if (!function_exists('url_portale_persona')) {
    // Pagina della persona sul portale di Ateneo (scheda docente o rubrica)
    function url_portale_persona(array $p): string {
        return 'https://www.unical.it/storage/' . (!empty($p['docente']) ? 'teachers' : 'addressbook') . '/' . rawurlencode((string)$p['id']) . '/';
    }
}

if (!function_exists('testo_da_html_api')) {
    // Testi HTML delle API (biografia, orari di ricevimento): solo testo, con gli a capo
    function testo_da_html_api(?string $html): string {
        $t = preg_replace('#<\s*(br|/p|/li|/div|/h\d)\s*/?>#i', "\n", (string)$html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);
        return trim(preg_replace("/\n\s*\n+/", "\n", preg_replace('/[ \t]+/', ' ', $t)));
    }
}

if (!function_exists('dettaglio_persona')) {
    // Scheda completa (foto, ORCID, ricevimento, curriculum…) dal portale, conservata 7 giorni.
    // La foto viene copiata in uploads/personale/ così le pagine non si collegano a server esterni.
    // $aggiorna = false: usa solo quello che c'è (nessuna chiamata di rete).
    function dettaglio_persona($conn, array $p, bool $aggiorna = true): array {
        $det = json_decode((string)($p['dettaglio_json'] ?? ''), true) ?: [];
        $fresco = !empty($p['dettaglio_il']) && strtotime($p['dettaglio_il']) > time() - 7 * 86400;
        if (!$aggiorna || $fresco) return $det;
        $j = api_unical_get((!empty($p['docente']) ? 'teachers/' : 'addressbook/') . rawurlencode($p['id']) . '/', [], 10);
        $r = $j['results'] ?? null;
        if (!is_array($r)) return $det; // API non raggiungibili: resta la scheda precedente
        $pulisci_url = function ($u) {
            $u = trim((string)$u);
            if (str_starts_with($u, '//')) $u = 'https:' . $u;
            return filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https://#i', $u) ? $u : '';
        };
        $nuovo = [
            'orcid'      => preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', (string)($r['ORCID'] ?? '')) ? $r['ORCID'] : '',
            'bio'        => mb_substr(testo_da_html_api($r['ShortBio'] ?? ''), 0, 3000),
            'cv_breve'   => mb_substr(testo_da_html_api($r['TeacherCVShort'] ?? ''), 0, 1500),
            'ricevimento'=> mb_substr(testo_da_html_api($r['ReceptionHours'] ?? ''), 0, 1000),
            'cv_ita'     => $pulisci_url($r['CVPathIta'] ?? ''),
            'cv_en'      => $pulisci_url($r['CVPathEn'] ?? ''),
            'siti'       => array_values(array_filter(array_map($pulisci_url, (array)($r['TeacherWebSite'] ?? $r['WebSite'] ?? [])))),
            'ufficio'    => implode(', ', (array)($r['TeacherOfficeReference'] ?? $r['OfficeReference'] ?? [])),
            'telefoni'   => array_values(array_slice((array)($r['TeacherTelOffice'] ?? $r['TelOffice'] ?? []), 0, 3)),
            'foto'       => $det['foto'] ?? '',
        ];
        // Foto: copia locale (solo immagini, max 2 MB), rinnovata con la scheda
        $url_foto = $pulisci_url($r['PhotoPath'] ?? '');
        if ($url_foto !== '' && preg_match('#^https://storage\.portale\.unical\.it/#i', $url_foto)) {
            $dati = false;
            if (function_exists('curl_init')) {
                $ch = curl_init($url_foto);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'DidatticaDiBEST/1.0']);
                $dati = curl_exec($ch);
                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $dati = false;
                curl_close($ch);
            } else $dati = @file_get_contents($url_foto, false, stream_context_create(['http' => ['timeout' => 10]]));
            $info = ($dati !== false && strlen($dati) < 2 * 1024 * 1024) ? @getimagesizefromstring($dati) : false;
            $est = $info ? (['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']] ?? '') : '';
            if ($est !== '') {
                $dir = RADICE_SITO . '/uploads/personale';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $file = preg_replace('/[^a-z0-9._-]/', '_', strtolower($p['id'])) . '.' . $est;
                if (@file_put_contents("$dir/$file", $dati) !== false) $nuovo['foto'] = "uploads/personale/$file";
            }
        } elseif ($url_foto === '') $nuovo['foto'] = '';
        $json = json_encode($nuovo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $st = $conn->prepare("UPDATE personale_ateneo SET dettaglio_json = ?, dettaglio_il = NOW() WHERE id = ?");
        $st->bind_param("ss", $json, $p['id']); $st->execute();
        return $nuovo;
    }
}

if (!function_exists('html_avatar_persona')) {
    // Foto della persona (copia locale) o una sagoma grigio chiaro
    function html_avatar_persona(?string $foto, string $alt = '', int $lato = 48, string $base = ''): string {
        if ($foto && preg_match('#^uploads/personale/[a-z0-9._-]+$#', $foto) && is_file(RADICE_SITO . '/' . $foto)) {
            return '<img src="' . htmlspecialchars($base . $foto) . '" alt="' . htmlspecialchars($alt) . '" width="' . $lato . '" height="' . $lato . '" loading="lazy" style="width:' . $lato . 'px;height:' . $lato . 'px;border-radius:50%;object-fit:cover;flex-shrink:0;background:#f1f5f9;">';
        }
        return '<svg width="' . $lato . '" height="' . $lato . '" viewBox="0 0 48 48" role="img" aria-label="' . htmlspecialchars($alt !== '' ? $alt : 'Nessuna foto') . '" style="flex-shrink:0;border-radius:50%;">'
             . '<circle cx="24" cy="24" r="24" fill="#e5e7eb"/><circle cx="24" cy="19" r="8.5" fill="#f8fafc"/><path d="M8.5 41.5c2.6-8 8.6-12 15.5-12s12.9 4 15.5 12A23.9 23.9 0 0 1 24 48a23.9 23.9 0 0 1-15.5-6.5z" fill="#f8fafc"/></svg>';
    }
}

if (!function_exists('html_referente_pubblico')) {
    // Riga "Contatti" di eventi e progetti: foto (o sagoma), ruolo, nome e recapiti. Chi è stato scelto
    // dall'anagrafe porta alla sua pagina nel portale (persona.php), gli altri al link indicato a mano.
    function html_referente_pubblico($conn, array $rf, string $col_testo): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $pers = !empty($rf['persona_id']) ? persona_ateneo($conn, $rf['persona_id']) : null;
        $det = $pers ? dettaglio_persona($conn, $pers, false) : [];
        $nome = trim((string)($rf['nome'] ?? '')) ?: ($pers ? nome_persona($pers) : '');
        $out = '<div class="pj-persona ev-persona">' . html_avatar_persona($det['foto'] ?? '', '', 48) . '<div style="min-width:0;">';
        if (!empty($rf['ruolo'])) $out .= '<div class="small text-uppercase fw-bold text-secondary" style="letter-spacing:.05em;">' . $h($rf['ruolo']) . '</div>';
        if ($nome !== '') {
            $out .= '<div class="fw-bold">';
            if ($pers) $out .= '<a href="persona.php?id=' . $h(rawurlencode($pers['id'])) . '" style="color:' . $h($col_testo) . ';">' . $h($nome) . '</a>';
            elseif (!empty($rf['link']) && preg_match('#^https?://#i', $rf['link'])) $out .= '<a href="' . $h($rf['link']) . '" target="_blank" rel="noopener" style="color:' . $h($col_testo) . ';">' . $h($nome) . ' <i class="fa fa-arrow-up-right-from-square small" aria-hidden="true"></i><span class="visually-hidden"> (pagina personale, si apre in una nuova scheda)</span></a>';
            else $out .= $h($nome);
            $out .= '</div>';
        }
        if ($pers && $pers['ruolo'] !== '' && mb_strtolower($pers['ruolo']) !== mb_strtolower((string)($rf['ruolo'] ?? ''))) $out .= '<div class="small text-secondary">' . $h($pers['ruolo']) . ($pers['ssd'] !== '' ? ' · ' . $h($pers['ssd']) : '') . '</div>';
        if (!empty($rf['email'])) $out .= '<div class="small"><i class="fa fa-envelope me-1 text-secondary" aria-hidden="true"></i><a href="mailto:' . $h($rf['email']) . '">' . $h($rf['email']) . '</a></div>';
        if (!empty($rf['telefono'])) $out .= '<div class="small"><i class="fa fa-phone me-1 text-secondary" aria-hidden="true"></i><a href="tel:' . $h(preg_replace('/[^0-9+]/', '', $rf['telefono'])) . '">' . $h($rf['telefono']) . '</a></div>';
        return $out . '</div></div>';
    }
}

if (!function_exists('html_ricerca_personale')) {
    // Pannello "Cerca nell'anagrafe di Ateneo" (pagine del pannello): filtri per gruppo, ruolo e struttura + nome.
    // Scegliendo una persona il pannello lancia l'evento "persona-scelta" con i dati (assets/js/ricerca-personale.js).
    function html_ricerca_personale($conn, string $pulsante = 'Aggiungi', string $righe = ''): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $r = $conn->query("SELECT COUNT(*) n FROM personale_ateneo WHERE attivo = 1");
        if (!$r || (int)$r->fetch_assoc()['n'] === 0) {
            return '<div class="alert alert-light border small py-2 mb-2"><i class="fa fa-address-book me-1" aria-hidden="true"></i>L\'anagrafe del personale di Ateneo è vuota: '
                 . (!empty($GLOBALS['is_full_admin']) ? 'caricala da <a href="anagrafe_personale.php?vista=strutture">Anagrafe personale</a>.' : 'chiedi a un amministratore di caricarla.') . ' Intanto puoi inserire le persone a mano.</div>';
        }
        $GLOBALS['usa_ricerca_personale'] = true;
        $id = 'rp_' . bin2hex(random_bytes(3));
        $ruoli = []; $strutture = [];
        $r = $conn->query("SELECT ruolo_cod, ruolo, COUNT(*) n FROM personale_ateneo WHERE attivo = 1 AND ruolo_cod <> '' GROUP BY ruolo_cod, ruolo ORDER BY ruolo");
        while ($r && $x = $r->fetch_assoc()) $ruoli[] = $x;
        $r = $conn->query("SELECT struttura_cod, struttura, COUNT(*) n FROM personale_ateneo WHERE attivo = 1 AND struttura_cod <> '' GROUP BY struttura_cod, struttura ORDER BY struttura");
        while ($r && $x = $r->fetch_assoc()) $strutture[] = $x;
        $out = '<div class="ricerca-personale border rounded p-2 mb-2" style="background:#f8fafc;" data-endpoint="cerca_personale.php" data-base="../" data-pulsante="' . $h($pulsante) . '"' . ($righe !== '' ? ' data-righe="' . $h($righe) . '"' : '') . '>'
             . '<div class="small fw-bold mb-1"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Cerca nell\'anagrafe di Ateneo</div>'
             . '<div class="row g-2">'
             . '<div class="col-md-3"><label class="visually-hidden" for="' . $id . 'g">Gruppo</label><select id="' . $id . 'g" class="form-select form-select-sm rp-gruppo"><option value="">Tutti i gruppi</option>';
        foreach (GRUPPI_PERSONALE as $k => $v) $out .= '<option value="' . $h($k) . '">' . $h($v) . '</option>';
        $out .= '</select></div><div class="col-md-3"><label class="visually-hidden" for="' . $id . 'r">Ruolo</label><select id="' . $id . 'r" class="form-select form-select-sm rp-ruolo"><option value="">Tutti i ruoli</option>';
        foreach ($ruoli as $x) $out .= '<option value="' . $h($x['ruolo_cod']) . '">' . $h($x['ruolo']) . ' (' . (int)$x['n'] . ')</option>';
        $out .= '</select></div><div class="col-md-3"><label class="visually-hidden" for="' . $id . 's">Struttura</label><select id="' . $id . 's" class="form-select form-select-sm rp-struttura"><option value="">Tutte le strutture</option>';
        foreach ($strutture as $x) $out .= '<option value="' . $h($x['struttura_cod']) . '">' . $h($x['struttura']) . ' (' . (int)$x['n'] . ')</option>';
        $out .= '</select></div><div class="col-md-3"><label class="visually-hidden" for="' . $id . 'q">Nome o cognome</label><input type="search" id="' . $id . 'q" class="form-control form-control-sm rp-q" placeholder="Nome o cognome" autocomplete="off"></div>'
              . '</div><div class="rp-risultati mt-2" aria-live="polite"></div></div>';
        return $out;
    }
}

if (!function_exists('cerca_personale')) {
    // Ricerca per parole (cognome, nome, email, settore) con filtri facoltativi. Prima chi è in servizio.
    function cerca_personale($conn, string $q, string $gruppo = '', string $ruolo = '', string $struttura = '', int $limite = 20): array {
        $where = ['1=1']; $tipi = ''; $par = [];
        foreach (array_slice(array_filter(explode(' ', trim(preg_replace('/\s+/u', ' ', $q))), fn($x) => mb_strlen($x) >= 2), 0, 5) as $w) {
            $like = '%' . addcslashes($w, '%_\\') . '%';
            $where[] = "(cognome LIKE ? OR nome LIKE ? OR email LIKE ? OR ssd LIKE ?)";
            array_push($par, $like, $like, $like, $like); $tipi .= 'ssss';
        }
        if (isset(GRUPPI_PERSONALE[$gruppo])) { $where[] = "gruppo = ?"; $par[] = $gruppo; $tipi .= 's'; }
        if ($ruolo !== '') { $where[] = "ruolo_cod = ?"; $par[] = $ruolo; $tipi .= 's'; }
        if ($struttura !== '') { $where[] = "struttura_cod = ?"; $par[] = $struttura; $tipi .= 's'; }
        if (count($par) === 0) return [];
        $st = $conn->prepare("SELECT * FROM personale_ateneo WHERE " . implode(' AND ', $where) . " ORDER BY attivo DESC, cognome, nome LIMIT " . max(1, min(50, $limite)));
        if (!$st) return [];
        $st->bind_param($tipi, ...$par); $st->execute();
        $out = []; $r = $st->get_result();
        while ($r && $p = $r->fetch_assoc()) {
            $det = json_decode((string)($p['dettaglio_json'] ?? ''), true) ?: [];
            $tel_mod = trim((string)(modifiche_persona($conn, $p['id'])['telefono'] ?? ''));
            $out[] = ['id' => $p['id'], 'nome' => nome_persona($p), 'cognome' => $p['cognome'], 'email' => $p['email'], 'telefono' => $tel_mod !== '' ? $tel_mod : $p['telefono'],
                      'ruolo' => $p['ruolo'], 'struttura' => $p['struttura'], 'ssd' => $p['ssd'], 'gruppo' => GRUPPI_PERSONALE[$p['gruppo']] ?? '',
                      'attivo' => (int)$p['attivo'], 'link' => url_portale_persona($p), 'foto' => $det['foto'] ?? ''];
        }
        return $out;
    }
}

if (!function_exists('ids_gruppi_personale')) {
    // id dei gruppi (tabella ruoli) Docenti / PTA / Altro, per chiave
    function ids_gruppi_personale($conn): array {
        static $ids = null;
        if ($ids !== null) return $ids;
        $ids = [];
        $res = $conn->query("SELECT id, nome FROM ruoli");
        while ($res && $r = $res->fetch_assoc()) { $k = array_search($r['nome'], GRUPPI_PERSONALE, true); if ($k !== false) $ids[$k] = (int)$r['id']; }
        return $ids;
    }
}

if (!function_exists('assegna_permessi_gestore')) {
    // Abilita un utente su un'area: su tutta l'area ($eventi_ids vuoto) o solo su alcuni eventi.
    // Toglie prima le abilitazioni precedenti dell'utente in quell'area.
    function assegna_permessi_gestore($conn, int $pagina_id, int $uid, array $permessi, array $eventi_ids = []): void {
        $permessi = array_values(array_intersect($permessi, ['eventi', 'iscritti', 'sondaggi', 'form', 'full']));
        if ($uid <= 0 || $pagina_id <= 0 || !$permessi) return;
        revoca_permessi_gestore($conn, $pagina_id, $uid);
        if (!$eventi_ids) {
            $r = $conn->query("SELECT permessi_gestori_json FROM pagine_eventi WHERE id = $pagina_id LIMIT 1");
            $pj = ($r && $row = $r->fetch_assoc()) ? (json_decode($row['permessi_gestori_json'] ?: '{}', true) ?: []) : [];
            $pj[$uid] = $permessi;
            $conn->query("UPDATE pagine_eventi SET permessi_gestori_json = '" . $conn->real_escape_string(json_encode($pj)) . "' WHERE id = $pagina_id");
            return;
        }
        foreach ($eventi_ids as $e_id) {
            $e_id = (int)$e_id;
            $r = $conn->query("SELECT permessi_gestori_json FROM eventi WHERE id = $e_id AND pagina_id = $pagina_id LIMIT 1");
            if (!$r || !($row = $r->fetch_assoc())) continue;
            $ej = json_decode($row['permessi_gestori_json'] ?: '{}', true) ?: [];
            $ej[$uid] = $permessi;
            $conn->query("UPDATE eventi SET permessi_gestori_json = '" . $conn->real_escape_string(json_encode($ej)) . "' WHERE id = $e_id");
        }
    }
}

if (!function_exists('applica_abilitazione')) {
    // Abilita un utente a un perimetro: 'area' (tutta l'area), 'attivita' ($eventi_ids dell'area),
    // 'progetti' / 'eventi' (tutte le attività di quel tipo dell'area), 'fsl', 'fsl_convenzioni', 'fsl_scuole'.
    // Dentro il perimetro l'utente gestisce tutto (le vecchie sezioni separate non si usano più).
    function applica_abilitazione($conn, int $uid, string $ambito, int $pagina_id, array $eventi_ids = [], int $da = 0): bool {
        if ($ambito === 'area' && $eventi_ids) $ambito = 'attivita'; // abilitazioni in attesa salvate prima dei perimetri
        if ($ambito === 'area') { assegna_permessi_gestore($conn, $pagina_id, $uid, ['full']); return true; }
        if ($ambito === 'attivita') {
            if (!$eventi_ids) return false;
            // Si aggiungono alle attività già assegnate nell'area (senza toglierle)
            foreach ($eventi_ids as $e_id) {
                $e_id = (int)$e_id;
                $r = $conn->query("SELECT permessi_gestori_json FROM eventi WHERE id = $e_id AND pagina_id = $pagina_id LIMIT 1");
                if (!$r || !($row = $r->fetch_assoc())) continue;
                $ej = json_decode($row['permessi_gestori_json'] ?: '{}', true) ?: [];
                $ej[$uid] = ['full'];
                $conn->query("UPDATE eventi SET permessi_gestori_json = '" . $conn->real_escape_string(json_encode($ej)) . "' WHERE id = $e_id");
            }
            return true;
        }
        return assegna_ambito($conn, $uid, $ambito, $pagina_id, $da);
    }
}

if (!function_exists('revoca_permessi_gestore')) {
    function revoca_permessi_gestore($conn, int $pagina_id, int $uid): void {
        $r = $conn->query("SELECT permessi_gestori_json, gestori_utenti_ids FROM pagine_eventi WHERE id = $pagina_id LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) {
            $pj = json_decode($row['permessi_gestori_json'] ?: '{}', true) ?: [];
            unset($pj[$uid]);
            $csv = array_diff(array_filter(array_map('trim', explode(',', $row['gestori_utenti_ids'] ?? ''))), [(string)$uid]);
            $conn->query("UPDATE pagine_eventi SET permessi_gestori_json = '" . $conn->real_escape_string(json_encode($pj)) . "', gestori_utenti_ids = '" . $conn->real_escape_string(implode(',', $csv)) . "' WHERE id = $pagina_id");
        }
        $r = $conn->query("SELECT id, permessi_gestori_json, gestori_utenti_ids FROM eventi WHERE pagina_id = $pagina_id");
        while ($r && $row = $r->fetch_assoc()) {
            $ej = json_decode($row['permessi_gestori_json'] ?: '{}', true) ?: [];
            $csv = array_filter(array_map('trim', explode(',', $row['gestori_utenti_ids'] ?? '')));
            if (!isset($ej[$uid]) && !in_array((string)$uid, $csv, true)) continue;
            unset($ej[$uid]);
            $csv = array_diff($csv, [(string)$uid]);
            $conn->query("UPDATE eventi SET permessi_gestori_json = '" . $conn->real_escape_string(json_encode($ej)) . "', gestori_utenti_ids = '" . $conn->real_escape_string(implode(',', $csv)) . "' WHERE id = " . (int)$row['id']);
        }
    }
}

if (!function_exists('collega_utente_anagrafe')) {
    // Al login: collega l'utente alla persona dell'anagrafe con la stessa email (quella dell'SSO o del profilo),
    // aggiorna il gruppo Docenti / PTA / Altro tra i gruppi secondari e attiva le abilitazioni in attesa per
    // quell'email. Ritorna i gruppi secondari aggiornati (per la sessione) o null se l'utente non esiste.
    function collega_utente_anagrafe($conn, int $uid, string $email_sso = ''): ?string {
        try {
            $r = $conn->query("SELECT id, email, ruoli_secondari, persona_id FROM utenti WHERE id = $uid LIMIT 1");
            $u = $r ? $r->fetch_assoc() : null;
            if (!$u) return null;
            $email_list = array_values(array_unique(array_filter([strtolower(trim($email_sso)), strtolower(trim((string)$u['email']))],
                                        fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
            $persona = null;
            foreach ($email_list as $e) {
                $st = $conn->prepare("SELECT id, gruppo FROM personale_ateneo WHERE email = ? ORDER BY attivo DESC LIMIT 1");
                $st->bind_param("s", $e); $st->execute();
                if ($persona = $st->get_result()->fetch_assoc()) break;
            }
            $pid = $persona['id'] ?? null;
            if ($pid !== ($u['persona_id'] ?? null)) {
                $st = $conn->prepare("UPDATE utenti SET persona_id = ? WHERE id = ?");
                $st->bind_param("si", $pid, $uid); $st->execute();
            }
            // Gruppo automatico: tolti quelli dell'anagrafe, aggiunto quello attuale
            $ids_g = ids_gruppi_personale($conn);
            $sec = array_values(array_filter(explode(',', (string)$u['ruoli_secondari']), fn($x) => $x !== '' && !in_array((int)$x, $ids_g, true)));
            if ($persona && isset($ids_g[$persona['gruppo']])) $sec[] = (string)$ids_g[$persona['gruppo']];
            $sec_str = implode(',', array_unique($sec));
            if ($sec_str !== (string)$u['ruoli_secondari']) {
                $st = $conn->prepare("UPDATE utenti SET ruoli_secondari = ? WHERE id = ?");
                $st->bind_param("si", $sec_str, $uid); $st->execute();
            }
            // Abilitazioni date prima del primo accesso
            foreach ($email_list as $e) {
                $st = $conn->prepare("SELECT * FROM abilitazioni_attesa WHERE email = ?");
                $st->bind_param("s", $e); $st->execute();
                $res_a = $st->get_result();
                while ($res_a && $a = $res_a->fetch_assoc()) {
                    applica_abilitazione($conn, $uid, (string)($a['ambito'] ?? 'area'), (int)$a['pagina_id'], array_filter(array_map('intval', explode(',', (string)$a['eventi_ids']))), (int)($a['creata_da'] ?? 0));
                    $conn->query("DELETE FROM abilitazioni_attesa WHERE id = " . (int)$a['id']);
                    registra_log_audit($conn, "Attivata abilitazione in attesa", ["Utente" => $uid, "Email" => $e, "Area" => (int)$a['pagina_id']]);
                }
            }
            return $sec_str;
        } catch (\Throwable $e) {
            error_log('[anagrafe] collega_utente_anagrafe: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('corsi_studio_visibili')) {
    // Corsi proposti nel campo "Corso di studio", raggruppati per tipo (Laurea, Laurea Magistrale…)
    function corsi_studio_visibili($conn): array {
        static $out = null;
        if ($out !== null) return $out;
        $out = [];
        $ordine = ['L' => 1, 'LM' => 2, 'LM5' => 3, 'LM6' => 3, 'FI' => 5];
        $res = $conn->query("SELECT codice, nome, tipo, tipo_descrizione FROM corsi_studio WHERE visibile = 1 AND presente = 1 ORDER BY nome");
        while ($res && $c = $res->fetch_assoc()) $out[$c['tipo_descrizione'] ?: 'Altri corsi'][] = $c + ['_o' => $ordine[$c['tipo']] ?? 4];
        uasort($out, fn($a, $b) => $a[0]['_o'] <=> $b[0]['_o']);
        return $out;
    }
}

if (!function_exists('corso_studio')) {
    function corso_studio($conn, ?string $codice): ?array {
        static $cache = [];
        $codice = trim((string)$codice);
        if ($codice === '' || strlen($codice) > 20) return null;
        if (!array_key_exists($codice, $cache)) {
            $st = $conn->prepare("SELECT * FROM corsi_studio WHERE codice = ? LIMIT 1");
            if (!$st) return null;
            $st->bind_param("s", $codice); $st->execute();
            $cache[$codice] = $st->get_result()->fetch_assoc() ?: null;
        }
        return $cache[$codice];
    }
}

if (!function_exists('url_corso_studio')) {
    // Pagina del corso sul portale di Ateneo (serve l'ID del regolamento didattico, arriva con l'aggiornamento dell'anagrafe)
    function url_corso_studio(?array $c): string {
        return !empty($c['regdid_id']) ? 'https://www.unical.it/storage/cds/' . (int)$c['regdid_id'] . '/' : '';
    }
}

if (!function_exists('nome_scheda_corso')) {
    // Nome proposto nella scheda: "Corso di laurea in Biologia", "Corso di laurea magistrale in …", altrimenti il nome del corso
    function nome_scheda_corso(array $c): string {
        $pref = ['L' => 'Corso di laurea in ', 'LM' => 'Corso di laurea magistrale in ', 'LM5' => 'Corso di laurea magistrale a ciclo unico in ', 'LM6' => 'Corso di laurea magistrale a ciclo unico in '][$c['tipo'] ?? ''] ?? '';
        return $pref . $c['nome'];
    }
}

if (!function_exists('html_scelta_corso_scheda')) {
    // Scheda di progetti ed eventi: tendina dei corsi di studio + testo libero (name=struttura). Scegliendo un corso
    // il testo si compila con il nome, che resta modificabile; "Altro" lascia scrivere una struttura qualsiasi.
    function html_scelta_corso_scheda($conn, string $codice, string $testo, string $id = 'schedaCorso'): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $out = '<select name="corso_codice" id="' . $h($id) . '" class="form-select form-select-sm mb-1" onchange="var o=this.options[this.selectedIndex], t=this.nextElementSibling; if (o.dataset.nome) t.value=o.dataset.nome;">'
              . '<option value="">Altro (scrivi sotto la struttura)</option>';
        $trovato = $codice === '';
        foreach (corsi_studio_visibili($conn) as $tipo => $corsi) {
            $out .= '<optgroup label="' . $h($tipo) . '">';
            foreach ($corsi as $c) {
                $sel = $c['codice'] === $codice; if ($sel) $trovato = true;
                $out .= '<option value="' . $h($c['codice']) . '" data-nome="' . $h(nome_scheda_corso($c)) . '"' . ($sel ? ' selected' : '') . '>' . $h($c['nome']) . '</option>';
            }
            $out .= '</optgroup>';
        }
        if (!$trovato && ($c = corso_studio($conn, $codice))) $out .= '<option value="' . $h($c['codice']) . '" selected>' . $h(etichetta_corso($c)) . '</option>';
        $out .= '</select><input type="text" name="struttura" id="' . $h($id) . 'Testo" class="form-control form-control-sm" value="' . $h($testo) . '" placeholder="es. Corso di laurea in Scienze geologiche" maxlength="255" aria-label="Nome del corso o della struttura come appare nella scheda">'
              . '<div class="form-text">Scegliendo un corso il nome nella scheda pubblica porta alla pagina del corso sul portale di Ateneo. Il testo si può modificare.</div>';
        return $out;
    }
}

if (!function_exists('completa_regdid_corso')) {
    // Corsi salvati prima che l'anagrafe leggesse l'ID del regolamento (serve per il link alla pagina del corso):
    // lo si chiede alle API del portale per il dipartimento del corso, al massimo una volta ogni 6 ore.
    function completa_regdid_corso($conn, array $corso): array {
        $dip = preg_replace('/[^0-9A-Za-z]/', '', (string)($corso['dipartimento_cod'] ?? ''));
        if ($dip === '' || !function_exists('api_unical_tutte')) return $corso;
        $segno = RADICE_SITO . '/cache/regdid_' . $dip . '.try';
        if (is_file($segno) && filemtime($segno) > time() - 6 * 3600) return $corso;
        @touch($segno);
        $cds = api_unical_tutte('cds/', ['departmentcod' => $dip]);
        if (!$cds) return $corso;
        $up = $conn->prepare("UPDATE corsi_studio SET regdid_id = ? WHERE codice = ? AND (regdid_id IS NULL OR regdid_id = 0)");
        usort($cds, fn($a, $b) => (int)($b['AcademicYear'] ?? 0) <=> (int)($a['AcademicYear'] ?? 0)); // il più recente per primo
        $fatti = [];
        foreach ($cds as $c) {
            $cc = trim((string)($c['CdSCod'] ?? '')); $rd = (int)($c['RegDidId'] ?? 0);
            if ($cc === '' || !$rd || isset($fatti[$cc])) continue;
            $fatti[$cc] = $rd;
            $up->bind_param("is", $rd, $cc); $up->execute();
        }
        if (isset($fatti[$corso['codice']])) $corso['regdid_id'] = $fatti[$corso['codice']];
        return $corso;
    }
}

if (!function_exists('html_corso_pubblico')) {
    // Nome del corso/struttura nelle schede pubbliche, con il link alla pagina del corso se scelto dall'anagrafe
    function html_corso_pubblico($conn, ?array $d, string $stile = ''): string {
        $testo = trim((string)($d['struttura'] ?? ''));
        $corso = corso_studio($conn, $d['corso_codice'] ?? '');
        if ($corso && empty($corso['regdid_id'])) $corso = completa_regdid_corso($conn, $corso);
        $url = url_corso_studio($corso);
        if ($testo === '' && $url !== '') $testo = etichetta_corso(corso_studio($conn, $d['corso_codice']));
        if ($testo === '') return '';
        $h = htmlspecialchars($testo, ENT_QUOTES, 'UTF-8');
        return $url !== '' ? '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" style="color:inherit;' . $stile . '">' . $h . ' <i class="fa fa-arrow-up-right-from-square small" aria-hidden="true"></i><span class="visually-hidden"> (pagina del corso, nuova scheda)</span></a>' : $h;
    }
}

if (!function_exists('etichetta_corso')) {
    function etichetta_corso(array $c): string {
        return $c['nome'] . (!empty($c['tipo_descrizione']) ? ' (' . $c['tipo_descrizione'] . ')' : '');
    }
}

if (!function_exists('html_campo_corso')) {
    // Campo "Corso di studio": tendina con i corsi dei dipartimenti dell'anagrafe. Si salva il nome del corso
    // con il tipo, es. "Scienze geologiche (Laurea)". Un valore salvato che non è più in elenco resta selezionabile.
    function html_campo_corso($conn, string $campo, string $valore = '', string $attr = '', string $classi = 'form-select form-select-sm', string $id = ''): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $out = '<select name="custom_' . $h($campo) . '"' . ($id !== '' ? ' id="' . $h($id) . '"' : '') . ' class="' . $h($classi) . '" ' . $attr . '><option value="">-- Scegli il corso --</option>';
        $trovato = $valore === '';
        foreach (corsi_studio_visibili($conn) as $tipo => $corsi) {
            $out .= '<optgroup label="' . $h($tipo) . '">';
            foreach ($corsi as $c) {
                $et = etichetta_corso($c);
                $sel = $et === $valore; if ($sel) $trovato = true;
                $out .= '<option value="' . $h($et) . '"' . ($sel ? ' selected' : '') . '>' . $h($c['nome']) . '</option>';
            }
            $out .= '</optgroup>';
        }
        if (!$trovato) $out .= '<option value="' . $h($valore) . '" selected>' . $h($valore) . '</option>';
        return $out . '</select>';
    }
}

if (!function_exists('avvisi_anagrafe')) {
    // Gestori e referenti che non sono più nell'anagrafe di Ateneo (cessati o trasferiti): per la dashboard
    function avvisi_anagrafe($conn): array {
        $out = ['gestori' => [], 'referenti' => []];
        $r = $conn->query("SELECT COUNT(*) n FROM personale_ateneo");
        if (!$r || (int)$r->fetch_assoc()['n'] === 0) return $out; // anagrafe mai sincronizzata
        $usciti = [];
        $r = $conn->query("SELECT id, cognome, nome, uscita_il FROM personale_ateneo WHERE attivo = 0");
        while ($r && $p = $r->fetch_assoc()) $usciti[$p['id']] = $p;
        // Gestori: utenti abilitati su un'area o un evento collegati a una persona uscita
        $ids_g = [];
        $r = $conn->query("SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi");
        while ($r && $x = $r->fetch_assoc()) foreach (ids_gestori_da_campi($x['gestore_utente_id'], $x['gestori_utenti_ids'], $x['permessi_gestori_json']) as $i) $ids_g[$i] = true;
        $r = $conn->query("SELECT gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE archiviato = 0");
        while ($r && $x = $r->fetch_assoc()) foreach (ids_gestori_da_campi(0, $x['gestori_utenti_ids'], $x['permessi_gestori_json']) as $i) $ids_g[$i] = true;
        $r = @$conn->query("SELECT DISTINCT utente_id FROM abilitazioni_ambito");
        while ($r && $x = $r->fetch_assoc()) $ids_g[(int)$x['utente_id']] = true;
        if ($ids_g && $usciti) {
            $r = $conn->query("SELECT id, nome, cognome, persona_id FROM utenti WHERE id IN (" . implode(',', array_map('intval', array_keys($ids_g))) . ") AND persona_id IS NOT NULL");
            while ($r && $u = $r->fetch_assoc()) if (isset($usciti[$u['persona_id']])) $out['gestori'][] = $u + ['uscita_il' => $usciti[$u['persona_id']]['uscita_il']];
        }
        // Referenti di eventi e progetti non archiviati scelti dall'anagrafe
        $r = $conn->query("SELECT e.id, e.titolo, e.tipo, e.pagina_id, d.referenti_json FROM progetti_dettagli d JOIN eventi e ON e.id = d.evento_id WHERE e.archiviato = 0 AND d.referenti_json LIKE '%persona_id%'");
        while ($r && $x = $r->fetch_assoc()) {
            foreach (json_decode((string)$x['referenti_json'], true) ?: [] as $rf) {
                $pid = (string)($rf['persona_id'] ?? '');
                if ($pid === '') continue;
                if (isset($usciti[$pid]) || !persona_ateneo($conn, $pid)) $out['referenti'][] = ['nome' => $rf['nome'] ?? $pid, 'evento_id' => (int)$x['id'], 'titolo' => $x['titolo'], 'tipo' => $x['tipo'], 'pagina_id' => (int)$x['pagina_id']];
            }
        }
        return $out;
    }
}

if (!function_exists('assicura_campi_progetto')) {
    // Il modulo di iscrizione dei progetti per le scuole chiede il numero di partecipanti: campo dell'area,
    // creato se manca, mostrato SOLO nei progetti dedicati alle scuole (vedi campo_form_visibile).
    // Gli altri campi si gestiscono dal Form Builder.
    function assicura_campi_progetto($conn, int $pagina_id): void {
        $nome = CAMPO_PARTECIPANTI;
        $stmt = $conn->prepare("SELECT 1 FROM campi_form WHERE pagina_id = ? AND nome_campo = ? LIMIT 1");
        $stmt->bind_param("is", $pagina_id, $nome);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) return;
        $ins = $conn->prepare("INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, tipo_campo, opzioni_select, obbligatorio, ordine) VALUES (?, NULL, ?, 'Numero di partecipanti', 'number', '', 1, -20)");
        $ins->bind_param("is", $pagina_id, $nome);
        $ins->execute();
    }
}
