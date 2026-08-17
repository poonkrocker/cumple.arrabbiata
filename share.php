<?php
/* ============================================================
   share.php — Vista previa para compartir (Open Graph por pizza)
   Arrabbiata · Armá tu pizza

   Al compartir el link de una pizza (WhatsApp, Instagram, etc.)
   los scrapers de redes no ejecutan JS ni leen el #hash, así que
   nunca veían la miniatura de la pizza. Esta página:
     - share.php?p=ID          -> emite el Open Graph de esa pizza
                                   (título + imagen) y redirige a la
                                   galería (index.html#p=ID).
     - share.php?p=ID&img=1     -> devuelve la miniatura como imagen
                                   real (los OG necesitan una URL de
                                   imagen, no un data-uri).

   Sin dependencias. Usa la misma config y carpeta de datos que api.php.
   ============================================================ */
declare(strict_types=1);

/* ---- config (misma que api.php) ---- */
$CFG = [ 'pizzas_dir' => __DIR__ . '/data/pizzas' ];
$cfgFile = __DIR__ . '/config.php';
if (is_file($cfgFile)) { $u = include $cfgFile; if (is_array($u)) $CFG = array_merge($CFG, $u); }
$dir = (string)$CFG['pizzas_dir'];

/* ---- helpers ---- */
function s_id(string $id): string { return (string)preg_replace('/[^a-zA-Z0-9_-]/', '', $id); }

$id = s_id((string)($_GET['p'] ?? ''));

/* base URL absoluta (para og:image / og:url) */
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$dirPath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$baseUrl = $scheme . '://' . $host . $dirPath . '/';
$galleryFallback = $baseUrl . 'index.html';

/* carga la pizza (o null si no existe / está oculta) */
$pizza = null;
if ($id !== '') {
  $f = $dir . '/' . $id . '.json';
  if (is_file($f)) {
    $j = json_decode((string)@file_get_contents($f), true);
    if (is_array($j) && empty($j['hidden'])) $pizza = $j;
  }
}

/* ---- modo imagen: devolver la miniatura como archivo real ---- */
if (isset($_GET['img'])) {
  $thumb = $pizza ? (string)($pizza['thumb'] ?? '') : '';
  if (preg_match('~^data:image/(png|jpe?g|webp);base64,(.+)$~s', $thumb, $m)) {
    $bin = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
    if ($bin !== false && $bin !== '') {
      $mime = 'image/' . (strtolower($m[1]) === 'jpg' ? 'jpeg' : strtolower($m[1]));
      header('Content-Type: ' . $mime);
      header('Cache-Control: public, max-age=86400');
      echo $bin; exit;
    }
  }
  // sin miniatura válida -> caemos a la portada genérica
  header('Location: ' . $baseUrl . 'og-cover.png', true, 302); exit;
}

/* ---- sin pizza válida: derecho a la galería ---- */
if (!$pizza) { header('Location: ' . $galleryFallback, true, 302); exit; }

/* ---- datos para el Open Graph (escapados) ---- */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
$name   = trim((string)($pizza['name'] ?? '')) ?: 'Mi pizza';
$author = trim((string)($pizza['author'] ?? ''));
$desc   = trim((string)($pizza['description'] ?? ''));
if ($desc === '') $desc = 'Mirá esta pizza y votala en la galería del cumple de Arrabbiata.';
$title  = $name . ' · Arrabbiata';
$ogImg  = $baseUrl . 'share.php?p=' . rawurlencode($id) . '&img=1';
$ogUrl  = $baseUrl . 'index.html#p=' . rawurlencode($id);

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Arrabbiata">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:image" content="<?= e($ogImg) ?>">
<meta property="og:url" content="<?= e($ogUrl) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($title) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>">
<meta name="twitter:image" content="<?= e($ogImg) ?>">
<link rel="canonical" href="<?= e($ogUrl) ?>">
<!-- humanos: los mandamos a la galería, en la pizza -->
<meta http-equiv="refresh" content="0; url=<?= e($ogUrl) ?>">
<script>location.replace(<?= json_encode($ogUrl) ?>);</script>
</head>
<body style="font-family:system-ui,sans-serif;background:#f0e6d0;color:#2b1a0a;text-align:center;padding:40px">
Te estamos llevando a la galería… <a href="<?= e($ogUrl) ?>">tocá acá si no pasa nada</a>.
</body>
</html>
