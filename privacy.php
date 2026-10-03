<?php
// privacy.php - Informativa sul trattamento dei dati personali (art. 13 GDPR) e sui cookie del portale.
// Le durate di conservazione sono lette dal file .env e dal codice: la pagina descrive ciò che il sistema fa davvero.
// Testo da far validare al Responsabile della Protezione dei Dati dell'Ateneo (rpd@unical.it).
require_once 'config.php';
require_once 'functions.php';

$mesi_log    = max(1, (int)(env_valore('CONSERVAZIONE_LOG_MESI') ?? 12));
$mesi_audit  = max(1, (int)(env_valore('CONSERVAZIONE_AUDIT_MESI') ?? 24));
$mesi_pren   = max(0, (int)(env_valore('CONSERVAZIONE_PRENOTAZIONI_MESI') ?? 0));
$mesi_utenti = max(0, (int)(env_valore('CONSERVAZIONE_UTENTI_MESI') ?? 0));
$mesi_inc    = max(0, (int)(env_valore('CONSERVAZIONE_INCARICHI_MESI') ?? 0));
$mesi_conv   = max(0, (int)(env_valore('CONSERVAZIONE_CONVOCAZIONI_MESI') ?? 12));
$giorni_nas  = max(1, (int)(env_valore('BACKUP_NAS_GIORNI') ?? 30));
$mesi_stud   = (int)MESI_CONSERVAZIONE_STUDENTI;
$aggiornata  = date('d/m/Y', filemtime(__FILE__));

