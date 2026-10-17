# Desarrolladores

[Documentación](README.md) › Desarrolladores

Cómo está hecho el plugin, cómo ampliarlo y cómo contribuir.

## Arquitectura

Sin compilación, sin dependencias de Composer, sin JavaScript. PHP 7.4+, WordPress 6.5+.

```
ai-visibility-dashboard/
├── ai-visibility-dashboard.php   Arranque: constantes, traducciones, activación, enlace de ajustes
├── includes/
│   ├── class-aivd-settings.php   Ajustes guardados, origen de la clave, permiso, versión de caché
│   ├── class-aivd-api.php        Cliente HTTP de solo lectura para la API de Mencoro
│   ├── class-aivd-data.php       Llama a la API, da forma a los datos y los guarda en caché
│   ├── class-aivd-widget.php     Genera el widget del Escritorio (HTML en servidor + SVG en línea)
│   └── class-aivd-admin.php      Pantalla de ajustes y manejadores admin-post
├── assets/admin.css              Solo se carga en el Escritorio y en la pantalla de ajustes
├── languages/                    .pot y es_ES .po/.mo/.l10n.php
└── uninstall.php                 Borra opciones y transitorios, compatible con multisitio
```

Prefijos: `aivd_` para funciones, opciones, transitorios, hooks y acciones; `AIVD_` para clases y constantes.

### Flujo de una petición

```
Carga del Escritorio
  └─ AIVD_Widget::render()
       └─ AIVD_Data::get( $range )
            ├─ en caché      → datos (o un error recordado)
            └─ sin caché     → AIVD_Data::build()
                 ├─ GET  projects/{id}                 nombre, dominios, marca, nombres de competidores
                 ├─ GET  projects/{id}/available-filters   motores que sigue el proyecto
                 ├─ GET  projects/{id}/metrics         cifras principales, sentimiento, cuota de voz por competidor
                 ├─ GET  projects/{id}/timeseries      cuota de voz por día o semana
                 ├─ GET  projects/{id}/cited-sources   6 dominios más citados
                 ├─ GET  projects/{id}/metrics/movers  3 mayores subidas (sortOrder=desc)
                 └─ GET  projects/{id}/metrics/movers  3 mayores bajadas (sortOrder=asc)
```

Todas las rutas cuelgan de `https://api.mencoro.com/api/v1/organizations/{organizationId}/`. Parámetros del tramo: `dateFrom`, `dateTo` (UTC, `Y-m-d`), `engines` (separados por comas). La serie temporal usa `granularity=daily`, o `weekly` en 90 días; las consultas que más se mueven usan `sortBy=trend_share_of_voice&limit=3`; las fuentes, `groupBy=domain&limit=6`.

Si fallan `projects/{id}`, `available-filters` o `metrics`, todo el widget muestra el error. Si fallan la serie temporal, las fuentes o las consultas, solo esa sección lo muestra.

La pantalla de ajustes usa además `GET /api/v1/me` (comprobar la clave al guardar), `GET /api/v1/organizations` y `GET /api/v1/organizations/{id}/projects` (selector de proyecto).

Referencia: [documentación de la API de Mencoro](https://mencoro.com/api-docs/) y el contrato OpenAPI en [mencoro/mencoro-api-sdk](https://github.com/mencoro/mencoro-api-sdk).

### Motores

`AIVD_Settings::AI_ENGINES` = `chatgpt`, `claude`, `perplexity`, `google_ai_overview`, `google_ai_mode`. Los motores que se envían son *(los marcados en los ajustes, o todos)* ∩ *(los filtros disponibles del proyecto)* ∩ `AI_ENGINES`. `google_serp` y `google_shopping` nunca se envían.

### Caché

| Qué | Clave | Duración |
|---|---|---|
| Datos del widget | `aivd_` + md5(versión de caché, organización, proyecto, periodo, motores, md5(clave), fecha UTC) | 6 h (`aivd_cache_ttl`) |
| Petición fallida | la misma clave | 5 min |
| Lista de proyectos (ajustes) | `aivd_projects_` + md5(versión de caché, clave) | 10 min |

**Actualizar** y guardar los ajustes suben la opción `aivd_cache_version` en lugar de borrar los transitorios uno a uno, así que la invalidación también funciona con cachés de objetos persistentes. Las entradas viejas simplemente caducan.

## Hooks y constantes

### Filtros

```php
// Quién ve el widget. Por defecto: 'manage_options', o 'edit_others_posts'
// si en los ajustes se elige "Editores y administradores".
add_filter( 'aivd_widget_capability', function ( $capability ) {
	return 'view_ai_visibility'; // p. ej. un permiso propio dado a un rol de cliente.
} );

// Cuánto tiempo se guardan los datos, en segundos (mínimo 60). Por defecto: 6 horas.
add_filter( 'aivd_cache_ttl', function () {
	return 12 * HOUR_IN_SECONDS;
} );

// URL base de la API, sin barra final. Por defecto: https://api.mencoro.com
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

`admin-post.php?action=aivd_save` (formulario de ajustes, nonce `aivd_save`, `manage_options`) y `admin-post.php?action=aivd_refresh` (nonce `aivd_refresh`, permiso del widget). El periodo del widget es un parámetro de solo lectura `?aivd_range=7|28|90` en la URL del Escritorio.

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

Normas de la casa: escapar al final, sanear al principio, nonces y comprobación de permisos en cada manejador, ninguna consulta directa a la base de datos fuera de `uninstall.php`, y nada de JavaScript salvo que se lo gane.

## Traducciones

Los textos de origen están en inglés; el dominio de texto es `ai-visibility-dashboard`. Con WP-CLI:

```bash
wp i18n make-pot . languages/ai-visibility-dashboard.pot --exclude=languages,docs
# traduce languages/ai-visibility-dashboard-{locale}.po y después:
wp i18n make-mo languages
wp i18n make-php languages
```

Sube los archivos `.po`, `.mo` y `.l10n.php`. El español de España tutea, como la traducción oficial de WordPress (es_ES).

## Publicar una versión

Los archivos de desarrollo (`docs/`, los README, `phpcs.xml.dist`, los archivos de git) están marcados como `export-ignore` en `.gitattributes`, así que el zip que GitHub genera para una etiqueta, y `git archive`, solo contienen el plugin:

```bash
git archive --format=zip --prefix=ai-visibility-dashboard/ -o ai-visibility-dashboard-1.0.0.zip v1.0.0
```

Antes de etiquetar: sube `Version` en la cabecera del plugin, `AIVD_VERSION` y `Stable tag` en `readme.txt`, y añade la entrada al registro de cambios.

## Contribuir

Las incidencias y las pull requests son bienvenidas. Mantén cada pull request centrada en una cosa, sigue las normas de código y cuenta cómo lo has probado. Si toca la clave de API o el escapado de la salida, explica el razonamiento de seguridad en la pull request.
