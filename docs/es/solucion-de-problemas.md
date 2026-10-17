# Solución de problemas

[Documentación](README.md) › Solución de problemas

Todos los mensajes que puede mostrar el plugin, qué los provoca y cómo arreglarlos. Aparecen en la pantalla de ajustes al guardar, o en rojo dentro del widget.

## Mensajes en la pantalla de ajustes

| Mensaje | Causa | Solución |
|---|---|---|
| *Mencoro ha rechazado la clave de API. Comprueba que está completa y que no se ha revocado.* | La clave es incorrecta, está incompleta o se ha revocado (HTTP 401). | Vuelve a pegarla. Si tienes dudas, crea una clave nueva en Mencoro y revoca la anterior. |
| *Esta clave de API no puede leer estos datos. Dale el permiso «read» y acceso a la organización.* | La clave es válida pero no tiene permiso para leer esa organización o proyecto (HTTP 403). | En Mencoro, amplía el alcance de la clave o crea una con acceso a esa organización. |
| *Esta clave todavía no ve ningún proyecto activo. Crea uno en Mencoro y vuelve.* | La clave funciona pero no ve ningún proyecto activo. | Crea un proyecto en Mencoro, o da a la clave acceso a la organización que lo tiene. Los proyectos archivados no aparecen. |
| *Guarda primero tu clave de API para ver tus proyectos.* | Aún no hay clave. | Pega la clave y guarda. |
| *No se pudo conectar con Mencoro: …* | WordPress no pudo conectar con `api.mencoro.com` (red, DNS, cortafuegos, SSL). | Mira [Problemas de conexión](#problemas-de-conexión). |

## Mensajes dentro del widget

| Mensaje | Causa | Solución |
|---|---|---|
| *Mencoro no encuentra este proyecto con esta clave. Vuelve a elegir el proyecto en los ajustes.* | El proyecto se archivó o se borró, o se cambió la clave por una de otra cuenta (HTTP 404). | Ve a **Ajustes → Visibilidad en IA** y vuelve a elegir el proyecto. |
| *Demasiadas peticiones a Mencoro. Inténtalo de nuevo en … segundos.* | Mencoro está limitando las peticiones (HTTP 429). | Espera el tiempo indicado y pulsa **Reintentar**. Si se repite, comprueba que nada más esté usando mucho la misma clave. |
| *Mencoro ha respondido con un error (HTTP …).* | Un error en el lado de Mencoro (normalmente 5xx). | Inténtalo más tarde. Si sigue, consulta el estado de Mencoro o escribe a su soporte. |
| *Mencoro envió una respuesta que el plugin no ha podido leer.* | La respuesta no era JSON válido; muchas veces es un proxy o un cortafuegos respondiendo en lugar de Mencoro. | Mira [Problemas de conexión](#problemas-de-conexión). |
| *Añade tu clave de API de Mencoro en los ajustes.* | Falta la clave (por ejemplo, se quitó la constante de `wp-config.php`). | Vuelve a añadir la clave. |

Cuando arregles la causa, pulsa **Reintentar** en el widget. Los errores se recuerdan **5 minutos** para que una clave rota no consulte a Mencoro en cada carga de página; **Reintentar** borra ese recuerdo.

## El widget no aparece

- **No tienes permiso para verlo.** Por defecto solo lo ven los administradores. Mira [Para agencias](agencias.md#enséñale-el-widget-al-cliente-no-la-clave).
- **Está oculto.** Abre **Opciones de pantalla**, arriba a la derecha del Escritorio, y marca **Visibilidad en IA**.
- **Está plegado.** Pulsa en su barra de título.

## Las cifras no coinciden con las de Mencoro

Revisa, en este orden:

1. **Motores.** El widget solo cuenta motores de IA, nunca los resultados clásicos de Google ni Shopping, y solo los marcados en los ajustes. Las vistas de Mencoro pueden incluir más.
2. **Periodo.** "28 días" son los últimos 28 días UTC, hoy incluido, comparados con los 28 anteriores.
3. **Caché.** El widget puede ir hasta 6 horas por detrás. Pulsa **Actualizar**.
4. **Recálculo.** Si el widget dice *Mencoro está recalculando parte de este periodo*, las cifras aún se están asentando en los dos lados.

## Las cifras no cambian de un día para otro

Mencoro comprueba las consultas con la frecuencia que tenga tu proyecto, normalmente una vez al día, y hay días en que casi nada se mueve. Los periodos cortos (7 días) tienen más ruido; los largos (90 días) enseñan la tendencia.

## Problemas de conexión

Los errores *No se pudo conectar* y *no ha podido leer* vienen del servidor donde corre WordPress, no de tu navegador:

- **Peticiones salientes bloqueadas.** Algunos alojamientos o plugins de seguridad bloquean las peticiones HTTP salientes (`WP_HTTP_BLOCK_EXTERNAL`). Permite `api.mencoro.com` con `WP_ACCESSIBLE_HOSTS`, o pregunta a tu alojamiento.
- **Certificados SSL o cURL de PHP desactualizados** en el servidor. **Herramientas → Salud del sitio** suele avisarlo.
- **Un proxy delante del servidor** que hay que configurar en WordPress (`WP_PROXY_HOST` y constantes relacionadas).

## ¿Sigue sin funcionar?

[Abre una incidencia](https://github.com/antoniodiazpi/AI-Visibility-Dashboard/issues) con tus versiones de WordPress y PHP, el mensaje exacto y lo que ya has probado. **Nunca pegues tu clave de API.**
