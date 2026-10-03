<?php
// inc/schema.php - Aggiornamento automatico dello schema del database (tabelle e colonne mancanti).
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// Migrazioni una tantum dello schema (funziona sia su MySQL che su MariaDB).
// UNICO punto in cui il codice modifica la struttura del database: nessuna pagina deve
// eseguire ALTER/CREATE al volo. Il file marcatore evita di interrogare lo schema a ogni
// richiesta: quando aggiungi qualcosa qui, cambia anche il nome del marcatore.
if (!function_exists('assicura_schema')) {
    function assicura_schema($conn) {
        $marker = RADICE_SITO . '/cache/schema_v38.ok';
        if (is_file($marker)) return;

        // 1. Tabelle di servizio (prima create dalle singole pagine a ogni richiesta)
        $tabelle = [
            'slide_home' => "CREATE TABLE IF NOT EXISTS slide_home (
                id INT AUTO_INCREMENT PRIMARY KEY, immagine_path VARCHAR(255) NOT NULL, titolo VARCHAR(255) DEFAULT '',
                sottotitolo VARCHAR(255) DEFAULT '', link VARCHAR(500) DEFAULT '', ordine INT DEFAULT 0, attiva TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_ordine (ordine)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'log_accessi' => "CREATE TABLE IF NOT EXISTS log_accessi (
                id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT DEFAULT NULL, email VARCHAR(255), nome VARCHAR(100), cognome VARCHAR(100),
                ip VARCHAR(45), user_agent VARCHAR(512), tipo VARCHAR(20) DEFAULT 'sso', created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_uid (utente_id), INDEX idx_cat (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'log_email' => "CREATE TABLE IF NOT EXISTS log_email (
                id INT AUTO_INCREMENT PRIMARY KEY, destinatario VARCHAR(255), oggetto VARCHAR(255), esito TINYINT(1) DEFAULT 0,
                canale VARCHAR(10) DEFAULT 'smtp', errore VARCHAR(500) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cat (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'rate_limit_attempts' => "CREATE TABLE IF NOT EXISTS rate_limit_attempts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip_hash CHAR(64) NOT NULL, endpoint VARCHAR(80) NOT NULL, hit_at DATETIME NOT NULL,
                INDEX idx_ip_ep (ip_hash, endpoint), INDEX idx_hit (hit_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v22: anagrafe delle scuole (open data del Ministero dell'Istruzione), chiave il codice meccanografico
            'scuole' => "CREATE TABLE IF NOT EXISTS scuole (
                codice VARCHAR(10) NOT NULL PRIMARY KEY, denominazione VARCHAR(255) NOT NULL DEFAULT '',
                istituto_codice VARCHAR(10) DEFAULT NULL, istituto_denominazione VARCHAR(255) DEFAULT NULL,
                tipo VARCHAR(150) DEFAULT '', comune VARCHAR(120) DEFAULT '', provincia VARCHAR(80) DEFAULT '', regione VARCHAR(80) DEFAULT '',
                indirizzo VARCHAR(255) DEFAULT '', cap VARCHAR(10) DEFAULT '', email VARCHAR(150) DEFAULT '', pec VARCHAR(150) DEFAULT '',
                statale TINYINT(1) NOT NULL DEFAULT 1, anno_scolastico VARCHAR(12) DEFAULT '', aggiornata_il DATETIME DEFAULT NULL,
                INDEX idx_istituto (istituto_codice), INDEX idx_comune (comune), INDEX idx_regione (regione)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v23: anagrafe del personale di Ateneo (API pubbliche del portale), chiave l'ID del portale (nome.cognome);
            // origine = struttura sincronizzata da cui arriva la persona
            // v30: dati della scheda di Ateneo modificati dalla persona dall'Area personale (vuoto = dato del portale);
            // tabella separata: l'aggiornamento settimanale dalle API non li sovrascrive
            // v31: anagrafe degli insegnamenti dei corsi del Dipartimento (API activities del portale di Ateneo)
            'insegnamenti' => "CREATE TABLE IF NOT EXISTS insegnamenti (
                id INT NOT NULL PRIMARY KEY, codice VARCHAR(30) DEFAULT '', nome VARCHAR(255) NOT NULL DEFAULT '',
                cds_cod VARCHAR(20) DEFAULT '', cds_nome VARCHAR(255) DEFAULT '', anno_corso TINYINT DEFAULT NULL, anno_accademico SMALLINT DEFAULT NULL, coorte SMALLINT DEFAULT NULL,
                semestre VARCHAR(60) DEFAULT '', ssd_cod VARCHAR(20) DEFAULT '', ssd VARCHAR(150) DEFAULT '', lingua VARCHAR(60) DEFAULT '',
                docente VARCHAR(150) DEFAULT '', docente_id VARCHAR(30) DEFAULT '', partizione VARCHAR(150) DEFAULT '', padre_id INT DEFAULT NULL,
                dipartimento_cod VARCHAR(20) DEFAULT '', presente TINYINT(1) NOT NULL DEFAULT 1, aggiornato_il DATETIME DEFAULT NULL,
                INDEX idx_cds (cds_cod), INDEX idx_aa (anno_accademico), INDEX idx_nome (nome)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v32: Calendari e risorse (aree di tipo "calendario"): aule, laboratori e sportelli prenotabili a slot,
            // orari settimanali, chiusure (risorsa_id NULL = tutta l'area) e prenotazioni
            'risorse' => "CREATE TABLE IF NOT EXISTS risorse (
                id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT NOT NULL, nome VARCHAR(150) NOT NULL DEFAULT '', tipo VARCHAR(20) NOT NULL DEFAULT 'aula',
                descrizione TEXT DEFAULT NULL, luogo VARCHAR(255) DEFAULT '', capienza INT DEFAULT NULL, referente VARCHAR(150) DEFAULT '',
                email_notifiche VARCHAR(500) DEFAULT '', durata_slot SMALLINT NOT NULL DEFAULT 60, max_slot TINYINT NOT NULL DEFAULT 2,
                anticipo_ore SMALLINT NOT NULL DEFAULT 2, max_giorni SMALLINT NOT NULL DEFAULT 60, accesso VARCHAR(20) NOT NULL DEFAULT 'tutti',
                approvazione TINYINT(1) NOT NULL DEFAULT 0, ripetizione TINYINT(1) NOT NULL DEFAULT 0, chiede_motivo TINYINT(1) NOT NULL DEFAULT 1,
                attiva TINYINT(1) NOT NULL DEFAULT 1, ordine INT NOT NULL DEFAULT 0, creata_il DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pagina (pagina_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'risorse_orari' => "CREATE TABLE IF NOT EXISTS risorse_orari (
                id INT AUTO_INCREMENT PRIMARY KEY, risorsa_id INT NOT NULL, giorno TINYINT NOT NULL, dalle TIME NOT NULL, alle TIME NOT NULL,
                INDEX idx_risorsa (risorsa_id, giorno)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'risorse_chiusure' => "CREATE TABLE IF NOT EXISTS risorse_chiusure (
                id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT NOT NULL, risorsa_id INT DEFAULT NULL, dal DATE NOT NULL, al DATE NOT NULL,
                motivo VARCHAR(255) DEFAULT '', INDEX idx_pagina (pagina_id, dal, al), INDEX idx_risorsa (risorsa_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'prenotazioni_risorse' => "CREATE TABLE IF NOT EXISTS prenotazioni_risorse (
                id INT AUTO_INCREMENT PRIMARY KEY, risorsa_id INT NOT NULL, utente_id INT DEFAULT NULL, nome VARCHAR(100) DEFAULT '', cognome VARCHAR(100) DEFAULT '',
                email VARCHAR(255) DEFAULT '', inizio DATETIME NOT NULL, fine DATETIME NOT NULL, motivo VARCHAR(500) DEFAULT '',
                stato VARCHAR(20) NOT NULL DEFAULT 'confermata', serie VARCHAR(12) DEFAULT NULL, codice VARCHAR(20) NOT NULL,
                nota_gestore VARCHAR(500) DEFAULT '', promemoria_inviato TINYINT(1) NOT NULL DEFAULT 0, creata_il DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_codice (codice), INDEX idx_risorsa (risorsa_id, inizio), INDEX idx_utente (utente_id), INDEX idx_stato (stato, inizio)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'personale_modifiche' => "CREATE TABLE IF NOT EXISTS personale_modifiche (
                persona_id VARCHAR(80) NOT NULL PRIMARY KEY, telefono VARCHAR(60) DEFAULT '', ufficio VARCHAR(255) DEFAULT '',
                ricevimento TEXT DEFAULT NULL, bio TEXT DEFAULT NULL, sito VARCHAR(255) DEFAULT '', aggiornata_il DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'personale_ateneo' => "CREATE TABLE IF NOT EXISTS personale_ateneo (
                id VARCHAR(80) NOT NULL PRIMARY KEY, cognome VARCHAR(100) NOT NULL DEFAULT '', nome VARCHAR(100) NOT NULL DEFAULT '',
                email VARCHAR(150) DEFAULT '', telefono VARCHAR(60) DEFAULT '', ufficio VARCHAR(255) DEFAULT '',
                ruolo_cod VARCHAR(10) DEFAULT '', ruolo VARCHAR(150) DEFAULT '', struttura_cod VARCHAR(20) DEFAULT '', struttura VARCHAR(255) DEFAULT '',
                gruppo VARCHAR(10) NOT NULL DEFAULT 'altro', docente TINYINT(1) NOT NULL DEFAULT 0, ssd_cod VARCHAR(20) DEFAULT '', ssd VARCHAR(150) DEFAULT '',
                origine VARCHAR(20) DEFAULT '', attivo TINYINT(1) NOT NULL DEFAULT 1, aggiornata_il DATETIME DEFAULT NULL, uscita_il DATETIME DEFAULT NULL,
                dettaglio_json MEDIUMTEXT DEFAULT NULL, dettaglio_il DATETIME DEFAULT NULL,
                INDEX idx_email (email), INDEX idx_gruppo (gruppo), INDEX idx_struttura (struttura_cod), INDEX idx_cognome (cognome)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v23: strutture di Ateneo da cui prendere personale e corsi di studio (DiBEST di partenza)
            'anagrafe_strutture' => "CREATE TABLE IF NOT EXISTS anagrafe_strutture (
                codice VARCHAR(20) NOT NULL PRIMARY KEY, nome VARCHAR(255) NOT NULL DEFAULT '', persone INT NOT NULL DEFAULT 0,
                corsi INT NOT NULL DEFAULT 0, ultima_sync DATETIME DEFAULT NULL, esito VARCHAR(255) DEFAULT '', aggiunta_il DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v23: corsi di studio dei dipartimenti scelti, per il campo "Corso di studio" dei moduli
            'corsi_studio' => "CREATE TABLE IF NOT EXISTS corsi_studio (
                codice VARCHAR(20) NOT NULL PRIMARY KEY, nome VARCHAR(255) NOT NULL DEFAULT '', tipo VARCHAR(10) DEFAULT '', tipo_descrizione VARCHAR(100) DEFAULT '',
                classe VARCHAR(255) DEFAULT '', anno INT DEFAULT NULL, lingua VARCHAR(100) DEFAULT '', durata INT DEFAULT NULL,
                dipartimento_cod VARCHAR(20) DEFAULT '', visibile TINYINT(1) NOT NULL DEFAULT 1, presente TINYINT(1) NOT NULL DEFAULT 1, aggiornato_il DATETIME DEFAULT NULL,
                INDEX idx_dip (dipartimento_cod)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v23: abilitazioni date a chi non ha ancora fatto il primo accesso: si attivano al login con quell'email
            'abilitazioni_attesa' => "CREATE TABLE IF NOT EXISTS abilitazioni_attesa (
                id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(150) NOT NULL, persona_id VARCHAR(80) DEFAULT NULL, nominativo VARCHAR(200) DEFAULT '',
                pagina_id INT NOT NULL, permessi VARCHAR(255) NOT NULL DEFAULT '', eventi_ids VARCHAR(500) DEFAULT '', creata_da INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_email (email), INDEX idx_pagina (pagina_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v9: scheda dei progetti (un record per evento con tipo = 'progetto'); tutti i campi facoltativi
            'progetti_dettagli' => "CREATE TABLE IF NOT EXISTS progetti_dettagli (
                evento_id INT NOT NULL PRIMARY KEY, struttura VARCHAR(255) DEFAULT '', data_inizio DATE DEFAULT NULL, data_fine DATE DEFAULT NULL,
                periodo_note VARCHAR(255) DEFAULT '', destinatari VARCHAR(255) DEFAULT '', modalita VARCHAR(100) DEFAULT '',
                ore_totali INT DEFAULT NULL, incontri_previsti INT DEFAULT NULL, min_studenti INT DEFAULT NULL, max_studenti INT DEFAULT NULL,
                referenti_json TEXT DEFAULT NULL, info_extra_json TEXT DEFAULT NULL, moduli_json TEXT DEFAULT NULL,
                obiettivi TEXT DEFAULT NULL, conoscenze TEXT DEFAULT NULL, competenze TEXT DEFAULT NULL,
                per_scuole TINYINT(1) NOT NULL DEFAULT 1, attestati TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v11: elenco degli studenti di un'iscrizione (progetti per le scuole) con il codice di verifica dell'attestato
            // v26: registro delle convenzioni scuola-Dipartimento (Anagrafe scuole)
            // v28: scheda di valutazione della struttura ospitante compilata dal docente a fine attività FSL
            // v29: abilitazioni per perimetro (oltre a quelle su tutta l'area o su singole attività, salvate nelle aree/attività):
            // tipo 'progetti' / 'eventi' = tutte le attività di quel tipo dell'area (pagina_id), anche future;
            // 'fsl' = pannello e attività di Formazione Scuola Lavoro di tutte le aree; 'fsl_convenzioni' / 'fsl_scuole' = solo quella parte (pagina_id 0)
            // v34: modulo Didattica – modulistica (documenti da scaricare e moduli online) e pratiche degli studenti
            'didattica_moduli' => "CREATE TABLE IF NOT EXISTS didattica_moduli (
                id INT AUTO_INCREMENT PRIMARY KEY, titolo VARCHAR(200) NOT NULL DEFAULT '', descrizione TEXT DEFAULT NULL, categoria VARCHAR(100) NOT NULL DEFAULT '',
                tipo VARCHAR(10) NOT NULL DEFAULT 'documento', file_path VARCHAR(255) DEFAULT NULL, link VARCHAR(500) DEFAULT NULL, campi_json TEXT DEFAULT NULL,
                destinatari VARCHAR(20) NOT NULL DEFAULT 'tutti', email_ufficio VARCHAR(500) DEFAULT '', attivo TINYINT(1) NOT NULL DEFAULT 1, ordine INT NOT NULL DEFAULT 0,
                creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, aggiornato_il DATETIME DEFAULT NULL, INDEX idx_cat (categoria, ordine)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'pratiche' => "CREATE TABLE IF NOT EXISTS pratiche (
                id INT AUTO_INCREMENT PRIMARY KEY, modulo_id INT NOT NULL, utente_id INT DEFAULT NULL, codice VARCHAR(20) NOT NULL,
                nome VARCHAR(100) DEFAULT '', cognome VARCHAR(100) DEFAULT '', email VARCHAR(255) DEFAULT '', matricola VARCHAR(50) DEFAULT '',
                risposte_json MEDIUMTEXT DEFAULT NULL, stato VARCHAR(20) NOT NULL DEFAULT 'inviata', creata_il DATETIME DEFAULT CURRENT_TIMESTAMP, aggiornata_il DATETIME DEFAULT NULL,
                UNIQUE KEY uq_codice (codice), INDEX idx_utente (utente_id), INDEX idx_stato (stato), INDEX idx_modulo (modulo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'pratiche_eventi' => "CREATE TABLE IF NOT EXISTS pratiche_eventi (
                id INT AUTO_INCREMENT PRIMARY KEY, pratica_id INT NOT NULL, tipo VARCHAR(10) NOT NULL DEFAULT 'messaggio', autore VARCHAR(10) NOT NULL DEFAULT 'studente',
                utente_id INT DEFAULT NULL, stato VARCHAR(20) DEFAULT NULL, testo TEXT DEFAULT NULL, allegato VARCHAR(255) DEFAULT NULL, nome_allegato VARCHAR(255) DEFAULT NULL,
                creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_pratica (pratica_id, creato_il)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v35: Ufficio didattico (operatori scelti dall'anagrafe di Ateneo, con i loro compiti) e sedute del Consiglio
            // di corso di studio a cui si portano le pratiche (per il verbale in Word)
            'ufficio_didattica' => "CREATE TABLE IF NOT EXISTS ufficio_didattica (
                id INT AUTO_INCREMENT PRIMARY KEY, persona_id VARCHAR(80) DEFAULT NULL, email VARCHAR(150) NOT NULL DEFAULT '', nominativo VARCHAR(200) NOT NULL DEFAULT '',
                ruolo VARCHAR(100) DEFAULT '', compiti VARCHAR(100) NOT NULL DEFAULT '', creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'didattica_sedute' => "CREATE TABLE IF NOT EXISTS didattica_sedute (
                id INT AUTO_INCREMENT PRIMARY KEY, organo VARCHAR(500) NOT NULL DEFAULT '', anno_accademico VARCHAR(20) DEFAULT '', data DATE DEFAULT NULL,
                ora_inizio VARCHAR(5) DEFAULT '', ora_fine VARCHAR(5) DEFAULT '', luogo VARCHAR(255) DEFAULT '', odg TEXT DEFAULT NULL, presenze TEXT DEFAULT NULL,
                segretario VARCHAR(200) DEFAULT '', coordinatore VARCHAR(200) DEFAULT '', creata_il DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_data (data)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v36: convenzioni FSL compilate online dalla scuola (moduli guidati di Convenzione e Allegato A, documenti Word
            // generati dai modelli del Dipartimento; poi firma digitale e invio via PEC)
            'convenzioni_compilate' => "CREATE TABLE IF NOT EXISTS convenzioni_compilate (
                id INT AUTO_INCREMENT PRIMARY KEY, token VARCHAR(40) NOT NULL, scuola_codice VARCHAR(10) DEFAULT NULL, prenotazione_id INT DEFAULT NULL,
                email VARCHAR(255) DEFAULT '', dati_json MEDIUMTEXT DEFAULT NULL, logo VARCHAR(255) DEFAULT NULL,
                creata_il DATETIME DEFAULT CURRENT_TIMESTAMP, aggiornata_il DATETIME DEFAULT NULL, scaricata_il DATETIME DEFAULT NULL,
                UNIQUE KEY uq_token (token), INDEX idx_scuola (scuola_codice)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v37: uffici dell'Ufficio didattico, definiti dal pannello (manager che smista, referenti dei corsi, carriere…):
            // il personale si assegna a un ufficio e l'iter dei moduli elenca gli uffici che ricevono la pratica
            'didattica_uffici' => "CREATE TABLE IF NOT EXISTS didattica_uffici (
                id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(150) NOT NULL DEFAULT '', descrizione VARCHAR(500) DEFAULT '', chiave VARCHAR(30) DEFAULT NULL,
                smista TINYINT(1) NOT NULL DEFAULT 0, segue_corsi TINYINT(1) NOT NULL DEFAULT 0, ordine INT NOT NULL DEFAULT 0, creato_il DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v38: consigli dei corsi di studio (organi delle sedute) con i referenti scelti dall'Ufficio didattico e i componenti
            // (docenti dall'anagrafe, inseriti una volta sola); presenze di ogni seduta (P = presente, AG / AI = assente giustificato / ingiustificato)
            'didattica_consigli' => "CREATE TABLE IF NOT EXISTS didattica_consigli (
                id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(500) NOT NULL DEFAULT '', corsi TEXT DEFAULT NULL, coordinatore VARCHAR(200) DEFAULT '',
                segretario VARCHAR(200) DEFAULT '', luogo VARCHAR(255) DEFAULT '', odg TEXT DEFAULT NULL, attivo TINYINT(1) NOT NULL DEFAULT 1, ordine INT NOT NULL DEFAULT 0,
                creato_il DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'didattica_consigli_persone' => "CREATE TABLE IF NOT EXISTS didattica_consigli_persone (
                id INT AUTO_INCREMENT PRIMARY KEY, consiglio_id INT NOT NULL, ruolo VARCHAR(12) NOT NULL DEFAULT 'componente', persona_id VARCHAR(80) DEFAULT NULL,
                email VARCHAR(150) DEFAULT '', nominativo VARCHAR(200) NOT NULL DEFAULT '', qualifica VARCHAR(150) DEFAULT '', ordine INT NOT NULL DEFAULT 0,
                creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_consiglio (consiglio_id, ruolo), INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'didattica_sedute_presenze' => "CREATE TABLE IF NOT EXISTS didattica_sedute_presenze (
                seduta_id INT NOT NULL, componente_id INT NOT NULL, nominativo VARCHAR(200) NOT NULL DEFAULT '', qualifica VARCHAR(150) DEFAULT '',
                ordine INT NOT NULL DEFAULT 0, stato VARCHAR(2) NOT NULL DEFAULT 'P', PRIMARY KEY (seduta_id, componente_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v38: chi ha avuto in carico la pratica (anche dopo il passaggio all'ufficio successivo continua a vederla e a integrarla)
            'pratiche_operatori' => "CREATE TABLE IF NOT EXISTS pratiche_operatori (
                pratica_id INT NOT NULL, operatore_id INT NOT NULL, passo TINYINT NOT NULL DEFAULT 0, dal DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (pratica_id, operatore_id), INDEX idx_operatore (operatore_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v38: catalogo di Ateneo per i moduli: corsi di studio di tutti i dipartimenti per anno di offerta (API cds) e insegnamenti
            // di un corso e di un anno di offerta, scaricati la prima volta che servono (API activities) e rinnovati ogni 30 giorni
            'ateneo_cds' => "CREATE TABLE IF NOT EXISTS ateneo_cds (
                codice VARCHAR(20) NOT NULL, anno SMALLINT NOT NULL, nome VARCHAR(255) NOT NULL DEFAULT '', tipo VARCHAR(10) DEFAULT '', tipo_descrizione VARCHAR(100) DEFAULT '',
                dipartimento_cod VARCHAR(20) DEFAULT '', dipartimento VARCHAR(255) DEFAULT '', aggiornato_il DATETIME DEFAULT NULL,
                PRIMARY KEY (codice, anno), INDEX idx_tipo (tipo, nome)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'ateneo_insegnamenti' => "CREATE TABLE IF NOT EXISTS ateneo_insegnamenti (
                id INT NOT NULL PRIMARY KEY, cds_cod VARCHAR(20) NOT NULL DEFAULT '', coorte SMALLINT NOT NULL DEFAULT 0, anno_corso TINYINT DEFAULT NULL,
                codice VARCHAR(30) DEFAULT '', nome VARCHAR(255) NOT NULL DEFAULT '', cfu DECIMAL(5,1) DEFAULT NULL, ssd_cod VARCHAR(20) DEFAULT '', ssd VARCHAR(150) DEFAULT '',
                partizione VARCHAR(150) DEFAULT '', semestre VARCHAR(60) DEFAULT '', docente VARCHAR(150) DEFAULT '', INDEX idx_cds (cds_cod, coorte)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'ateneo_insegnamenti_scaricati' => "CREATE TABLE IF NOT EXISTS ateneo_insegnamenti_scaricati (
                cds_cod VARCHAR(20) NOT NULL, coorte SMALLINT NOT NULL, n INT NOT NULL DEFAULT 0, scaricato_il DATETIME DEFAULT NULL, PRIMARY KEY (cds_cod, coorte)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // v38: Tutorato – bandi (decreti, direttore) e lettere di incarico dei vincitori: conferma dello studente con SPID/CIE,
            // firma digitale PAdES del docente responsabile e del direttore, protocollo; tutorato_eventi = storico della lettera
            'tutorato_bandi' => "CREATE TABLE IF NOT EXISTS tutorato_bandi (
                id INT AUTO_INCREMENT PRIMARY KEY, titolo VARCHAR(255) NOT NULL DEFAULT '', anno_accademico VARCHAR(20) DEFAULT '',
                decreto_bando VARCHAR(100) DEFAULT '', decreto_bando_data DATE DEFAULT NULL, decreto_commissione VARCHAR(100) DEFAULT '', decreto_commissione_data DATE DEFAULT NULL,
                direttore_persona_id VARCHAR(80) DEFAULT NULL, direttore_nome VARCHAR(200) DEFAULT '', direttore_email VARCHAR(150) DEFAULT '', direttore_cf VARCHAR(16) DEFAULT '',
                operatore_id INT DEFAULT NULL, luogo VARCHAR(100) DEFAULT 'Rende', creato_da INT DEFAULT NULL, creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, aggiornato_il DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'tutorato_incarichi' => "CREATE TABLE IF NOT EXISTS tutorato_incarichi (
                id INT AUTO_INCREMENT PRIMARY KEY, bando_id INT NOT NULL, codice VARCHAR(20) NOT NULL, stato VARCHAR(20) NOT NULL DEFAULT 'bozza',
                genere CHAR(1) NOT NULL DEFAULT 'M', cognome VARCHAR(100) NOT NULL DEFAULT '', nome VARCHAR(100) NOT NULL DEFAULT '', luogo_nascita VARCHAR(150) DEFAULT '',
                data_nascita DATE DEFAULT NULL, comune_residenza VARCHAR(150) DEFAULT '', indirizzo VARCHAR(255) DEFAULT '', civico VARCHAR(20) DEFAULT '',
                codice_fiscale VARCHAR(16) NOT NULL DEFAULT '', email VARCHAR(255) NOT NULL DEFAULT '', telefono VARCHAR(40) DEFAULT '',
                attivita TEXT DEFAULT NULL, ore DECIMAL(6,1) DEFAULT NULL, periodo VARCHAR(255) DEFAULT '', compenso DECIMAL(10,2) DEFAULT NULL,
                docente_persona_id VARCHAR(80) DEFAULT NULL, docente_nome VARCHAR(100) DEFAULT '', docente_cognome VARCHAR(100) DEFAULT '', docente_email VARCHAR(150) DEFAULT '', docente_cf VARCHAR(16) DEFAULT '',
                token_studente VARCHAR(40) DEFAULT NULL, token_docente VARCHAR(40) DEFAULT NULL, token_direttore VARCHAR(40) DEFAULT NULL,
                studente_firma_json TEXT DEFAULT NULL, file_pdf VARCHAR(255) DEFAULT NULL, data_lettera DATE DEFAULT NULL,
                protocollo VARCHAR(100) NOT NULL DEFAULT '', protocollo_data DATE DEFAULT NULL, nota_studente TEXT DEFAULT NULL,
                inviata_il DATETIME DEFAULT NULL, confermata_il DATETIME DEFAULT NULL, firmata_docente_il DATETIME DEFAULT NULL, firmata_direttore_il DATETIME DEFAULT NULL,
                protocollata_il DATETIME DEFAULT NULL, creata_il DATETIME DEFAULT CURRENT_TIMESTAMP, aggiornata_il DATETIME DEFAULT NULL,
                UNIQUE KEY uq_codice (codice), INDEX idx_bando (bando_id), INDEX idx_stato (stato)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'tutorato_eventi' => "CREATE TABLE IF NOT EXISTS tutorato_eventi (
                id INT AUTO_INCREMENT PRIMARY KEY, incarico_id INT NOT NULL, tipo VARCHAR(20) NOT NULL DEFAULT '', testo TEXT DEFAULT NULL, autore VARCHAR(200) DEFAULT '',
                ip VARCHAR(45) DEFAULT '', creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_incarico (incarico_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'abilitazioni_ambito' => "CREATE TABLE IF NOT EXISTS abilitazioni_ambito (
                id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT NOT NULL, tipo VARCHAR(20) NOT NULL, pagina_id INT NOT NULL DEFAULT 0,
                creata_da INT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ambito (utente_id, tipo, pagina_id), INDEX idx_pagina (pagina_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'valutazioni_fsl' => "CREATE TABLE IF NOT EXISTS valutazioni_fsl (
                id INT AUTO_INCREMENT PRIMARY KEY, prenotazione_id INT NOT NULL, evento_id INT NOT NULL, scuola_codice VARCHAR(10) DEFAULT NULL,
                compilata_da VARCHAR(150) DEFAULT '', risposte_json TEXT, media DECIMAL(3,2) DEFAULT NULL, ripeterebbe VARCHAR(10) DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_pren (prenotazione_id), INDEX idx_ev (evento_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'convenzioni_scuole' => "CREATE TABLE IF NOT EXISTS convenzioni_scuole (
                id INT AUTO_INCREMENT PRIMARY KEY, scuola_codice VARCHAR(10) NOT NULL, data_stipula DATE DEFAULT NULL, scadenza DATE DEFAULT NULL,
                protocollo VARCHAR(100) DEFAULT '', note VARCHAR(500) DEFAULT '', avviso_scadenza_inviato TINYINT(1) NOT NULL DEFAULT 0,
                registrata_da VARCHAR(255) DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_scuola (scuola_codice), INDEX idx_scad (scadenza)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'partecipanti_prenotazione' => "CREATE TABLE IF NOT EXISTS partecipanti_prenotazione (
                id INT AUTO_INCREMENT PRIMARY KEY, prenotazione_id INT NOT NULL, cognome VARCHAR(100) NOT NULL DEFAULT '', nome VARCHAR(100) NOT NULL DEFAULT '',
                codice VARCHAR(20) DEFAULT NULL, escluso TINYINT(1) NOT NULL DEFAULT 0, anonimizzato TINYINT(1) NOT NULL DEFAULT 0, ordine INT NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pren (prenotazione_id), UNIQUE KEY uq_codice (codice)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($tabelle as $nome => $ddl) {
            if (!$conn->query($ddl)) { error_log("[assicura_schema] CREATE fallito su $nome: " . $conn->error); return; }
        }

        // 2. Colonne: 'tabella' => ['colonna' => ALTER] oppure ['colonna' => [ALTER, SQL da eseguire subito dopo averla creata]]
        $colonne = [
            'turni' => [
                'nome_turno' => "ADD COLUMN nome_turno VARCHAR(150) DEFAULT NULL AFTER evento_id, MODIFY data_turno DATE NULL DEFAULT NULL, MODIFY orario_inizio TIME NULL DEFAULT NULL, MODIFY orario_fine TIME NULL DEFAULT NULL",
                'token_checkin' => "ADD COLUMN token_checkin VARCHAR(64) DEFAULT NULL",
                // v15: partecipanti per iscrizione della singola edizione di un progetto (NULL = limiti generali del progetto)
                'min_partecipanti' => "ADD COLUMN min_partecipanti INT DEFAULT NULL",
                'max_partecipanti' => "ADD COLUMN max_partecipanti INT DEFAULT NULL",
                // v17: oltre questa data/ora chi ha prenotato non può più annullare né cambiare turno (NULL = sempre)
                'annullabile_fino' => "ADD COLUMN annullabile_fino DATETIME DEFAULT NULL",
                // v33: aula (risorsa di Prenotazioni e risorse) occupata dal turno: lo slot viene prenotato in automatico
                'risorsa_id' => "ADD COLUMN risorsa_id INT DEFAULT NULL",
            ],
            'risorse' => [
                // v33: colore della riga nella vista a calendario; docente dello sportello di ricevimento (anagrafe di Ateneo)
                'colore'     => "ADD COLUMN colore VARCHAR(7) DEFAULT NULL",
                'persona_id' => "ADD COLUMN persona_id VARCHAR(80) DEFAULT NULL, ADD INDEX idx_persona (persona_id)",
                // v35: sportello di un ufficio (es. 'didattica'): lo gestiscono gli operatori dell'ufficio
                'ufficio'    => "ADD COLUMN ufficio VARCHAR(20) NOT NULL DEFAULT ''",
            ],
            'pratiche' => [
                // v35: seduta del Consiglio a cui va la pratica, testo della delibera per il verbale, campi compilati dall'ufficio
                'seduta_id'    => "ADD COLUMN seduta_id INT DEFAULT NULL, ADD INDEX idx_seduta (seduta_id)",
                'delibera'     => "ADD COLUMN delibera TEXT DEFAULT NULL",
                'ufficio_json' => "ADD COLUMN ufficio_json MEDIUMTEXT DEFAULT NULL",
                // v36: chi ha in carico la pratica, passo dell'iter, integrazione richiesta allo studente (documenti o autodichiarazione)
                'assegnata_a'   => "ADD COLUMN assegnata_a INT DEFAULT NULL, ADD INDEX idx_assegnata (assegnata_a)",
                'passo'         => "ADD COLUMN passo TINYINT NOT NULL DEFAULT 0",
                'richiesta_json' => "ADD COLUMN richiesta_json TEXT DEFAULT NULL",
                // v37: numero e data di protocollo; ultimo promemoria inviato per la pratica ferma
                'protocollo'      => "ADD COLUMN protocollo VARCHAR(100) NOT NULL DEFAULT ''",
                'protocollo_data' => "ADD COLUMN protocollo_data DATE DEFAULT NULL",
                'promemoria_il'   => "ADD COLUMN promemoria_il DATETIME DEFAULT NULL",
                // v38: decisioni prese in seduta (convalide degli esami, inserimento nel piano di studi) ed esito in seduta
                'decisioni_json'  => "ADD COLUMN decisioni_json MEDIUMTEXT DEFAULT NULL",
                'esito_seduta'    => "ADD COLUMN esito_seduta VARCHAR(20) NOT NULL DEFAULT ''",
            ],
            'didattica_sedute' => [
                // v38: consiglio (organo) della seduta, con i suoi componenti e i referenti
                'consiglio_id' => "ADD COLUMN consiglio_id INT DEFAULT NULL, ADD INDEX idx_consiglio (consiglio_id)",
            ],
            'insegnamenti' => [
                // v38: crediti dell'insegnamento (proposti nelle convalide in seduta)
                'cfu' => "ADD COLUMN cfu DECIMAL(5,1) DEFAULT NULL",
            ],
            'convenzioni_compilate' => [
                // v37: protocollo della convenzione compilata online, riportato nei documenti
                'protocollo'      => "ADD COLUMN protocollo VARCHAR(100) NOT NULL DEFAULT ''",
                'protocollo_data' => "ADD COLUMN protocollo_data DATE DEFAULT NULL",
            ],
            'ufficio_didattica' => [
                // v37: ufficio di appartenenza (didattica_uffici) e corsi di studio seguiti (uffici che seguono i corsi)
                'ufficio_id' => "ADD COLUMN ufficio_id INT DEFAULT NULL",
                'corsi'   => "ADD COLUMN corsi TEXT DEFAULT NULL",
            ],
            'pratiche_eventi' => [
                // v36: note interne tra i referenti (non visibili allo studente) e nome di chi scrive
                'interno'     => "ADD COLUMN interno TINYINT(1) NOT NULL DEFAULT 0",
                'autore_nome' => "ADD COLUMN autore_nome VARCHAR(200) DEFAULT ''",
            ],
            'didattica_moduli' => [
                // v35: come compare il modulo nel verbale (titolo della sezione, testo per ogni pratica, delibera, elenco/scheda)
                'verbale_json' => "ADD COLUMN verbale_json TEXT DEFAULT NULL",
                // v36: iter della pratica (profili che la ricevono, in ordine)
                'iter_json'    => "ADD COLUMN iter_json TEXT DEFAULT NULL",
                // v37: modulo compilabile solo in un periodo; giorni dopo i quali si avvisa chi ha una pratica ferma (0 = mai)
                'aperto_dal'        => "ADD COLUMN aperto_dal DATE DEFAULT NULL",
                'aperto_al'         => "ADD COLUMN aperto_al DATE DEFAULT NULL",
                'giorni_promemoria' => "ADD COLUMN giorni_promemoria SMALLINT NOT NULL DEFAULT 7",
            ],
            'prenotazioni_risorse' => [
                // v33: prenotazione creata dal turno di un evento (aula collegata): si aggiorna con il turno
                'turno_id' => "ADD COLUMN turno_id INT DEFAULT NULL, ADD INDEX idx_turno (turno_id)",
            ],
            'sondaggi_domande' => [
                'condizione_json' => "ADD COLUMN condizione_json TEXT NULL",
                'ordine'          => "ADD COLUMN ordine INT DEFAULT 0",
                'obbligatorio'    => "ADD COLUMN obbligatorio TINYINT(1) DEFAULT 0",
            ],
            'campi_form' => [
                'condizione_json' => "ADD COLUMN condizione_json TEXT NULL",
            ],
            'configurazione_portale' => [
                'widgets_home'    => "ADD COLUMN widgets_home TEXT DEFAULT NULL",
                'annuncio_home'   => "ADD COLUMN annuncio_home TEXT DEFAULT NULL",
                'annuncio_colore' => "ADD COLUMN annuncio_colore VARCHAR(20) DEFAULT 'info'",
                // v20: logo per gli schermi piccoli (intestazione da telefono), accanto al nome del portale
                'logo_mobile_path' => "ADD COLUMN logo_mobile_path VARCHAR(255) DEFAULT ''",
                // v21: indirizzo della Dichiarazione di accessibilità pubblicata su form.agid.gov.it (link nel footer)
                'url_accessibilita' => "ADD COLUMN url_accessibilita VARCHAR(500) DEFAULT ''",
            ],
            'prenotazioni' => [
                'presente'                  => "ADD COLUMN presente INT DEFAULT 0 AFTER stato",
                'data_presenza'             => "ADD COLUMN data_presenza DATETIME DEFAULT NULL",
                'scadenza_conferma'         => "ADD COLUMN scadenza_conferma DATETIME DEFAULT NULL",
                'reminder_inviato'          => "ADD COLUMN reminder_inviato TINYINT(1) NOT NULL DEFAULT 0",
                'attestato_inviato'         => "ADD COLUMN attestato_inviato TINYINT(1) NOT NULL DEFAULT 0",
                'email_post_evento_inviata' => "ADD COLUMN email_post_evento_inviata TINYINT(1) NOT NULL DEFAULT 0",
                'token_sondaggio'           => "ADD COLUMN token_sondaggio VARCHAR(64) DEFAULT NULL",
                'sondaggio_completato'      => "ADD COLUMN sondaggio_completato TINYINT(1) NOT NULL DEFAULT 0",
                // v11: promemoria al docente per l'elenco degli studenti (progetti con attestati)
                'promemoria_elenco_inviato' => "ADD COLUMN promemoria_elenco_inviato TINYINT(1) NOT NULL DEFAULT 0",
                // v22: scuola scelta dall'anagrafe (codice meccanografico del plesso), per report e statistiche
                'scuola_codice' => "ADD COLUMN scuola_codice VARCHAR(10) DEFAULT NULL, ADD INDEX idx_scuola (scuola_codice)",
                // v25: risposta della scuola sulla convenzione ('si' | 'no'; NULL = domanda non prevista)
                'convenzione'   => "ADD COLUMN convenzione VARCHAR(10) DEFAULT NULL",
                // v26: promemoria sulla convenzione inviati alla scuola e avviso ai gestori prima dell'inizio
                'conv_promemoria'     => "ADD COLUMN conv_promemoria INT NOT NULL DEFAULT 0",
                'conv_promemoria_il'  => "ADD COLUMN conv_promemoria_il DATETIME DEFAULT NULL",
                'conv_avviso_gestori' => "ADD COLUMN conv_avviso_gestori TINYINT(1) NOT NULL DEFAULT 0",
                // v28: scheda di valutazione FSL (link personale, data dell'invito, promemoria)
                'valutazione_token'       => "ADD COLUMN valutazione_token VARCHAR(64) DEFAULT NULL",
                'valutazione_inviata'     => "ADD COLUMN valutazione_inviata DATETIME DEFAULT NULL",
                'valutazione_promemoria'  => "ADD COLUMN valutazione_promemoria TINYINT(1) NOT NULL DEFAULT 0",
            ],
            'eventi' => [
                'abilita_presenze'      => "ADD COLUMN abilita_presenze TINYINT(1) NOT NULL DEFAULT 1",
                'blocca_auto_archivio'  => "ADD COLUMN blocca_auto_archivio TINYINT(1) NOT NULL DEFAULT 0",
                'permessi_gestori_json' => "ADD COLUMN permessi_gestori_json TEXT DEFAULT NULL",
                // Indirizzi aggiuntivi (CSV) che ricevono il riepilogo di ogni prenotazione e disdetta dell'evento
                'email_notifiche_extra' => "ADD COLUMN email_notifiche_extra TEXT DEFAULT NULL",
                'allegato_pdf'          => "ADD COLUMN allegato_pdf VARCHAR(255) DEFAULT NULL",
                // v9: 'evento' | 'progetto' (i progetti si gestiscono da admin/progetti.php)
                'tipo'                  => "ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'evento'",
                // v13: testo breve mostrato nelle card (la descrizione completa sta nella scheda dell'evento)
                'descrizione_breve'     => "ADD COLUMN descrizione_breve TEXT DEFAULT NULL AFTER descrizione",
            ],
            'corsi_studio' => [
                // v24: ID del regolamento didattico, per il link alla pagina del corso sul portale di Ateneo
                'regdid_id' => "ADD COLUMN regdid_id INT DEFAULT NULL",
            ],
            // v10: articolazione del percorso (moduli/fasi/incontri) e sezioni obiettivi/conoscenze/competenze
            'progetti_dettagli' => [
                // v24: corso di studio dell'anagrafe collegato a progetti ed eventi (nome in struttura, link alla pagina del corso)
                'corso_codice' => "ADD COLUMN corso_codice VARCHAR(20) DEFAULT NULL",
                'moduli_json' => "ADD COLUMN moduli_json TEXT DEFAULT NULL",
                'obiettivi'   => "ADD COLUMN obiettivi TEXT DEFAULT NULL",
                'conoscenze'  => "ADD COLUMN conoscenze TEXT DEFAULT NULL",
                'competenze'  => "ADD COLUMN competenze TEXT DEFAULT NULL",
                // v11: progetto dedicato alle scuole (1 scuola per edizione) e attestati per gli studenti
                'per_scuole'  => "ADD COLUMN per_scuole TINYINT(1) NOT NULL DEFAULT 1",
                'attestati'   => "ADD COLUMN attestati TINYINT(1) NOT NULL DEFAULT 0",
                // v18: progetto che rimanda a un'altra pagina (slug di un'area del portale oppure indirizzo http/https)
                'destinazione' => "ADD COLUMN destinazione VARCHAR(300) DEFAULT NULL",
                // v25: attività di Formazione Scuola Lavoro: nel modulo si chiede alla scuola se ha la convenzione (se no: da approvare)
                'convenzione' => "ADD COLUMN convenzione TINYINT(1) NOT NULL DEFAULT 0",
                // v27: evento dedicato alle scuole (prenota il docente per la classe, con il numero di studenti)
                'dedicata_scuole' => "ADD COLUMN dedicata_scuole TINYINT(1) NOT NULL DEFAULT 0",
                // v31: insegnamento dell'anagrafe da cui nasce l'attività (aree "Gruppi degli insegnamenti")
                'insegnamento_id' => "ADD COLUMN insegnamento_id INT DEFAULT NULL",
            ],
            // v12: nomi degli studenti ridotti alle iniziali dopo il periodo di conservazione (i codici restano verificabili)
            // v27: file della convenzione e dell'Allegato A, docenti di riferimento indicati nell'Allegato A
            'convenzioni_scuole' => [
                'file_convenzione' => "ADD COLUMN file_convenzione VARCHAR(255) DEFAULT NULL",
                'file_allegato'    => "ADD COLUMN file_allegato VARCHAR(255) DEFAULT NULL",
                'docenti_json'     => "ADD COLUMN docenti_json TEXT DEFAULT NULL",
            ],
            'partecipanti_prenotazione' => [
                'anonimizzato' => "ADD COLUMN anonimizzato TINYINT(1) NOT NULL DEFAULT 0 AFTER escluso",
            ],
            'pagine_eventi' => [
                'copertina_path'        => "ADD COLUMN copertina_path VARCHAR(255) DEFAULT NULL",
                'mostra_in_home'        => "ADD COLUMN mostra_in_home TINYINT(1) NOT NULL DEFAULT 1",
                'limite_iscrizioni'     => "ADD COLUMN limite_iscrizioni VARCHAR(20) NOT NULL DEFAULT 'nessuno'",
                // v31: tipo dell'area (fsl, eventi, gruppi, calendario) che la colloca in una macroarea; '' = non assegnata
                'tipo_area'             => "ADD COLUMN tipo_area VARCHAR(20) NOT NULL DEFAULT ''",
                // v25: modelli della convenzione con le scuole e PEC a cui inviarla (vuoti = valori del DiBEST)
                'conv_url_modello'      => "ADD COLUMN conv_url_modello VARCHAR(500) DEFAULT NULL",
                'conv_url_allegato'     => "ADD COLUMN conv_url_allegato VARCHAR(500) DEFAULT NULL",
                'conv_pec'              => "ADD COLUMN conv_pec VARCHAR(255) DEFAULT NULL",
                // CSV dei gestori che ricevono le email sulle prenotazioni; NULL = tutti
                'notifiche_gestori_ids' => "ADD COLUMN notifiche_gestori_ids TEXT DEFAULT NULL",
                // v8: colonne usate dal codice ma assenti da schema.sql (sul server aggiunte a mano)
                'permessi_gestori_json' => "ADD COLUMN permessi_gestori_json TEXT DEFAULT NULL",
                'firma_nome'            => "ADD COLUMN firma_nome VARCHAR(255) DEFAULT ''",
                'firma_titolo'          => "ADD COLUMN firma_titolo VARCHAR(255) DEFAULT ''",
                'logo_attestato_path'   => "ADD COLUMN logo_attestato_path VARCHAR(255) DEFAULT ''",
                'allegati_box_info'     => "ADD COLUMN allegati_box_info TEXT DEFAULT NULL",
                'allegati_sidebar'      => "ADD COLUMN allegati_sidebar TEXT DEFAULT NULL",
                // v16: frase dell'attestato prima del titolo (NULL = predefinita per eventi/progetti)
                'testo_attestato'       => "ADD COLUMN testo_attestato VARCHAR(300) DEFAULT NULL",
            ],
            'impostazioni_sistema' => [
                'email_attestato_oggetto' => "ADD COLUMN email_attestato_oggetto VARCHAR(255) DEFAULT ''",
                'email_attestato_corpo'   => "ADD COLUMN email_attestato_corpo TEXT DEFAULT NULL",
                'email_sondaggio_oggetto' => "ADD COLUMN email_sondaggio_oggetto VARCHAR(255) DEFAULT ''",
                'email_sondaggio_corpo'   => "ADD COLUMN email_sondaggio_corpo TEXT DEFAULT NULL",
            ],
            // v19: voce di menu nascondibile (usata da admin/menu.php, mancava dallo schema; nuove aree: voce creata nascosta)
            'menu_voci' => [
                'visibile' => "ADD COLUMN visibile TINYINT(1) NOT NULL DEFAULT 1",
            ],
            'sottocategorie' => [
                // Sezione mostrata in alto, affiancata alle altre, nel layout Griglia (prima dedotto dal nome).
                // Alla creazione conserva l'aspetto attuale delle sezioni che prima venivano riconosciute dal nome.
                'affiancata_in_alto' => ["ADD COLUMN affiancata_in_alto TINYINT(1) NOT NULL DEFAULT 0",
                    "UPDATE sottocategorie SET affiancata_in_alto = 1 WHERE nome LIKE '%Online%' OR nome LIKE '%Generali%' OR nome LIKE '%Speciali%' OR nome LIKE '%Conclusive%'"],
            ],
            'utenti' => [
                'matricola_studente'   => "ADD COLUMN matricola_studente VARCHAR(50) DEFAULT NULL",
                'matricola_dipendente' => "ADD COLUMN matricola_dipendente VARCHAR(50) DEFAULT NULL",
                'ultimo_accesso'       => "ADD COLUMN ultimo_accesso DATETIME DEFAULT NULL",
                'ruoli_secondari'      => "ADD COLUMN ruoli_secondari VARCHAR(255) DEFAULT ''",
                'email_personalizzata' => "ADD COLUMN email_personalizzata TINYINT(1) NOT NULL DEFAULT 0",
                // v22: ultima scuola indicata dal docente, proposta già compilata alle iscrizioni successive
                'scuola_codice'        => "ADD COLUMN scuola_codice VARCHAR(10) DEFAULT NULL",
                // v23: persona dell'anagrafe di Ateneo collegata al login (per email)
                'persona_id'           => "ADD COLUMN persona_id VARCHAR(80) DEFAULT NULL, ADD INDEX idx_persona (persona_id)",
            ],
            'abilitazioni_attesa' => [
                // v29: perimetro dell'abilitazione in attesa del primo accesso ('area', 'attivita', 'progetti', 'eventi', 'fsl', 'fsl_convenzioni', 'fsl_scuole')
                'ambito' => "ADD COLUMN ambito VARCHAR(20) NOT NULL DEFAULT 'area'",
            ],
        ];
        foreach ($colonne as $tabella => $cols) {
            foreach ($cols as $col => $def) {
                [$alter, $dopo] = is_array($def) ? $def : [$def, null];
                $chk = $conn->query("SHOW COLUMNS FROM `$tabella` LIKE '$col'");
                if (!$chk) return;
                if ($chk->num_rows > 0) continue;
                if (!$conn->query("ALTER TABLE `$tabella` $alter")) {
                    error_log("[assicura_schema] ALTER fallito su $tabella.$col: " . $conn->error);
                    return;
                }
                if ($dopo !== null) $conn->query($dopo);
            }
        }

        // 3. Tipi di colonna da correggere nei database più vecchi
        $tipi = [
            // stato era ENUM senza 'annullata' (prima controllato in config.php a ogni richiesta)
            ['prenotazioni', 'stato', fn($t) => str_contains($t, 'enum'), "MODIFY COLUMN stato VARCHAR(50) DEFAULT 'confermata'"],
            // matricola nata numerica, ma può contenere lettere (prima ALTER in saml_login.php a ogni login)
            ['utenti', 'matricola', fn($t) => !str_contains($t, 'varchar'), "MODIFY COLUMN matricola VARCHAR(50) DEFAULT NULL"],
            // v14: descrizione breve con formattazione (l'HTML è più lungo dei 300 caratteri visibili)
            ['eventi', 'descrizione_breve', fn($t) => str_contains($t, 'varchar'), "MODIFY COLUMN descrizione_breve TEXT DEFAULT NULL"],
        ];
        foreach ($tipi as [$tabella, $col, $da_correggere, $alter]) {
            $res = $conn->query("SHOW COLUMNS FROM `$tabella` LIKE '$col'");
            $riga = $res ? $res->fetch_assoc() : null;
            if ($riga && $da_correggere(strtolower((string)$riga['Type'])) && !$conn->query("ALTER TABLE `$tabella` $alter")) {
                error_log("[assicura_schema] MODIFY fallito su $tabella.$col: " . $conn->error);
                return;
            }
        }

        // 4. v23: struttura di partenza dell'anagrafe (DiBEST) e gruppi assegnati al login in base al ruolo in Ateneo
        $conn->query("INSERT IGNORE INTO anagrafe_strutture (codice, nome) VALUES ('002014', 'Dipartimento di Biologia, Ecologia e Scienze della Terra')");
        foreach (GRUPPI_PERSONALE as $g) {
            $g_sql = $conn->real_escape_string($g);
            $conn->query("INSERT INTO ruoli (nome) SELECT '$g_sql' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ruoli WHERE nome = '$g_sql')");
        }

        // 4b. v32: indice per le tendine regione / provincia / comune della scelta guidata della scuola
        $idx = $conn->query("SHOW INDEX FROM scuole WHERE Key_name = 'idx_luogo'");
        if ($idx && $idx->num_rows === 0) $conn->query("ALTER TABLE scuole ADD INDEX idx_luogo (regione, provincia, comune)");

        // 4c. v37: uffici di partenza dell'Ufficio didattico (si cambiano dal pannello) e personale già assegnato a un profilo
        if ((int)($conn->query("SELECT COUNT(*) n FROM didattica_uffici")->fetch_assoc()['n'] ?? 1) === 0) {
            $conn->query("INSERT INTO didattica_uffici (nome, descrizione, chiave, smista, segue_corsi, ordine) VALUES
                ('Manager dell’Ufficio didattico', 'Riceve le pratiche nuove e le smista', 'manager', 1, 0, 1),
                ('Referenti dei corsi di studio', 'Istruttoria e verbali dei consigli di corso', 'referente_cdl', 0, 1, 2),
                ('Carriere studenti', 'Registrazione in carriera', 'carriere', 0, 0, 3),
                ('Internazionalizzazione', 'Mobilità e attività all’estero', 'internazionalizzazione', 0, 0, 4),
                ('Segreteria didattica', 'Operatori dell’ufficio', 'operatore', 0, 0, 5)");
        }
        $col_prof = $conn->query("SHOW COLUMNS FROM ufficio_didattica LIKE 'profilo'");
        if ($col_prof && $col_prof->num_rows) $conn->query("UPDATE ufficio_didattica o JOIN didattica_uffici u ON u.chiave = o.profilo SET o.ufficio_id = u.id WHERE o.ufficio_id IS NULL");

        // 4d. v38: i consigli dei corsi di studio del Dipartimento (si cambiano dal pannello Didattica → Sedute e verbali → Consigli)
        if ((int)($conn->query("SELECT COUNT(*) n FROM didattica_consigli")->fetch_assoc()['n'] ?? 1) === 0) {
            $conn->query("INSERT INTO didattica_consigli (nome, ordine) VALUES
                ('Consiglio Unificato del Corso di Laurea in Scienze Naturali e Ambientali e del Corso di Laurea Magistrale in Biodiversità e Conservazione dei Sistemi Naturali', 1),
                ('Consiglio Unificato del Corso di Laurea in Scienze Geologiche e del Corso di Laurea Magistrale in Scienze Geologiche per la Gestione dei Rischi Ambientali e le Georisorse', 2),
                ('Consiglio del Corso di Laurea in Scienze e Tecnologie per le Attività Motorie e Sportive', 3),
                ('Consiglio di Coordinamento del Corso di Laurea in Biologia, del Corso di Laurea Magistrale in Biologia, del Corso di Laurea in Scienze e Tecnologie Biologiche e del Corso di Laurea Magistrale in Health Biotechnology', 4),
                ('Consiglio di Coordinamento del Corso di Laurea Magistrale a Ciclo Unico in Conservazione e Restauro dei Beni Culturali', 5)");
        }

        // 5. v31: il portale diventa "Didattica DiBEST" (solo dove c'è ancora il nome predefinito di prima)
        $conn->query("UPDATE configurazione_portale SET nome_portale = 'Didattica DiBEST' WHERE nome_portale IN ('EventiDiBEST', 'Eventi DiBEST', 'Eventi Dibest')");
        $conn->query("UPDATE impostazioni_sistema SET smtp_from_name = REPLACE(REPLACE(smtp_from_name, 'EventiDiBEST', 'Didattica DiBEST'), 'Eventi DiBEST', 'Didattica DiBEST')
                      WHERE smtp_from_name LIKE '%Eventi%DiBEST%'");
        if (function_exists('invalidate_configurazione_portale_cache')) invalidate_configurazione_portale_cache();

        if (!is_dir(RADICE_SITO . '/cache')) @mkdir(RADICE_SITO . '/cache', 0755, true);
        @file_put_contents($marker, date('c'));
    }
}
