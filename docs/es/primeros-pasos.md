# Primeros pasos

[Documentación](README.md) › Primeros pasos

De cero al widget en tu Escritorio en unos cinco minutos.

## Qué necesitas

- **WordPress 6.5 o superior** y **PHP 7.4 o superior**.
- **Una cuenta de Mencoro con un proyecto.** El plugin muestra los datos que recoge Mencoro; no consulta ninguna IA por su cuenta. El proyecto debe seguir al menos un motor de IA: ChatGPT, Claude, Perplexity, Google AI Overviews o Google Modo IA.
- **Una clave de API de Mencoro.** Mira el paso 1.
- Una cuenta de administrador en WordPress.

## 1. Crea una clave de API en Mencoro

1. Entra en Mencoro.
2. Abre el menú de usuario y ve a **API keys**.
3. Crea una clave. Basta con el permiso **`read`** (lectura): el plugin nunca crea, cambia ni borra nada en Mencoro. Si puedes limitar la clave a algunas organizaciones, dale acceso a la que tiene tu proyecto.
4. Copia la clave y guárdala en un sitio seguro. **Mencoro solo la muestra una vez**: si la pierdes, revócala y crea otra.

> 💡 Una clave por web es buena costumbre. Si una web se ve comprometida o un cliente se va, revocas esa clave y no se rompe nada más.

## 2. Instala el plugin

**Desde el zip de la versión (recomendado)**

1. Descarga `ai-visibility-dashboard-x.y.z.zip` de la [última versión](https://github.com/antoniodiazpi/AI-Visibility-Dashboard/releases/latest).
2. En WordPress, ve a **Plugins → Añadir nuevo plugin → Subir plugin**, elige el zip y pulsa **Instalar ahora**.
3. Pulsa **Activar**.

**Desde el repositorio**

```bash
cd wp-content/plugins
git clone https://github.com/antoniodiazpi/AI-Visibility-Dashboard.git ai-visibility-dashboard
```

Después actívalo en **Plugins**. Al plugin le da igual el nombre de la carpeta, pero `ai-visibility-dashboard` deja todo más ordenado.

## 3. Conecta tu clave

1. Ve a **Ajustes → Visibilidad en IA**. (También hay un enlace **Ajustes** junto al plugin, en la pantalla de Plugins.)
2. Pega la clave en **Clave de API**.
3. Pulsa **Guardar y comprobar la conexión**.

El plugin consulta a Mencoro en ese momento para comprobar la clave:

- ✅ *Clave guardada y funcionando. Ahora elige el proyecto que quieres ver.* Sigue con el paso 4.
- ❌ Un mensaje de error: mira [Solución de problemas](solucion-de-problemas.md#mensajes-en-la-pantalla-de-ajustes).

> 🔒 En webs de clientes o compartidas, mejor pon la clave en `wp-config.php` que en la base de datos. Mira [Para agencias](agencias.md#saca-la-clave-de-la-base-de-datos).

## 4. Elige qué mostrar

Cuando hay una clave que funciona, se rellena la segunda parte de la pantalla.

| Ajuste | Qué hace |
|---|---|
| **Proyecto** | Todos los proyectos activos que puede leer la clave, agrupados por organización. Si solo hay uno, ya viene elegido. |
| **Motores de IA** | Qué motores cuentan. Todos marcados significa "todos los motores de IA que siga el proyecto", también los que añadas más adelante. Los que el proyecto no sigue se ignoran. Los resultados clásicos de Google y Shopping siempre se dejan fuera. |
| **Periodo por defecto** | 7, 28 o 90 días. Cualquiera puede cambiar el periodo desde el widget; este es con el que se abre. |
| **Quién ve el widget** | *Administradores*, o *Editores y administradores*. Los ajustes y la clave solo los ven los administradores. |

Vuelve a pulsar **Guardar y comprobar la conexión**. Debería aparecer *Ajustes guardados. El widget ya está listo en tu Escritorio.*

## 5. Abre el Escritorio

Ve al **Escritorio**. Ahí está el widget **Visibilidad en IA**; puedes arrastrarlo a otra columna o plegarlo como cualquier otro widget.

La primera carga hace unas pocas peticiones a Mencoro (alrededor de un segundo); después los datos se guardan en caché 6 horas. Para entender cada cifra y qué hacer con ella, lee [Cómo leer el widget](leer-el-widget.md).

## Actualizar

Sube el zip de la nueva versión encima del anterior (WordPress te ofrece reemplazarlo), o haz `git pull` en la carpeta del plugin. Los ajustes y la clave se conservan.

## Desinstalar

Desactiva y borra el plugin desde **Plugins**. Al borrarlo se eliminan los ajustes, la clave guardada y todos los datos en caché. Si definiste la clave en `wp-config.php`, quita esa línea tú. Y acuérdate de revocar la clave en Mencoro si ya no la necesitas.
