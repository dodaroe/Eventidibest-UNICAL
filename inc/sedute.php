<?php
// inc/sedute.php - Sedute dei consigli, funzioni aggiunte dopo le presenze e le decisioni (inc/didattica.php):
// - convocazione per email (facoltativa): l'operatore o il referente personalizza oggetto e testo, ogni componente riceve il suo
//   link per giustificare l'assenza (giustifica.php), che diventa «assente giustificato» nelle presenze della seduta;
// - estratto del verbale per lo studente: PDF con la delibera e il quadro delle convalide, nella pratica quando si applicano gli esiti;
// - verbale firmato in PAdES: si carica il PDF del verbale, firma il segretario e poi il coordinatore (firma_verbale.php, firma remota
//   Aruba se configurata, altrimenti scarica, firma e ricarica); solleciti dal cron;
// - importazione dei componenti da un altro consiglio.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('DIR_VERBALI')) define('DIR_VERBALI', 'uploads/verbali/');
if (!defined('STATI_VERBALE')) define('STATI_VERBALE', [
    ''            => ['Non inviato alla firma', '#64748b'],
    'segretario'  => ['Da firmare: segretario verbalizzante', '#b45309'],
    'coordinatore' => ['Da firmare: coordinatore', '#7c3aed'],
    'firmato'     => ['Firmato in PAdES', '#15803d'],
]);
if (!defined('TESTO_CONVOCAZIONE')) define('TESTO_CONVOCAZIONE', "Gentile {NOME},\n\nè convocata la seduta del {ORGANO} per il giorno {DATA} alle ore {ORA}, presso {LUOGO}, con il seguente ordine del giorno:\n\n{ODG}\n\nIn caso di impedimento può giustificare l'assenza da qui: {LINK_GIUSTIFICA}\n\nCordiali saluti,\n{COORDINATORE}");

if (!function_exists('presenze_registrate')) {
    // Presenze per il verbale e il riepilogo: se ne è stata salvata almeno una (anche una giustificazione arrivata con la
    // convocazione), valgono tutti i componenti, con quelli non segnati proposti presenti; altrimenti nessuna.
    function presenze_registrate($conn, array $s): array {
        $tutte = !empty($s['id']) ? presenze_seduta($conn, $s) : [];
        return array_filter($tutte, fn($r) => !empty($r['_salvata'])) ? $tutte : [];
    }
}

