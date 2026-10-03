<?php
// inc/sezioni.php - Moduli del portale (Orientamento, Prenotazioni e risorse, Didattica, Gestione del portale),
// macroaree delle aree (Orientamento, Prenotazioni e risorse) e tipi di area.
// Ogni area (pagine_eventi) ha un tipo (pagine_eventi.tipo_area) che la colloca in una macroarea e decide le impostazioni
// proposte e ciò che si vede. Tipo vuoto = area non ancora assegnata: si comporta come sempre (eventi generici).
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// Macroaree che contengono aree (pagine_eventi): la chiave 'calendari' resta per compatibilità con i dati
if (!defined('SEZIONI_PORTALE')) define('SEZIONI_PORTALE', [
    'orientamento' => ['nome' => 'Orientamento', 'icona' => 'fa-compass',
                       'descr' => 'Welcome Week, eventi e seminari, Formazione Scuola Lavoro e laboratori per le scuole'],
    'calendari'    => ['nome' => 'Prenotazioni e risorse', 'icona' => 'fa-calendar-days',
                       'descr' => 'Aule, laboratori, sportelli e gruppi delle attività degli insegnamenti'],
]);

// Moduli: ognuno con il suo pannello (pagina iniziale e menu). Orientamento e Prenotazioni contengono aree;
// Didattica e Gestione del portale hanno pagine proprie. 'colore' per le card e il selettore in alto.
if (!defined('MODULI_PORTALE')) define('MODULI_PORTALE', [
    'orientamento' => ['nome' => 'Orientamento', 'icona' => 'fa-compass', 'colore' => '#0056B3',
                       'descr' => 'Welcome Week, eventi e seminari; sottomodulo Formazione Scuola Lavoro (convenzioni, attestati, valutazioni)'],
    'calendari'    => ['nome' => 'Prenotazioni e risorse', 'icona' => 'fa-calendar-days', 'colore' => '#7c3aed',
                       'descr' => 'Aule, laboratori e sportelli a slot, gruppi delle attività degli insegnamenti'],
    'didattica'    => ['nome' => 'Didattica', 'icona' => 'fa-graduation-cap', 'colore' => '#047857',
                       'descr' => 'Modulistica, moduli online e pratiche degli studenti, sedute dei consigli, tutorato'],
    'portale'      => ['nome' => 'Gestione del portale', 'icona' => 'fa-sliders', 'colore' => '#334155',
                       'descr' => 'Anagrafi, testata e home, menu, utenti e abilitazioni, sistema e registri'],
]);

// Pagine del pannello che appartengono a un modulo senza aree (le altre seguono l'area corrente)
if (!defined('PAGINE_MODULO')) define('PAGINE_MODULO', [
    'fsl.php' => 'orientamento', 'convenzione_file.php' => 'orientamento',
    'didattica.php' => 'didattica', 'tutorato.php' => 'didattica',
    'testata.php' => 'portale', 'menu.php' => 'portale', 'utenti.php' => 'portale', 'sistema.php' => 'portale', 'audit_log.php' => 'portale',
    'log_accessi.php' => 'portale', 'anagrafe_personale.php' => 'portale', 'anagrafe_docenti.php' => 'portale', 'anagrafe_pta.php' => 'portale',
    'anagrafe_insegnamenti.php' => 'portale', 'scuole.php' => 'portale', 'nuova_area.php' => 'portale',
]);

// disponibile = false: tipo previsto ma non ancora utilizzabile
if (!defined('TIPI_AREA')) define('TIPI_AREA', [
    'fsl'        => ['nome' => 'Formazione Scuola Lavoro', 'sezione' => 'orientamento', 'disponibile' => true,
                     'descr' => 'Progetti ed eventi per le scuole con convenzioni, elenco degli studenti e attestati: nuovi eventi e progetti con "Attività di Formazione Scuola Lavoro" già acceso.'],
    'eventi'     => ['nome' => 'Eventi e seminari', 'sezione' => 'orientamento', 'disponibile' => true,
                     'descr' => 'Eventi aperti a scuole, studenti ed esterni (es. Welcome Week).'],
    'gruppi'     => ['nome' => 'Gruppi degli insegnamenti', 'sezione' => 'calendari', 'disponibile' => true,
                     'descr' => 'Attività create a partire da un insegnamento dell\'anagrafe, con i gruppi come turni (es. Scienze Motorie).'],
    'calendario' => ['nome' => 'Aule, laboratori e sportelli', 'sezione' => 'calendari', 'disponibile' => true,
                     'descr' => 'Aule, laboratori e sportelli (appuntamenti con gli uffici) prenotabili a calendario, a slot.'],
]);

if (!function_exists('tipo_area')) {
    // Tipo dell'area ('' se non assegnato)
    function tipo_area(?array $pagina): string {
        $t = (string)($pagina['tipo_area'] ?? '');
        return isset(TIPI_AREA[$t]) ? $t : '';
    }
}

if (!function_exists('sezione_area')) {
    // Macroarea dell'area ('' se il tipo non è assegnato)
    function sezione_area(?array $pagina): string {
        $t = tipo_area($pagina);
        return $t !== '' ? TIPI_AREA[$t]['sezione'] : '';
    }
}

if (!function_exists('raggruppa_aree_per_sezione')) {
    // Aree divise per macroarea, nell'ordine di SEZIONI_PORTALE; in fondo ('') quelle non assegnate.
    // L'ordine delle aree dentro ogni macroarea resta quello ricevuto.
    function raggruppa_aree_per_sezione(array $aree): array {
        $gruppi = array_fill_keys(array_keys(SEZIONI_PORTALE), []);
        $gruppi[''] = [];
        foreach ($aree as $a) $gruppi[sezione_area($a)][] = $a;
        return array_filter($gruppi);
    }
}

if (!function_exists('modulo_di_area')) {
    // Modulo di un'area: quello della sua macroarea; le aree senza tipo (eventi generici) stanno in Orientamento
    function modulo_di_area(?array $pagina): string {
        $s = sezione_area($pagina);
        return $s !== '' ? $s : 'orientamento';
    }
}

if (!function_exists('html_scelta_tipo_area')) {
    // Tendina del tipo di area, raggruppata per macroarea
    function html_scelta_tipo_area(string $name, string $valore, string $attr = '', string $classi = 'form-select form-select-sm'): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $out = '<select name="' . $h($name) . '" class="' . $h($classi) . '" ' . $attr . '><option value="">Non assegnata (eventi generici)</option>';
        foreach (SEZIONI_PORTALE as $k_s => $s) {
            $out .= '<optgroup label="' . $h($s['nome']) . '">';
            foreach (TIPI_AREA as $k_t => $t) {
                if ($t['sezione'] !== $k_s) continue;
                $out .= '<option value="' . $h($k_t) . '"' . ($valore === $k_t ? ' selected' : '') . (!$t['disponibile'] ? ' disabled' : '') . '>' . $h($t['nome']) . (!$t['disponibile'] ? ' (in arrivo)' : '') . '</option>';
            }
            $out .= '</optgroup>';
        }
        return $out . '</select>';
    }
}
