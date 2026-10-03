<?php
// inc/catalogo_ateneo.php - Catalogo di Ateneo per i moduli della Didattica: corsi di studio di tutti i dipartimenti
// per anno di offerta (API cds, aggiornati con l'anagrafe ogni settimana) e insegnamenti di un corso in un anno di offerta
// (API activities), scaricati la prima volta che uno studente li cerca e rinnovati ogni 30 giorni.
// Lo studente sceglie tipo di corso (triennale, magistrale…), corso di studio, anno accademico di offerta e insegnamento
// (cerca_insegnamenti.php + assets/js/campi-pratica.js); se non lo trova lo scrive a mano.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('TIPI_CORSO_ATENEO')) define('TIPI_CORSO_ATENEO', [
    'L' => 'Laurea (triennale)', 'LM' => 'Laurea magistrale', 'LM5' => 'Laurea magistrale a ciclo unico (5 anni)', 'LM6' => 'Laurea magistrale a ciclo unico (6 anni)',
]);

if (!function_exists('cfu_attivita_api')) {
    // Crediti di un'attività delle API (il nome del campo cambia tra le versioni delle API)
    function cfu_attivita_api(array $r): ?float {
        foreach (['StudyActivityCFU', 'StudyActivityECTS', 'StudyActivityCredits', 'CFU', 'ECTS', 'Credits'] as $k) {
            if (isset($r[$k]) && is_numeric(str_replace(',', '.', (string)$r[$k]))) return (float)str_replace(',', '.', (string)$r[$k]);
        }
        return null;
    }
}

if (!function_exists('sincronizza_catalogo_cds')) {
    // Corsi di studio di tutto l'Ateneo (API cds senza filtri), anni di offerta dagli ultimi 7 anni. Ritorna quanti, null se le API non rispondono.
    function sincronizza_catalogo_cds($conn): ?int {
        if (!function_exists('api_unical_tutte')) return null;
        $cds = api_unical_tutte('cds/');
        if ($cds === null) return null;
        $min = anno_accademico_corrente() - 7;
        $st = $conn->prepare("INSERT INTO ateneo_cds (codice, anno, nome, tipo, tipo_descrizione, dipartimento_cod, dipartimento, aggiornato_il) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                              ON DUPLICATE KEY UPDATE nome = VALUES(nome), tipo = VALUES(tipo), tipo_descrizione = VALUES(tipo_descrizione), dipartimento_cod = VALUES(dipartimento_cod), dipartimento = VALUES(dipartimento), aggiornato_il = NOW()");
        $n = 0;
        foreach ($cds as $c) {
            $cod = trim((string)($c['CdSCod'] ?? '')); $anno = (int)($c['AcademicYear'] ?? 0);
            if ($cod === '' || strlen($cod) > 20 || $anno < $min) continue;
            $nome = mb_substr(maiuscole_corso((string)($c['CdSName'] ?? '')), 0, 255);
            $tipo = mb_substr((string)($c['CourseType'] ?? ''), 0, 10); $tipo_d = mb_substr((string)($c['CourseTypeDescription'] ?? ''), 0, 100);
            $dc = mb_substr((string)($c['DepartmentCod'] ?? ''), 0, 20); $dn = mb_substr(trim((string)($c['DepartmentName'] ?? '')), 0, 255);
            $st->bind_param("sisssss", $cod, $anno, $nome, $tipo, $tipo_d, $dc, $dn);
            if ($st->execute()) $n++;
        }
        return $n;
    }
}

if (!function_exists('catalogo_tipi_corso')) {
    // Tipi di corso proposti: quelli presenti nel catalogo, altrimenti quelli dell'anagrafe dei corsi del Dipartimento
    function catalogo_tipi_corso($conn): array {
        $out = [];
        $r = @$conn->query("SELECT DISTINCT tipo, tipo_descrizione FROM ateneo_cds WHERE tipo <> '' ORDER BY tipo");
        while ($r && $x = $r->fetch_assoc()) $out[$x['tipo']] = TIPI_CORSO_ATENEO[$x['tipo']] ?? ($x['tipo_descrizione'] ?: $x['tipo']);
        if (!$out) {
            $r = @$conn->query("SELECT DISTINCT tipo, tipo_descrizione FROM corsi_studio WHERE presente = 1 AND tipo <> '' ORDER BY tipo");
            while ($r && $x = $r->fetch_assoc()) $out[$x['tipo']] = TIPI_CORSO_ATENEO[$x['tipo']] ?? ($x['tipo_descrizione'] ?: $x['tipo']);
        }
        $ord = array_flip(array_keys(TIPI_CORSO_ATENEO));
        uksort($out, fn($a, $b) => ($ord[$a] ?? 9) <=> ($ord[$b] ?? 9) ?: strcmp($a, $b));
        return $out;
    }
}

