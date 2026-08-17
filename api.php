<?php
/* ============================================================
   api.php  —  Backend público de Arrabbiata · Armá tu pizza
   Versión CLIENTES (galería + votación + concurso)

   - Editor de libre acceso: guardar pizza con datos de contacto
   - Galería pública: listar, abrir, votar (1 voto por dispositivo)
   - Admin (detrás de login PHP+MySQL del sitio): ocultar, borrar,
     ver contacto, exportar CSV, guardar biblioteca de ingredientes

   Compatible con PHP 7.4+. Un solo archivo, sin dependencias.
   Datos como JSON plano en data/pizzas/ (sin base de datos).
   ============================================================ */
declare(strict_types=1);

/* ---- polyfill para PHP < 8.1 ---- */
if (!function_exists('array_is_list')) {
  function array_is_list(array $a): bool { $i = 0; foreach ($a as $k => $_) { if ($k !== $i++) return false; } return true; }
}

/* ---- config por defecto (sobreescribible por config.php) ---- */
$CFG = [
  'lib_file'      => __DIR__ . '/ingredients.json',   // biblioteca de ingredientes
  'pizzas_dir'    => __DIR__ . '/data/pizzas',        // carpeta de pizzas guardadas
  'max_body'      => 8 * 1024 * 1024,                 // 8 MB
  'close_at'      => 0,                               // timestamp de cierre de votación (0 = sin cierre)
  'max_per_device'=> 5,                               // máx pizzas por dispositivo
  'vote_salt'     => 'arrabbiata-cumple',             // sal para hashear votantes
];
$cfgFile = __DIR__ . '/config.php';
if (is_file($cfgFile)) { $u = include $cfgFile; if (is_array($u)) $CFG = array_merge($CFG, $u); }

/* ---- sesión (para saber si es admin, reusa el login del sitio) ---- */
@session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
@session_start();
function is_admin(): bool { return !empty($_SESSION['admin_id']); }
function current_user_id(): int { return (int)($_SESSION['user_id'] ?? 0); }
function is_user(): bool { return current_user_id() > 0; }

/* Conexión PDO a MySQL. Sólo se usa para las cuentas de usuarios;
   las pizzas siguen guardándose como archivos JSON, como siempre.
   db_connect.php debe estar en esta misma carpeta (el mismo que usa el login). */
function db(): PDO {
  static $conn = null;
  if ($conn === null) {
    require __DIR__ . '/db_connect.php';   // define $pdo (o corta con error 500)
    if (!isset($pdo) || !($pdo instanceof PDO)) { fail('Error de base de datos', 500); }
    $conn = $pdo;
  }
  return $conn;
}

/* ¿Esta pizza participa del concurso (aparece en la galería y se puede votar)?
   - Anónimas / históricas (sin ownerId): SIEMPRE participan, igual que antes.
   - De usuarios registrados: sólo si marcaron "participar" en su perfil. */
function is_entered(array $p): bool {
  if (empty($p['ownerId'])) return true;
  return !empty($p['entered']);
}

function user_public(array $u): array {
  return [
    'id'          => (int)($u['id'] ?? 0),
    'username'    => (string)($u['username'] ?? ''),
    'displayName' => (string)($u['display_name'] ?? ''),
    'email'       => (string)($u['email'] ?? ''),
    'phone'       => (string)($u['phone'] ?? ''),
    'instagram'   => (string)($u['instagram'] ?? ''),
    'favPizza'    => (string)($u['fav_pizza'] ?? ''),
  ];
}

/* normaliza un nombre de usuario: minúsculas, letras/números y . _ - */
function norm_username(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9._-]/', '', $s);
  return substr((string)$s, 0, 40);
}

/* Pizzas de la carta (para el campo "pizza favorita" del registro). */
function fav_pizzas(): array {
  return ['Margherita','Cipollina','Genovesa','Pineta','Romerina','Quattro','Pucheta','Especial','Arrabbiata'];
}
/* Devuelve el nombre exacto de la carta si coincide (sin importar mayúsculas), o '' si no. */
function norm_fav(string $s): string {
  $s = trim($s);
  foreach (fav_pizzas() as $p) { if (strcasecmp($p, $s) === 0) return $p; }
  return '';
}
function valid_email(string $s): bool {
  $s = trim($s);
  return strlen($s) <= 120 && (bool)filter_var($s, FILTER_VALIDATE_EMAIL);
}