// ==============================================================================
// CONVOCAZIONE PER EMAIL E GIUSTIFICAZIONE DELL'ASSENZA
// ==============================================================================
if (!function_exists('testo_convocazione')) {
    // Segnaposti: {NOME} {ORGANO} {DATA} {ORA} {LUOGO} {ODG} {COORDINATORE} {LINK_GIUSTIFICA}
    function testo_convocazione(string $tpl, array $s, string $nome, string $link): string {
        $odg = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$s['odg']))));
        $odg = implode("\n", array_map(fn($i, $t) => ($i + 1) . '. ' . preg_replace('/^\d+[\.\)]\s*/', '', $t), array_keys($odg), $odg));
        $sost = ['{NOME}' => $nome, '{ORGANO}' => (string)$s['organo'], '{DATA}' => $s['data'] ? date('d/m/Y', strtotime($s['data'])) : '____',
                 '{ORA}' => $s['ora_inizio'] ?: '____', '{LUOGO}' => (string)($s['luogo'] ?: '____'), '{ODG}' => $odg, '{COORDINATORE}' => (string)$s['coordinatore'], '{LINK_GIUSTIFICA}' => $link];
        if ($link === '') $tpl = preg_replace('/^.*\{LINK_GIUSTIFICA\}.*\R?/mu', '', $tpl);
        return strtr($tpl, $sost);
    }
    // Invia la convocazione ai componenti con l'email. $con_link: ognuno riceve il link per giustificare l'assenza.
    // Ritorna [inviate, componenti senza email, errore]
    function invia_convocazione($conn, array $s, string $oggetto, string $testo, bool $con_link): array {
        if (empty($s['consiglio_id'])) return [0, 0, "La convocazione si invia per le sedute di un consiglio con i componenti."];
        if (!$s['data']) return [0, 0, "Indica prima la data della seduta."];
        if ($s['data'] < date('Y-m-d')) return [0, 0, "La seduta è già passata."];
        $oggetto = mb_substr(trim($oggetto), 0, 255); $testo = mb_substr(trim($testo), 0, 10000);
        if ($oggetto === '' || $testo === '') return [0, 0, "Scrivi l'oggetto e il testo dell'email."];
        $comp = persone_consiglio($conn, (int)$s['consiglio_id']);
        if (!$comp) return [0, 0, "Il consiglio non ha componenti."];
        db_esegui($conn, "UPDATE didattica_sedute SET convocazione_oggetto = ?, convocazione_testo = ?, convocazione_il = NOW() WHERE id = ?", [$oggetto, $testo, (int)$s['id']]);
        $n = 0; $senza = 0; $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
        foreach ($comp as $c) {
            $email = strtolower(trim((string)$c['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $senza++; continue; }
            $tok = db_valore($conn, "SELECT token FROM didattica_convocazioni WHERE seduta_id = ? AND componente_id = ?", [(int)$s['id'], (int)$c['id']]);
            if (!$tok) {
                $tok = bin2hex(random_bytes(20));
                db_esegui($conn, "INSERT INTO didattica_convocazioni (seduta_id, componente_id, email, token) VALUES (?, ?, ?, ?)", [(int)$s['id'], (int)$c['id'], $email, $tok]);
            }
            db_esegui($conn, "UPDATE didattica_convocazioni SET email = ?, inviata_il = NOW() WHERE token = ?", [$email, $tok]);
            $link = $con_link ? url_base_sito() . '/giustifica.php?t=' . $tok : '';
            // Testo in HTML: il link diventa un collegamento
            $corpo = nl2br($h(testo_convocazione($testo, $s, (string)$c['nominativo'], $link !== '' ? '%%LINK%%' : '')));
            $corpo = str_replace('%%LINK%%', "<a href='" . $h($link) . "'>giustifica l'assenza</a>", $corpo);
            inviaNotificaEmail($email, testo_convocazione($oggetto, $s, (string)$c['nominativo'], ''), "<div style='font-size:14px;line-height:1.5;'>$corpo</div>", $conn, '#0056B3');
            $n++;
        }
        return [$n, $senza, null];
    }
    function convocazione_per_token($conn, string $tok): ?array {
        if (!preg_match('/^[a-f0-9]{40}$/', $tok)) return null;
        return db_riga($conn, "SELECT c.*, p.nominativo, p.qualifica FROM didattica_convocazioni c JOIN didattica_consigli_persone p ON p.id = c.componente_id WHERE c.token = ?", [$tok]);
    }
    // Il componente giustifica l'assenza dal link della convocazione: «assente giustificato» nelle presenze della seduta
    function giustifica_assenza($conn, string $tok, string $motivo): ?string {
        $c = convocazione_per_token($conn, $tok);
        $s = $c ? seduta_didattica($conn, (int)$c['seduta_id']) : null;
        if (!$c || !$s) return "Il link non è valido.";
        if ($s['data'] && $s['data'] < date('Y-m-d')) return "La seduta si è già svolta: per giustificare scrivi al coordinatore.";
        $motivo = mb_substr(trim($motivo), 0, 500);
        $ordine = (int)db_valore($conn, "SELECT COUNT(*) FROM didattica_sedute_presenze WHERE seduta_id = ?", [(int)$s['id']]);
        db_esegui($conn, "INSERT INTO didattica_sedute_presenze (seduta_id, componente_id, nominativo, qualifica, ordine, stato) VALUES (?, ?, ?, ?, ?, 'AG')
                          ON DUPLICATE KEY UPDATE stato = 'AG'", [(int)$s['id'], (int)$c['componente_id'], (string)$c['nominativo'], (string)$c['qualifica'], 1000 + $ordine]);
        db_esegui($conn, "UPDATE didattica_convocazioni SET giustificata_il = NOW(), motivo = ? WHERE id = ?", [$motivo, (int)$c['id']]);
        return null;
    }
    // Stato della convocazione per componente: [componente_id => riga]
    function convocazioni_seduta($conn, int $sid): array {
        $out = [];
        foreach (db_righe($conn, "SELECT * FROM didattica_convocazioni WHERE seduta_id = ?", [$sid]) as $r) $out[(int)$r['componente_id']] = $r;
        return $out;
    }
}

// ==============================================================================
// IMPORTA I COMPONENTI DA UN ALTRO CONSIGLIO
// ==============================================================================
if (!function_exists('importa_componenti_consiglio')) {
    // Copia i componenti di $da in $a (salta chi c'è già, per scheda dell'anagrafe o per nome). Ritorna quanti ne ha aggiunti.
    function importa_componenti_consiglio($conn, int $da, int $a): int {
        if ($da === $a || !consiglio_didattica($conn, $da) || !consiglio_didattica($conn, $a)) return 0;
        $gia = persone_consiglio($conn, $a);
        $pid = array_filter(array_column($gia, 'persona_id')); $nomi = array_map('mb_strtolower', array_column($gia, 'nominativo'));
        $ordine = (int)db_valore($conn, "SELECT COALESCE(MAX(ordine), 0) FROM didattica_consigli_persone WHERE consiglio_id = ?", [$a]);
        $n = 0;
        foreach (persone_consiglio($conn, $da) as $x) {
            if (($x['persona_id'] && in_array($x['persona_id'], $pid, true)) || in_array(mb_strtolower($x['nominativo']), $nomi, true)) continue;
            db_esegui($conn, "INSERT INTO didattica_consigli_persone (consiglio_id, ruolo, persona_id, email, nominativo, qualifica, ordine) VALUES (?, 'componente', ?, ?, ?, ?, ?)",
                      [$a, $x['persona_id'] ?: null, (string)$x['email'], (string)$x['nominativo'], (string)$x['qualifica'], ++$ordine]);
            $n++;
        }
        return $n;
    }
}

// ==============================================================================
// ESTRATTO DEL VERBALE PER LO STUDENTE (PDF)
// ==============================================================================
if (!function_exists('pdf_estratto_pratica')) {
    function pdf_estratto_pratica($conn, array $s, array $p): string {
        $v = verbale_modulo(['titolo' => $p['modulo_titolo'] ?? '', 'verbale_json' => $p['verbale_json'] ?? '']);
        $pdf = new PdfSemplice(['logo' => RADICE_SITO . '/' . LOGO_VERBALE, 'logo_larghezza' => 200, 'piede' => 'Estratto del verbale – pratica ' . $p['codice'],
                                'info' => ['Title' => 'Estratto del verbale – ' . trim($p['cognome'] . ' ' . $p['nome']), 'Subject' => 'Estratto del verbale della seduta del ' . ($s['data'] ? date('d/m/Y', strtotime($s['data'])) : '')]]);
        $pdf->paragrafo('**ESTRATTO DEL VERBALE**', ['al' => 'centro', 'dopo' => 4]);
        $pdf->paragrafo((string)$s['organo'], ['al' => 'centro', 'dopo' => 2]);
        $pdf->paragrafo('Seduta del ' . ($s['data'] ? date('d/m/Y', strtotime($s['data'])) : '____') . ($s['anno_accademico'] !== '' ? ' – a.a. ' . $s['anno_accademico'] : ''), ['al' => 'centro', 'dopo' => 16]);
        $pdf->paragrafo('**' . $v['sezione'] . '**', ['dopo' => 8, 'al' => 'sinistra']);
        $pdf->paragrafo(testo_segnaposti_pratica($v['testo'], $p), ['dopo' => 10]);
        $dec = json_decode((string)($p['decisioni_json'] ?? ''), true);
        if (is_array($dec) && !empty($dec['righe'])) {
            [$int, $righe] = tabella_decisioni($dec);
            $pdf->paragrafo('**' . ($dec['tipo'] === 'piano' ? 'Insegnamenti richiesti nel piano di studi' : 'Quadro delle convalide') . '**', ['sz' => 10, 'dopo' => 4]);
            $pdf->tabella($int, $righe, ['sz' => count($int) > 6 ? 7 : 9]);
            $pdf->spazio(8);
        }
        $esito = (string)($p['esito_seduta'] ?? '');
        $del_esito = ['respinta' => 'Il Consiglio non approva la richiesta.'][$esito] ?? '';
        $del = trim((string)$p['delibera']) !== '' ? (string)$p['delibera'] : ($del_esito !== '' ? $del_esito : $v['delibera']);
        $pdf->paragrafo('**Delibera** – ' . (ESITI_SEDUTA[$esito][0] ?? ''), ['dopo' => 4]);
        $pdf->paragrafo(testo_segnaposti_pratica($del, $p), ['dopo' => 18]);
        $pdf->paragrafo('Per estratto conforme al verbale della seduta.', ['sz' => 9, 'dopo' => 4]);
        $pdf->paragrafo(trim('Il Segretario verbalizzante ' . $s['segretario']) . "\n" . trim('Il Coordinatore ' . $s['coordinatore']), ['sz' => 9]);
        return $pdf->pdf();
    }
    // Salva l'estratto nella pratica come documento visibile allo studente. Ritorna l'id dell'evento o 0.
    function allega_estratto_pratica($conn, array $s, int $pid, int $uid, string $autore_nome = ''): int {
        $p = pratiche_per_esportazione($conn, [$pid])[0] ?? null;
        if (!$p) return 0;
        $dir = RADICE_SITO . '/' . DIR_PRATICHE;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_file($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "# Allegati delle pratiche: si scaricano solo da allegato_pratica.php\nRequire all denied\n");
        $nome = 'estratto_' . preg_replace('/[^A-Za-z0-9_-]/', '', $p['codice']) . '_' . bin2hex(random_bytes(4)) . '.pdf';
        if (@file_put_contents($dir . $nome, pdf_estratto_pratica($conn, $s, $p)) === false) return 0;
        evento_pratica($conn, $pid, 'attivita', 'ufficio', $uid, null, 'Estratto del verbale della seduta' . ($s['data'] ? ' del ' . date('d/m/Y', strtotime($s['data'])) : '') . ' con la delibera' . (json_decode((string)$p['decisioni_json'], true) ? ' e il quadro delle decisioni' : '') . '.',
                       DIR_PRATICHE . $nome, 'Estratto_verbale_' . $p['codice'] . '.pdf', false, $autore_nome);
        return (int)db_valore($conn, "SELECT MAX(id) FROM pratiche_eventi WHERE pratica_id = ? AND tipo = 'attivita'", [$pid]);
    }
}

// ==============================================================================
// VERBALE FIRMATO IN PADES: SEGRETARIO, POI COORDINATORE
// ==============================================================================
if (!function_exists('invia_verbale_alla_firma')) {
    function percorso_verbale_pdf(array $s): ?string {
        if (empty($s['verbale_pdf'])) return null;
        $base = realpath(RADICE_SITO . '/' . DIR_VERBALI); $p = realpath(RADICE_SITO . '/' . $s['verbale_pdf']);
        return ($base && $p && strpos($p, $base . DIRECTORY_SEPARATOR) === 0 && is_file($p)) ? $p : null;
    }
    function salva_file_verbale(array $s, string $pdf, string $suffisso): ?string {
        $dir = RADICE_SITO . '/' . DIR_VERBALI;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_file($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "# Verbali: si scaricano solo dal pannello o con il link personale\nRequire all denied\n");
        $nome = 'verbale_' . (int)$s['id'] . '_' . ($s['data'] ? date('Ymd', strtotime($s['data'])) : 'seduta') . '_' . $suffisso . '_' . bin2hex(random_bytes(4)) . '.pdf';
        return @file_put_contents($dir . $nome, $pdf) === false ? null : DIR_VERBALI . $nome;
    }
    function email_firma_verbale($conn, array $s, string $a, string $chi): void {
        $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); $link = url_base_sito() . '/firma_verbale.php?t=' . $s['verbale_token'];
        inviaNotificaEmail($a, "Verbale da firmare: " . etichetta_seduta($s),
            "<p>Gentile,</p><p>il verbale della seduta del <strong>" . $h($s['organo']) . "</strong>" . ($s['data'] ? " del " . date('d/m/Y', strtotime($s['data'])) : '') . " aspetta la sua firma digitale in PAdES come <strong>$chi</strong>.</p>"
            . "<p style='margin-top:18px;'><a href='" . $h($link) . "' style='background:#0056B3;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Apri e firma</a></p>"
            . "<p style='font-size:12px;color:#64748b;'>Si entra con le credenziali Unical, SPID o CIE. Il link è personale: non inoltrarlo.</p>", $conn, '#0056B3');
    }
    // Carica il PDF del verbale e lo manda alla firma del segretario (poi del coordinatore)
    function invia_verbale_alla_firma($conn, int $sid, string $pdf, string $email_seg, string $email_coo): ?string {
        $s = seduta_didattica($conn, $sid);
        if (!$s) return "Seduta non trovata.";
        if (($s['verbale_stato'] ?? '') === 'firmato') return "Il verbale è già firmato.";
        if (!str_starts_with($pdf, '%PDF')) return "Carica il verbale in PDF (esportalo da Word come PDF).";
        $email_seg = strtolower(trim($email_seg)); $email_coo = strtolower(trim($email_coo));
        if (!filter_var($email_seg, FILTER_VALIDATE_EMAIL) || !filter_var($email_coo, FILTER_VALIDATE_EMAIL)) return "Indica le email del segretario e del coordinatore.";
        $file = salva_file_verbale($s, $pdf, 'da_firmare');
        if (!$file) return "Non è stato possibile salvare il PDF.";
        db_esegui($conn, "UPDATE didattica_sedute SET verbale_pdf = ?, verbale_stato = 'segretario', verbale_token = ?, segretario_email = ?, coordinatore_email = ?, verbale_inviato_il = NOW(), verbale_firmato_il = NULL, verbale_sollecito_il = NULL, verbale_solleciti = 0 WHERE id = ?",
                  [$file, bin2hex(random_bytes(20)), $email_seg, $email_coo, $sid]);
        email_firma_verbale($conn, seduta_didattica($conn, $sid), $email_seg, 'segretario verbalizzante');
        return null;
    }
    function seduta_per_token_verbale($conn, string $tok): ?array {
        if (!preg_match('/^[a-f0-9]{40}$/', $tok)) return null;
        $id = (int)db_valore($conn, "SELECT id FROM didattica_sedute WHERE verbale_token = ?", [$tok]);
        return $id ? seduta_didattica($conn, $id) : null;
    }
    // Chi deve firmare ora (email dell'accesso): segretario o coordinatore
    function firmatario_verbale(array $s, ?array $u): bool {
        $e = strtolower(trim((string)($u['email'] ?? '')));
        $atteso = ($s['verbale_stato'] ?? '') === 'segretario' ? $s['segretario_email'] : (($s['verbale_stato'] ?? '') === 'coordinatore' ? $s['coordinatore_email'] : '');
        return $e !== '' && $e === strtolower((string)$atteso);
    }
    // Firma PAdES aggiunta al verbale (stesso PDF, revisione incrementale): passa al coordinatore, poi è firmato
    function registra_firma_verbale($conn, int $sid, string $pdf, string $come = 'caricamento'): ?string {
        $s = seduta_didattica($conn, $sid);
        if (!$s || !in_array($s['verbale_stato'], ['segretario', 'coordinatore'], true) || !($cor = percorso_verbale_pdf($s))) return "Il verbale non è in attesa di firma.";
        [$err, $info] = verifica_pdf_firmato((string)file_get_contents($cor), $pdf, '');
        if ($err) return $err;
        $chi = $s['verbale_stato'] === 'segretario' ? 'segretario' : 'coordinatore';
        $file = salva_file_verbale($s, $pdf, 'firmato_' . $chi);
        if (!$file) return "Non è stato possibile salvare il PDF firmato.";
        if ($chi === 'segretario') {
            db_esegui($conn, "UPDATE didattica_sedute SET verbale_pdf = ?, verbale_stato = 'coordinatore', verbale_token = ?, verbale_inviato_il = NOW(), verbale_sollecito_il = NULL, verbale_solleciti = 0 WHERE id = ?", [$file, bin2hex(random_bytes(20)), $sid]);
            email_firma_verbale($conn, seduta_didattica($conn, $sid), (string)$s['coordinatore_email'], 'coordinatore');
        } else {
            db_esegui($conn, "UPDATE didattica_sedute SET verbale_pdf = ?, verbale_stato = 'firmato', verbale_token = NULL, verbale_firmato_il = NOW() WHERE id = ?", [$file, $sid]);
            $s = seduta_didattica($conn, $sid); $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
            $a = array_unique(array_filter(array_merge([$s['segretario_email']], $s['consiglio_id'] ? array_column(persone_consiglio($conn, (int)$s['consiglio_id'], 'referente'), 'email') : [])));
            foreach ($a as $e) inviaNotificaEmail($e, "Verbale firmato: " . etichetta_seduta($s),
                "<p>Il verbale della seduta del <strong>" . $h($s['organo']) . "</strong>" . ($s['data'] ? " del " . date('d/m/Y', strtotime($s['data'])) : '') . " è firmato in PAdES dal segretario e dal coordinatore" . ($info['nome'] !== '' ? ' (' . $h($info['nome']) . ')' : '') . ".</p><p>Il PDF è in allegato e nel pannello Didattica › Sedute.</p>",
                $conn, '#15803d', [['path' => percorso_verbale_pdf($s), 'nome' => 'Verbale_' . ($s['data'] ? date('d_m_Y', strtotime($s['data'])) : 'seduta') . '_firmato.pdf']]);
        }
        return null;
    }
    // Cron: sollecito ogni FIRME_GIORNI_SOLLECITO giorni (al massimo 3) a chi deve firmare il verbale
    function solleciti_verbali($conn): int {
        $gg = max(1, (int)(env_valore('FIRME_GIORNI_SOLLECITO') ?? 5)); $n = 0;
        foreach (db_righe($conn, "SELECT id FROM didattica_sedute WHERE verbale_stato IN ('segretario', 'coordinatore') AND verbale_solleciti < 3
                                  AND COALESCE(verbale_sollecito_il, verbale_inviato_il) < NOW() - INTERVAL ? DAY", [$gg]) as $x) {
            $s = seduta_didattica($conn, (int)$x['id']);
            $seg = $s['verbale_stato'] === 'segretario';
            email_firma_verbale($conn, $s, (string)($seg ? $s['segretario_email'] : $s['coordinatore_email']), ($seg ? 'segretario verbalizzante' : 'coordinatore') . ' (sollecito)');
            db_esegui($conn, "UPDATE didattica_sedute SET verbale_sollecito_il = NOW(), verbale_solleciti = verbale_solleciti + 1 WHERE id = ?", [(int)$s['id']]);
            $n++;
        }
        return $n;
    }
}

if (!function_exists('conserva_dati_sedute')) {
    // Conservazione (cron_background.php, CONSERVAZIONE_CONVOCAZIONI_MESI, predefinito 12): i link della convocazione,
    // le email e i motivi delle assenze delle sedute passate da più di $mesi mesi si cancellano (le presenze restano nel verbale)
    function conserva_dati_sedute($conn, int $mesi): int {
        if ($mesi <= 0) return 0;
        return max(0, db_esegui($conn, "DELETE c FROM didattica_convocazioni c JOIN didattica_sedute s ON s.id = c.seduta_id WHERE s.data < CURDATE() - INTERVAL ? MONTH", [$mesi]));
    }
}