if (!function_exists('catalogo_corsi')) {
    // Corsi di studio di un tipo: [['codice', 'nome', 'dipartimento', 'anni' => [2025, 2024…]]], dal catalogo o dall'anagrafe del Dipartimento
    function catalogo_corsi($conn, string $tipo): array {
        $out = [];
        $st = $conn->prepare("SELECT codice, nome, dipartimento, GROUP_CONCAT(anno ORDER BY anno DESC) AS anni FROM ateneo_cds WHERE tipo = ? GROUP BY codice, nome, dipartimento ORDER BY nome");
        $st->bind_param("s", $tipo); $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $x) {
            $k = $x['codice'];
            if (isset($out[$k])) { $out[$k]['anni'] = array_values(array_unique(array_merge($out[$k]['anni'], array_map('intval', explode(',', $x['anni']))))); rsort($out[$k]['anni']); continue; }
            $out[$k] = ['codice' => $k, 'nome' => $x['nome'], 'dipartimento' => (string)$x['dipartimento'], 'anni' => array_map('intval', explode(',', (string)$x['anni']))];
        }
        if (!$out) {
            $st = $conn->prepare("SELECT codice, nome, anno FROM corsi_studio WHERE presente = 1 AND tipo = ? ORDER BY nome");
            $st->bind_param("s", $tipo); $st->execute();
            $aa = anno_accademico_corrente();
            foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $x) $out[$x['codice']] = ['codice' => $x['codice'], 'nome' => $x['nome'], 'dipartimento' => '', 'anni' => range($aa + 1, $aa - 6)];
        }
        return array_values($out);
    }
}

if (!function_exists('catalogo_corso')) {
    // Nome del corso dal catalogo (o dall'anagrafe del Dipartimento)
    function catalogo_corso($conn, string $codice): ?array {
        $st = $conn->prepare("SELECT codice, nome, tipo, dipartimento FROM ateneo_cds WHERE codice = ? ORDER BY anno DESC LIMIT 1");
        $st->bind_param("s", $codice); $st->execute();
        $x = $st->get_result()->fetch_assoc();
        if ($x) return $x;
        $c = function_exists('corso_studio') ? corso_studio($conn, $codice) : null;
        return $c ? ['codice' => $c['codice'], 'nome' => $c['nome'], 'tipo' => $c['tipo'], 'dipartimento' => ''] : null;
    }
}

if (!function_exists('catalogo_insegnamenti')) {
    // Insegnamenti di un corso per l'anno accademico di offerta (coorte nelle API): dalla copia locale se ha meno di 30 giorni,
    // altrimenti dalle API (se non rispondono resta la copia precedente, anche vecchia). Si aggiungono quelli dell'anagrafe del Dipartimento.
    function catalogo_insegnamenti($conn, string $cds, int $coorte, bool $scarica = true): array {
        if ($cds === '' || strlen($cds) > 20 || $coorte < 1990 || $coorte > 2100) return [];
        $st = $conn->prepare("SELECT scaricato_il FROM ateneo_insegnamenti_scaricati WHERE cds_cod = ? AND coorte = ?");
        $st->bind_param("si", $cds, $coorte); $st->execute();
        $ult = $st->get_result()->fetch_assoc()['scaricato_il'] ?? null;
        if ($scarica && (!$ult || strtotime($ult) < time() - 30 * 86400) && function_exists('api_unical_tutte')) {
            $el = api_unical_tutte('activities/', ['cds' => $cds, 'academic_year' => $coorte]);
            if ($el !== null) salva_catalogo_insegnamenti($conn, $cds, $coorte, $el);
        }
        $st = $conn->prepare("SELECT id, nome, codice, cfu, ssd_cod, ssd, anno_corso, partizione, semestre FROM ateneo_insegnamenti WHERE cds_cod = ? AND coorte = ? ORDER BY anno_corso, nome, partizione");
        $st->bind_param("si", $cds, $coorte); $st->execute();
        $out = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!$out) {
            // Corsi del Dipartimento: l'anagrafe degli insegnamenti è già sul portale (anno di erogazione = coorte + anno di corso - 1)
            $st = $conn->prepare("SELECT id, nome, codice, cfu, ssd_cod, ssd, anno_corso, partizione, semestre FROM insegnamenti WHERE cds_cod = ? AND coorte = ? ORDER BY anno_corso, nome, partizione");
            $st->bind_param("si", $cds, $coorte); $st->execute();
            $out = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        return $out;
    }
}

