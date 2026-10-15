# Administrador del menú de WordPress (buckler-core, módulo `adminmenu`)

Ajustes → Buckler Core → "Administrador del menú de WordPress". Herramienta: **Herramientas → Administrador del menú**
(`tools.php?page=bk-admin-menu`).

## Qué hace
- **Diseño:** menú lateral con los colores globales reales de Divi (navy `#1a3955`, lima `#e4ec6a`), 200 px solo en
  escritorio ≥ 961 px con el menú expandido (tableta: WordPress lo contrae; celular: panel; contraído: 36 px). Activo con
  barra lima + fondo oscuro, hover/foco en lima (contraste ≥ 7:1), iconos SVG de plugins repintados vía `_wpColorScheme`.
  Ajustes de ancho para el editor de bloques (`.interface-interface-skeleton`) y la cabecera de WooCommerce.
- **Logo:** desde la Mediateca, ancho máximo 60–176 px, sin deformarse (ancho y alto automáticos), enlace configurable
  (por defecto el sitio público) con `target="_blank" rel="noopener noreferrer"`; en el menú contraído se reduce a 28 px.
- **Organización:** orden (arrastrar y soltar con jQuery UI Sortable de WordPress, o teclado con flechas sobre el asa),
  ocultar/mostrar elementos y submenús, separadores y encabezados propios, vista previa, guardar y restablecer.
  Se aplica con el filtro nativo `menu_order` y quitando entradas de `$menu`/`$submenu` (la página sigue registrada).
- **Por usuario:** selector de usuarios existentes (por nombre; no se muestran IDs). La configuración del usuario
  (meta `bk_am_user`) tiene prioridad sobre la global (opción `bk_am_settings[global]`); "Restablecer este usuario"
  vuelve a la global. Usuarios nuevos o con rol cambiado: usan la global y las capacidades de su rol.
- **Acceso a plugins de IA y buckler-core:** usuarios autorizados guardados por **ID interno** (resueltos desde los
  nombres `sebas_ufoweb` y `sebas_ufoweb.co`). Las páginas marcadas desaparecen del menú de los demás y su URL directa
  responde **403**; las acciones de buckler-core (`bk_modules_save`, `bk_cache_purge`, `bk_am_*`) también. Las páginas
  se identifican por la carpeta del plugin que registra su función (reflexión), no por palabras del título.
  Por defecto: todas las de buckler-core salvo "Carritos abandonados" (`bk-abandoned`, uso de la tienda) y las de las
  carpetas del editor con IA de Divi (`ai-editor-divi5`, variantes; filtro `bk_am_ai_plugin_dirs`).

## Prioridad
1. Obligatorios: Perfil para todos; Herramientas → Administrador del menú para administradores autorizados.
2. Capacidades de WordPress (nunca se conceden: ocultar no es restringir y un elemento "Sin permiso" no se ve).
3. Configuración del usuario. 4. Configuración global. 5. Menú original (incluido el orden que imponen otros plugins,
   p. ej. WooCommerce con `menu_order`).

## Seguridad
- Guardar/restablecer: `manage_options` + usuario autorizado + nonce (`check_admin_referer`), JSON limpiado en el
  servidor (`bk_am_clean_conf`: solo IDs de texto, encabezados ≤ 40 caracteres, separadores/encabezados `bk-(sep|head)-*`).
- Encabezados y separadores no son páginas: el encabezado se pinta como texto con `role="heading"` (no es un enlace)
  y el separador usa el marcado nativo de WordPress.
- Quien guarda la lista de autorizados debe estar en ella (evita quedarse fuera). Si ningún usuario autorizado existe,
  la restricción se desactiva y aparece un aviso. **Recuperación:** `define( 'BK_AM_SAFE_MODE', true );` en
  `wp-config.php` desactiva reorganización y restricción.

## Excepciones conocidas (no se prometen para todos los plugins)
- **REST y AJAX propios de plugins de terceros** (p. ej. el endpoint MCP del editor con IA) no se interceptan: su
  seguridad depende de cada plugin (el MCP usa su propia clave). Se protegen las páginas de administración y las
  acciones de buckler-core.
- **Barra superior:** los accesos que algunos plugins añaden allí no se tocan.
- **Plugins que cambian su slug** en una actualización: el ajuste viejo se ignora (la herramienta avisa y lo limpia al
  guardar) y el elemento nuevo aparece en su posición original.
- WordPress quita solo los separadores repetidos o finales; la vista previa los muestra igual.
- Páginas de WooCommerce hechas en React (`wc-admin`) se reordenan/ocultan como cualquier otra entrada del menú.
