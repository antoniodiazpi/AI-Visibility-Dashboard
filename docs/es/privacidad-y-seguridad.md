# Privacidad y seguridad

[Documentación](README.md) › Privacidad y seguridad

Qué envía, qué guarda y qué muestra el plugin. Útil para tu tranquilidad, para el delegado de protección de datos de un cliente o para una política de privacidad.

## Qué sale de tu web

El plugin habla con **un único servicio: la API de Mencoro (`https://api.mencoro.com`)**. Todas las peticiones son `GET` (solo lectura). Envía:

- tu clave de API, en la cabecera `Authorization`;
- los identificadores de la organización y el proyecto que elegiste;
- el tramo de fechas y los motores de IA sobre los que informar;
- un `User-Agent` con el nombre y la versión del plugin.

**No** envía la URL de tu web, su contenido, sus usuarios, visitantes, direcciones IP ni analítica. Desde la parte pública de tu web no se envía nada: el plugin solo funciona en el panel de administración.

### Cuándo se hacen peticiones

- Cuando alguien con permiso para ver el widget abre el Escritorio y los datos en caché han caducado (cada 6 horas, y al empezar cada día UTC).
- Cuando alguien pulsa **Actualizar** o **Reintentar**.
- Cuando un administrador guarda o abre **Ajustes → Visibilidad en IA** (para comprobar la clave y listar proyectos; la lista de proyectos se guarda 10 minutos).

Una actualización completa hace 7 peticiones; listar proyectos hace una por cada organización que pueda leer la clave, más una.

Condiciones y política de privacidad de Mencoro: <https://mencoro.com/legal/>.

## Qué se guarda en WordPress

| Dato | Dónde | Notas |
|---|---|---|
| Ajustes y clave de API | `wp_options`, opción `aivd_settings` | No se carga automáticamente, así que no se lee en cada página. Si la clave viene de `wp-config.php` o de una variable de entorno, al guardar los ajustes se borra la que hubiera guardada. |
| Datos en caché | Transitorios `aivd_…` | Métricas agregadas, nombres de competidores, dominios citados y textos de las consultas seguidas. Sin datos personales. Caducan solos. |
| Versión de la caché | `wp_options`, opción `aivd_cache_version` | Un número que sube al pulsar **Actualizar**. |

Al borrar el plugin desde **Plugins** se elimina todo, en todos los sitios de una red multisitio.

## Qué se muestra y a quién

- La **pantalla de ajustes** exige `manage_options` (administradores).
- El **widget** exige `manage_options`, o `edit_others_posts` (editores) si lo permites; se puede cambiar con un filtro.
- La **clave nunca se vuelve a mostrar**, ni siquiera en el código HTML: solo sus cuatro últimos caracteres, para reconocerla.
- Todo lo que llega de Mencoro (nombres de proyecto y competidores, dominios, textos de consultas) se escapa antes de mostrarse.

## Parte pública y código de terceros

- No añade nada a la parte pública de tu web: ni scripts, ni estilos, ni cookies, ni seguimiento.
- En el panel carga una pequeña hoja de estilos, solo en el Escritorio y en su propia pantalla de ajustes.
- Sin JavaScript, sin fuentes externas, sin CDN, sin librerías de terceros.

## Lista de comprobación

- Usa una **clave de solo lectura**, limitada a la organización que necesites.
- **Una clave por web**, con el nombre de la web en Mencoro.
- En webs que administran otras personas, mejor la **constante en `wp-config.php`**.
- **Revoca** la clave en Mencoro cuando retires una web o se vaya un cliente.

## Informar de una vulnerabilidad

No abras una incidencia pública, por favor. Contacta con el autor en privado a través de [trailseo.com](https://trailseo.com/) con los detalles, y deja tiempo para corregirla antes de hacerla pública.
