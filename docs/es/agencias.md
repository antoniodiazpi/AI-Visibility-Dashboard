# Para agencias

[Documentación](README.md) › Para agencias

El plugin está pensado para una situación muy habitual en agencias: sigues la visibilidad en IA de un cliente en Mencoro, y el cliente vive en su panel de WordPress. Así se configura bien.

## Enséñale el widget al cliente, no la clave

En **Ajustes → Visibilidad en IA → Quién ve el widget**, elige **Editores y administradores**.

| Rol | Ve el widget | Ve los ajustes y la clave |
|---|---|---|
| Administrador | ✅ | ✅ |
| Editor | ✅ con esta opción | ❌ nunca |
| Autor, Colaborador, Suscriptor | ❌ | ❌ |

Los editores también tienen el enlace **Actualizar**; el enlace **Ajustes** solo lo ven los administradores. Si el usuario principal del cliente es administrador, podrá ver la pantalla de ajustes, pero nunca la clave: solo se muestran sus cuatro últimos caracteres.

¿Necesitas otra regla (un rol propio, un permiso concreto)? Usa el [filtro `aivd_widget_capability`](desarrolladores.md#filtros).

## Saca la clave de la base de datos

Define la clave en `wp-config.php`, encima de la línea que dice *¡Eso es todo, deja de editar!* (*That's all, stop editing!*):

```php
define( 'MENCORO_API_KEY', 'mencoro_sk_…' );
```

Cuando existe la constante:

- Tiene prioridad sobre cualquier clave guardada en los ajustes.
- El campo de la clave desaparece de los ajustes y en su lugar pone *Definida en wp-config.php con la constante MENCORO_API_KEY (termina en …)*.
- Nadie puede cambiarla ni borrarla desde el panel de WordPress, y no viaja con las exportaciones ni las copias de la base de datos.
- Si antes había una clave guardada en los ajustes, pulsa **Guardar y comprobar la conexión** una vez: la copia guardada se borra.

Una **variable de entorno** `MENCORO_API_KEY` funciona igual, para alojamientos que gestionan los secretos así. La constante tiene prioridad sobre la variable, y las dos sobre la clave guardada.

## ¿Una clave o varias?

Una clave de Mencoro puede leer varias organizaciones. En el plugin, el selector agrupa los proyectos por organización, así que la misma clave podría servir para muchas webs. Aun así, **una clave por web de cliente** es más seguro:

- Revocar la clave de una web no rompe las demás.
- Puedes limitar la clave a la organización del cliente, así que una web comprometida solo expone los datos de ese cliente.
- La lista de claves de Mencoro te dice qué web es cuál.

## Varias webs, un proyecto de Mencoro

Nada impide que varias webs de WordPress (el blog y la tienda de una marca, por ejemplo) muestren el mismo proyecto. Cada web guarda su propia caché, así que cada actualización gasta sus propias peticiones.

## Multisitio

Los ajustes van por sitio. En una red, cada sitio conecta su propia clave y proyecto, o defines `MENCORO_API_KEY` una vez en `wp-config.php` y cada sitio solo elige su proyecto. Al desinstalar desde la red se borran los datos del plugin de todos los sitios.

## Una rutina que funciona

1. Crea el proyecto del cliente en Mencoro, con sus competidores y los prompts que le importan.
2. Crea una clave de solo lectura limitada a la organización del cliente, con el nombre de la web.
3. Instala el plugin en la web del cliente, define la clave en `wp-config.php`, elige el proyecto y marca *Editores y administradores*.
4. Repasa con el cliente [Cómo leer el widget](leer-el-widget.md) una vez. El glosario del propio widget cubre el resto.
5. En tu informe mensual, apóyate en las mismas cifras que el cliente ve cada día.
