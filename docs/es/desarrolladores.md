# Desarrolladores

[Documentación](README.md) › Desarrolladores

Cómo está hecho el plugin, cómo ampliarlo y cómo contribuir.

## Arquitectura

Sin compilación, sin dependencias en ejecución, sin JavaScript. PHP 7.4+, WordPress 6.5+. Composer solo se usa para las herramientas de desarrollo (PHPCS, PHPUnit).

```
ai-visibility-dashboard/
├── ai-visibility-dashboard.php   Arranque: constantes, activación y desactivación, enlace de ajustes
├── includes/
│   ├── class-aivd-settings.php   Ajustes guardados, origen de la clave, permiso, vaciado de caché
│   ├── class-aivd-api.php        Cliente HTTP de solo lectura para la API de Mencoro
│   ├── class-aivd-data.php       Llama a la API, da forma a los datos, guarda los últimos válidos, estados
│   ├── class-aivd-lock.php       Bloqueo atómico para que solo haya una actualización a la vez
│   ├── class-aivd-widget.php     Genera el widget del Escritorio (HTML en servidor + SVG en línea)
│   └── class-aivd-admin.php      Pantalla de ajustes y manejadores admin-post
├── assets/admin.css              Solo se carga en el Escritorio y en la pantalla de ajustes
├── languages/                    .pot y es_ES .po/.mo/.l10n.php
└── uninstall.php                 Borra opciones, transitorios, bloqueos y eventos de cron, compatible con multisitio
```

Prefijos: `aivd_` para funciones, opciones, transitorios, hooks y acciones; `AIVD_` para clases y constantes.

### Flujo de una petición

```
Carga del Escritorio
  └─ AIVD_Widget::render()
       └─ AIVD_Data::state( $range )
            ├─ datos recientes           → se muestran (estado fresh, partial o empty)
            ├─ datos desactualizados     → se muestran con su antigüedad (estado stale)
            │                              y se programa un evento de WP-Cron: aivd_background_refresh
            ├─ sin datos, bloqueo libre  → se actualiza ya, con 12 s de margen (la única espera)
            ├─ sin datos, bloqueo ocupado → estado pending
            └─ sin datos, en espera      → estado error, con la hora del próximo intento

actualización (con AIVD_Lock, por contexto)
  └─ AIVD_Data::build()
       ├─ GET  projects/{id}                 nombre, dominios, marca, competidores  ┐
       ├─ GET  projects/{id}/available-filters   motores que sigue el proyecto      ├ esenciales
       ├─ GET  projects/{id}/metrics         cifras principales, sentimiento        ┘
       ├─ GET  projects/{id}/timeseries      cuota de voz por día o semana          ┐
       ├─ GET  projects/{id}/cited-sources   6 dominios más citados y el total      │ secundarias
       ├─ GET  projects/{id}/metrics/movers  3 mayores subidas (sortOrder=desc)     │
       └─ GET  projects/{id}/metrics/movers  3 mayores bajadas (sortOrder=asc)      ┘
```

Todas las rutas cuelgan de `https://api.mencoro.com/api/v1/organizations/{organizationId}/`. Parámetros del periodo: `dateFrom`, `dateTo` (UTC, `Y-m-d`), `engines` (separados por comas). La serie temporal usa `granularity=daily`, o `weekly` en 90 días; los movimientos usan `sortBy=trend_share_of_voice&limit=3`; las fuentes, `groupBy=domain&limit=6`.

Si falla una petición esencial, falla la actualización: se conservan los datos anteriores y se registra el error. Si falla una secundaria, o no cabe en el margen de tiempo, solo esa sección muestra un aviso y los datos quedan marcados como parciales (`failed_sections`); los datos parciales se vuelven a pedir a los 15 minutos.

Cada petición espera 10 s como mucho (`aivd_http_timeout`), no sigue redirecciones y lee 1 MB como máximo. Los mensajes de error nunca incluyen el cuerpo de la respuesta.

La pantalla de ajustes usa además `GET /api/v1/me` (comprobar la clave al guardar; la identidad que devuelve no se guarda), `GET /api/v1/organizations` y `GET /api/v1/organizations/{id}/projects` (selector de proyecto).

