# Actualizaciones del plugin Formularios

El plugin se actualiza desde las **versiones (Releases) de GitHub**, con un clic
en **Plugins** del panel de WordPress. No hace falta desinstalarlo ni volver a
instalarlo, y los formularios y las respuestas guardadas se conservan.

Esto afecta únicamente a Formularios; ningún otro plugin del sitio se modifica.

## Cómo publicar una versión nueva

1. En `formularios-plugin/formularios.php`, cambie el número en **los dos lugares**:
   `Version:` (encabezado) y `FORMULARIOS_VERSION`. Deben ser iguales.
2. Fusione los cambios en `main`.
3. Cree la versión en GitHub: **Releases → Draft a new release**, con una etiqueta
   nueva `vX.Y.Z` (por ejemplo `v1.5.1`, igual al número del paso 1) y publíquela.
4. Un flujo automático (pestaña **Actions**) verifica que los números coincidan,
   revisa la sintaxis PHP de todos los archivos, arma el ZIP y lo adjunta.
   **Si algo falla, la versión no llega a los sitios.** Revise el error en Actions,
   corrija y publique una etiqueta nueva (no reutilice la anterior).

El sitio ofrece la actualización solo si la versión tiene su ZIP adjunto
(`formularios-X.Y.Z.zip`).

## Cómo llega al sitio

- WordPress busca versiones nuevas cada 12 horas. Para forzarlo: **Escritorio →
  Actualizaciones → Comprobar de nuevo**.
- Aparece el aviso en **Plugins**; se aplica con **Actualizar ahora**.
- Se recomienda **no activar las actualizaciones automáticas** de este plugin
  en un sitio institucional: es preferible probar cada versión antes.

## Antes de actualizar en el sitio real

1. Haga un respaldo de la base de datos y de la carpeta `wp-content/plugins`.
2. Si es posible, pruebe la versión primero en un sitio de pruebas.

## Si una actualización da error

Los datos (formularios, respuestas, configuración) están en la base de datos y
**no se modifican** al actualizar ni al volver a una versión anterior.

1. **El sitio muestra "error crítico":** WordPress envía un correo al administrador
   con un enlace al *modo de recuperación*. Ingrese, **desactive Formularios** y el
   resto del sitio vuelve a funcionar.
2. **Volver a la versión anterior:** descargue el ZIP de la versión anterior desde
   **Releases** en GitHub y, en **Plugins → Añadir nuevo → Subir plugin**, elija
   **Reemplazar actual con la subida**.
3. **Sin acceso al panel:** desde el administrador de archivos o FTP del hosting,
   renombre la carpeta del plugin en `wp-content/plugins` (queda desactivado),
   ingrese al panel y repita el paso 2.
4. Corrija el problema y publique una versión nueva (paso "Cómo publicar").

Solo se perderían datos si se restaurara un respaldo antiguo de la **base de
datos**; restaurar únicamente los archivos del plugin no los afecta.

## Desactivar las actualizaciones desde GitHub

Agregue en `wp-config.php`:

```php
define( 'FORMULARIOS_DISABLE_UPDATER', true );
```

## Seguridad

- Active la verificación en dos pasos en las cuentas con acceso al repositorio.
- Proteja las etiquetas `v*` (GitHub → Settings → Rules) para que solo personas
  autorizadas puedan crear versiones.
- Quien pueda publicar una versión puede cambiar el código que se instala en el
  sitio: limite ese permiso.
