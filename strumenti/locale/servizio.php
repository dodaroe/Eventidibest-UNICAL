<?php
// servizio.php - Pagine dell'ambiente locale di prova (solo server integrato di PHP, vedi router.php):
//   /__accesso  accesso come uno degli utenti di prova (al posto di SPID/CIE/SSO), ?esci=1 per uscire
//   /__email    email che il portale avrebbe inviato (salvate in cache/email_locali/)
//   /__cron     esegue a mano il cron delle attività in background

if (PHP_SAPI !== 'cli-server') exit;
chdir(realpath(__DIR__ . '/../..'));
require_once 'config.php';
require_once 'functions.php';
if (!AMBIENTE_LOCALE) exit('Solo nell\'ambiente locale (manca .env.locale).');
while (ob_get_level() > 0) ob_end_clean();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$pagina = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$menu = '<p style="margin:0 0 1rem"><a href="/eventi/">Portale</a> · <a href="/eventi/admin/">Pannello</a> · <a href="/__accesso">Accesso di prova</a> · <a href="/__email">Email</a> · <a href="/__cron">Cron</a></p>';
$testa = fn($t) => "<!doctype html><html lang='it'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>$t · locale</title>"
    . "<style>body{font-family:system-ui,sans-serif;max-width:1000px;margin:2rem auto;padding:0 16px;color:#1e293b}a{color:#0b5cad}table{border-collapse:collapse;width:100%}"
    . "td,th{border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left;font-size:14px;vertical-align:top}.b{display:inline-block;padding:6px 12px;background:#b30000;color:#fff;border-radius:6px;text-decoration:none}"
    . "iframe{width:100%;height:70vh;border:1px solid #cbd5e1;border-radius:8px}</style></head><body><h1 style='font-size:1.3rem'>Ambiente locale · $t</h1>";

if ($pagina === '/__accesso') {
    if (isset($_GET['esci'])) { $_SESSION = []; session_destroy(); header('Location: /__accesso'); exit; }
    $vai = (string)($_GET['vai'] ?? '');
    if (!preg_match('#^/eventi/#', $vai)) $vai = '';
    if (isset($_GET['u'])) {
        $st = $conn->prepare("SELECT * FROM utenti WHERE id = ?");
        $id = (int)$_GET['u']; $st->bind_param("i", $id); $st->execute();
        $u = $st->get_result()->fetch_assoc();
        if ($u) {
            session_regenerate_id(true);
            $_SESSION['utente_id'] = (int)$u['id'];
            $_SESSION['utente_cf'] = $u['codice_fiscale'];
            $_SESSION['utente_nome'] = trim($u['nome'] . ' ' . $u['cognome']);
            $_SESSION['utente_email'] = $u['email'];
            $_SESSION['utente_ruolo_id'] = (int)$u['ruolo_id'];
            $_SESSION['utente_ruoli_secondari'] = $u['ruoli_secondari'] ?? '';
            $_SESSION['accesso_sso_loggato'] = 1;
            // Accesso di prova come se fosse SPID livello 2 (serve per confermare le lettere di incarico)
            $_SESSION['auth_meta'] = ['metodo' => 'spid', 'livello' => 2, 'idp' => 'https://idp-di-prova.locale', 'contesto' => 'https://www.spid.gov.it/SpidL2', 'spid_code' => 'PROVA' . (int)$u['id'], 'cf' => $u['codice_fiscale'], 'sessione' => '', 'istante' => date('c'), 'ip' => '127.0.0.1'];
            header('Location: ' . ($vai ?: ((int)$u['ruolo_id'] <= 2 ? '/eventi/admin/' : '/eventi/area_personale.php'))); exit;
        }
    }
    echo $testa('Accesso di prova') . $menu;
    echo '<p>Al posto di SPID/CIE/SSO: scegli con chi entrare.' . (!empty($_SESSION['utente_id']) ? ' Ora sei <strong>' . $h($_SESSION['utente_nome']) . '</strong> · <a href="/__accesso?esci=1">Esci</a>' : '') . '</p><table><tr><th>Utente</th><th>Email</th><th>Ruolo</th><th></th></tr>';
    $r = $conn->query("SELECT u.*, r.nome AS ruolo FROM utenti u LEFT JOIN ruoli r ON r.id = u.ruolo_id ORDER BY u.ruolo_id, u.id");
    while ($r && $u = $r->fetch_assoc()) {
        echo '<tr><td>' . $h($u['nome'] . ' ' . $u['cognome']) . '</td><td>' . $h($u['email']) . '</td><td>' . $h($u['ruolo'] ?? $u['ruolo_id']) . ($u['ruoli_secondari'] ? ' + ' . $h($u['ruoli_secondari']) : '')
           . '</td><td><a class="b" href="/__accesso?u=' . (int)$u['id'] . ($vai ? '&vai=' . urlencode($vai) : '') . '">Entra</a></td></tr>';
    }
    exit('</table></body></html>');
}

if ($pagina === '/__email') {
    $dir = __DIR__ . '/../../cache/email_locali/';
    $files = is_dir($dir) ? array_reverse(glob($dir . '*.html')) : [];
    if (isset($_GET['svuota'])) { foreach ($files as $f) @unlink($f); header('Location: /__email'); exit; }
    if (isset($_GET['f']) && preg_match('/^[A-Za-z0-9@._-]+\.html$/', $_GET['f']) && is_file($dir . $_GET['f'])) { header('Content-Type: text/html; charset=utf-8'); readfile($dir . $_GET['f']); exit; }
    echo $testa('Email intercettate') . $menu . '<p>' . count($files) . ' email (le più recenti in alto) · <a href="/__email?svuota=1">Svuota</a></p>';
    $sel = $_GET['vedi'] ?? ($files ? basename($files[0]) : '');
    echo '<table><tr><th>Quando</th><th>A</th><th>Oggetto</th></tr>';
    foreach (array_slice($files, 0, 200) as $f) {
        $prima = (string)fgets(fopen($f, 'r'));
        preg_match('/A: (.*?) \| Oggetto: (.*?) \|/', $prima, $m);
        $b = basename($f);
        echo '<tr' . ($b === $sel ? ' style="background:#fef3c7"' : '') . '><td>' . date('d/m H:i:s', filemtime($f)) . '</td><td>' . $h($m[1] ?? '') . '</td><td><a href="/__email?vedi=' . urlencode($b) . '">' . ($m[2] ?? $b) . '</a></td></tr>';
    }
    echo '</table>';
    if ($sel && preg_match('/^[A-Za-z0-9@._-]+\.html$/', $sel)) echo '<h2 style="font-size:1rem;margin-top:1.5rem">Anteprima</h2><iframe src="/__email?f=' . urlencode($sel) . '"></iframe>';
    exit('</body></html>');
}

if ($pagina === '/__cron') {
    $key = env_valore('CRON_KEY');
    echo $testa('Cron') . $menu . '<p>Esegue <code>cron_background.php</code> (promemoria, convenzioni, conservazione dei dati…). Le email finiscono in <a href="/__email">Email</a>.</p>'
       . '<p><a class="b" href="/eventi/cron_background.php?key=' . urlencode((string)$key) . '">Esegui il cron ora</a></p></body></html>';
    exit;
}