$page_cfg['titolo'] = "Privacy e cookie";
require_once 'header.php';
?>
<style>
.pv h2 { font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: .75rem; scroll-margin-top: 90px; }
.pv h3 { font-size: 1.05rem; font-weight: 700; margin-top: 1.1rem; }
.pv p, .pv li { line-height: 1.6; }
.pv .indice a { text-decoration: none; }
.pv table { font-size: .92rem; }
</style>
<div class="container my-4 pv" style="max-width: 900px;">
    <h1 class="fw-bold mb-1"><i class="fa fa-user-shield me-2 text-danger" aria-hidden="true"></i>Privacy e cookie</h1>
    <p class="text-secondary">Informativa sul trattamento dei dati personali del portale <strong>Didattica DiBEST</strong> (art. 13 del Regolamento UE 2016/679) · aggiornata al <?php echo $aggiornata; ?></p>

    <nav class="indice card border-0 bg-light p-3 mb-4" aria-label="Indice">
        <ol class="mb-0 small">
            <li><a href="#titolare">Titolare e Responsabile della protezione dei dati</a></li>
            <li><a href="#dati">Quali dati trattiamo</a></li>
            <li><a href="#finalita">Perché li trattiamo e base giuridica</a></li>
            <li><a href="#destinatari">Chi può vedere i dati</a></li>
            <li><a href="#conservazione">Per quanto tempo li conserviamo</a></li>
            <li><a href="#diritti">Esercizio dei diritti degli interessati</a></li>
            <li><a href="#cookie">Cookie e altri strumenti</a></li>
        </ol>
    </nav>

    <h2 id="titolare">1. Titolare e Responsabile della protezione dei dati</h2>
    <p><strong>Titolare del trattamento</strong> è l'Università della Calabria, via Pietro Bucci, 87036 Arcavacata di Rende (CS), tel. 0984 4911. Il portale è gestito dal Dipartimento di Biologia, Ecologia e Scienze della Terra (DiBEST).</p>
    <p><strong>Responsabile della Protezione dei Dati (RPD)</strong>: via Pietro Bucci, Cubo 7/11, 87036 Arcavacata di Rende (CS) · email <a href="mailto:rpd@unical.it">rpd@unical.it</a> · PEC <a href="mailto:rpd@pec.unical.it">rpd@pec.unical.it</a> · tel. 0984 493918.</p>

    <h2 id="dati">2. Quali dati trattiamo</h2>
    <h3>Chi visita il portale</h3>
    <p>Dati tecnici necessari al funzionamento e alla sicurezza (indirizzo IP, data e ora, pagina richiesta). Per limitare i tentativi ripetuti l'indirizzo IP viene salvato solo in forma cifrata (hash) e cancellato dopo un'ora. Non usiamo strumenti di analisi o di profilazione.</p>
    <h3>Chi accede con SPID, CIE o credenziali Unical</h3>
    <p>Dal sistema di autenticazione riceviamo nome, cognome, codice fiscale, indirizzo email e, se presenti, matricola di studente o di dipendente. Registriamo la data dell'ultimo accesso e, in un registro degli accessi, data, indirizzo IP e tipo di browser.</p>
    <h3>Chi si prenota o iscrive</h3>
    <p>Nome, cognome, email, matricola (se richiesta), le risposte ai campi aggiuntivi del modulo dell'attività e gli eventuali allegati, la presenza registrata con il check-in, i messaggi scambiati con la segreteria e, se compilati, le risposte ai questionari di gradimento. Per chi si prenota senza accesso, il modulo contiene una domanda di controllo anti-robot gestita interamente dal portale.</p>
    <h3>Scuole (Formazione Scuola Lavoro, OpenLab e attività per le classi)</h3>
    <p>Il docente referente fornisce i propri dati, il numero di studenti e, se l'attività prevede gli attestati, <strong>cognome e nome degli studenti</strong>. Non raccogliamo altri dati degli studenti. È la scuola a informare studenti e famiglie della comunicazione dei nominativi all'Università per il rilascio degli attestati.</p>
    <p>Per le attività di Formazione Scuola Lavoro il Dipartimento conserva le <strong>convenzioni</strong> stipulate con le scuole e i relativi Allegati A, con il periodo di validità e nome ed email dei <strong>docenti di riferimento</strong> indicati dalla scuola: servono a verificare che ogni attività sia coperta da una convenzione valida e sono consultabili solo dagli amministratori del portale.</p>
    <p>A fine attività il docente può compilare la <strong>scheda di valutazione della struttura ospitante</strong> prevista dalla convenzione: le risposte sono collegate alla prenotazione e alla scuola, con il nome di chi la compila, e servono al Dipartimento per valutare e migliorare i percorsi.</p>

    <h3>Personale dell'Ateneo</h3>
    <p>Per docenti e personale delle strutture che organizzano le attività il portale riprende dal portale pubblico dell'Università della Calabria nome, ruolo, struttura, settore disciplinare, recapiti di ufficio e, per chi è indicato come referente di un'attività, foto, curriculum e orari di ricevimento già pubblicati dall'Ateneo. Servono a indicare i referenti, a riconoscere al login il personale (per email) e a gestire le abilitazioni. I dati si aggiornano ogni settimana e chi non compare più nel portale di Ateneo viene cancellato dopo 12 mesi.</p>

    <h2 id="finalita">3. Perché li trattiamo e base giuridica</h2>
    <ul>
        <li>gestire prenotazioni, iscrizioni e liste d'attesa;</li>
        <li>inviare comunicazioni di servizio (conferme, promemoria, variazioni, posti liberati);</li>
        <li>registrare le presenze e rilasciare gli <strong>attestati di partecipazione</strong>, verificabili con il codice stampato;</li>
        <li>produrre statistiche in forma aggregata sulle attività;</li>
        <li>garantire la sicurezza del portale e prevenire usi impropri.</li>
    </ul>
    <p>Il trattamento è necessario per lo svolgimento dei <strong>compiti di interesse pubblico</strong> dell'Ateneo, come didattica, orientamento e attività per le scuole e il territorio (art. 6, par. 1, lett. e, del Regolamento). Non si basa sul consenso. Il conferimento dei dati richiesti è necessario per prenotarsi: senza, la prenotazione non può essere registrata. Non effettuiamo decisioni automatizzate né profilazione e non usiamo i dati a fini commerciali.</p>

    <h2 id="destinatari">4. Chi può vedere i dati</h2>
    <ul>
        <li>il personale dell'Ateneo autorizzato a gestire l'area o l'attività (gestori e segreteria), ciascuno solo per le attività di sua competenza;</li>
        <li>i referenti indicati per l'attività, che ricevono il riepilogo delle prenotazioni;</li>
        <li>il docente che ha prenotato per la classe, per l'elenco dei propri studenti e i relativi attestati;</li>
        <li>chi possiede il codice di un attestato può verificarne la validità: vede nome, attività e periodo.</li>
    </ul>
    <p>I dati sono conservati sui server dell'Ateneo e le email partono dal servizio di posta dell'Ateneo. Non vengono diffusi né trasferiti fuori dall'Unione Europea.</p>

    <h2 id="conservazione">5. Per quanto tempo li conserviamo</h2>
    <ul>
        <li><strong>Registro degli accessi</strong> (IP e browser) e <strong>registro delle email inviate</strong>: <?php echo $mesi_log; ?> mesi, poi cancellati automaticamente.</li>
        <li><strong>Registro delle operazioni amministrative</strong>: <?php echo $mesi_audit; ?> mesi.</li>
        <li><strong>Nomi degli studenti</strong> forniti dalle scuole: ridotti alle iniziali <?php echo $mesi_stud; ?> mesi dopo la fine dell'attività; cancellati subito se l'iscrizione viene annullata prima dell'emissione degli attestati. I codici degli attestati restano verificabili.</li>
        <li><strong>Prenotazioni</strong>: <?php echo $mesi_pren > 0
            ? $mesi_pren . " mesi dopo l'attività nome e cognome vengono ridotti alle iniziali ed email, matricola, risposte al modulo, allegati e messaggi vengono cancellati. Restano, in forma non identificativa, i dati per le statistiche e per la verifica degli attestati."
            : "per il tempo necessario alla gestione delle attività, al rilascio e alla verifica degli attestati e agli obblighi di documentazione dell'Ateneo."; ?></li>
        <li><strong>Lettere di incarico di tutorato</strong> (dati del vincitore, conferma con SPID/CIE, firme, registro delle attività): <?php echo $mesi_inc > 0 ? "$mesi_inc mesi dopo il protocollo restano solo nome, ore, compenso e numeri di protocollo; dati personali, PDF e registro vengono cancellati (gli originali firmati sono nel protocollo di Ateneo)." : "per il tempo necessario agli adempimenti amministrativi e contabili dell'incarico; gli originali firmati sono conservati nel protocollo di Ateneo."; ?></li>
        <li><strong>Convocazioni delle sedute dei consigli</strong> (link personali e motivi delle assenze giustificate): <?php echo $mesi_conv; ?> mesi dopo la seduta; le presenze restano nel verbale.</li>
        <li><strong>Account</strong>: <?php echo $mesi_utenti > 0 ? "eliminati dopo $mesi_utenti mesi senza accessi." : "finché l'utente utilizza il servizio; su richiesta vengono eliminati."; ?></li>
        <li><strong>Copie di sicurezza</strong> (backup cifrati): 7 giorni sul server e <?php echo $giorni_nas; ?> giorni sull'archivio dell'Ateneo.</li>
    </ul>

    <h2 id="diritti">6. Esercizio dei diritti degli interessati</h2>
    <p>Fatte salve le limitazioni all’esercizio dei diritti degli interessati di cui agli artt. 2-undecies e 2-duodecies del d.lgs. 196/2003 s.m.i. (“Codice in materia di protezione dei dati personali”), l’interessato può esercitare i propri diritti ai sensi e nei limiti degli artt. 15-21 RGPD, tra cui il diritto di chiedere al titolare l’accesso ai Suoi dati personali, la rettifica o la cancellazione degli stessi, nonché la limitazione del trattamento dei dati che La riguarda, l’opposizione al trattamento e la portabilità dei Suoi dati. Infine, l’interessato ha il diritto di proporre reclamo a un’autorità di controllo competente, ai sensi dell’art. 77, par. 1, RGPD. Lei può esercitare i diritti sopra indicati inviando una comunicazione scritta presso la sede del Titolare o all’indirizzo di posta elettronica <a href="mailto:rpd@unical.it">rpd@unical.it</a>.</p>
    <p>Molti dati si possono consultare e correggere direttamente nell'<a href="area_personale.php">Area personale</a>. Per i minorenni i diritti sono esercitati dai genitori o da chi ne fa le veci. L'autorità di controllo in Italia è il <a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">Garante per la protezione dei dati personali</a>.</p>
    <p class="small text-secondary">Informazioni generali sulla protezione dei dati in Ateneo: <a href="https://www.unical.it/privacy/" target="_blank" rel="noopener">unical.it/privacy</a>.</p>

    <h2 id="cookie">7. Cookie e altri strumenti</h2>
    <p>Il portale usa <strong>solo cookie tecnici</strong> e memorie locali del browser necessari al funzionamento o scelti da te. Non usa cookie di profilazione, di analisi o di terze parti. Per questo non serve il consenso (Linee guida del Garante del 10 giugno 2021): il banner ha solo scopo informativo.</p>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light"><tr><th>Nome</th><th>Tipo</th><th>A cosa serve</th><th>Durata</th></tr></thead>
            <tbody>
                <tr><td class="font-monospace">PHPSESSID</td><td>cookie tecnico</td><td>Mantiene la sessione di navigazione e di accesso</td><td>fino alla chiusura del browser</td></tr>
                <tr><td class="font-monospace">SimpleSAML</td><td>cookie tecnico</td><td>Accesso con SPID, CIE o credenziali Unical (sistema di autenticazione dell'Ateneo)</td><td>fino alla chiusura del browser</td></tr>
                <tr><td class="font-monospace">dibest_uscito</td><td>cookie tecnico</td><td>Ricorda che sei uscito, per non riaccedere in automatico</td><td>30 giorni</td></tr>
                <tr><td class="font-monospace">_ev_csrf</td><td>cookie tecnico</td><td>Protezione dei moduli del pannello dei gestori</td><td>fino alla chiusura del browser</td></tr>
                <tr><td class="font-monospace">cookie_dibest_accepted</td><td>memoria locale</td><td>Ricorda che hai chiuso il banner informativo</td><td>finché non la cancelli</td></tr>
                <tr><td class="font-monospace">zoom_dibest, hc_dibest</td><td>memoria locale</td><td>Preferenze di accessibilità: dimensione del testo e alto contrasto</td><td>finché non la cancelli</td></tr>
                <tr><td class="font-monospace">barra_gestore_chiusa, adminTheme</td><td>memoria locale</td><td>Solo per i gestori: barra di gestione ridotta e tema del pannello</td><td>finché non la cancelli</td></tr>
                <tr><td>Service worker</td><td>cache del browser</td><td>Rende più veloce il caricamento di stili e icone e mostra una pagina di cortesia offline; non contiene dati personali</td><td>fino all'aggiornamento del portale</td></tr>
            </tbody>
        </table>
    </div>
    <p>Caratteri, stili e librerie del portale sono serviti dai server dell'Ateneo: aprendo le pagine il tuo browser non si collega a servizi di terzi. I collegamenti esterni (per esempio Google Maps o "Aggiungi a Google Calendar") si aprono solo se li scegli; da quel momento vale l'informativa del sito di destinazione.</p>
    <p>Puoi cancellare cookie e memorie locali dalle impostazioni del browser: dovrai solo accedere di nuovo e reimpostare le preferenze.</p>
</div>
<?php require_once 'footer.php'; ?>
