<?php
// allegato_pratica.php - Scarica un allegato di una pratica (risposta del modulo ?r=n o messaggio ?e=id).
// Solo chi ha inviato la pratica, chi gestisce il modulo Didattica e i referenti del consiglio della seduta; i file stanno in uploads/pratiche/ (bloccata al web).
require_once __DIR__ . '/middleware.php';

$p = pratica($conn, (int)($_GET['p'] ?? 0));
$nega = function () { while (ob_get_level() > 0) ob_end_clean(); http_response_code(404); exit('File non trovato.'); };
if (!$p || ((int)$p['utente_id'] !== (int)$_SESSION['utente_id'] && !utente_vede_pratica($conn, $user_info, $p))) $nega();

$file = null; $nome = 'allegato';
if (isset($_GET['r'])) {
    $r = (json_decode((string)$p['risposte_json'], true) ?: [])[(int)$_GET['r']] ?? null;
    if ($r && !empty($r['file'])) { $file = $r['file']; $nome = $r['nome_file'] ?: $nome; }
} elseif (isset($_GET['e'])) {
    $st = $conn->prepare("SELECT allegato, nome_allegato FROM pratiche_eventi WHERE id = ? AND pratica_id = ?" . (utente_gestisce_didattica($conn, $user_info) ? "" : " AND interno = 0"));
    $eid = (int)$_GET['e']; $pid = (int)$p['id'];
    $st->bind_param("ii", $eid, $pid); $st->execute();
    if ($x = $st->get_result()->fetch_assoc()) { $file = $x['allegato']; $nome = $x['nome_allegato'] ?: $nome; }
}
$base = realpath(RADICE_SITO . '/' . DIR_PRATICHE);
$percorso = $file ? realpath(RADICE_SITO . '/' . $file) : false;
if (!$percorso || !$base || strpos($percorso, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($percorso)) $nega();

$ext = strtolower(pathinfo($percorso, PATHINFO_EXTENSION));
$tipi = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'p7m' => 'application/pkcs7-mime'];
$nome = preg_replace('/[^\w.\- ]+/u', '_', $nome);
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . ($tipi[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: ' . (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) ? 'inline' : 'attachment') . '; filename="' . $nome . '"');
header('Content-Length: ' . filesize($percorso));
header('X-Content-Type-Options: nosniff');
readfile($percorso);
exit;