Referencia: [documentación de la API de Mencoro](https://mencoro.com/api-docs/) y el contrato OpenAPI en [mencoro/mencoro-api-sdk](https://github.com/mencoro/mencoro-api-sdk).

### Motores

`AIVD_Settings::AI_ENGINES` = `chatgpt`, `claude`, `perplexity`, `google_ai_overview`, `google_ai_mode`. Los motores que se envían son *(los marcados en los ajustes, o todos)* ∩ *(los filtros disponibles del proyecto)* ∩ `AI_ENGINES`. `google_serp` y `google_shopping` nunca se envían.

### Almacenamiento y estados

| Qué | Dónde | Duración |
|---|---|---|
| Últimos datos válidos + último intento (error, fallos, próximo intento) | Opción `aivd_snapshot_{md5(organización, proyecto, periodo, motores, wp_hash(clave))}`, sin carga automática; índice en `aivd_snapshots`; 6 como mucho | Recientes 6 h (`aivd_cache_ttl`) y dentro del mismo día UTC; 15 min si son parciales. Se guardan hasta que se sustituyen o se borran. |
| Bloqueo de actualización | Opción `aivd_lock_refresh_{contexto}`, leída y escrita con consultas directas (`INSERT IGNORE`) para que una caché de objetos no la oculte | 120 s, o hasta que acaba la actualización |
| Lista de proyectos (ajustes) | Transitorio `aivd_projects_` + md5(versión de caché, clave) | 10 min |

`AIVD_Data::state( $range )` devuelve `status` (`fresh`, `partial`, `empty`, `stale`, `pending`, `error`), `payload`, `partial`, `fetched_at`, `expires_at`, `error`, `retry_at`, `refreshing` y `cron_late`. `AIVD_Data::peek()` devuelve lo mismo sin pedir ni programar nada. `AIVD_Data::get( $range )` se mantiene por compatibilidad y devuelve los últimos datos o un `WP_Error`.

Espera tras un error: `Retry-After` en 429 (también para Actualizar), 15 min en 401/403/404 y 1, 2, 4… hasta 30 min en los demás. **Actualizar** funciona como mucho una vez cada 30 s por vista.

Guardar los ajustes sube `aivd_cache_version` (lista de proyectos) y borra todos los datos guardados y las actualizaciones programadas, así que nunca se muestra nada de una clave o un proyecto anteriores.

## Hooks y constantes

### Filtros

```php
// Quién ve el widget. Por defecto: 'manage_options', o 'edit_others_posts'
// si en los ajustes se elige "Editores y administradores".
add_filter( 'aivd_widget_capability', function ( $capability ) {
	return 'view_ai_visibility'; // p. ej. un permiso propio dado a un rol de cliente.
} );

// Cuánto tiempo se consideran recientes los datos, en segundos (mínimo 60). Por defecto: 6 horas.
add_filter( 'aivd_cache_ttl', function () {
	return 12 * HOUR_IN_SECONDS;
} );

// Segundos de espera por respuesta (de 2 a 30). Por defecto: 10.
add_filter( 'aivd_http_timeout', function () {
	return 15;
} );

// Segundos que puede esperar una actualización en total (mínimo 5).
// Por defecto: 12 si alguien espera a la página ('interactive'), 25 en WP-Cron ('background').
add_filter( 'aivd_refresh_budget', function ( $budget, $mode ) {
	return 'background' === $mode ? 40 : $budget;
}, 10, 2 );

// URL base de la API, sin barra final. Por defecto: https://api.mencoro.com
// Debe ser HTTPS, o HTTP en localhost / 127.0.0.1 / ::1; si no, se usa la de por defecto.
add_filter( 'aivd_api_base', function () {
	return 'http://127.0.0.1:8898'; // p. ej. un servidor simulado en local.
} );
```

### Constantes

| Constante | Efecto |
|---|---|
| `MENCORO_API_KEY` | Clave de API. Tiene prioridad sobre la variable de entorno `MENCORO_API_KEY` y sobre la clave guardada; oculta el campo de la clave. |
| `AIVD_API_BASE` | URL base de la API; el filtro `aivd_api_base` se aplica después. |
| `AIVD_VERSION`, `AIVD_FILE`, `AIVD_DIR`, `AIVD_URL` | Las define el plugin. |

### Acciones del panel

`admin-post.php?action=aivd_save` (formulario de ajustes, nonce `aivd_save`, `manage_options`) y `admin-post.php?action=aivd_refresh` (nonce `aivd_refresh`, permiso del widget, con límite de frecuencia). El periodo del widget es un parámetro de solo lectura `?aivd_range=7|28|90` en la URL del Escritorio.

Evento de WP-Cron: `aivd_background_refresh`, con el periodo como único argumento. Se desprograma al desactivar y al desinstalar.

## Desarrollo en local

1. Clona el repositorio en `wp-content/plugins/` de cualquier WordPress 6.5+ y actívalo.
2. Usa una clave real de solo lectura de Mencoro, o apunta el plugin a un servidor simulado con `define( 'AIVD_API_BASE', 'http://127.0.0.1:8898' );` que devuelva JSON con la forma de las respuestas OpenAPI. El plugin solo necesita las nueve rutas `GET` de arriba.
3. Pon `WP_DEBUG` y `WP_DEBUG_LOG` a `true` y comprueba que `wp-content/debug.log` no tenga avisos de `aivd`.

Casos que merece la pena probar a mano: un proyecto nuevo sin comprobaciones (métricas nulas, serie vacía), un proyecto que solo sigue `google_serp`, una clave con 401, un proyecto con 404, una respuesta 5xx, un competidor con HTML en el nombre, un día sin datos en la serie y el widget a ancho de móvil.

## Normas de código

```bash
phpcs --standard=phpcs.xml.dist
```

El conjunto de reglas aplica WordPress-Extra, WordPress-Docs, el dominio de texto y el prefijo `aivd`. El código también se revisa con PHPCompatibility para PHP 7.4+:

```bash
phpcs --standard=PHPCompatibility --runtime-set testVersion 7.4- --extensions=php --ignore=languages .
```

Normas de la casa: escapar al final, sanear al principio, nonces y comprobación de permisos en cada manejador, ninguna consulta directa a la base de datos fuera de `uninstall.php` y del bloqueo (`AIVD_Lock`, que debe saltarse la caché de opciones), y nada de JavaScript salvo que se lo gane.

## Traducciones

Los textos de origen están en inglés; el dominio de texto es `ai-visibility-dashboard`. Con WP-CLI:

```bash
wp i18n make-pot . languages/ai-visibility-dashboard.pot --exclude=languages,docs
# traduce languages/ai-visibility-dashboard-{locale}.po y después:
wp i18n make-mo languages
wp i18n make-php languages
```

Sube los archivos `.po`, `.mo` y `.l10n.php`. El español de España tutea, como la traducción oficial de WordPress (es_ES).

El plugin no llama a `load_plugin_textdomain()`: como se recomienda para los plugins alojados en WordPress.org, WordPress carga sobre la marcha los paquetes de idioma de translate.wordpress.org. El `.po` de `languages/` es el que hay que importar allí (español de España, proyecto *AI Visibility Dashboard for Mencoro*). En instalaciones desde GitHub, los archivos `.mo` y `.l10n.php` funcionan si se copian a `wp-content/languages/plugins/`.

## Tests

Consulta [CONTRIBUTING.md](../../CONTRIBUTING.md): `bin/install-wp-tests.sh` prepara WordPress con el drop-in de SQLite y `composer test` ejecuta PHPUnit contra una API de Mencoro simulada (`pre_http_request`), en sitio único o multisitio (`WP_MULTISITE=1`).

## Publicar una versión

Los archivos de desarrollo (`docs/`, `tests/`, `bin/`, `.github/`, `.wordpress-org/`, los README y los archivos de Composer y PHPUnit) están marcados como `export-ignore` en `.gitattributes`, así que el zip que GitHub genera para una etiqueta, y `git archive`, solo contienen el plugin:

```bash
git archive --format=zip --prefix=ai-visibility-dashboard/ -o ai-visibility-dashboard-1.0.1.zip v1.0.1
```

Antes de etiquetar: sube `Version` en la cabecera del plugin, `AIVD_VERSION` y `Stable tag` en `readme.txt`, añade la entrada al registro de cambios y el aviso de actualización, regenera las traducciones y pasa Plugin Check al zip.

Las imágenes de WordPress.org (icono, banner, capturas) están en `.wordpress-org/`. Van en la carpeta `assets/` de primer nivel del repositorio SVN, junto a `trunk/` y `tags/`, nunca dentro del plugin.

## Contribuir

Las incidencias y las pull requests son bienvenidas. Mantén cada pull request centrada en una cosa, sigue las normas de código y cuenta cómo lo has probado. Si toca la clave de API o el escapado de la salida, explica el razonamiento de seguridad en la pull request.