if (!function_exists('salva_catalogo_insegnamenti')) {
    // Salva gli insegnamenti arrivati dalle API per un corso e un anno di offerta (solo quelli di quel corso)
    function salva_catalogo_insegnamenti($conn, string $cds, int $coorte, array $el): int {
        $st = $conn->prepare("INSERT INTO ateneo_insegnamenti (id, cds_cod, coorte, anno_corso, codice, nome, cfu, ssd_cod, ssd, partizione, semestre, docente) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE cds_cod = VALUES(cds_cod), coorte = VALUES(coorte), anno_corso = VALUES(anno_corso), codice = VALUES(codice), nome = VALUES(nome), cfu = VALUES(cfu),
                                ssd_cod = VALUES(ssd_cod), ssd = VALUES(ssd), partizione = VALUES(partizione), semestre = VALUES(semestre), docente = VALUES(docente)");
        $n = 0;
        foreach ($el as $r) {
            $id = (int)($r['StudyActivityID'] ?? 0); $nome = trim((string)($r['StudyActivityName'] ?? ''));
            $cds_r = (string)($r['StudyActivityCdSCod'] ?? $cds);
            if ($id <= 0 || $nome === '' || ($cds_r !== '' && $cds_r !== $cds)) continue;
            $nome = mb_substr(maiuscole_corso($nome), 0, 255);
            $anno_c = (int)($r['StudyActivityYear'] ?? 0) ?: null;
            $cod = mb_substr((string)($r['StudyActivityCod'] ?? ''), 0, 30);
            $cfu = cfu_attivita_api($r);
            $ssd_cod = mb_substr((string)($r['StudyActivitySSDCod'] ?? ''), 0, 20);
            $ssd = mb_substr(maiuscole_corso((string)($r['StudyActivitySSD'] ?? '')), 0, 150);
            $part = mb_substr(trim((string)($r['StudyActivityPartitionDes'] ?? $r['StudyActivityExtendedPartitionDes'] ?? '')), 0, 150);
            $sem = mb_substr((string)($r['StudyActivitySemester'] ?? ''), 0, 60);
            $doc = mb_substr(maiuscole_nome((string)($r['StudyActivityTeacherName'] ?? '')), 0, 150);
            $st->bind_param("isiissdsssss", $id, $cds, $coorte, $anno_c, $cod, $nome, $cfu, $ssd_cod, $ssd, $part, $sem, $doc);
            if ($st->execute()) $n++;
        }
        $st = $conn->prepare("INSERT INTO ateneo_insegnamenti_scaricati (cds_cod, coorte, n, scaricato_il) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE n = VALUES(n), scaricato_il = NOW()");
        $st->bind_param("sii", $cds, $coorte, $n); $st->execute();
        return $n;
    }
}

if (!function_exists('insegnamenti_dipartimento_scelta')) {
    // Insegnamenti del Dipartimento per le decisioni in seduta (convalide, piano di studi): id => etichetta con corso e CFU
    function insegnamenti_dipartimento_scelta($conn): array {
        static $out = null;
        if ($out !== null) return $out;
        $out = [];
        foreach (function_exists('insegnamenti_per_corso') ? insegnamenti_per_corso($conn) : [] as $corso => $ins) {
            foreach ($ins as $i) $out[(int)$i['id']] = ['id' => (int)$i['id'], 'nome' => $i['nome'] . ($i['partizione'] !== '' ? ' (' . $i['partizione'] . ')' : ''), 'corso' => $corso,
                                                        'cfu' => $i['cfu'] !== null ? (float)$i['cfu'] : null, 'ssd' => (string)$i['ssd_cod'], 'anno' => (int)$i['anno_corso']];
        }
        return $out;
    }
    function etichetta_insegnamento_scelta(array $i): string {
        return $i['nome'] . ' – ' . $i['corso'] . ($i['cfu'] !== null ? ' · ' . rtrim(rtrim(number_format($i['cfu'], 1, ',', ''), '0'), ',') . ' CFU' : '') . ($i['ssd'] !== '' ? ' · ' . $i['ssd'] : '');
    }
}
