<?php
/* fxaudio.php — entrega de audios demo con enlaces firmados, caducos y de un solo uso.
   Subir junto a index.html. Los audios van en la carpeta /audios (mismo nivel). */

$SECRET = 'jlp002769';  // <- cámbiala
$ALLOWED_HOSTS = ['fx.introdedj.es'];                                // dominios permitidos
$TTL = 60;                                                           // segundos de vida del enlace
$MAX_SIGNS = 120;                                                    // enlaces por IP cada 10 min

$DIR = __DIR__ . '/audios/';
if (!is_dir($DIR)) { @mkdir($DIR, 0755, true); }
// La carpeta de audios queda cerrada al acceso directo (solo este PHP puede leerla)
if (!file_exists($DIR . '.htaccess')) {
  @file_put_contents($DIR . '.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
}

function deny($code = 403) { http_response_code($code); header('Cache-Control: no-store'); exit; }

$a = $_GET['a'] ?? '';
if ($a === 'setup') { header('Content-Type: text/plain; charset=utf-8'); echo "Listo: carpeta de audios protegida."; exit; }

$f = basename($_GET['f'] ?? '');
if (!preg_match('/^[A-Za-z0-9_\-]+\.(mp3|wav|ogg|m4a)$/i', $f) || !is_file($DIR . $f)) deny(404);

// Solo peticiones hechas por la propia página (no abrir el enlace en otra pestaña, ni desde otra web)
$site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
$dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
if ($dest !== '' && $dest !== 'empty') deny();
if ($site !== 'same-origin') {
  if ($site !== '') deny();
  $ok = false;
  foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
    if (!empty($_SERVER[$h])) { $x = strtolower((string)parse_url($_SERVER[$h], PHP_URL_HOST)); $ok = in_array($x, $ALLOWED_HOSTS, true); break; }
  }
  if (!$ok) deny();
}

$ua = sha1($_SERVER['HTTP_USER_AGENT'] ?? '');

if ($a === 'sign') {
  if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fxdj') deny();
  // límite de enlaces por IP
  $rd = $DIR . '_rate/'; if (!is_dir($rd)) @mkdir($rd, 0755, true);
  $rf = $rd . sha1($_SERVER['REMOTE_ADDR'] ?? '') . '.txt';
  $t = array_values(array_filter(array_map('intval', @file($rf, FILE_IGNORE_NEW_LINES) ?: []), function ($x) { return $x > time() - 600; }));
  if (count($t) >= $MAX_SIGNS) deny(429);
  $t[] = time(); @file_put_contents($rf, implode("\n", $t));
  $e = time() + $TTL; $n = bin2hex(random_bytes(8));
  $s = hash_hmac('sha256', "$f|$e|$n|$ua", $SECRET);
  header('Content-Type: application/json'); header('Cache-Control: no-store');
  echo json_encode(['url' => $_SERVER['SCRIPT_NAME'] . '?a=get&f=' . rawurlencode($f) . "&e=$e&n=$n&s=$s"]);
  exit;
}

if ($a === 'get') {
  $e = (int)($_GET['e'] ?? 0);
  $n = preg_replace('/[^a-f0-9]/', '', $_GET['n'] ?? '');
  $s = (string)($_GET['s'] ?? '');
  if ($e < time()) deny(410);
  if (!hash_equals(hash_hmac('sha256', "$f|$e|$n|$ua", $SECRET), $s)) deny();
  // un solo uso: el segundo intento con el mismo enlace falla
  $ud = $DIR . '_used/'; if (!is_dir($ud)) @mkdir($ud, 0755, true);
  $h = @fopen($ud . $n, 'x'); if (!$h) deny(410); fclose($h);
  if (mt_rand(1, 40) === 1) foreach (glob($ud . '*') ?: [] as $x) if (filemtime($x) < time() - 600) @unlink($x);
  $types = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4'];
  header('Content-Type: ' . $types[strtolower(pathinfo($f, PATHINFO_EXTENSION))]);
  header('Content-Length: ' . filesize($DIR . $f));
  header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex');
  readfile($DIR . $f);
  exit;
}
deny(400);