/* ---- cabeceras ---- */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

/* ---- helpers ---- */
function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'error' => $msg], $code); }
function require_admin(): void { if (!is_admin()) fail('Necesitás iniciar sesión', 401); }

function read_body(array $CFG): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') fail('Body vacío');
  if (strlen($raw) > (int)$CFG['max_body']) fail('Body demasiado grande', 413);
  $j = json_decode($raw, true);
  if (!is_array($j)) fail('JSON inválido');
  return $j;
}

function atomic_write(string $path, string $contents): bool {
  $dir = dirname($path);
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, $contents, LOCK_EX) === false) return false;
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
  return true;
}

function safe_id(string $id): string { return (string)preg_replace('/[^a-zA-Z0-9_-]/', '', $id); }

/* corre $fn con un lock exclusivo sobre $lockPath, para serializar el
   lee-modifica-escribe (ej: votos simultáneos sobre la misma pizza).
   Best-effort: si no puede abrir el lock, corre igual. */
function with_lock(string $lockPath, callable $fn) {
  $fh = @fopen($lockPath, 'c');
  if ($fh === false) return $fn();
  @flock($fh, LOCK_EX);
  try { return $fn(); }
  finally { @flock($fh, LOCK_UN); @fclose($fh); }
}

/* valida que un thumb sea realmente una imagen data-uri (png/jpg/webp) y no
   texto arbitrario que rompa el HTML de la galería. Devuelve '' si no lo es. */
function safe_thumb(string $t): string {
  if ($t === '') return '';
  if (strlen($t) > 350 * 1024) return '';
  return preg_match('~^data:image/(png|jpe?g|webp);base64,[A-Za-z0-9+/=\s]+$~', $t) ? $t : '';
}

/* huella del votante/creador: hash estable de IP + user-agent + sal.
   No guarda la IP en claro; sirve para "1 voto por dispositivo" y límite de creación. */
function device_hash(array $CFG): string {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  return substr(hash('sha256', $ip . '|' . $ua . '|' . $CFG['vote_salt']), 0, 16);
}

/* limpia una pizza para mostrarla en público (sin datos de contacto).
   $withItems: incluir la lista de ingredientes (para la vista ampliada). */
function public_view(array $p, bool $withItems = false): array {
  $author = trim((string)($p['author'] ?? ''));
  $ig     = trim((string)($p['instagram'] ?? ''));
  $showIg = !empty($p['showInstagram']) && $ig !== '';
  $v = [
    'id'          => (string)($p['id'] ?? ''),
    'name'        => (string)($p['name'] ?? '(sin nombre)'),
    'description' => (string)($p['description'] ?? ''),
    'author'      => $author !== '' ? $author : 'Anónimo',
    'instagram'   => $showIg ? ltrim($ig, '@') : '',
    'base'        => (string)($p['base'] ?? ''),
    'base2'       => (string)($p['base2'] ?? ''),
    'split'       => !empty($p['split']),
    'count'       => is_array($p['items'] ?? null) ? count($p['items']) : 0,
    'thumb'       => (string)($p['thumb'] ?? ''),
    'votes'       => (int)($p['votes'] ?? 0),
    'createdAt'   => (int)($p['createdAt'] ?? 0),
  ];
  if ($withItems) {
    // solo los sid + estado (para nombrar ingredientes); nada sensible
    $items = [];
    foreach ((array)($p['items'] ?? []) as $it) {
      if (is_array($it) && isset($it['sid'])) $items[] = ['sid' => (string)$it['sid'], 'st' => (string)($it['st'] ?? '')];
    }
    $v['items']    = $items;
    $v['drizzles'] = is_array($p['drizzles'] ?? null) ? array_values($p['drizzles']) : [];
  }
  return $v;
}

/* nombre de instagram normalizado (sin @, sin url) o '' */
function norm_ig(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~^https?://(www\.)?instagram\.com/~i', '', $s);
  $s = ltrim($s, '@/');
  $s = preg_replace('/[^a-zA-Z0-9._]/', '', (string)$s);
  return substr((string)$s, 0, 40);
}

