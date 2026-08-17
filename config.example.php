<?php
/* config.example.php — PLANTILLA de configuración.
 *
 * Copiá este archivo a config.php y completá los valores.
 * NUNCA subas config.php al repositorio (está en .gitignore).
 *
 * En producción, la conexión a la base de datos vive en un config.php
 * FUERA del docroot (p. ej. /home/USUARIO/config.php) que además incluye
 * una clave 'db'. Ver db_connect.php para el orden de búsqueda.
 */
return [
  // Fecha de cierre de la VOTACIÓN (timestamp UNIX).
  // 0 = sin cierre (votación siempre abierta).
  'close_at' => 0,

  // Máximo de pizzas que puede crear un mismo dispositivo.
  'max_per_device' => 5,

  // Sal para hashear votantes/creadores.
  // Reemplazá por una cadena larga y aleatoria propia. No la compartas.
  'arrabbiata-2026-xk39fh2' => 'CAMBIAME-por-una-cadena-larga-y-aleatoria',

  // ── Solo en el config.php de PRODUCCIÓN (fuera del docroot) ──
  // 'db' => [
  //   'host'     => 'localhost',
  //   'name'     => 'NOMBRE_BASE',
  //   'user'     => 'USUARIO_BASE',
  //   'password' => 'CONTRASEÑA_BASE',
  // ],
];
