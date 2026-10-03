# Didattica DiBEST

Portale open-source per orientamento, didattica e prenotazioni del **Dipartimento DiBEST** — Università della Calabria (già EventiDiBEST).

Il portale è organizzato in **macroaree**, ciascuna con le sue aree:
- **Orientamento**: Formazione Scuola Lavoro (FSL, OpenLab: convenzioni, elenco studenti, attestati, valutazioni) ed eventi e seminari (es. Welcome Week);
- **Didattica**: gruppi degli insegnamenti (es. Scienze Motorie), con le attività create a partire dall'anagrafe degli insegnamenti;
- **Calendari e risorse**: aule, laboratori e sportelli (appuntamenti con gli uffici) prenotabili a slot.

Ogni area ha un **tipo** (`pagine_eventi.tipo_area`: fsl, eventi, gruppi, calendario) scelto in Aree o alla creazione: colloca l'area nella home e propone le impostazioni dei nuovi eventi e progetti. Le **anagrafi** (docenti, PTA, insegnamenti, corsi di studio, scuole) sono comuni a tutto il portale.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://www.mysql.com/)
[![Guardalo live](https://img.shields.io/badge/Guardalo-Live-green.svg)](https://dibest2.unical.it/didattica/)

**Guardalo live:** https://dibest2.unical.it/didattica/ (il vecchio indirizzo /eventi/ rimanda qui)

---

## Funzionalita

- **Ingresso nel pannello per macroarea**: chi gestisce più aree sceglie prima la sezione (Orientamento, Didattica, Calendari e risorse) e poi l'area, con card che riassumono i numeri di ciascuna (`admin/inizio.php`); "Cambia" riporta alla sezione dell'area corrente
- **Calendari e risorse** (aree di tipo `calendario`): aule, laboratori e sportelli con orari settimanali (fino a due fasce al giorno), slot di durata scelta, più slot di seguito, preavviso minimo e giorni prenotabili, chi può prenotare (tutti, studenti, docenti, personale), approvazione facoltativa dei gestori, ripetizione settimanale (le settimane non disponibili vengono saltate e segnalate) e chiusure della risorsa o di tutta l'area. Pagina pubblica con la settimana degli slot liberi, controllo delle sovrapposizioni (anche con richieste contemporanee), email di conferma, approvazione, rifiuto, annullamento e promemoria del giorno prima, file .ics, prenotazioni nell'Area personale con annullamento; nel pannello agenda di oggi, prenotazioni con filtri, approvazione anche di tutta la serie ed export CSV (`admin/risorse.php`, `admin/prenotazioni_risorse.php`)
- **Didattica – Sedute dei consigli dei corsi di studio** (`admin/didattica.php?tab=sedute`): i 5 consigli del Dipartimento sono già inseriti; l'Ufficio didattico sceglie uno o più **referenti** per consiglio (entrano nel pannello e vedono solo le sedute del loro consiglio), i referenti inseriscono **una volta sola i componenti** (docenti dall'anagrafe, rappresentanti a mano) con il gruppo del verbale; in ogni seduta si segna **presente / assente giustificato / assente ingiustificato**. Per ogni pratica in seduta: **esito** (approvata, con modifiche, respinta, rinviata), **convalide** (insegnamento indicato dallo studente → insegnamento del Dipartimento dall'anagrafe, convalida totale o parziale con CFU riconosciuti e da integrare calcolati) o **piano di studi** (in piano / fuori piano), delibera. Tutto finisce nel verbale Word (presenze per gruppo con il riepilogo, quadro delle convalide) e nell'Excel; «Applica gli esiti» chiude le pratiche e avvisa gli studenti
- **Didattica – Moduli online avanzati**: tipi di campo raggruppati (anche ora, URL, codice fiscale, scelta multipla, dichiarazione da accettare, titoli e testi informativi), **tabelle con colonne tipizzate** (insegnamento dal catalogo di Ateneo, CFU, voto, S.S.D., data, tendina…) definite dal costruttore, **logica condizionale** («mostra solo se» un'altra risposta è uguale / diversa / contiene / compilata / vuota) e **valori automatici** («compila se…»), ripetute dal server; modelli pronti anche per convalida di esami e piano di studi
- **Catalogo degli insegnamenti di Ateneo**: i corsi di studio di tutti i dipartimenti si aggiornano con l'anagrafe; lo studente sceglie **tipo di corso → corso di studio → anno accademico di offerta → insegnamento** (scaricato dalle API la prima volta e tenuto 30 giorni), CFU e S.S.D. si compilano da soli, oppure lo scrive a mano (`cerca_insegnamenti.php`, `assets/js/campi-pratica.js`)
- **Didattica – Iter**: chi ha avuto in carico la pratica nei passi precedenti continua a vederla (filtro «Passate ad altri uffici») e la integra; chi l'ha in carico può chiedergli un'integrazione; lo studente può sempre aggiungere documenti. Le email partono **solo ai passaggi**: all'operatore che riceve la pratica e allo studente («passata a…»), per le richieste di integrazione e per gli esiti; niente email per note interne e cambi di stato intermedi
- **Tutorato – Lettere di incarico** (`admin/tutorato.php`): bando con decreto del bando e della commissione e direttore dall'anagrafe; per ogni vincitore dati anagrafici, attività, ore, periodo, compenso e docente responsabile (dall'anagrafe o a mano). Word precompilato dal modello del Dipartimento e PDF della lettera. Iter: email allo studente → **conferma con SPID o CIE** (`incarico.php`: solo il titolare del codice fiscale; nel PDF metodo, livello, identity provider, spidCode, data e ora, impronta SHA-256) → **firma PAdES del docente** → **firma PAdES del direttore** (`firma_incarico.php`: firma remota Aruba dal portale oppure caricamento del PDF firmato) → email all'operatore che scarica il PDF con tutte le firme e registra il protocollo (copia allo studente). Accettati solo PDF PAdES che contengono la lettera senza modifiche, con la firma integra e, se noto, il codice fiscale del firmatario; i .p7m (CAdES) sono rifiutati
- **Solo PAdES** anche per le convenzioni FSL: istruzioni alla scuola e registro accettano solo PDF firmati in PAdES
- **Gestione eventi multi-area** con sezioni (Pagine) personalizzabili per colori, layout e accessi
- **8 layout di pagina**: griglia per sezioni, lista cronologica, elenco avanzato con ricerca, calendario, timeline, agenda a schede per giorno, gruppi/corsi, progetti — tutti gestiscono anche i turni senza data fissa
- **Progetti** (es. Formazione Scuola Lavoro), **dedicati alle scuole o generici**: maschera dedicata con corso di laurea, periodo o "date da definire", requisiti di accesso, ore, articolazione in moduli/fasi/incontri, obiettivi, conoscenze e competenze, referenti con pagina personale; scheda pubblica di ogni progetto con link condivisibile; **edizioni** (repliche) con lista d'attesa in ordine di arrivo — per le scuole una scuola per edizione e numero di studenti controllato, altrimenti posti per edizione; iscrizione con SSO, SPID o CIE
- **Prenotazioni con turni**: nome, data e orari facoltativi, apertura/chiusura automatica, multi-posto, approvazione manuale
- **Lista d'attesa**: posizione in coda visibile all'utente ("Sei 3° in lista"), posto liberato offerto con 24 ore per confermare o rinunciare, promozione automatica
- **Limite iscrizioni per area** (un solo evento o un solo turno per evento): le liste d'attesa non contano e decadono alla prima conferma
- **Autenticazione SSO** via SimpleSAMLphp (integrazione SSO Unical) + accesso esterno (CIE/SPID)
- **RBAC** a 3 livelli: Super Admin, Gestore Area/Evento, Utente — ogni azione verifica che evento o prenotazione appartengano all'area del gestore
- **Check-in** tramite QR code: scanner integrato nel pannello admin (scansione continua, contatore presenti in tempo reale, check-in manuale, lettori USB), self check-in studente, email attestato automatica post-check-in
- **Home configurabile a widget**: carosello, la mia prossima prenotazione (con ricevuta QR), bacheca annunci, card aree, ultimi posti disponibili, prossimi appuntamenti, numeri del dipartimento — ordine con drag & drop, colonne e numero di card regolabili
- **Gestione iscritti**: azioni di massa (presenze, approvazione, promozione dalla lista d'attesa, annullamento), prenotazione manuale
- **Duplicazione** di eventi e progetti (con turni, campi del form e sondaggi) e di singoli turni
- **Aree senza file da generare**: ogni area è servita da `area.php` tramite `.htaccess`, gli slug che coincidono con file del sito vengono rifiutati
- **Attestati** PDF generati automaticamente al completamento dell'evento, con **codice e QR di verifica** (pagina pubblica `verifica_attestato.php`); il QR è disegnato nella pagina, senza servizi esterni; la frase dell'attestato è personalizzabile per area (es. "ha partecipato al progetto di Formazione Scuola Lavoro dal titolo:")
- **Attestati per gli studenti** nei progetti per le scuole e negli eventi con l'opzione "Attestati per gli studenti della classe" (es. OpenLab): il docente inserisce l'elenco dall'Area personale (caselle separate Cognome e Nome, incolla da Excel o modello .xlsx/.csv), la segreteria può correggerlo e togliere gli assenti; a progetto concluso il docente riceve per email il link agli attestati di tutta la classe, da stampare in un unico PDF o da **scaricare in uno ZIP con un PDF per studente** (`attestato_cognome_nome.pdf`); promemoria automatico se l'elenco è ancora vuoto a 7 giorni dalla fine; dopo 12 mesi dalla fine del progetto i nomi vengono ridotti alle iniziali (i codici restano verificabili)
- **Pannello organizzato per aree**: pagina **Aree** con le aree visibili a ciascun utente secondo le abilitazioni; menu a gruppi richiudibili (l'apertura viene ricordata): per l'area corrente Attività, Partecipanti, Statistiche e Impostazioni area; nel Portale Scuole e FSL, Anagrafe di Ateneo, Sito pubblico, Utenti e abilitazioni, Sistema e registri (email e controlli, registro operazioni, accessi SSO come schede); nome dell'area sempre visibile nella barra in alto. Le **nuove aree** si creano da una pagina dedicata e nascono **nascoste al pubblico**, con l'eventuale voce di menu anch'essa nascosta, da pubblicare quando sono pronte
- **Abilitazioni per perimetro**: in Utenti e abilitazioni si abilita una persona a **tutta l'area** (come un amministratore, ma solo per quell'area, impostazioni comprese) oppure a una parte: **tutti i progetti**, **tutti gli eventi** (anche quelli creati dopo) e/o attività scelte; per la **Formazione Scuola Lavoro** (tutte le aree) a tutto (pannello FSL e attività FSL di ogni area) oppure solo alle convenzioni o all'anagrafe scuole. Dentro il perimetro si gestisce tutto: attività, iscritti e check-in, sondaggi, moduli, attestati e statistiche. Riepilogo in cima alla pagina con amministratori, abilitati sull'area, abilitati alla FSL e abilitazioni in attesa del primo accesso
- **Barra del gestore** nelle pagine pubbliche, visibile solo a chi gestisce l'area o l'evento: link diretti a modifica evento/progetto, eventi, impostazioni e pannello
- **Prenotazioni pubbliche protette**: per chi prenota senza accesso domanda di controllo anti-robot (CAPTCHA interno, senza servizi esterni), campo trappola e limite di prenotazioni per indirizzo IP
- **Progetti con rimando**: un progetto può comparire nell'elenco (es. Formazione Scuola Lavoro) ma rimandare a un'altra pagina del portale o a un indirizzo esterno, con il pulsante "Vai a …" (es. OpenLab)
- **Termine per annullare** impostabile per ogni turno ("Annullabile fino a"): dopo quella data l'utente non può più annullare né cambiare turno dall'Area personale
- **Scheda di dettaglio di ogni evento** (`<area>.php?evento=ID`), raggiungibile da tutte le card e dalla ricerca (nelle card solo la **descrizione breve**, qui la completa con la locandina): turni in sequenza con posti liberi, prenotazione e aggiunta al calendario, luogo con link a Google Maps, referenti con email, telefono e pagina personale, link da condividere
- **Sondaggi/questionari** collegabili agli eventi: 15 tipi di campo (rating, NPS, matrice, scelta, testo, data, email…), ordinamento drag & drop, logica condizionale ("mostra se…"), anteprima interattiva, statistiche NPS ed export XLS
- **Dashboard amministrativa** con KPI, grafici (Chart.js), messaggi non letti, scelta dell'area per chi ne gestisce più di una e avviso sugli attestati delle classi (elenchi vuoti, presenze mancanti, invii non partiti)
- **Statistiche & report**: presenze effettive, tasso di presenza, annullate, riempimento, trend iscrizioni 30 giorni, presenti vs assenti per evento, vista Live/Storico, export CSV/Excel e stampa PDF
- **Menu di navigazione** a 3 livelli con ordinamento drag & drop e voci nascondibili
- **Profilo utente**: pagina dedicata con dati SSO e modifica email personale
- **Form builder** per campi prenotazione personalizzati per area/evento
- **Anagrafe delle scuole**: l'elenco ufficiale del Ministero dell'Istruzione (open data, statali e paritarie) si carica dal pannello; il campo "Scuola (anagrafe del Ministero)" dei moduli apre una finestra guidata con regione, provincia e comune (tendine con il numero di scuole), ricerca per nome o codice meccanografico ed elenco delle scuole del luogo, oppure "La scuola non è in elenco" per scriverla a mano; salva il nome ufficiale e il codice meccanografico e la propone già compilata alle iscrizioni successive; resta possibile scrivere a mano una scuola non in elenco. Le scuole scritte a mano nelle iscrizioni passate si abbinano all'anagrafe con i suggerimenti del portale, così report e statistiche contano ogni scuola una volta sola. La pagina Anagrafe scuole elenca le scuole collegate con i docenti di riferimento, le iscrizioni e le attività (esportabile in CSV)
- **Convenzioni con le scuole**: progetti ed eventi possono chiedere nel modulo se la scuola ha già la convenzione con il Dipartimento; con "No" la prenotazione resta in attesa e la scuola scarica dal portale i modelli di Convenzione e Allegato A (assets/modelli, sostituibili per area in Impostazioni area caricando un nuovo file) e riceve la PEC a cui inviarli firmati; la durata proposta per una nuova convenzione è un anno, come nel modello del Dipartimento. Registro delle convenzioni nel pannello **Formazione Scuola Lavoro** (scheda Convenzioni), per scuola: convenzione e Allegato A firmati (PDF o .p7m, scaricabili solo dal pannello), validità dal/al, docenti di riferimento dell'Allegato A, protocollo. La convenzione deve coprire tutto il periodo dell'attività (Dal/Al del progetto, giorno del turno dell'evento): le scuole con una convenzione che lo copre sono riconosciute nel modulo, altrimenti ne va stipulata una nuova. La verifica delle iscrizioni alle attività FSL, anche già confermate, si fa dalla scheda Verifica iscrizioni dello stesso pannello e ogni giorno dal cron. Negli eventi gli interruttori "Dedicato alle scuole" e "Attività di Formazione Scuola Lavoro" (come nei progetti) attivano la prenotazione per classe e il processo delle convenzioni, e quando la convenzione arriva le prenotazioni in attesa si confermano con l'email alla scuola. In Iscrizioni: richiesta della convenzione per email a chi si è già iscritto, "Convenzione ricevuta" (registrata per la scuola), badge sullo stato. Dal cron: promemoria alla scuola ogni 7 giorni (massimo 3), avviso ai gestori 7 giorni prima dell'inizio se mancano convenzioni, avviso agli amministratori 60 giorni prima della scadenza. I progetti possono anche richiedere l'approvazione dei gestori per tutte le edizioni. Con il modello del Dipartimento la scuola scarica anche la **Convenzione già compilata** (`convenzione_precompilata.php`, .docx): istituto, codice meccanografico e sede dall'anagrafe, attività, descrizione, studenti, periodo, durata e tutor; restano evidenziati da completare codice fiscale e dati del Dirigente
- **Scheda di valutazione della struttura ospitante** (FSL): a fine attività, con la presenza registrata, il docente riceve il link personale a `valutazione_fsl.php` (8 aspetti da 1 a 5, "riproporrebbe", commenti), con un promemoria dopo 7 giorni
- **Pannello Formazione Scuola Lavoro** (`admin/fsl.php`), a schede: Riepilogo per anno scolastico (attività, scuole, studenti, presenze, convenzioni mancanti), Convenzioni (registro e registrazione), Verifica iscrizioni, Valutazioni; tabelle con export CSV
- **Controllo automatico del sito** ogni notte dal cron (database, cartelle, spazio su disco, backup, email non partite, pagine pubbliche e file riservati): email agli amministratori solo se qualcosa non va, stato e "Controlla ora" in Sistema
- **Anagrafe del personale di Ateneo** dalle API pubbliche del portale Unical (rubrica, docenti, corsi di studio), per le strutture scelte (DiBEST di partenza, se ne aggiungono altre dalla tendina delle strutture o col codice) e aggiornata ogni settimana: elenchi Docenti, Personale tecnico amministrativo e Altro personale con filtri per ruolo e struttura. Al login la persona viene collegata per email e inserita nel gruppo Docenti / Personale tecnico amministrativo / Altro personale di Ateneo, utilizzabile per riservare gli eventi
- **Referenti dall'anagrafe**: in eventi e progetti si cercano per gruppo, ruolo, struttura e nome e si compilano da soli nome, email, telefono e link (resta possibile l'inserimento a mano); il nome porta alla **pagina pubblica del docente** (`persona.php`) con foto (o una sagoma grigia), ruolo, settore, contatti, ricevimento, curriculum e attività sul portale. Foto e schede sono copiate dal portale di Ateneo e rinnovate ogni 7 giorni
- **Abilitazione dei gestori dall'anagrafe**, anche prima del loro primo accesso: l'abilitazione resta in attesa e si attiva al primo login con quell'email. La dashboard avvisa se un gestore o un referente non compare più nell'anagrafe di Ateneo
- **Campo "Corso di studio"** nel Form Builder: tendina con i corsi dei dipartimenti dell'anagrafe, raggruppati per tipo; quali proporre si sceglie in Corsi e strutture Ateneo
- Nei moduli di prenotazione l'**email si scrive a mano** e si ripete per conferma (non viene presa dall'accesso)
- **Email automatiche**: conferma, cancellazione, promemoria (via SMTP configurabile), con layout nel colore dell'area, registro degli invii ed email di prova dal pannello
- **Notifiche delle prenotazioni** ai gestori, a indirizzi in copia scelti per ogni evento e ai referenti di eventi e progetti, con il riepilogo completo della prenotazione (campi aggiuntivi compresi)
- **Badge e barre dei posti disponibili** in tempo reale sulle card eventi e in home (liberi / lista d'attesa / esauriti / concluso)
- **Colore dell'area coerente** su pagine, badge, ricevute ed email, con testo a contrasto calcolato automaticamente
- **Accessibilità**: struttura dei titoli, landmark, focus da tastiera visibile, contrasti verificati con axe-core
- **Stampa lista iscritti** in vista ottimizzata per stampa/PDF con filtri attivi
- **Ricerca testuale** iscritti per nome, cognome, email, codice prenotazione
- **Audit log** di tutte le operazioni amministrative
- **Rate limiting** anti-flood sugli endpoint pubblici
- **PWA-ready** (manifest + service worker + offline fallback): le pagine arrivano sempre dal server, in cache solo le librerie statiche
- **Configurazione portale** da pannello admin (logo, colori, SMTP, email template)

---

## Requisiti

| Componente | Versione minima |
|---|---|
| PHP | 8.2+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| Web server | Apache (mod_rewrite) o Nginx |
| SimpleSAMLphp | 1.19+ (solo per SSO istituzionale) |

**Librerie front-end utilizzate** (incluse via CDN, nessun Composer richiesto):
- Bootstrap 5.3
- Font Awesome 6.4
- DataTables
- Chart.js
- SortableJS (drag & drop)

**Invio email**: client SMTP interno in `inc/base.php` (`inviaNotificaEmail()`), senza librerie esterne: STARTTLS/SSL, autenticazione, verifica di ogni risposta del server, registro degli invii nella tabella `log_email`. Se l'host SMTP non è configurato o non è raggiungibile usa la funzione `mail()` di PHP.

---

## Installazione

### 1. Scarica il codice

```bash
git clone https://github.com/TUO_USERNAME/eventidibest-cms.git
cd eventidibest-cms
```

### 2. Configura le variabili d'ambiente

```bash
cp .env.example .env
```

Modifica `.env` con i tuoi parametri MySQL:

```ini
DB_HOST=localhost
DB_USER=db_username
DB_PASS=db_password
DB_NAME=eventi_dibest
```

### 3. Crea il database

**Opzione A — Installer via browser (consigliata):**

Visita `http://tuo-dominio/install.php` e compila il form con le credenziali DB e il profilo del Super Admin. Al termine, **elimina `install.php`** dal server.

**Opzione B — Import SQL manuale:**

```bash
mysql -u utente -p eventi_dibest < database/schema.sql
```

Poi imposta manualmente le credenziali in `.env`.

### 4. Configura il web server

Il file `.htaccess` e` gia` predisposto per Apache. Per Nginx, aggiungi:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 5. Permessi cartelle

```bash
chmod 775 uploads/ cache/
```

La cartella `cache/` deve essere scrivibile da PHP: oltre alla cache della configurazione contiene i marcatori degli aggiornamenti del database (vedi sotto). La cartella del codice invece non deve essere scrivibile: creare una nuova area non genera file.

### Operazioni pianificate (cron)

**Backup**: `admin/cron_backup.php` esporta il database (`.sql.gz`) e i file del sito (`.zip`) in `backups/` (7 giorni), li copia nella cartella del NAS indicata da `BACKUP_NAS_PATH` (conservazione `BACKUP_NAS_GIORNI`) e invia una copia del solo database, cifrata AES-256 con `BACKUP_PASSWORD`, a `BACKUP_EMAIL` (`BACKUP_EMAIL_FREQUENZA`: settimanale, giornaliera, no). Esito e configurazione nel pannello Sistema; se qualcosa non va gli amministratori ricevono un avviso. Crontab consigliato: `30 2 * * * php /percorso/eventi/admin/cron_backup.php`. Ogni lunedì `cron_background.php` manda agli amministratori il riepilogo settimanale delle email (inviate, fallite, stato del backup). Una volta a settimana lo stesso script aggiorna l'anagrafe del personale e i corsi di studio dal portale di Ateneo.

Gli script `cron_background.php`, `cron_attestati.php`, `admin/cron_reminders.php` e `admin/cron_backup.php` si avviano da riga di comando (es. `php cron_background.php`) oppure via URL con la chiave `CRON_KEY` del file `.env` (almeno 16 caratteri), es. `https://tuo-dominio/eventi/cron_background.php?key=LA_TUA_CHIAVE`. Senza chiave rispondono 403.

### Ambiente locale di prova e prove automatiche

Su Windows con XAMPP, da Git Bash: `bash strumenti/locale/avvia.sh` avvia MariaDB e il portale su `http://127.0.0.1:8080/eventi/` con un database separato (`eventi_locale`) e dati di esempio inventati (`--nuovo` li ricrea, `--ferma` spegne tutto). Usa il file `.env.locale` (creato da solo, mai da caricare sul server): nessuna email parte, si leggono in `/__email`; l'accesso SSO è sostituito da `/__accesso`; il cron si lancia da `/__cron`. Le poche righe del codice che lo permettono si attivano solo con il server integrato di PHP (`PHP_SAPI === 'cli-server'`), mai con Apache.

Prima di ogni caricamento: `/c/xampp/php/php.exe -d extension=zip strumenti/prove/esegui.php` lancia le prove automatiche (funzioni su un database usa e getta e, se l'ambiente locale è acceso, pagine, file riservati, pannello e un'iscrizione completa). Esce con codice 1 se una prova fallisce. Dopo il caricamento, sul server: `PHP_BIN=/opt/lampp/bin/php bash strumenti/verifica_sito.sh`.

### Indirizzo del portale (/didattica)

Il codice non dipende dal nome della cartella. Per passare da `/eventi` a `/didattica` sul server: `sudo bash /opt/lampp/htdocs/eventi/strumenti/migra_a_didattica.sh` (sposta la cartella, crea in `/eventi` un rimando permanente pagina per pagina, imposta `URL_SITO` nel `.env`); poi aggiornare il crontab come indicato dallo script. `--annulla` torna a `/eventi`.

### Tutorato: firma remota Aruba e SPID/CIE

Nel `.env`: `INCARICHI_SOLO_SPID_CIE=1` (lo studente conferma solo se è entrato con SPID o CIE; il metodo si legge dagli attributi e dal contesto di autenticazione SAML in `metadati_accesso_saml()`), `ARUBA_ARSS_URL`, `ARUBA_ARSS_DOMINIO` e `ARUBA_ARSS_CERTID` per la firma remota Aruba (ArubaSignService, `pdfsignatureV2` con profilo PADESBES). Senza `ARUBA_ARSS_URL` docente e direttore scaricano il PDF, lo firmano in PAdES e lo caricano. I PDF stanno in `uploads/incarichi/` (bloccata al web); il modello Word è `modelli_documenti/lettera_incarico_tutorato.docx`.

### Anagrafe degli insegnamenti

Con l'aggiornamento settimanale dell'anagrafe arrivano anche gli insegnamenti dei corsi del proprio dipartimento (la prima struttura) dalle API `activities` del portale di Ateneo. Nelle API `academic_year` è la coorte: l'anno in cui l'insegnamento si tiene è coorte + anno di corso − 1. Si conservano l'anno accademico in corso e il successivo (Anagrafi → Insegnamenti).

### Aggiornamenti del database

Non servono script SQL manuali dopo un aggiornamento del codice. Al primo accesso la funzione `assicura_schema()` in `inc/schema.php` crea le tabelle e le colonne mancanti e corregge i tipi di colonna dei database più vecchi, poi scrive un marcatore (es. `cache/schema_v29.ok`) e da quel momento non interroga più lo schema. Le pagine non modificano mai la struttura del database: ogni nuova colonna va aggiunta lì, cambiando il nome del marcatore.

### 6. Configura il SSO (opzionale)

Se usi SimpleSAMLphp per l'autenticazione istituzionale, modifica le impostazioni in `saml_login.php` e `functions.php` puntando alla tua istanza SimpleSAML.

La funzione `sync_sso_user()` in `functions.php` gestisce automaticamente la mappatura degli attributi SAML per i diversi tipi di utente:

| Tipo utente | Attributo email cercato | Fallback |
|---|---|---|
| Studente | `mail` / OID `0.9.2342.19200300.100.1.3` | nessuno (i nomi degli attributi ricevuti finiscono nel log) |
| Dipendente | `mail` / OID `0.9.2342.19200300.100.1.3` | nessuno |
| Esterno (CIE/SPID) | `mail` / OID `0.9.2342.19200300.100.1.3` | nessuno |

Il tipo viene riconosciuto tramite gli attributi `matricola_studente` e `matricola_dipendente` dell'IdP. Le email errate salvate in precedenza vengono corrette automaticamente al login successivo.

---

## Struttura del progetto

```
eventidibest-cms/
├── admin/              # Pannello di amministrazione
│   ├── admin_header.php        # Autenticazione, RBAC, menu dell'area corrente e barra superiore
│   ├── dashboard.php           # Dashboard con KPI e grafici, scelta dell'area
│   ├── aree.php                # Elenco delle aree (gestisci, visibile/nascosta, elimina)
│   ├── inizio.php              # Ingresso: scelta della sezione e dell'area (card)
│   ├── nuova_area.php          # Creazione di un'area (nasce nascosta, voce di menu nascosta)
│   ├── risorse.php             # Calendari e risorse: risorse, orari settimanali e chiusure
│   ├── prenotazioni_risorse.php # Calendari e risorse: agenda, prenotazioni, approvazioni, CSV (dashboard dell'area)
│   ├── didattica.php           # Didattica: pratiche, sedute e consigli (presenze, convalide, verbale), moduli, ufficio, statistiche
│   ├── tutorato.php            # Tutorato: bandi e lettere di incarico (iter SPID/CIE → PAdES docente → PAdES direttore → protocollo)
│   ├── utenti.php              # Utenti, gruppi e abilitazioni, con riepilogo per area
│   ├── scuole.php              # Anagrafe scuole: caricamento del file del Ministero e abbinamento dello storico, scuole collegate con i docenti (le convenzioni sono in fsl.php)
│   ├── convenzione_file.php    # Scarica la convenzione o l'Allegato A firmati (solo amministratori; i file sono bloccati al web)
│   ├── fsl.php                 # Formazione Scuola Lavoro a schede: riepilogo per anno scolastico, convenzioni, verifica delle iscrizioni, valutazioni
│   ├── anagrafe_personale.php  # Anagrafi di Ateneo: docenti, PTA, insegnamenti (anagrafe_insegnamenti.php), corsi di studio, strutture
│   ├── anagrafe_docenti.php    # Apre l'elenco dei docenti (anagrafe_pta.php: personale tecnico amministrativo)
│   ├── cerca_personale.php     # Ricerca nell'anagrafe del personale per referenti e abilitazioni (JSON)
│   ├── eventi.php              # CRUD eventi, turni, sezioni e referenti, duplicazione
│   ├── progetti.php            # Progetti: scheda completa, tipo (scuole o generico), edizioni, attestati
│   ├── partecipanti.php        # Studenti e attestati: elenco delle classi dell'area e dettaglio di ciascuna (invio al docente)
│   ├── iscritti.php            # Gestione prenotazioni (ricerca, presenza, azioni di massa)
│   ├── scanner.php             # Scanner check-in integrato con contatore in tempo reale
│   ├── impostazioni_area.php   # Colori, layout e regole di ogni area
│   ├── testata.php             # Testata, carosello e widget della home
│   ├── stampa_lista_iscritti.php # Vista stampabile/PDF lista iscritti
│   ├── messaggi.php            # Sistema messaggistica admin<->utente
│   ├── sondaggi.php            # Questionari: campi, logica condizionale, statistiche
│   ├── menu.php                # Menu a 3 livelli con drag & drop
│   ├── statistiche.php         # KPI presenze, trend, Live/Storico, export CSV/Excel
│   ├── audit_log.php           # Log attivita sistema
│   └── ...
├── database/
│   └── schema.sql          # Schema completo del database (23 tabelle)
├── uploads/            # File caricati (escluso da git)
├── cache/              # Cache runtime (escluso da git)
├── assets/             # Icone PWA
├── config.php          # Connessione DB, session, security headers, CSP
├── functions.php       # Carica le funzioni condivise da inc/ (nell'ordine giusto)
├── inc/                # Funzioni per argomento: base, sezioni, aspetto, liste_attesa, sistema, dati, eventi_progetti, anagrafi, fsl, prenotazioni, attestati, risorse, schema (bloccata al web)
├── modelli_documenti/  # Modelli interni per i documenti precompilati (bloccata al web)
├── strumenti/          # verifica_sito.sh, ambiente locale (locale/) e prove automatiche (prove/); bloccata al web
├── valutazione_fsl.php # Scheda di valutazione della struttura ospitante (link personale del docente)
├── convenzione_precompilata.php # Convenzione FSL già compilata con i dati della prenotazione (.docx)
├── install.php         # Installer guidato (da eliminare dopo l'uso)
├── index.php           # Homepage pubblica a widget
├── master_template.php # Motore dei layout delle pagine area (le pagine area lo includono)
├── checkin.php         # Esito del QR letto con la fotocamera del telefono (admin)
├── self_checkin.php    # Self check-in studente
├── area_personale.php  # Area utente loggato (prenotazioni, messaggi)
├── elenco_studenti.php # Elenco degli studenti inserito dal docente (progetti per le scuole)
├── attestati_gruppo.php # Attestati della classe, uno per pagina
├── verifica_attestato.php # Verifica pubblica di un attestato dal codice o dal QR
├── privacy.php         # Informativa privacy e cookie
├── crediti.php         # Credits: copyright, realizzazione, software open source e licenze
├── cerca_scuole.php    # Anagrafe delle scuole per il campo "Scuola" dei moduli: regioni, province, comuni e scuole (JSON)
├── calendario_area.php # Pagina pubblica delle aree Calendari e risorse (incluso da master_template.php)
├── risorsa_ics.php     # File .ics di una prenotazione di aula, laboratorio o sportello
├── persona.php         # Pagina pubblica di un referente scelto dall'anagrafe di Ateneo
├── incarico.php        # Lettera di incarico: controllo e conferma dello studente con SPID/CIE (link personale)
├── firma_incarico.php  # Lettera di incarico: firma PAdES del docente e del direttore (Aruba o PDF firmato)
├── cerca_insegnamenti.php # Catalogo di Ateneo per i campi Insegnamento dei moduli (JSON)
├── profilo.php         # Profilo utente: dati SSO e modifica email
├── sw.js               # Service Worker (PWA)
└── manifest.json       # Web App Manifest (PWA)
```

---

## Schema Database

Il database e` composto da **23 tabelle**:

| Tabella | Descrizione |
|---|---|
| `ruoli` | Ruoli utente (Admin, Gestore, Studente, Dipendente, Ospite) |
| `utenti` | Profili utente sincronizzati da SSO |
| `pagine_eventi` | Sezioni/aree del portale (Welcome Week, OpenLab, ...) |
| `sottocategorie` | Sezioni degli eventi per area (con opzione "affiancata in alto" nel layout Griglia) |
| `eventi` | Singoli eventi e progetti (campo `tipo`) con locandina e accesso per ruolo |
| `progetti_dettagli` | Scheda di progetti ed eventi (per gli eventi: i referenti). Progetti: tipo (scuole o generico), attestati, periodo, requisiti, partecipanti per iscrizione, referenti, moduli, obiettivi e competenze |
| `partecipanti_prenotazione` | Studenti di un'iscrizione ai progetti per le scuole, con il codice di verifica dell'attestato |
| `turni` | Slot orari con posti, apertura/chiusura, lista attesa |
| `prenotazioni` | Prenotazioni con QR code univoco e stato |
| `campi_form` | Campi custom del form prenotazione per area/evento |
| `messaggi_prenotazioni` | Chat admin ↔ utente per ogni prenotazione |
| `sondaggi` | Questionari collegati agli eventi |
| `sondaggi_domande` | Domande del questionario (ordine e condizione di visibilità) |
| `sondaggi_risposte` | Risposte anonime |
| `template_email` | Template email personalizzabili |
| `impostazioni_sistema` | Config SMTP e template email di sistema |
| `configurazione_portale` | Impostazioni grafiche del portale |
| `menu_voci` | Voci del menu di navigazione principale (nascondibili) |
| `scuole` | Anagrafe delle scuole del Ministero (codice meccanografico, istituto di riferimento, comune, tipo); le prenotazioni e gli utenti hanno il campo `scuola_codice` |
| `personale_ateneo` | Anagrafe del personale delle strutture scelte (API del portale Unical): ruolo, struttura, gruppo, settore, recapiti, scheda con foto; gli utenti hanno il campo `persona_id` |
| `anagrafe_strutture` | Strutture di Ateneo da sincronizzare, con l'esito dell'ultimo aggiornamento |
| `corsi_studio` | Corsi di studio dei dipartimenti dell'anagrafe, con la scelta di quelli proposti nei moduli |
| `insegnamenti` | Anagrafe degli insegnamenti dei corsi del Dipartimento (API activities); le attività collegate hanno `progetti_dettagli.insegnamento_id` |
| `risorse`, `risorse_orari`, `risorse_chiusure` | Calendari e risorse: aule, laboratori e sportelli con regole di prenotazione, orari settimanali e chiusure (della risorsa o di tutta l'area) |
| `prenotazioni_risorse` | Prenotazioni a slot delle risorse (stato confermata / da approvare / rifiutata / annullata, serie settimanale, codice, promemoria) |
| `abilitazioni_ambito` | Abilitazioni per perimetro: tutti i progetti / tutti gli eventi di un'area, Formazione Scuola Lavoro (tutto, convenzioni, anagrafe scuole) |
| `abilitazioni_attesa` | Abilitazioni date a chi non ha ancora fatto accesso: si attivano al primo login con quell'email |
| `log_attivita` | Audit trail di tutte le operazioni admin |
| `rate_limit_attempts` | Protezione anti-flood endpoint pubblici |
| `slide_home` | Immagini del carosello della home |
| `log_accessi` | Registro degli accessi SSO |
| `log_email` | Registro degli invii email (accettate / rifiutate dal server SMTP) |
| `didattica_consigli`, `didattica_consigli_persone`, `didattica_sedute_presenze` | Consigli dei corsi di studio con referenti e componenti; presenze di ogni seduta |
| `pratiche_operatori` | Operatori che hanno avuto in carico una pratica (la vedono e la integrano anche dopo il passaggio) |
| `ateneo_cds`, `ateneo_insegnamenti`, `ateneo_insegnamenti_scaricati` | Catalogo di Ateneo: corsi di studio per anno di offerta e insegnamenti scaricati quando servono |
| `tutorato_bandi`, `tutorato_incarichi`, `tutorato_eventi` | Bandi di tutorato, lettere di incarico (dati, stato, conferma SPID/CIE, PDF, protocollo) e loro storico |

---

## Sicurezza

- Tutti i parametri utente sono passati tramite **prepared statements** (MySQLi)
- **CSRF token** su tutti i form e sulle richieste AJAX
- **Controllo dei permessi per ogni azione**: un gestore agisce solo su eventi, turni e prenotazioni della propria area o dei propri eventi
- Output HTML e dati passati a JavaScript sempre codificati; i codici QR letti dallo scanner non vengono mai aperti come link
- Colori personalizzati validati prima di essere usati negli stili
- **Rate limiting** sugli endpoint di prenotazione e sondaggio
- Ruolo richiesto per prenotare e appartenenza del turno all'area verificati anche dal server
- Script cron eseguibili solo da riga di comando, con `CRON_KEY` o da un utente con il ruolo adatto
- **Content Security Policy** (CSP) configurata in `config.php`
- **Subresource Integrity**: tutte le librerie da CDN hanno l'impronta SHA-384 (`integrity`), anche quelle caricate su richiesta
- **Nessuna connessione automatica a terzi**: caratteri (Titillium Web, Lora, Dancing Script) e librerie (Bootstrap Italia, Bootstrap, Font Awesome, jQuery, DataTables, Select2, Chart.js, SortableJS, FullCalendar, TinyMCE, html2canvas, jsPDF, JSZip) sono in `assets/vendor/`, con la stessa struttura dei CDN, e si richiamano con `url_vendor()`; la CSP non ammette più Google Fonts
- **Privacy**: pagina `privacy.php` con l'informativa (art. 13 GDPR) e l'elenco dei cookie tecnici, collegata ai moduli e al piè di pagina; nei moduli si dichiara la presa visione (base giuridica: compito di interesse pubblico, non il consenso); pagina `crediti.php` con copyright, realizzazione e licenze del software open source
- **Conservazione dei dati** (`cron_background.php`, durate nel `.env`): registri di accessi ed email `CONSERVAZIONE_LOG_MESI` (12), azioni amministrative `CONSERVAZIONE_AUDIT_MESI` (24), prenotazioni anonimizzate dopo `CONSERVAZIONE_PRENOTAZIONI_MESI` e account inattivi eliminati dopo `CONSERVAZIONE_UTENTI_MESI` (0 = mai, da concordare con il DPO), esclusi amministratori e chiunque abbia un'abilitazione (area, attività, progetti, eventi o FSL)
- **Librerie dei QR sul server**: generazione dei QR (`qrcode-generator`) e scanner del check-in (`html5-qrcode`) sono in `assets/js/` e si caricano con `script_libreria()`; se il file locale mancasse si ripiega sul CDN, con la stessa impronta
- **Eliminazione di un'area** solo scrivendo il nome dell'area (controllato anche dal server), con il riepilogo di eventi, prenotazioni, studenti e sondaggi che verrebbero cancellati
- **QR generati nella pagina** (ricevute, badge, QR d'aula, attestati): nessun codice o token di check-in inviato a servizi esterni
- Codici di prenotazione e di attestato generati con `random_bytes` / `random_int`
- **HTTP Security Headers**: HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy
- Cookie di sessione: `Secure`, `HttpOnly`, `SameSite=Lax`, sempre aperti da `config.php`
- Dopo "Esci" nessun accesso automatico dalla sessione SSO di Ateneo finché l'utente non rientra con "Accedi"
- Password SMTP cifrate nel database, mai esposte nel codice sorgente
- `uploads/` e `.env` esclusi da git e protetti da `.htaccess`

---

## Contribuire

Pull request e segnalazioni di bug sono benvenute. Per modifiche sostanziali, apri prima una issue per discutere il cambiamento proposto.

---

## Licenza

Questo software e` distribuito sotto licenza **GNU Affero General Public License v3.0**.  
Vedi il file [LICENSE](LICENSE) per il testo completo.

In sintesi: puoi usare, modificare e distribuire il software liberamente, ma **qualsiasi versione modificata che esegui su un server deve rendere il codice sorgente disponibile agli utenti del servizio**.

---

## Autore

**Emanuele Dodaro** — Universita della Calabria, Dipartimento DiBEST  
Progetto sviluppato per la gestione eventi accademici.
