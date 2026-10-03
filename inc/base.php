<?php
// inc/base.php - Utilità di base: escaping, messaggi flash, limiti di richieste, invio delle email, destinatari delle notifiche, indirizzo del sito.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// ── Utility: output escaping ──────────────────────────────────────────────────
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// ── Flash messages (sessione) ─────────────────────────────────────────────────
if (!function_exists('flash_set')) {
    function flash_set($msg, $type = 'success') {
        $_SESSION['_flash'] = ['msg' => $msg, 'type' => $type];
    }
    function flash_get() {
        if (isset($_SESSION['_flash'])) {
            $f = $_SESSION['_flash'];
            unset($_SESSION['_flash']);
            return $f;
        }
        return null;
    }
    function flash_html() {
        $f = flash_get();
        if (!$f) return '';
        $type = $f['type'];
        if ($type === 'danger')       { $cls = 'danger';  $icon = 'fa-times-circle'; }
        elseif ($type === 'warning')  { $cls = 'warning'; $icon = 'fa-exclamation-triangle'; }
        elseif ($type === 'info')     { $cls = 'info';    $icon = 'fa-info-circle'; }
        else                          { $cls = 'success'; $icon = 'fa-check-circle'; }
        return '<div class="alert alert-' . $cls . ' fw-bold text-center border-' . $cls
             . ' shadow-sm alert-dismissible fade show" role="alert">'
             . '<i class="fa ' . $icon . ' me-1"></i> '
             . h($f['msg'])
             . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

// ── Rate limiting (protezione endpoint da flooding) ───────────────────────────
if (!function_exists('check_rate_limit')) {
    /**
     * Verifica se l'IP corrente ha superato il limite di tentativi.
     * Ritorna true se la richiesta è permessa, false se bloccata.
     * L'IP viene hashato prima di salvarlo (privacy GDPR).
     */
    function check_rate_limit($conn, $endpoint, $max = 10, $window_sec = 300) {
        $ip_hash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . $endpoint);

        // Pulisce record vecchi (>1 ora) per tenere la tabella piccola
        $conn->query("DELETE FROM rate_limit_attempts WHERE hit_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        // Conta i tentativi nella finestra temporale corrente
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS hits FROM rate_limit_attempts
             WHERE ip_hash = ? AND endpoint = ? AND hit_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->bind_param("ssi", $ip_hash, $endpoint, $window_sec);
        $stmt->execute();
        $hits = (int)$stmt->get_result()->fetch_assoc()['hits'];
        $stmt->close();

        if ($hits >= $max) {
            return false; // bloccato
        }

        // Registra questo tentativo
        $stmt2 = $conn->prepare("INSERT INTO rate_limit_attempts (ip_hash, endpoint, hit_at) VALUES (?, ?, NOW())");
        $stmt2->bind_param("ss", $ip_hash, $endpoint);
        $stmt2->execute();
        $stmt2->close();

        return true;
    }
}

if (!defined('COOKIE_USCITO')) define('COOKIE_USCITO', 'dibest_uscito');
if (!function_exists('imposta_cookie_uscito')) {
    // true = l'utente ha fatto "Esci" (30 giorni); false = ha rifatto l'accesso
    function imposta_cookie_uscito(bool $uscito): void {
        setcookie(COOKIE_USCITO, $uscito ? '1' : '', ['expires' => $uscito ? time() + 30 * 86400 : time() - 3600,
                  'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    }
}

// 1. GESTIONE AUTENTICAZIONE SSO UNIFICATA (Con Auto-Riparazione Email)
if (!function_exists('db_query')) {
    // Query con parametri (prepared statement): i valori non entrano mai nel testo SQL.
    // db_righe($conn, "SELECT * FROM t WHERE id = ? AND stato = ?", [5, 'ok']) → righe; db_riga → una riga o null;
    // db_valore → primo campo della prima riga o null; db_esegui → righe toccate (-1 se la query non riesce).
    // Tipo di ogni parametro: int → i, float → d, il resto (anche null) → s.
    function db_query($conn, string $sql, array $par = []) {
        $st = $conn->prepare($sql);
        if (!$st) return false;
        if ($par) {
            $tipi = '';
            foreach ($par as $v) $tipi .= is_int($v) || is_bool($v) ? 'i' : (is_float($v) ? 'd' : 's');
            $par = array_map(fn($v) => is_bool($v) ? (int)$v : $v, array_values($par));
            $st->bind_param($tipi, ...$par);
        }
        if (!$st->execute()) return false;
        $r = $st->get_result();
        return $r === false ? $st : $r;
    }
    function db_righe($conn, string $sql, array $par = []): array {
        $r = db_query($conn, $sql, $par);
        return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }
    function db_riga($conn, string $sql, array $par = []): ?array {
        $r = db_query($conn, $sql, $par);
        return $r instanceof mysqli_result ? ($r->fetch_assoc() ?: null) : null;
    }
    function db_valore($conn, string $sql, array $par = []) {
        $r = db_query($conn, $sql, $par);
        $x = $r instanceof mysqli_result ? $r->fetch_row() : null;
        return $x ? $x[0] : null;
    }
    function db_esegui($conn, string $sql, array $par = []): int {
        $r = db_query($conn, $sql, $par);
        if ($r === false) return -1;
        return $r instanceof mysqli_stmt ? $r->affected_rows : $conn->affected_rows;
    }
}

if (!function_exists('metadati_accesso_saml')) {
    // Come si è autenticata la persona (dall'IdP SAML): SPID (con il livello), CIE o credenziali di Ateneo, con identificativi
    // e ora dell'autenticazione. Si conservano in sessione ($_SESSION['auth_meta']) e finiscono nella lettera di incarico
    // confermata dallo studente. Da chiamare prima di cleanup(): legge la sessione di SimpleSAML.
    function metadati_accesso_saml($as, array $attributes): array {
        $dato = function (string $k) use ($as) { try { return $as->getAuthData($k); } catch (\Throwable $e) { return null; } };
        $a1 = fn(string $k) => trim((string)(($attributes[$k][0] ?? '') ?: ''));
        $idp = (string)($dato('saml:sp:IdP') ?? '');
        $ctx = $dato('saml:sp:AuthnContext');
        $ctx = is_array($ctx) ? implode(' ', $ctx) : (string)($ctx ?? '');
        $ist = $dato('AuthnInstant');
        $spid_code = $a1('spidCode');
        $tutto = mb_strtolower($idp . ' ' . $ctx . ' ' . implode(' ', array_keys($attributes)));
        $metodo = 'ateneo';
        if (str_contains($tutto, 'cie') && (str_contains($tutto, 'servizicie') || str_contains($tutto, 'idserver') || str_contains($tutto, '/cie'))) $metodo = 'cie';
        elseif ($spid_code !== '' || str_contains($tutto, 'spid')) $metodo = 'spid';
        $livello = preg_match('/SpidL([123])/i', $ctx, $m) ? (int)$m[1] : null;
        return ['metodo' => $metodo, 'livello' => $livello, 'idp' => mb_substr($idp, 0, 255), 'contesto' => mb_substr($ctx, 0, 255),
                'spid_code' => mb_substr($spid_code, 0, 64), 'cf' => strtoupper(str_replace('TINIT-', '', $a1('fiscalNumber') ?: $a1('codice_fiscale'))),
                'sessione' => mb_substr((string)($dato('saml:sp:SessionIndex') ?? ''), 0, 120),
                'istante' => is_numeric($ist) ? date('c', (int)$ist) : date('c'), 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')];
    }
}

if (!function_exists('sync_sso_user')) {
    function sync_sso_user($conn) {
        if (!empty($_SESSION['utente_id'])) return true;
        // Dopo "Esci" niente accesso automatico dalla sessione SSO di Ateneo: si rientra solo con "Accedi"
        if (!empty($_COOKIE[COOKIE_USCITO])) return false;

        $simplesaml_path = '/opt/simplesamlphp/lib/_autoload.php';
        if (!file_exists($simplesaml_path)) return false;

        // Salviamo nome e ID sessione PRIMA di qualsiasi chiamata a SimpleSAML
        $our_session_name = session_name();
        $our_session_id   = session_id();

        try {
            require_once($simplesaml_path);
            $as = new \SimpleSAML\Auth\Simple('default-sp');
            if (!$as->isAuthenticated()) {
                // SimpleSAML può aver chiuso la sessione anche solo leggendo lo stato
                if (session_status() !== PHP_SESSION_ACTIVE && $our_session_id) {
                    session_name($our_session_name);
                    session_id($our_session_id);
                    session_start();
                }
                return false;
            }

            $attributes = $as->getAttributes();
            $meta_accesso = metadati_accesso_saml($as, $attributes);

            // Ripristina la nostra sessione PHP (pattern LibreBooking adSAML::Cleanup)
            \SimpleSAML\Session::getSessionFromRequest()->cleanup();

            // cleanup() può chiudere la sessione: la riapriamo esplicitamente
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_name($our_session_name);
                session_id($our_session_id);
                session_start();
            }

            $cf_saml = $attributes['codice_fiscale'][0] ?? null;

            if (!$cf_saml && !empty($attributes['urn:oid:1.3.6.1.4.1.25178.1.2.15'][0])) {
                $parts = explode(':', $attributes['urn:oid:1.3.6.1.4.1.25178.1.2.15'][0]);
                $cf_saml = end($parts);
            }

            if (!$cf_saml) return false;

            $cf_clean = strtoupper(trim($cf_saml));
            $stmt_cf = $conn->prepare("SELECT u.*, r.nome as ruolo_nome FROM utenti u LEFT JOIN ruoli r ON u.ruolo_id = r.id WHERE u.codice_fiscale = ? LIMIT 1");
            $stmt_cf->bind_param("s", $cf_clean);
            $stmt_cf->execute();
            $res_chk = $stmt_cf->get_result();

            if (!$res_chk || $res_chk->num_rows === 0) return false;

            $u_info = $res_chk->fetch_assoc();

            // Email mancante in DB: la recupera dall'IdP (mai inventare un indirizzo: le notifiche finirebbero nel vuoto)
            if (empty(trim($u_info['email'] ?? ''))) {
                $saml_email = estrai_email_saml($attributes, tipo_utente_saml((string)($u_info['matricola_studente'] ?? ''), (string)($u_info['matricola_dipendente'] ?? '')));
                if ($saml_email !== '') {
                    $stmt_em = $conn->prepare("UPDATE utenti SET email = ? WHERE id = ?");
                    $stmt_em->bind_param("si", $saml_email, $u_info['id']);
                    $stmt_em->execute();
                    $u_info['email'] = $saml_email;
                }
            }

            // Anagrafe di Ateneo: persona collegata per email, gruppo Docenti/PTA/Altro e abilitazioni in attesa
            $sec_ag = collega_utente_anagrafe($conn, (int)$u_info['id'], estrai_email_saml($attributes, tipo_utente_saml((string)($u_info['matricola_studente'] ?? ''), (string)($u_info['matricola_dipendente'] ?? ''))));
            if ($sec_ag !== null) $u_info['ruoli_secondari'] = $sec_ag;

            $_SESSION['utente_id']              = (int)$u_info['id'];
            $_SESSION['utente_cf']              = $u_info['codice_fiscale'];
            $_SESSION['utente_nome']            = trim($u_info['nome'] . ' ' . $u_info['cognome']);
            $_SESSION['utente_email']           = $u_info['email'];
            $_SESSION['utente_ruolo_id']        = (int)$u_info['ruolo_id'];
            $_SESSION['utente_ruoli_secondari'] = $u_info['ruoli_secondari'] ?? '';
            $_SESSION['auth_meta']              = $meta_accesso;

            if (empty($_SESSION['accesso_sso_loggato'])) {
                registra_accesso_sso($conn, (int)$u_info['id'], $u_info['email'], $u_info['nome'], $u_info['cognome'], 'sso');
                $_SESSION['accesso_sso_loggato'] = 1;
            }
            return true;

        } catch (\Throwable $e) {
            // Errore SimpleSAML: logga ma NON distruggere la sessione,
            // che potrebbe contenere dati validi scritti da saml_login.php.
            error_log('[SSO] sync_sso_user exception: ' . $e->getMessage());
            return false;
        }
    }
}

// 1a. EMAIL DAGLI ATTRIBUTI SAML
if (!function_exists('estrai_email_saml')) {
    // L'IdP può inviare l'email col nome breve (mail/email) o in formato OID (urn:oid:0.9.2342.19200300.100.1.3),
    // anche con più valori. Sceglie l'indirizzo in base al tipo di utente:
    //   'studente'   → @studenti.unical.it
    //   'dipendente' → @unical.it
    //   'esterno'    → email personale (SPID/CIE), cioè non di Ateneo
    // Se l'indirizzo preferito non c'è, usa il primo valido. Restituisce '' se non ne trova.
    function estrai_email_saml(array $attributes, string $tipo = 'esterno'): string {
        $chiavi = ['mail', 'email', 'Email', 'emailAddress', 'urn:oid:0.9.2342.19200300.100.1.3',
                   'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'];
        $candidate = [];
        foreach ($chiavi as $k) {
            foreach ((array)($attributes[$k] ?? []) as $v) {
                $v = strtolower(trim((string)$v));
                if (filter_var($v, FILTER_VALIDATE_EMAIL) && !in_array($v, $candidate, true)) $candidate[] = $v;
            }
        }
        if (empty($candidate)) {
            // Logga i NOMI degli attributi ricevuti (non i valori) per capire cosa manda l'IdP
            error_log('[SSO] Email non trovata negli attributi SAML. Attributi ricevuti: ' . implode(', ', array_keys($attributes)));
            return '';
        }
        $is_studenti = fn($e) => substr($e, -strlen('@studenti.unical.it')) === '@studenti.unical.it';
        $is_unical   = fn($e) => substr($e, -strlen('@unical.it')) === '@unical.it';
        $filtri = [
            'studente'   => $is_studenti,
            'dipendente' => $is_unical,
            'esterno'    => fn($e) => !$is_studenti($e) && !$is_unical($e),
        ];
        foreach ($candidate as $e) { if (($filtri[$tipo] ?? $filtri['esterno'])($e)) return $e; }
        return $candidate[0];
    }
}

if (!function_exists('tipo_utente_saml')) {
    // Stessa priorità del ruolo di default in saml_login.php: matricola studente > matricola dipendente > esterno (SPID/CIE)
    function tipo_utente_saml(string $matr_stud, string $matr_dip): string {
        if (trim($matr_stud) !== '') return 'studente';
        if (trim($matr_dip) !== '')  return 'dipendente';
        return 'esterno';
    }
}

// 1b. LOG ACCESSI SSO
if (!function_exists('registra_accesso_sso')) {
    function registra_accesso_sso($conn, $utente_id, $email, $nome, $cognome, $tipo = 'sso') {
        $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
        $stmt = $conn->prepare("INSERT INTO log_accessi (utente_id, email, nome, cognome, ip, user_agent, tipo) VALUES (?,?,?,?,?,?,?)");
        if ($stmt) {
            $stmt->bind_param("issssss", $utente_id, $email, $nome, $cognome, $ip, $ua, $tipo);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// 2. INVIO EMAIL UNIFICATO
// Ultimo errore di invio (mostrato dal pulsante "Email di prova" in admin/sistema.php)
$GLOBALS['ultimo_errore_email'] = '';

if (!function_exists('registra_log_email')) {
    // Traccia ogni invio in log_email: serve a capire quali mail partono e quali vengono rifiutate dal server SMTP.
    function registra_log_email($conn, string $to, string $subject, bool $ok, string $errore = '', string $canale = 'smtp') {
        $stmt = @$conn->prepare("INSERT INTO log_email (destinatario, oggetto, esito, canale, errore) VALUES (?,?,?,?,?)");
        if ($stmt) {
            $to = mb_substr($to, 0, 255); $subject = mb_substr($subject, 0, 255); $errore = mb_substr($errore, 0, 500);
            $esito = $ok ? 1 : 0;
            $stmt->bind_param("ssiss", $to, $subject, $esito, $canale, $errore);
            @$stmt->execute();
            $stmt->close();
        }
        if (!$ok) error_log("[Email] Invio fallito a $to ($canale): $errore");
    }
}

if (!function_exists('smtp_risposta')) {
    // Legge una risposta SMTP completa (anche multi-riga "250-...") e ne restituisce [codice, testo].
    function smtp_risposta($socket): array {
        $testo = '';
        while (($riga = fgets($socket, 515)) !== false) {
            $testo .= $riga;
            if (strlen($riga) < 4 || $riga[3] !== '-') break;
        }
        return [(int)substr($testo, 0, 3), trim($testo)];
    }
}

if (!function_exists('smtp_comando')) {
    // Invia un comando (null = solo lettura) e verifica il codice di risposta; eccezione se il server rifiuta.
    function smtp_comando($socket, ?string $cmd, array $codici_ok, string $fase): string {
        if ($cmd !== null) fwrite($socket, $cmd . "\r\n");
        [$codice, $testo] = smtp_risposta($socket);
        if (!in_array($codice, $codici_ok, true)) {
            throw new RuntimeException("$fase: " . ($testo !== '' ? $testo : 'nessuna risposta dal server'));
        }
        return $testo;
    }
}

if (!function_exists('inviaNotificaEmail')) {
    // $colore: colore dell'area (es. colore_area_turno()); null = rosso istituzionale
    // $allegati = [['path' => file sul server, 'nome' => nome del file nell'email], ...] (facoltativi)
    function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) {
        $GLOBALS['ultimo_errore_email'] = '';
        $to = trim((string)$to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $GLOBALS['ultimo_errore_email'] = 'Indirizzo destinatario non valido';
            return false;
        }

        // Ambiente locale di prova: nessun invio, l'email si salva in cache/email_locali/ (visibile da /__email)
        if (defined('AMBIENTE_LOCALE') && AMBIENTE_LOCALE) {
            $dir_loc = RADICE_SITO . '/cache/email_locali/';
            if (!is_dir($dir_loc)) @mkdir($dir_loc, 0755, true);
            $nome_loc = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '_' . preg_replace('/[^a-z0-9@._-]/i', '_', $to) . '.html';
            @file_put_contents($dir_loc . $nome_loc, "<!-- A: $to | Oggetto: " . htmlspecialchars((string)$subject) . " | Allegati: " . count($allegati) . " -->
"
                                                  . impagina_email((string)$body_html, 'Didattica DiBEST (locale)', $colore));
            registra_log_email($conn, $to, (string)$subject, true, '', 'locale');
            return true;
        }

        $res_sys = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1");
        $sys = $res_sys ? $res_sys->fetch_assoc() : null;
        if (!$sys) {
            $GLOBALS['ultimo_errore_email'] = 'Impostazioni di sistema mancanti';
            registra_log_email($conn, $to, (string)$subject, false, $GLOBALS['ultimo_errore_email'], '-');
            return false;
        }

        $host   = trim($sys['smtp_host'] ?? '');
        $port   = (int)($sys['smtp_port'] ?? 587);
        $user   = $sys['smtp_username'] ?? '';
        $pass   = $sys['smtp_password'] ?? '';
        $from_e = !empty($sys['smtp_from_email']) ? trim($sys['smtp_from_email']) : 'noreply.eventi@unical.it';
        $from_n = !empty($sys['smtp_from_name']) ? $sys['smtp_from_name'] : 'Didattica DiBEST';
        $secure = strtolower($sys['smtp_secure'] ?? 'tls');

        $body_html = impagina_email((string)$body_html, $from_n, $colore);

        $dominio_from = substr(strrchr($from_e, '@') ?: '@unical.it', 1);
        $headers  = "Date: " . date('r') . "\r\n";
        $headers .= "Message-ID: <" . bin2hex(random_bytes(12)) . "@" . $dominio_from . ">\r\n";
        $from_hdr = "From: =?UTF-8?B?" . base64_encode($from_n) . "?= <$from_e>\r\n";
        // base64 a righe da 76 caratteri: niente righe oltre il limite SMTP (998) e niente troncamenti su righe che iniziano con "."
        $html_b64 = chunk_split(base64_encode((string)$body_html), 76, "\r\n");
        $allegati = array_values(array_filter($allegati, fn($a) => !empty($a['path']) && is_readable($a['path'])));
        if (!$allegati) {
            $headers .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n";
            $body_b64 = $html_b64;
        } else {
            // Messaggio multipart: testo HTML + allegati (nomi dei file ridotti a caratteri sicuri)
            $confine = 'b_' . bin2hex(random_bytes(12));
            $headers .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$confine\"\r\n";
            $body_b64 = "--$confine\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $html_b64;
            foreach ($allegati as $a) {
                $nome_a = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($a['nome'] ?? basename($a['path'])));
                $body_b64 .= "--$confine\r\nContent-Type: application/octet-stream; name=\"$nome_a\"\r\nContent-Transfer-Encoding: base64\r\n"
                           . "Content-Disposition: attachment; filename=\"$nome_a\"\r\n\r\n" . chunk_split(base64_encode((string)file_get_contents($a['path'])), 76, "\r\n");
            }
            $body_b64 .= "--$confine--\r\n";
        }
        $subject_enc = "=?UTF-8?B?" . base64_encode((string)$subject) . "?=";

        $invia_con_mail = function (string $motivo) use ($to, $subject, $subject_enc, $headers, $from_hdr, $body_b64, $conn) {
            $ok = @mail($to, $subject_enc, $body_b64, $from_hdr . $headers);
            $GLOBALS['ultimo_errore_email'] = $ok ? '' : "$motivo; anche la funzione mail() di PHP ha fallito";
            registra_log_email($conn, $to, (string)$subject, $ok, $ok ? $motivo : $GLOBALS['ultimo_errore_email'], 'mail()');
            return $ok;
        };

        if ($host === '') return $invia_con_mail('Host SMTP non configurato');

        $transport = ($secure === 'ssl') ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 10);
        if (!$socket) return $invia_con_mail("Connessione SMTP a $host:$port fallita ($errstr)");
        stream_set_timeout($socket, 15);

        try {
            $ehlo_host = $_SERVER['SERVER_NAME'] ?? (gethostname() ?: 'localhost');
            smtp_comando($socket, null, [220], 'Benvenuto server');
            $caps = smtp_comando($socket, "EHLO $ehlo_host", [250], 'EHLO');

            if ($secure === 'tls') {
                smtp_comando($socket, "STARTTLS", [220], 'STARTTLS');
                $metodo = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (!@stream_socket_enable_crypto($socket, true, $metodo)) throw new RuntimeException('Negoziazione TLS fallita');
                $caps = smtp_comando($socket, "EHLO $ehlo_host", [250], 'EHLO dopo TLS');
            }

            if ($user !== '' && $pass !== '') {
                if (stripos($caps, 'AUTH') === false) throw new RuntimeException('Il server non accetta autenticazione (AUTH) con questa porta/cifratura');
                smtp_comando($socket, "AUTH LOGIN", [334], 'AUTH LOGIN');
                smtp_comando($socket, base64_encode($user), [334], 'AUTH username');
                smtp_comando($socket, base64_encode($pass), [235], 'Autenticazione (credenziali errate?)');
            }

            smtp_comando($socket, "MAIL FROM:<$from_e>", [250], 'Mittente rifiutato');
            smtp_comando($socket, "RCPT TO:<$to>", [250, 251], 'Destinatario rifiutato');
            smtp_comando($socket, "DATA", [354], 'DATA');
            fwrite($socket, $from_hdr . "To: <$to>\r\nSubject: $subject_enc\r\n" . $headers . "\r\n" . $body_b64 . "\r\n.\r\n");
            smtp_comando($socket, null, [250], 'Messaggio rifiutato');
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
            registra_log_email($conn, $to, (string)$subject, true);
            return true;
        } catch (Throwable $e) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
            $GLOBALS['ultimo_errore_email'] = $e->getMessage();
            registra_log_email($conn, $to, (string)$subject, false, $e->getMessage());
            return false;
        }
    }
}

// ── Destinatari notifiche gestori ────────────────────────────────────────────
if (!function_exists('ids_gestori_da_campi')) {
    // Unisce gli ID gestore dai tre formati presenti nel DB: campo singolo, CSV legacy, JSON permessi (chiavi = ID utente).
    // admin/abilitazioni.php oggi salva SOLO nel JSON: leggere solo il CSV fa perdere i gestori.
    function ids_gestori_da_campi($singolo, $csv, $json): array {
        $ids = [];
        if ((int)$singolo > 0) $ids[] = (int)$singolo;
        foreach (explode(',', (string)$csv) as $v) { if ((int)trim($v) > 0) $ids[] = (int)trim($v); }
        $perm = json_decode((string)$json ?: '{}', true);
        if (is_array($perm)) { foreach (array_keys($perm) as $k) { if ((int)$k > 0) $ids[] = (int)$k; } }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('get_gestori_ids_area')) {
    // Tutti i gestori di un'area: quelli dell'intera area + quelli assegnati ai singoli eventi.
    function get_gestori_ids_area($conn, int $pagina_id): array {
        $ids = [];
        $res = $conn->query("SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi WHERE id = $pagina_id LIMIT 1");
        if ($res && $r = $res->fetch_assoc()) $ids = ids_gestori_da_campi($r['gestore_utente_id'], $r['gestori_utenti_ids'], $r['permessi_gestori_json']);
        $res = $conn->query("SELECT gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE pagina_id = $pagina_id");
        if ($res) while ($r = $res->fetch_assoc()) $ids = array_merge($ids, ids_gestori_da_campi(0, $r['gestori_utenti_ids'], $r['permessi_gestori_json']));
        // Abilitati a tutti i progetti o a tutti gli eventi dell'area
        $res = @$conn->query("SELECT DISTINCT utente_id FROM abilitazioni_ambito WHERE pagina_id = $pagina_id AND tipo IN ('progetti', 'eventi')");
        if ($res) while ($r = $res->fetch_assoc()) $ids[] = (int)$r['utente_id'];
        return array_values(array_unique($ids));
    }
}

if (!function_exists('get_notifiche_gestori_attive')) {
    // ID dei gestori che ricevono le email sulle prenotazioni dell'area.
    // null = mai configurato dall'admin → le ricevono tutti i gestori.
    function get_notifiche_gestori_attive($conn, int $pagina_id): ?array {
        $res = @$conn->query("SELECT notifiche_gestori_ids FROM pagine_eventi WHERE id = $pagina_id LIMIT 1");
        $r = $res ? $res->fetch_assoc() : null;
        if (!$r || $r['notifiche_gestori_ids'] === null) return null;
        return array_values(array_filter(array_map('intval', explode(',', $r['notifiche_gestori_ids']))));
    }
}

if (!function_exists('set_notifica_gestore')) {
    function set_notifica_gestore($conn, int $pagina_id, int $utente_id, bool $attiva) {
        $attivi = get_notifiche_gestori_attive($conn, $pagina_id) ?? get_gestori_ids_area($conn, $pagina_id);
        $attivi = $attiva ? array_merge($attivi, [$utente_id]) : array_diff($attivi, [$utente_id]);
        $csv = implode(',', array_unique(array_map('intval', $attivi)));
        $stmt = $conn->prepare("UPDATE pagine_eventi SET notifiche_gestori_ids = ? WHERE id = ?");
        $stmt->bind_param("si", $csv, $pagina_id);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('get_email_gestori_evento')) {
    // Email dei gestori da avvisare per un evento: gestori dell'intera area + gestori del singolo evento.
    // $solo_notifiche_attive = true applica l'interruttore "Notifiche prenotazioni" di admin/abilitazioni.php.
    function get_email_gestori_evento($conn, int $evento_id, bool $solo_notifiche_attive = true): array {
        $res = $conn->query("SELECT e.pagina_id, e.gestori_utenti_ids AS ev_csv, e.permessi_gestori_json AS ev_json,
                                    pe.gestore_utente_id, pe.gestori_utenti_ids AS p_csv, pe.permessi_gestori_json AS p_json
                             FROM eventi e JOIN pagine_eventi pe ON e.pagina_id = pe.id WHERE e.id = $evento_id LIMIT 1");
        $r = $res ? $res->fetch_assoc() : null;
        if (!$r) return [];
        $ids = array_unique(array_merge(
            ids_gestori_da_campi($r['gestore_utente_id'], $r['p_csv'], $r['p_json']),
            ids_gestori_da_campi(0, $r['ev_csv'], $r['ev_json']),
            ids_ambito_attivita($conn, $evento_id, false)   // perimetri dell'area (progetti / eventi), non la FSL di tutte le aree
        ));
        if ($solo_notifiche_attive) {
            $attivi = get_notifiche_gestori_attive($conn, (int)$r['pagina_id']);
            if ($attivi !== null) $ids = array_intersect($ids, $attivi);
        }
        if (empty($ids)) return [];
        $emails = [];
        $res_u = $conn->query("SELECT DISTINCT email FROM utenti WHERE id IN (" . implode(',', array_map('intval', $ids)) . ") AND email IS NOT NULL AND email != ''");
        if ($res_u) while ($u = $res_u->fetch_assoc()) $emails[] = $u['email'];
        return $emails;
    }
}

// ── Abilitazioni per perimetro ───────────────────────────────────────────────
// Oltre a "tutta l'area" (JSON dell'area) e "singole attività" (JSON dell'attività) si può abilitare un utente a:
//   'progetti' / 'eventi'  tutte le attività di quel tipo di un'area, anche quelle create dopo (pagina_id = area)
//   'fsl'                  pannello Formazione Scuola Lavoro + tutte le attività FSL di tutte le aree
//   'fsl_convenzioni'      solo il registro delle convenzioni;  'fsl_scuole'  solo l'anagrafe delle scuole
// Dentro il perimetro l'utente vede e gestisce tutto: attività, iscritti, sondaggi, moduli, attestati, statistiche.
if (!defined('TIPI_AMBITO')) define('TIPI_AMBITO', [
    'progetti'        => "Tutti i progetti dell'area",
    'eventi'          => "Tutti gli eventi dell'area",
    'fsl'             => "Formazione Scuola Lavoro: tutto",
    'fsl_convenzioni' => "Formazione Scuola Lavoro: solo convenzioni",
    'fsl_scuole'      => "Formazione Scuola Lavoro: solo anagrafe scuole",
    // Moduli interi (pagina_id 0): tutte le aree del modulo come "tutta l'area"; Orientamento comprende la FSL
    'modulo_orientamento' => "Modulo Orientamento (tutte le aree e la Formazione Scuola Lavoro)",
    'modulo_calendari'    => "Modulo Prenotazioni e risorse (tutte le aree)",
    'modulo_didattica'    => "Modulo Didattica",
]);

if (!function_exists('ha_modulo')) {
    // Abilitato al modulo intero
    function ha_modulo($conn, int $uid, string $modulo): bool {
        return $modulo !== '' && ha_ambito($conn, $uid, 'modulo_' . $modulo);
    }
}

if (!function_exists('area_nel_modulo_utente')) {
    // L'area appartiene a un modulo a cui l'utente è abilitato per intero
    function area_nel_modulo_utente($conn, int $uid, ?array $pagina): bool {
        return $pagina && function_exists('modulo_di_area') && ha_modulo($conn, $uid, modulo_di_area($pagina));
    }
}

if (!function_exists('ambiti_utente')) {
    // [['tipo' => …, 'pagina_id' => …], …] dell'utente
    function ambiti_utente($conn, int $uid, bool $rileggi = false): array {
        static $cache = [];
        if ($rileggi) $cache = [];
        if ($uid <= 0) return [];
        if (!isset($cache[$uid])) {
            $cache[$uid] = [];
            $r = @$conn->query("SELECT tipo, pagina_id FROM abilitazioni_ambito WHERE utente_id = $uid");
            while ($r && $x = $r->fetch_assoc()) $cache[$uid][] = ['tipo' => $x['tipo'], 'pagina_id' => (int)$x['pagina_id']];
        }
        return $cache[$uid];
    }
}

if (!function_exists('ha_ambito')) {
    function ha_ambito($conn, int $uid, string $tipo, int $pagina_id = 0): bool {
        foreach (ambiti_utente($conn, $uid) as $a) if ($a['tipo'] === $tipo && $a['pagina_id'] === $pagina_id) return true;
        return false;
    }
}

if (!function_exists('sql_attivita_ambiti')) {
    // Condizione SQL (alias e = eventi) con le attività dell'area comprese nei perimetri dell'utente; '' se nessuna
    function sql_attivita_ambiti($conn, int $uid, int $pagina_id): string {
        $cond = [];
        if (ha_ambito($conn, $uid, 'progetti', $pagina_id)) $cond[] = "e.tipo = 'progetto'";
        if (ha_ambito($conn, $uid, 'eventi', $pagina_id)) $cond[] = "IFNULL(e.tipo, 'evento') <> 'progetto'";
        if (ha_ambito($conn, $uid, 'fsl') || ha_ambito($conn, $uid, 'modulo_orientamento')) $cond[] = "e.id IN (SELECT evento_id FROM progetti_dettagli WHERE convenzione = 1)";
        // Modulo intero a cui appartiene l'area: tutte le attività
        $r_p = $conn->query("SELECT * FROM pagine_eventi WHERE id = " . (int)$pagina_id);
        if ($r_p && ($pag = $r_p->fetch_assoc()) && area_nel_modulo_utente($conn, $uid, $pag)) $cond[] = "1 = 1";
        return $cond ? '(' . implode(' OR ', $cond) . ')' : '';
    }
}

if (!function_exists('attivita_da_ambiti')) {
    // ID delle attività dell'area comprese nei perimetri dell'utente
    function attivita_da_ambiti($conn, int $uid, int $pagina_id): array {
        $cond = sql_attivita_ambiti($conn, $uid, $pagina_id);
        if ($cond === '') return [];
        $ids = [];
        $r = $conn->query("SELECT e.id FROM eventi e WHERE e.pagina_id = $pagina_id AND $cond");
        while ($r && $x = $r->fetch_assoc()) $ids[] = (int)$x['id'];
        return $ids;
    }
}

if (!function_exists('aree_da_ambiti')) {
    // Aree in cui l'utente lavora grazie ai perimetri (tipo di attività, oppure attività FSL)
    function aree_da_ambiti($conn, int $uid): array {
        $aree = [];
        foreach (ambiti_utente($conn, $uid) as $a) if (in_array($a['tipo'], ['progetti', 'eventi'], true) && $a['pagina_id'] > 0) $aree[$a['pagina_id']] = true;
        // Moduli interi: tutte le aree del modulo
        $moduli = array_filter(array_map(fn($a) => str_starts_with($a['tipo'], 'modulo_') ? substr($a['tipo'], 7) : null, ambiti_utente($conn, $uid)));
        if ($moduli) {
            $r = $conn->query("SELECT * FROM pagine_eventi");
            while ($r && $x = $r->fetch_assoc()) if (in_array(modulo_di_area($x), $moduli, true)) $aree[(int)$x['id']] = true;
        }
        if (ha_ambito($conn, $uid, 'fsl') || ha_ambito($conn, $uid, 'modulo_orientamento')) {
            $r = $conn->query("SELECT DISTINCT e.pagina_id FROM eventi e JOIN progetti_dettagli pd ON pd.evento_id = e.id WHERE pd.convenzione = 1");
            while ($r && $x = $r->fetch_assoc()) $aree[(int)$x['pagina_id']] = true;
        }
        return array_keys($aree);
    }
}

if (!function_exists('ids_ambito_attivita')) {
    // Utenti che vedono un'attività grazie a un perimetro ('progetti'/'eventi' della sua area; con $con_fsl anche 'fsl')
    function ids_ambito_attivita($conn, int $ev_id, bool $con_fsl = true): array {
        $r = $conn->query("SELECT e.pagina_id, IFNULL(e.tipo, 'evento') AS tipo, IFNULL(pd.convenzione, 0) AS fsl
                           FROM eventi e LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id WHERE e.id = $ev_id LIMIT 1");
        $e = $r ? $r->fetch_assoc() : null;
        if (!$e) return [];
        $tipo = $e['tipo'] === 'progetto' ? 'progetti' : 'eventi';
        $cond = "(tipo = '$tipo' AND pagina_id = " . (int)$e['pagina_id'] . ")" . ($con_fsl && (int)$e['fsl'] === 1 ? " OR tipo IN ('fsl', 'modulo_orientamento')" : '');
        // Con $con_fsl (perimetri ampi) anche chi ha il modulo intero dell'area
        if ($con_fsl && function_exists('modulo_di_area')) {
            $r_p = $conn->query("SELECT * FROM pagine_eventi WHERE id = " . (int)$e['pagina_id']);
            if ($r_p && $pag = $r_p->fetch_assoc()) $cond .= " OR tipo = 'modulo_" . $conn->real_escape_string(modulo_di_area($pag)) . "'";
        }
        $ids = [];
        $r = @$conn->query("SELECT DISTINCT utente_id FROM abilitazioni_ambito WHERE $cond");
        while ($r && $x = $r->fetch_assoc()) $ids[] = (int)$x['utente_id'];
        return $ids;
    }
}

if (!function_exists('utente_gestisce_attivita')) {
    // L'utente lavora su questa attività: gestore dell'area, dell'attività o con un perimetro che la comprende
    function utente_gestisce_attivita($conn, int $uid, int $ev_id): bool {
        if ($uid <= 0 || $ev_id <= 0) return false;
        $r = $conn->query("SELECT e.gestori_utenti_ids AS ev_csv, e.permessi_gestori_json AS ev_json, pe.gestore_utente_id, pe.gestori_utenti_ids AS p_csv, pe.permessi_gestori_json AS p_json
                           FROM eventi e JOIN pagine_eventi pe ON e.pagina_id = pe.id WHERE e.id = $ev_id LIMIT 1");
        $x = $r ? $r->fetch_assoc() : null;
        if (!$x) return false;
        if (in_array($uid, ids_gestori_da_campi($x['gestore_utente_id'], $x['p_csv'], $x['p_json']), true)) return true;
        if (in_array($uid, ids_gestori_da_campi(0, $x['ev_csv'], $x['ev_json']), true)) return true;
        return in_array($uid, ids_ambito_attivita($conn, $ev_id), true);
    }
}

if (!function_exists('utente_ha_abilitazioni')) {
    // Ha almeno un'abilitazione: su un'area, su un'attività o un perimetro (progetti, eventi, FSL)
    function utente_ha_abilitazioni($conn, int $uid): bool {
        if ($uid <= 0) return false;
        if (ambiti_utente($conn, $uid)) return true;
        $r = $conn->query("SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi");
        while ($r && $x = $r->fetch_assoc()) if (in_array($uid, ids_gestori_da_campi($x['gestore_utente_id'], $x['gestori_utenti_ids'], $x['permessi_gestori_json']), true)) return true;
        $r = $conn->query("SELECT gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE archiviato = 0");
        while ($r && $x = $r->fetch_assoc()) if (in_array($uid, ids_gestori_da_campi(0, $x['gestori_utenti_ids'], $x['permessi_gestori_json']), true)) return true;
        return false;
    }
}

if (!function_exists('assegna_ambito')) {
    function assegna_ambito($conn, int $uid, string $tipo, int $pagina_id = 0, int $da = 0): bool {
        if ($uid <= 0 || !isset(TIPI_AMBITO[$tipo])) return false;
        if (in_array($tipo, ['fsl', 'fsl_convenzioni', 'fsl_scuole'], true) || str_starts_with($tipo, 'modulo_')) $pagina_id = 0;
        elseif ($pagina_id <= 0) return false;
        $st = $conn->prepare("INSERT IGNORE INTO abilitazioni_ambito (utente_id, tipo, pagina_id, creata_da) VALUES (?, ?, ?, ?)");
        $st->bind_param("isii", $uid, $tipo, $pagina_id, $da);
        $ok = $st->execute();
        ambiti_utente($conn, $uid, true);
        return $ok;
    }
}

if (!function_exists('revoca_ambito')) {
    // $tipo null = tutti i perimetri dell'utente nell'area $pagina_id (con $pagina_id 0: quelli FSL)
    function revoca_ambito($conn, int $uid, ?string $tipo, int $pagina_id = 0): void {
        if ($tipo !== null) {
            $st = $conn->prepare("DELETE FROM abilitazioni_ambito WHERE utente_id = ? AND tipo = ? AND pagina_id = ?");
            $st->bind_param("isi", $uid, $tipo, $pagina_id);
        } else {
            $st = $conn->prepare("DELETE FROM abilitazioni_ambito WHERE utente_id = ? AND pagina_id = ?");
            $st->bind_param("ii", $uid, $pagina_id);
        }
        $st->execute();
        ambiti_utente($conn, $uid, true);
    }
}

if (!function_exists('normalizza_lista_email')) {
    // Testo libero (separatori: virgola, punto e virgola, spazi, a capo) -> indirizzi validi, minuscoli, senza doppioni.
    // $scartati riceve gli indirizzi non validi, per poterli segnalare all'admin.
    function normalizza_lista_email(?string $testo, int $max = 10, ?array &$scartati = null): array {
        $scartati = [];
        $validi = [];
        foreach (preg_split('/[\s,;]+/', (string)$testo, -1, PREG_SPLIT_NO_EMPTY) as $e) {
            $e = strtolower(trim($e));
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) $validi[$e] = true; else $scartati[] = $e;
        }
        return array_slice(array_keys($validi), 0, $max);
    }
}

if (!function_exists('get_destinatari_notifiche_prenotazione')) {
    // Chi riceve il riepilogo di prenotazioni e disdette: gestori con notifiche attive + indirizzi aggiuntivi dell'evento
    // + referenti del progetto con "Riceve le iscrizioni" attivo.
    function get_destinatari_notifiche_prenotazione($conn, int $evento_id): array {
        $dest = [];
        foreach (get_email_gestori_evento($conn, $evento_id) as $e) $dest[strtolower(trim($e))] = true;
        $res = $conn->query("SELECT email_notifiche_extra FROM eventi WHERE id = $evento_id LIMIT 1");
        $extra = ($res && $r = $res->fetch_assoc()) ? (string)($r['email_notifiche_extra'] ?? '') : '';
        foreach (normalizza_lista_email($extra) as $e) $dest[$e] = true;
        $res_p = $conn->query("SELECT referenti_json FROM progetti_dettagli WHERE evento_id = $evento_id LIMIT 1");
        $referenti = ($res_p && $rp = $res_p->fetch_assoc()) ? (json_decode((string)$rp['referenti_json'], true) ?: []) : [];
        foreach ($referenti as $rf) {
            $em = strtolower(trim((string)($rf['email'] ?? '')));
            if (!empty($rf['notifiche']) && filter_var($em, FILTER_VALIDATE_EMAIL)) $dest[$em] = true;
        }
        return array_keys($dest);
    }
}

if (!function_exists('corpo_notifica_per')) {
    // Il pulsante "Apri gli iscritti del turno" solo per i gestori (hanno accesso all'amministrazione);
    // referenti dei progetti e indirizzi in copia ricevono il riepilogo senza link all'admin.
    function corpo_notifica_per(string $email, string $intro, array $riepilogo, array $email_gestori): string {
        $gestore = in_array(strtolower(trim($email)), array_map(fn($e) => strtolower(trim($e)), $email_gestori), true);
        return $intro . ($gestore ? $riepilogo['html'] : $riepilogo['html_senza_admin']);
    }
}

if (!function_exists('html_riepilogo_prenotazione')) {
    // Riepilogo completo di una prenotazione per le email a gestori e indirizzi aggiuntivi:
    // dati anagrafici, evento e turno, stato, e TUTTI i campi aggiuntivi del form con la loro etichetta
    // (gli allegati diventano link). Ritorna ['oggetto_evento' => titolo, 'html' => tabella] oppure null.
    function html_riepilogo_prenotazione($conn, int $pr_id): ?array {
        $res = $conn->query("SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
                                    e.id AS evento_id, e.titolo AS evento_titolo, e.luogo, e.pagina_id, pe.titolo AS area_titolo
                             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                             JOIN pagine_eventi pe ON e.pagina_id = pe.id WHERE pr.id = $pr_id LIMIT 1");
        $p = $res ? $res->fetch_assoc() : null;
        if (!$p) return null;

        $stati = ['confermata' => 'Confermata', 'in_attesa' => "In lista d'attesa", 'da_approvare' => 'Da approvare',
                  'richiesta_conferma' => 'Posto offerto, da confermare', 'annullata' => 'Annullata', 'rifiutata' => 'Rifiutata', 'scaduta' => 'Scaduta'];
        $righe = [
            'Partecipante'      => trim($p['nome'] . ' ' . $p['cognome']),
            'Email'             => $p['email'],
            'Matricola'         => $p['matricola'] ?? '',
            'Area'              => $p['area_titolo'],
            'Evento'            => $p['evento_titolo'],
            'Turno'             => etichetta_turno($p),
            'Luogo'             => $p['luogo'] ?? '',
            'Posti'             => (string)max(1, (int)$p['num_posti']),
            'Stato'             => $stati[$p['stato'] ?? 'confermata'] ?? (string)$p['stato'],
            'Convenzione'       => ['si' => 'Già stipulata (dichiarato dalla scuola)', 'no' => 'Da stipulare: prenotazione in attesa della convenzione'][$p['convenzione'] ?? ''] ?? '',
            'Codice'            => $p['codice_prenotazione'],
            'Registrata il'     => !empty($p['data_prenotazione']) ? date('d/m/Y H:i', strtotime($p['data_prenotazione'])) : '',
        ];
        $html_righe = '';
        foreach ($righe as $etichetta => $valore) {
            if ($valore === '' || $valore === null) continue;
            $html_righe .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;color:#6b7280;white-space:nowrap;vertical-align:top;">' . htmlspecialchars($etichetta) . '</td>'
                         . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;font-weight:bold;">' . htmlspecialchars((string)$valore) . '</td></tr>';
        }

        // Campi aggiuntivi: etichette da campi_form (dell'area o dell'evento), nell'ordine del form
        $custom = json_decode((string)($p['dati_custom_json'] ?? ''), true) ?: [];
        if ($custom) {
            $etichette = [];
            $pag = (int)$p['pagina_id']; $ev = (int)$p['evento_id'];
            $res_cf = $conn->query("SELECT nome_campo, etichetta FROM campi_form WHERE pagina_id = $pag AND (evento_id IS NULL OR evento_id = $ev) ORDER BY ordine ASC, id ASC");
            if ($res_cf) while ($cf = $res_cf->fetch_assoc()) $etichette[$cf['nome_campo']] = $cf['etichetta'];
            // Prima i campi nell'ordine del form, poi eventuali valori di campi non più presenti
            $chiavi = array_merge(array_values(array_intersect(array_keys($etichette), array_keys($custom))), array_diff(array_keys($custom), array_keys($etichette)));
            $html_righe .= '<tr><td colspan="2" style="padding:12px 10px 4px;font-weight:bold;color:#1f2937;">Informazioni aggiuntive</td></tr>';
            foreach ($chiavi as $k) {
                $v = trim((string)$custom[$k]);
                if ($v === '') continue;
                if (strpos($v, 'uploads/allegati_prenotazioni/') !== false) {
                    $link = [];
                    foreach (array_filter(array_map('trim', explode(',', $v))) as $i => $path) {
                        $link[] = '<a href="' . htmlspecialchars(url_base_sito() . '/' . ltrim($path, '/')) . '">Allegato ' . ($i + 1) . '</a>';
                    }
                    $cella = implode(' · ', $link);
                } else {
                    $cella = nl2br(htmlspecialchars($v));
                }
                $html_righe .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;color:#6b7280;vertical-align:top;">' . htmlspecialchars($etichette[$k] ?? ucfirst(str_replace('_', ' ', $k))) . '</td>'
                             . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;">' . $cella . '</td></tr>';
            }
        }

        $link_admin = url_base_sito() . '/admin/iscritti.php?p_id=' . (int)$p['pagina_id'] . '&f_turno=' . (int)$p['turno_id'];
        $tabella = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;margin:8px 0 16px;">' . $html_righe . '</table>';
        $html = $tabella . '<p><a href="' . htmlspecialchars($link_admin) . '" style="background:#B30000;color:#ffffff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;">Apri gli iscritti del turno</a></p>';
        // 'html_senza_admin': per i referenti dei progetti, che non hanno accesso all'amministrazione
        return ['oggetto_evento' => $p['evento_titolo'], 'html' => $html, 'html_senza_admin' => $tabella, 'dati' => $p];
    }
}

// ── Attestato: invio immediato se evento concluso ─────────────────────────────
if (!function_exists('url_base_sito')) {
    // URL della radice del portale (es. https://dibest2.unical.it/didattica), senza slash finale.
    // Calcolato dalla posizione di functions.php: corretto anche se chiamato da /admin o da un cron.
    function url_base_sito(): string {
        // Da riga di comando (cron) non ci sono HTTPS, host né document root: si usa l'indirizzo pubblico
        // (URL_SITO del file .env, altrimenti https://dibest2.unical.it/eventi)
        $cli = PHP_SAPI === 'cli';
        if ($cli && function_exists('env_valore') && preg_match('#^https?://[^/]+#i', (string)env_valore('URL_SITO'))) return rtrim((string)env_valore('URL_SITO'), '/');
        $proto = ($cli || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'dibest2.unical.it';
        $doc_root = (!$cli && !empty($_SERVER['DOCUMENT_ROOT'])) ? realpath($_SERVER['DOCUMENT_ROOT']) : '';
        $func_root = realpath(RADICE_SITO);
        $rel = ($doc_root && strpos($func_root, $doc_root) === 0) ? str_replace('\\', '/', substr($func_root, strlen($doc_root))) : '/eventi';
        return $proto . $host . rtrim($rel, '/');
    }
}
