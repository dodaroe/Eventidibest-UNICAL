<?php
// didattica_moduli.php - Moduli e documenti: documenti da scaricare e moduli online (campi, logica, iter, verbale), modelli pronti.
// Scheda del pannello Didattica: si apre da didattica.php?tab=moduli (stesso indirizzo di sempre), mai direttamente.
// $fase = 'azioni': gestisce i POST della scheda (ognuno risponde con un rimando e termina); $fase = 'vista': la pagina.
if (!defined('DIDATTICA_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }

if ($fase === 'azioni') {
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
        $n = (int)db_valore($conn, "SELECT COUNT(*) FROM pratiche WHERE modulo_id = ?", [$id]);
        if ($n > 0) {
            db_esegui($conn, "UPDATE didattica_moduli SET attivo = 0 WHERE id = ?", [$id]);
            flash_set("Il modulo ha $n pratiche: non l'ho eliminato ma nascosto agli studenti.", 'warning');
        } else {
            $m = modulo_didattica($conn, $id);
            if ($m && $m['file_path'] && is_file(RADICE_SITO . '/' . $m['file_path'])) @unlink(RADICE_SITO . '/' . $m['file_path']);
            db_esegui($conn, "DELETE FROM didattica_moduli WHERE id = ?", [$id]);
            registra_log_audit($conn, "Modulo della didattica eliminato", ["ID" => $id]);
            flash_set("Modulo eliminato.", 'warning');
        }
        admin_redirect("$base&tab=moduli");
    }

    return;
}
?>
<?php // ── MODULI E DOCUMENTI ──
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
