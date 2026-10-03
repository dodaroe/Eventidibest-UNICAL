<?php
// functions.php - Contiene tutte le logiche condivise

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Radice del sito per i percorsi dei file (le funzioni stanno in inc/, un livello più in basso)
if (!defined('RADICE_SITO')) define('RADICE_SITO', __DIR__);

// Le funzioni sono divise per argomento in inc/: l'ordine conta (costanti e codice eseguito al caricamento)
foreach (['base', 'sezioni', 'aspetto', 'liste_attesa', 'sistema', 'dati', 'eventi_progetti', 'anagrafi', 'fsl', 'prenotazioni', 'attestati', 'risorse', 'calendario_risorse', 'esporta', 'didattica', 'catalogo_ateneo', 'pdf', 'tutorato', 'tutorato_registro', 'sedute', 'schema'] as $__inc) {
    require_once __DIR__ . '/inc/' . $__inc . '.php';
}
unset($__inc);

if (isset($conn) && $conn instanceof mysqli) { assicura_schema($conn); }
?>
