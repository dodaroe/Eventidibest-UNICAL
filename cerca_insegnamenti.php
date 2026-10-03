<?php
// cerca_insegnamenti.php - Catalogo di Ateneo per i campi "Insegnamento" dei moduli online (JSON, serve l'accesso):
//   ?azione=tipi                         tipi di corso (triennale, magistrale, ciclo unico…)
//   ?azione=corsi&tipo=L                 corsi di studio di quel tipo, con gli anni accademici di offerta
//   ?azione=insegnamenti&cds=X&aa=2024   insegnamenti del corso per l'anno di offerta (scaricati dalle API la prima volta)
// Usato da assets/js/campi-pratica.js (finestra di scelta dell'insegnamento).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');
if (empty($_SESSION['utente_id'])) { http_response_code(401); echo json_encode(['errore' => 'Serve l\'accesso']); exit; }
if (!check_rate_limit($conn, 'cerca_insegnamenti', 240, 600)) { http_response_code(429); echo json_encode(['errore' => 'Troppe richieste']); exit; }

$azione = (string)($_GET['azione'] ?? '');
$out = [];
if ($azione === 'tipi') {
    foreach (catalogo_tipi_corso($conn) as $k => $n) $out[] = ['tipo' => $k, 'nome' => $n];
} elseif ($azione === 'corsi') {
    $tipo = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($_GET['tipo'] ?? '')));
    $out = $tipo !== '' ? catalogo_corsi($conn, $tipo) : [];
} elseif ($azione === 'insegnamenti') {
    $cds = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['cds'] ?? ''));
    $aa = (int)($_GET['aa'] ?? 0);
    foreach (catalogo_insegnamenti($conn, $cds, $aa) as $i) {
        $out[] = ['id' => (int)$i['id'], 'nome' => $i['nome'] . ($i['partizione'] !== '' ? ' (' . $i['partizione'] . ')' : ''), 'cfu' => $i['cfu'] !== null ? (float)$i['cfu'] : null,
                  'ssd' => (string)$i['ssd_cod'], 'ssd_nome' => (string)$i['ssd'], 'anno' => (int)$i['anno_corso'], 'semestre' => (string)$i['semestre']];
    }
} else {
    http_response_code(400);
    $out = ['errore' => 'Azione non valida'];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
