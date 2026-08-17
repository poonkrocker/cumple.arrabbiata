# cumple.arrabbiata.com.ar — Concurso de pizzas

Sitio del concurso de pizzas de cumpleaños de Arrabbiata. Los usuarios diseñan
una pizza en un editor visual y la envían al concurso; hay galería, votación y
un panel de administración.

## Stack

- **Frontend:** HTML + JS (sin framework). El editor y la biblioteca de
  ingredientes viven en `editor.html` e `ingredients.json`.
- **Backend:** PHP plano. `api.php` es el router principal; toda la lógica va
  envuelta en un `try/catch (Throwable)` para garantizar respuestas JSON.
- **Datos:** pizzas enviadas como archivos JSON planos en `data/pizzas/`;
  usuarios y cuentas en MySQL vía PDO (`db_connect.php`, instancia `$pdo`).

## Estructura

```
.
├── index.html          Galería / portada
├── editor.html         Editor visual de pizzas
├── ingredients.json    Biblioteca de ingredientes (base64)
├── api.php             Router de la API (JSON)
├── db_connect.php      Conexión PDO (busca config.php fuera del docroot)
├── config.example.php  Plantilla de configuración (copiar a config.php)
├── login.php           Login
├── cuenta.php          Registro / cuenta
├── perfil.php          Perfil de usuario
├── share.php           Compartir a historias
├── admin/
│   ├── panel.php        Panel admin (ajuste de votos, export de clientes)
│   └── ingredientes.php Gestión de ingredientes
└── data/
    └── pizzas/         Pizzas enviadas (JSON) — NO versionadas
```

## Puesta en marcha

1. Copiá la plantilla de configuración:
   ```bash
   cp config.example.php config.php
   ```
2. Completá `config.php` (sal, `close_at`, `max_per_device`). En producción,
   las credenciales de MySQL van en un `config.php` **fuera del docroot** con
   una clave `db` (ver `db_connect.php`).
3. Creá las tablas MySQL: `pizza_users` y `pizza_login_attempts`.
4. Serví los archivos con PHP + un servidor web (Apache/Nginx). El `.htaccess`
   incluido activa compresión, cache y cabeceras de seguridad.

## Notas de seguridad

- `config.php` y los JSON de `data/pizzas/` **están excluidos del repo**
  (`.gitignore`) porque contienen la sal y datos personales de usuarios.
- Usá prefijos de tabla propios (`pizza_`) para evitar colisiones en entornos
  MySQL compartidos: `CREATE TABLE IF NOT EXISTS` omite silenciosamente la
  creación si ya existe una tabla con el mismo nombre y otro esquema.