/* ---- router ---- */
$action = (string)($_GET['action'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$dir    = (string)$CFG['pizzas_dir'];

/* Si algo falla (por ejemplo, la base de datos), devolvemos el error como JSON
   en vez de romper con un error de PHP (que el front lee como "sin conexión"). */
try {

switch ($action) {

  /* estado del concurso (fecha de cierre, si es admin, etc.) */
  case 'status': {
    $now = time();
    $close = (int)$CFG['close_at'];
    out([
      'ok'       => true,
      'now'      => $now,
      'closeAt'  => $close,
      'closed'   => $close > 0 && $now >= $close,
      'isAdmin'  => is_admin(),
      'maxPer'   => (int)$CFG['max_per_device'],
    ]);
  }

  /* ---------- GUARDAR PIZZA (público, con contacto) ---------- */
  case 'save-pizza': {
    if ($method !== 'POST') fail('Usá POST', 405);
    $body = read_body($CFG);

    if (!isset($body['items']) || !is_array($body['items']) || count($body['items']) === 0)
      fail('Agregá al menos un ingrediente a tu pizza');

    // ¿hay un usuario registrado logueado? (si sí, la pizza se guarda en su perfil)
    $owner = null;
    if (is_user()) {
      $pdo = db();
      $st = $pdo->prepare('SELECT * FROM pizza_users WHERE id = ?');
      $st->execute([current_user_id()]);
      $owner = $st->fetch() ?: null;
      if (!$owner) { unset($_SESSION['user_id']); }   // sesión colgada -> tratar como anónimo
    }

    $author = trim((string)($body['author'] ?? ''));
    $phone  = trim((string)($body['phone'] ?? ''));
    $ig     = norm_ig((string)($body['instagram'] ?? ''));

    $dev     = device_hash($CFG);
    $ownerId = 0;
    $entered = true;   // por defecto (anónimas): participa del concurso, igual que siempre

    if ($owner) {
      // ---- usuario registrado: guarda en su perfil como borrador ----
      $ownerId = (int)$owner['id'];
      $entered = false;  // elige después, desde su perfil, con cuáles participa
      if ($author === '') $author = trim((string)($owner['display_name'] ?? '')) ?: (string)$owner['username'];
      if ($phone === '')  $phone  = (string)($owner['phone'] ?? '');
      if ($ig === '')     $ig     = norm_ig((string)($owner['instagram'] ?? ''));
    } else {
      // ---- participación sin registro: idéntica a antes ----
      if ($author === '') fail('Poné tu nombre');
      if ($phone === '' && $ig === '') fail('Dejá un teléfono o un Instagram para poder avisarte si ganás');
      // límite de pizzas por dispositivo (los admin no tienen límite; sólo cuenta lo anónimo)
      if (!is_admin()) {
        $mine = 0;
        foreach ((array)glob($dir . '/*.json') as $f) {
          $j = json_decode((string)@file_get_contents($f), true);
          if (is_array($j) && empty($j['ownerId']) && ($j['device'] ?? '') === $dev && empty($j['hidden'])) $mine++;
        }
        if ($mine >= (int)$CFG['max_per_device'])
          fail('Ya creaste ' . $CFG['max_per_device'] . ' pizzas con este dispositivo. ¡Gracias por participar!', 429);
      }
    }

    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $now = time();
    $pizza = [
      'schema'        => 1,
      'id'            => $id,
      'name'          => substr(trim((string)($body['name'] ?? '')) ?: 'Mi pizza', 0, 60),
      'description'   => substr(trim((string)($body['description'] ?? '')), 0, 240),
      'base'          => (string)($body['base'] ?? ''),
      'base2'         => (string)($body['base2'] ?? ''),
      'split'         => !empty($body['split']),
      'items'         => $body['items'],
      'drizzles'      => is_array($body['drizzles'] ?? null) ? $body['drizzles'] : [],
      'thumb'         => safe_thumb((string)($body['thumb'] ?? '')),
      // contacto (privado, nunca se sirve en las vistas públicas)
      'author'        => substr($author, 0, 60),
      'phone'         => substr($phone, 0, 40),
      'instagram'     => $ig,
      'showInstagram' => !empty($body['showInstagram']),
      // control
      'device'        => $dev,
      'ownerId'       => $ownerId,   // 0 = anónima; >0 = de un usuario registrado
      'entered'       => $entered,   // ¿participa del concurso? (anónimas: siempre true)
      'votes'         => 0,
      'voters'        => [],
      'hidden'        => false,
      'createdAt'     => $now,
      'updatedAt'     => $now,
    ];
    $json = json_encode($pizza, JSON_UNESCAPED_UNICODE);
    if ($json === false) fail('No pude serializar la pizza', 500);
    $f = $dir . '/' . $id . '.json';
    if (!atomic_write($f, $json)) fail('No pude guardar la pizza (¿permisos?)', 500);
    out(['ok' => true, 'id' => $id, 'owned' => (bool)$ownerId, 'entered' => $entered]);
  }

  /* ---------- LISTAR GALERÍA (público) ---------- */
  case 'list-pizzas': {
    $items = []; $totalVotes = 0;
    if (is_dir($dir)) {
      foreach ((array)glob($dir . '/*.json') as $f) {
        if (basename($f) === 'index.json') continue;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) continue;
        if (!is_entered($j)) continue;                        // borradores de perfil: no van a la galería
        if (!empty($j['hidden']) && !is_admin()) continue;    // ocultas solo para admin
        $v = public_view($j);
        if (is_admin()) { $v['hidden'] = !empty($j['hidden']); }
        $totalVotes += $v['votes'];
        $items[] = $v;
      }
    }
    // orden: más votadas primero, luego más nuevas
    usort($items, function ($a, $b) {
      if ($b['votes'] !== $a['votes']) return $b['votes'] <=> $a['votes'];
      return $b['createdAt'] <=> $a['createdAt'];
    });
    $dev = device_hash($CFG);
    out(['ok' => true, 'pizzas' => $items, 'totalVotes' => $totalVotes, 'device' => $dev]);
  }

  /* ---------- ABRIR UNA PIZZA (público) ---------- */
  case 'get-pizza': {
    $id = safe_id((string)($_GET['id'] ?? ''));
    if ($id === '') fail('Falta id');
    $f = $dir . '/' . $id . '.json';
    if (!is_file($f)) fail('No existe esa pizza', 404);
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j)) fail('Pizza corrupta', 500);
    $isOwner = is_user() && (int)($j['ownerId'] ?? 0) === current_user_id();
    if (!is_entered($j) && !is_admin() && !$isOwner) fail('No existe esa pizza', 404);
    if (!empty($j['hidden']) && !is_admin() && !$isOwner) fail('No existe esa pizza', 404);
    out(['ok' => true, 'pizza' => public_view($j, true)]);
  }

  /* ---------- VOTAR (público, 1 por dispositivo) ---------- */
  case 'vote': {
    if ($method !== 'POST') fail('Usá POST', 405);
    // respeta el cierre del concurso
    $close = (int)$CFG['close_at'];
    if ($close > 0 && time() >= $close) fail('La votación ya cerró. ¡Gracias por participar!', 403);

    $id = safe_id((string)($_GET['id'] ?? ''));
    if ($id === '') { $b = read_body($CFG); $id = safe_id((string)($b['id'] ?? '')); }
    if ($id === '') fail('Falta id');
    $f = $dir . '/' . $id . '.json';
    if (!is_file($f)) fail('No existe esa pizza', 404);

    $dev = device_hash($CFG);
    // todo el lee-modifica-escribe va dentro de un lock por pizza para que
    // dos votos simultáneos no se pisen (last-write-wins perdía un voto).
    $res = with_lock($f . '.lk', function () use ($f, $dev) {
      $j = json_decode((string)@file_get_contents($f), true);
      if (!is_array($j)) return ['err' => ['Pizza corrupta', 500]];
      if (!empty($j['hidden'])) return ['err' => ['No existe esa pizza', 404]];
      if (!is_entered($j)) return ['err' => ['Esa pizza no está en el concurso', 404]];
      $voters = is_array($j['voters'] ?? null) ? $j['voters'] : [];
      if (in_array($dev, $voters, true)) {
        return ['ok' => true, 'already' => true, 'votes' => (int)($j['votes'] ?? 0)];
      }
      $voters[] = $dev;
      $j['voters'] = $voters;
      $j['votes']  = count($voters);
      if (!atomic_write($f, json_encode($j, JSON_UNESCAPED_UNICODE))) return ['err' => ['No pude registrar el voto', 500]];
      return ['ok' => true, 'voted' => true, 'votes' => $j['votes']];
    });
    if (isset($res['err'])) fail($res['err'][0], (int)$res['err'][1]);
    out($res);
  }

  /* ================= CUENTAS DE USUARIOS ================= */

  /* ---------- REGISTRO ---------- */
  case 'register': {
    if ($method !== 'POST') fail('Usá POST', 405);
    $b = read_body($CFG);
    $username = norm_username((string)($b['username'] ?? ''));
    $password = (string)($b['password'] ?? '');
    $email    = trim((string)($b['email'] ?? ''));
    $display  = substr(trim((string)($b['displayName'] ?? '')), 0, 60);
    $phone    = substr(trim((string)($b['phone'] ?? '')), 0, 40);
    $ig       = norm_ig((string)($b['instagram'] ?? ''));
    $fav      = norm_fav((string)($b['favPizza'] ?? ''));
    if (strlen($username) < 3) fail('El usuario debe tener al menos 3 caracteres (letras, números y . _ -)');
    if (strlen($password) < 6) fail('La contraseña debe tener al menos 6 caracteres');
    if (!valid_email($email))  fail('Poné un email válido');
    if ($fav === '')           fail('Elegí tu pizza favorita de la carta');
    $pdo = db();
    $chk = $pdo->prepare('SELECT id FROM pizza_users WHERE username = ?');
    $chk->execute([$username]);
    if ($chk->fetch()) fail('Ese usuario ya existe. Probá con otro.', 409);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $disp = $display !== '' ? $display : $username;
    $ins = $pdo->prepare('INSERT INTO pizza_users (username, password, email, display_name, phone, instagram, fav_pizza, created_at) VALUES (?,?,?,?,?,?,?,?)');
    $ins->execute([$username, $hash, $email, $disp, $phone, $ig, $fav, time()]);
    $uid = (int)$pdo->lastInsertId();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $uid;
    out(['ok' => true, 'user' => user_public([
      'id'=>$uid,'username'=>$username,'display_name'=>$disp,
      'email'=>$email,'phone'=>$phone,'instagram'=>$ig,'fav_pizza'=>$fav,
    ])]);
  }

  /* ---------- LOGIN ---------- */
  case 'user-login': {
    if ($method !== 'POST') fail('Usá POST', 405);
    $b = read_body($CFG);
    $username = norm_username((string)($b['username'] ?? ''));
    $password = (string)($b['password'] ?? '');
    if ($username === '' || $password === '') fail('Completá usuario y contraseña');
    $pdo = db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $win = 600; $max = 10;
    $q = $pdo->prepare('SELECT attempts, first_attempt FROM pizza_login_attempts WHERE ip = ?');
    $q->execute([$ip]);
    $row = $q->fetch();
    $attempts = ($row && (time() - (int)$row['first_attempt']) <= $win) ? (int)$row['attempts'] : 0;
    if ($attempts >= $max) fail('Demasiados intentos. Esperá unos minutos antes de reintentar.', 429);
    $st = $pdo->prepare('SELECT * FROM pizza_users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if ($u && password_verify($password, (string)$u['password'])) {
      $pdo->prepare('DELETE FROM pizza_login_attempts WHERE ip = ?')->execute([$ip]);
      session_regenerate_id(true);
      $_SESSION['user_id'] = (int)$u['id'];
      out(['ok' => true, 'user' => user_public($u)]);
    }
    $now = time();
    $pdo->prepare('INSERT INTO pizza_login_attempts (ip, attempts, first_attempt) VALUES (?, 1, ?)
      ON DUPLICATE KEY UPDATE
        attempts = IF(? - first_attempt > 600, 1, attempts + 1),
        first_attempt = IF(? - first_attempt > 600, ?, first_attempt)')
      ->execute([$ip, $now, $now, $now, $now]);
    fail('Usuario o contraseña incorrectos', 401);
  }

  /* ---------- LOGOUT ---------- */
  case 'user-logout': {
    unset($_SESSION['user_id']);
    session_regenerate_id(true);
    out(['ok' => true]);
  }

  /* ---------- QUIÉN SOY (estado de sesión para editor/galería/perfil) ---------- */
  case 'me': {
    if (!is_user()) out(['ok' => true, 'user' => null]);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM pizza_users WHERE id = ?');
    $st->execute([current_user_id()]);
    $u = $st->fetch();
    if (!$u) { unset($_SESSION['user_id']); out(['ok' => true, 'user' => null]); }
    out(['ok' => true, 'user' => user_public($u)]);
  }

  /* ---------- ACTUALIZAR PERFIL (datos de contacto / nombre visible) ---------- */
  case 'update-profile': {
    if ($method !== 'POST') fail('Usá POST', 405);
    if (!is_user()) fail('Iniciá sesión', 401);
    $b = read_body($CFG);
    $display = substr(trim((string)($b['displayName'] ?? '')), 0, 60);
    $email   = trim((string)($b['email'] ?? ''));
    $phone   = substr(trim((string)($b['phone'] ?? '')), 0, 40);
    $ig      = norm_ig((string)($b['instagram'] ?? ''));
    $fav     = norm_fav((string)($b['favPizza'] ?? ''));
    if (!valid_email($email)) fail('Poné un email válido');
    if ($fav === '')          fail('Elegí tu pizza favorita de la carta');
    $pdo = db();
    $pdo->prepare('UPDATE pizza_users SET display_name = ?, email = ?, phone = ?, instagram = ?, fav_pizza = ? WHERE id = ?')
        ->execute([$display, $email, $phone, $ig, $fav, current_user_id()]);
    $st = $pdo->prepare('SELECT * FROM pizza_users WHERE id = ?');
    $st->execute([current_user_id()]);
    out(['ok' => true, 'user' => user_public($st->fetch())]);
  }

  /* ---------- MIS PIZZAS (borradores + las que participan) ---------- */
  case 'my-pizzas': {
    if (!is_user()) fail('Iniciá sesión', 401);
    $uid = current_user_id();
    $items = []; $enteredCount = 0;
    if (is_dir($dir)) {
      foreach ((array)glob($dir . '/*.json') as $f) {
        if (basename($f) === 'index.json') continue;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j) || (int)($j['ownerId'] ?? 0) !== $uid) continue;
        $entered = !empty($j['entered']);
        if ($entered) $enteredCount++;
        $v = public_view($j);
        $v['entered']       = $entered;
        $v['hiddenByAdmin'] = !empty($j['hidden']);
        $items[] = $v;
      }
    }
    usort($items, function ($a, $b) { return $b['createdAt'] <=> $a['createdAt']; });
    out(['ok' => true, 'pizzas' => $items, 'enteredCount' => $enteredCount, 'maxEntries' => (int)$CFG['max_per_device']]);
  }

  /* ---------- PARTICIPAR / RETIRAR una pizza del concurso ---------- */
  case 'toggle-entered': {
    if ($method !== 'POST') fail('Usá POST', 405);
    if (!is_user()) fail('Iniciá sesión', 401);
    $uid = current_user_id();
    $raw = json_decode((string)file_get_contents('php://input'), true);
    $b   = is_array($raw) ? $raw : [];
    $id  = safe_id((string)($_GET['id'] ?? ($b['id'] ?? '')));
    $want = array_key_exists('entered', $b) ? (bool)$b['entered'] : null;
    if ($id === '') fail('Falta id');
    $f = $dir . '/' . $id . '.json';
    if (!is_file($f)) fail('No existe esa pizza', 404);
    $res = with_lock($f . '.lk', function () use ($f, $dir, $uid, $want, $CFG) {
      $j = json_decode((string)@file_get_contents($f), true);
      if (!is_array($j)) return ['err' => ['Pizza corrupta', 500]];
      if ((int)($j['ownerId'] ?? 0) !== $uid) return ['err' => ['Esa pizza no es tuya', 403]];
      $newState = ($want === null) ? empty($j['entered']) : $want;
      if ($newState) {
        $cnt = 0;
        foreach ((array)glob($dir . '/*.json') as $g) {
          if ($g === $f) continue;
          $x = json_decode((string)@file_get_contents($g), true);
          if (is_array($x) && (int)($x['ownerId'] ?? 0) === $uid && !empty($x['entered'])) $cnt++;
        }
        if ($cnt >= (int)$CFG['max_per_device'])
          return ['err' => ['Ya tenés ' . $CFG['max_per_device'] . ' pizzas en el concurso. Retirá una para sumar otra.', 409]];
      }
      $j['entered']   = $newState;
      $j['updatedAt'] = time();
      if (!atomic_write($f, json_encode($j, JSON_UNESCAPED_UNICODE))) return ['err' => ['No se pudo guardar', 500]];
      return ['ok' => true, 'entered' => $newState];
    });
    if (isset($res['err'])) fail($res['err'][0], (int)$res['err'][1]);
    out($res);
  }

  /* ================= ACCIONES DE ADMIN ================= */

  /* ocultar / mostrar una pizza */
  case 'admin-toggle-hidden': {
    require_admin();
    if ($method !== 'POST') fail('Usá POST', 405);
    $id = safe_id((string)($_GET['id'] ?? ''));
    if ($id === '') fail('Falta id');
    $f = $dir . '/' . $id . '.json';
    if (!is_file($f)) fail('No existe esa pizza', 404);
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j)) fail('Pizza corrupta', 500);
    $j['hidden'] = empty($j['hidden']);
    atomic_write($f, json_encode($j, JSON_UNESCAPED_UNICODE));
    out(['ok' => true, 'hidden' => $j['hidden']]);
  }

  /* borrar definitivamente */
  case 'admin-delete': {
    require_admin();
    if ($method !== 'POST') fail('Usá POST', 405);
    $id = safe_id((string)($_GET['id'] ?? ''));
    if ($id === '') fail('Falta id');
    $f = $dir . '/' . $id . '.json';
    if (is_file($f)) @unlink($f);
    out(['ok' => true, 'id' => $id]);
  }

  /* listado admin con datos de contacto (para elegir/contactar ganadores) */
  case 'admin-list': {
    require_admin();
    $items = [];
    if (is_dir($dir)) {
      foreach ((array)glob($dir . '/*.json') as $f) {
        if (basename($f) === 'index.json') continue;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) continue;
        $items[] = [
          'id'          => (string)($j['id'] ?? ''),
          'name'        => (string)($j['name'] ?? ''),
          'description' => (string)($j['description'] ?? ''),
          'author'      => (string)($j['author'] ?? ''),
          'phone'       => (string)($j['phone'] ?? ''),
          'instagram'   => (string)($j['instagram'] ?? ''),
          'votes'       => (int)($j['votes'] ?? 0),
          'hidden'      => !empty($j['hidden']),
          'thumb'       => (string)($j['thumb'] ?? ''),
          'createdAt'   => (int)($j['createdAt'] ?? 0),
          'ownerId'     => (int)($j['ownerId'] ?? 0),
          'entered'     => is_entered($j),
        ];
      }
    }
    usort($items, function ($a, $b) { return $b['votes'] <=> $a['votes']; });
    out(['ok' => true, 'pizzas' => $items]);
  }

  /* exportar CSV de participantes (para el 1° de septiembre) */
  case 'admin-csv': {
    require_admin();
    $rows = [];
    if (is_dir($dir)) {
      foreach ((array)glob($dir . '/*.json') as $f) {
        if (basename($f) === 'index.json') continue;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) continue;
        $rows[] = $j;
      }
    }
    usort($rows, function ($a, $b) { return (int)($b['votes'] ?? 0) <=> (int)($a['votes'] ?? 0); });
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pizzas-arrabbiata.csv"');
    $fp = fopen('php://output', 'w');
    fprintf($fp, "\xEF\xBB\xBF"); // BOM para Excel
    fputcsv($fp, ['Puesto','Votos','Pizza','Autor','Telefono','Instagram','Descripcion','Fecha','Oculta','EnConcurso','Cuenta']);
    $pos = 0;
    foreach ($rows as $j) {
      $pos++;
      fputcsv($fp, [
        $pos,
        (int)($j['votes'] ?? 0),
        (string)($j['name'] ?? ''),
        (string)($j['author'] ?? ''),
        (string)($j['phone'] ?? ''),
        (string)($j['instagram'] ?? ''),
        (string)($j['description'] ?? ''),
        date('Y-m-d H:i', (int)($j['createdAt'] ?? 0)),
        !empty($j['hidden']) ? 'si' : 'no',
        is_entered($j) ? 'si' : 'no',
        !empty($j['ownerId']) ? 'registrado' : 'anonimo',
      ]);
    }
    fclose($fp);
    exit;
  }

  /* sumar o restar votos a mano (admin) */
  case 'admin-adjust-votes': {
    require_admin();
    if ($method !== 'POST') fail('Usá POST', 405);
    $id    = safe_id((string)($_GET['id'] ?? ''));
    $delta = (int)($_GET['delta'] ?? 0);
    if ($id === '') fail('Falta id');
    if ($delta === 0) fail('Falta el ajuste de votos');
    $f = $dir . '/' . $id . '.json';
    if (!is_file($f)) fail('No existe esa pizza', 404);
    $res = with_lock($f . '.lk', function () use ($f, $delta) {
      $j = json_decode((string)@file_get_contents($f), true);
      if (!is_array($j)) return ['err' => ['Pizza corrupta', 500]];
      $v = max(0, (int)($j['votes'] ?? 0) + $delta);   // nunca baja de 0
      $j['votes']     = $v;
      $j['updatedAt'] = time();
      if (!atomic_write($f, json_encode($j, JSON_UNESCAPED_UNICODE))) return ['err' => ['No se pudo guardar', 500]];
      return ['ok' => true, 'votes' => $v];
    });
    if (isset($res['err'])) fail($res['err'][0], (int)$res['err'][1]);
    out($res);
  }

  /* exportar CSV de clientes registrados (usuario + datos cargados) */
  case 'admin-users-csv': {
    require_admin();
    $pdo = db();
    $rows = $pdo->query('SELECT username, display_name, email, phone, instagram, fav_pizza, created_at FROM pizza_users ORDER BY created_at DESC')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clientes-arrabbiata.csv"');
    $fp = fopen('php://output', 'w');
    fprintf($fp, "\xEF\xBB\xBF"); // BOM para Excel
    fputcsv($fp, ['Usuario','Nombre','Email','Telefono','Instagram','PizzaFavorita','Fecha']);
    foreach ((array)$rows as $u) {
      fputcsv($fp, [
        (string)($u['username'] ?? ''),
        (string)($u['display_name'] ?? ''),
        (string)($u['email'] ?? ''),
        (string)($u['phone'] ?? ''),
        (string)($u['instagram'] ?? ''),
        (string)($u['fav_pizza'] ?? ''),
        date('Y-m-d H:i', (int)($u['created_at'] ?? 0)),
      ]);
    }
    fclose($fp);
    exit;
  }

  /* guardar biblioteca de ingredientes (admin) */
  case 'save-lib': {
    require_admin();
    if ($method !== 'POST') fail('Usá POST', 405);
    $body = read_body($CFG);
    if (isset($body['ingredients']) && is_array($body['ingredients'])) { $lib = $body; }
    else if (array_is_list($body)) { $lib = ['version' => 1, 'ingredients' => $body]; }
    else { fail('Formato de biblioteca inválido'); }
    if (!isset($lib['ingredients']) || !is_array($lib['ingredients']) || count($lib['ingredients']) === 0)
      fail('La biblioteca está vacía; no se guarda por seguridad');
    if (!isset($lib['version'])) $lib['version'] = 1;
    $json = json_encode($lib, JSON_UNESCAPED_UNICODE);
    if ($json === false) fail('No pude serializar la biblioteca', 500);
    if (!atomic_write((string)$CFG['lib_file'], $json)) fail('No pude escribir ingredients.json (¿permisos?)', 500);
    out(['ok' => true, 'count' => count($lib['ingredients']), 'bytes' => strlen($json)]);
  }

  default:
    fail('Acción desconocida: ' . $action, 404);
}

} catch (Throwable $e) {
  out(['ok' => false, 'error' => 'Error del servidor: ' . $e->getMessage()], 500);
}
