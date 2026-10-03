<?php
// cerca_personale.php - Ricerca nell'anagrafe del personale di Ateneo (JSON) per referenti e abilitazioni.
// Solo per chi lavora nel pannello: amministratori, gestori di almeno un'area o un evento, Ufficio didattico e referenti dei consigli.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int)($_SESSION['utente_id'] ?? 0);
$sec = explode(',', (string)($_SESSION['utente_ruoli_secondari'] ?? ''));
$ok = $uid > 0 && ((int)($_SESSION['utente_ruolo_id'] ?? 5) === 1 || in_array('1', $sec, true) || (int)($_SESSION['utente_ruolo_id'] ?? 5) === 2 || in_array('2', $sec, true));
if ($uid > 0 && !$ok) {
    $ok = (bool)ambiti_utente($conn, $uid); // abilitati a un perimetro (progetti, eventi, FSL)
    $r = $conn->query("SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi");
    while (!$ok && $r && $x = $r->fetch_assoc()) $ok = in_array($uid, ids_gestori_da_campi($x['gestore_utente_id'], $x['gestori_utenti_ids'], $x['permessi_gestori_json']), true);
    $r = $conn->query("SELECT gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE archiviato = 0");
    while (!$ok && $r && $x = $r->fetch_assoc()) $ok = in_array($uid, ids_gestori_da_campi(0, $x['gestori_utenti_ids'], $x['permessi_gestori_json']), true);
}
if ($uid > 0 && !$ok) {
    // Ufficio didattico e referenti dei consigli (referenti, componenti, docenti delle lettere di incarico)
    $u_dd = $conn->query("SELECT * FROM utenti WHERE id = $uid")->fetch_assoc();
    $ok = utente_gestisce_didattica($conn, $u_dd) || (bool)consigli_referente($conn, $u_dd);
}
if (!$ok) { http_response_code(403); echo json_encode(['errore' => 'Accesso non consentito']); exit; }
session_write_close();

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$gruppo = (string)($_GET['gruppo'] ?? '');
$ruolo = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($_GET['ruolo'] ?? '')));
$struttura = preg_replace('/[^A-Za-z0-9.]/', '', (string)($_GET['struttura'] ?? ''));
// Serve almeno un filtro: 2 lettere del nome oppure ruolo/struttura
if (mb_strlen($q) < 2 && $ruolo === '' && $struttura === '' && !isset(GRUPPI_PERSONALE[$gruppo])) { echo json_encode(['risultati' => []]); exit; }
echo json_encode(['risultati' => cerca_personale($conn, $q, $gruppo, $ruolo, $struttura, 25)], JSON_UNESCAPED_UNICODE);
