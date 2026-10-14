# Buckler Jeans — contexto del proyecto

Rediseño de https://buckler.com.co (WordPress + Divi 5 + ACF Pro + WooCommerce 11.2.1, COP).
Marca: **BUCKLER JEANS by Denim Blue** (razón social DENIM BLUE JEANS S.A.S., Medellín). Moda femenina en
denim, dos líneas: **Slim** (tallas regulares 6–12) y **Curvy** (plus size 14–24). Idioma: español (Colombia).

Rama de trabajo: `claude/awesome-cori-fea1y8`. **No crear PR** salvo petición expresa.

## Objetivos
1. Tienda más profesional, sencilla e intuitiva.
2. Velocidad: reemplazar plugins pesados por código propio (`buckler-core`).
3. SEO para venta online + preparación para pauta Meta/Google (feeds, eventos, landings).
4. Accesibilidad alta (objetivo WCAG 2.2 AA).

## Conexión MCP (Divi 5 AI Editor Pro v4.10.0)
- Endpoint: `https://buckler.com.co/wp-json/ai-editor-divi5/v1/mcp` (JSON-RPC 2.0, POST).
- Auth: el proxy del entorno inyecta `X-AIED-Authorization`; el mu-plugin `auth-bridge.php` la pasa a WP.
  **Nunca** pedir ni escribir la clave. 401 = la clave no llega; 403 = clave no vigente.
- 42 herramientas (incluye WooCommerce: list_products, get_product, create_product, update_products,
  bulk_adjust_prices). Rutas `/pro/*` presentes → versión Pro confirmada.
- Cliente: `python3 tools/mcp.py ping | tools --names | call <tool> '<json>' | pull <page_id> | rest [/ruta]`.
- `audit_site` es de solo lectura (no lleva dry_run). `set_primary_menu` sí admite `dry_run`.

## Estado actual del sitio (diagnóstico 2026-10-14)
- Tema: Divi 5 + tema hijo `buckler` (los snippets PHP viven ahí o en un plugin de snippets: **no son
  accesibles por MCP**; pedir el código al cliente).
- Páginas Divi: Inicio (44), Preguntas frecuentes (2503). Total páginas WP: 9.
- Theme Builder: header 1114 y footer 119 globales; cuerpos para Tienda (423), Línea Slim (782, pág. 776),
  Línea Curvy (785, pág. 778), ¡Sales! (1199, pág. 1201), producto (330), carrito (231), checkout (218),
  mi cuenta (template 193), búsqueda (1364), archivos de categoría 18/29/65 (2415), políticas (170).
  Los cuerpos de Woo **no son editables** por el plugin (`editable:false`) → se reemplazan con plantillas
  propias o shortcodes en el plugin.
- CPT: `politica-empresa` (4), `pregunta-frecuente` (20, tax `caregoria-pf`), `product-feed` (1).
- ACF: opciones *Banners Páginas* (repeater `banners_pagina_inicio` PC/MO + `linea_slim_pc/mo`,
  `linea_curvy_pc/mo`), *Guía de Tallas y Siluetas* (`imagen_guia_de_tallas`, `imagen_siluetas`),
  *Listado de Políticas* (wysiwyg por política), *Archivos Pedidos* (repeater en shop_order),
  `texto_politica`, `respuesta_pregunta_frecuente`.
- Atributos: `pa_tallas-regular` (6,8,10,12) y `pa_tallas-plus-size` (14–24).
- Catálogo: 133 productos variables publicados; **37 nombres duplicados** (mismo modelo en Slim y Curvy como
  productos separados → títulos/H1 duplicados para SEO); 34 agotados sin precio; 23 en oferta.
- Categorías duplicadas por línea (jeans / jeans-linea-slim / jeans-sale, etc.) + “NUEVO - SLIM/CURVY”,
  “LÍNEA NUEVA”, “¡SALE!”.
- Colores globales: primario `#1a3955` (navy), enlace `#e4ec6a` (lima), `#f0f0f0` (Bris), texto `#000`.
  Tipografía global: Manrope (títulos y cuerpo). Sin presets de módulo.
- Plugins detectados en el front: divi-essential (dnxte), divi-bodycommerce (Divi Engine), yaypricing-pro,
  woocommerce-side-cart-premium (Xootix), ajax-search-for-woocommerce (FiboSearch),
  themify-wc-product-filter, filtrado-productos-ufo, analytics-ufo, divi-assistant, cleantalk,
  jetpack, facebook-for-woocommerce, official-facebook-pixel, woo-save-abandoned-carts,
  pasarelas: bold-pagos-en-linea, rapyd, buy-now-pay-later-addi (+ Wompi).
- Tracking: GTM-NCDM5V29, gtag, fbq (pixel duplicado: facebook-for-woocommerce + official-facebook-pixel).
- Rendimiento (Playwright, sin caché de navegador): home 191 peticiones / ~153 scripts / 31 CSS;
  tienda 192; producto 192. HTML de 370–540 KB.
- SEO: home con 3 H1; producto con 2 H1 (“+ Productos”); `<title>` de tienda vacío (“Tienda -”);
  nombre del sitio con errata “Buckler **Jenas** by Denimblue”; texto del hero incrustado en la imagen;
  60–70 imágenes sin `alt` en home. No se detecta plugin SEO (Yoast/Rank Math).
- Video de producto: BodyCommerce inyecta en la galería
  `<div class="main-gallery-video"><video class="woocommerce-product-gallery-video">` con MP4 en
  uploads y miniatura `VIDEO-PRODUCTO-1.webp`. La meta key donde guarda la URL no es visible por MCP
  (empieza por `_`): el plugin propio incluye un detector en wp-admin antes de migrar.

## Auditoría (audit_site)
- Inicio 44: 3× `divi/code` (no permitido por el plugin) y CSS propio en una fila.
- Footer 119: CSS propio; enlace a `/politica-de-envio/` sin página.
- Header 1114: CSS freeForm en un texto.
- FAQ 2503: CSS en accordion items.
→ Antes de reguardar esas páginas con el plugin hay que quitar código/CSS (va al plugin propio).

## Estructura del repo
- `tools/mcp.py` cliente MCP · `tools/shot.js` capturas Playwright 1440/390 + métricas.
- `snapshots/` copias JSON del contenido en vivo (antes de tocar cualquier cosa) y capturas.
- `divi-layouts/divi.py` + `ui.py` + `paginas/<slug>.py` (`--validate | --create | --update ID [--dry]`).
- `buckler-core/` plugin propio → `dist/buckler-core-<versión>.zip`.
- `docs/propuesta/` propuesta de diseño (HTML).

## Reglas
- Páginas nuevas en **borrador**; `--dry` antes de guardar; un solo H1 por página.
- Nunca inventar cifras, testimonios ni datos legales → `[PENDIENTE: …]`.
- Verificar en el sitio real con Playwright a 1440 y 390 px.
- `set_front_page` y `publish_page` los confirma el cliente.

## Trampas Divi 5 + plugin
- Rechaza módulos de terceros (`dnxte/*`): convertir a nativos.
- Layouts sin CSS/JS: van en `buckler-core` o `set_custom_css`.
- El validador no comprueba claves de atributos: copiarlas de `get_page_layout` o recetas.
  Ancla: `module.advanced.htmlAttributes.desktop.value.id` (y `.class`).
- Divi 5 añade gap por defecto a columnas/grupos: anularlo y espaciar con márgenes.
- No fijar `family` en títulos (Divi añade `fantasy`): heredar la fuente global.
- Divi pone overflow en línea en body/#page-container → header sticky: `overflow-x: clip !important`.
- Radios/checkbox del formulario: repetir la clase para ganar especificidad.
- El plugin no sube medios: Mediateca o importador en `buckler-core`.

## buckler-core (plugin propio)
Versión actual 0.2.0 → `dist/buckler-core-0.2.0.zip` (`tools/build-plugin.sh`, valida versión + lint PHP/JS).
- `[bk_hero autoplay="6"]`: carrusel a ancho completo con el repeater ACF `banners_pagina_inicio`
  (opciones). Los subcampos PC/móvil/enlace se detectan desde la definición ACF (nombre o etiqueta).
  `<picture>`: PC 1920×1080 (16:9), móvil 1200×1200 (1:1), corte 767 px. Pausa al hover/foco y botón de pausa.
- `[bk_lineas]`: `linea_slim_pc/mo`, `linea_curvy_pc/mo` a ancho completo, apilados.
- `[bk_icon name="lucide:heart" label=""]`: Iconify local (`tools/icons.txt` → `python3 tools/icons.py`).
- Video de producto: metas `_bk_video_id` / `_bk_video_url`; respaldo con la meta del plugin anterior
  (opción `bk_legacy_video_key`, autodetectada al activar). Herramientas → Buckler: videos (detectar,
  usar como respaldo, migrar). Se inserta con `woocommerce_product_thumbnails`; no se pinta si
  BodyCommerce sigue activo (evita duplicado).
- `[bk_header]`: anuncios rotativos con pausa, logo, menú (Tienda con desplegable por línea generado
  desde `product_cat`, Lo nuevo, Línea Slim, Línea Curvy, ¡Sale!), buscador de productos, cuenta y
  carrito (contador por Store API solo si existe la cookie `woocommerce_items_in_cart` → cacheable).
  Móvil: menú lateral modal (foco atrapado, Esc). Enlace "Saltar al contenido" en `wp_body_open`.
  Sticky con `.et-l--header:has(.bk-header.is-sticky)`.
- `[bk_footer]`: beneficios, marca y redes, columnas por línea (dinámicas), ayuda, contacto y horario,
  medios de pago, políticas desde el CPT `politica-empresa`, botón flotante de WhatsApp (`[bk_whatsapp]`).
- Datos del negocio en `includes/config.php` (`bk_config()`, filtro `bk_site_config`).
- `divi-layouts/theme_hf.py`: reemplaza header 1114 / footer 119 por los shortcodes
  (dry_run por defecto; `--apply` guarda snapshot antes). Dry run validado el 2026-10-14.
  **Solo aplicar con buckler-core ≥ 0.2.0 activo.**
- Corrección: el footer anterior enlazaba `/politica-de-envio/` (404); la URL real es
  `/politica-empresa/politica-de-envio/`.
- `core.js`: anti rage click (450 ms por control, controles ocupados bloqueados, sin doble submit),
  anti dead click (tarjeta completa clicable, mensaje en línea en vez del `alert()` de variaciones),
  quick back (barra de navegación inmediata, limpieza en `pageshow`/bfcache).
- Pruebas locales: WP 7.1 + SQLite con stubs de Woo/ACF (wordpress.org está bloqueado en el entorno,
  no se puede instalar WooCommerce aquí). Falta probar en el sitio real con Woo + flexslider.

## Decisiones del cliente
- 2026-10-14: propuesta de diseño **aprobada**.
- Banners: se mantienen las imágenes ACF con texto dentro (rellenar el alt en la Mediateca).
- Header y footer: los diseña Claude (shortcodes en buckler-core).

## Pendiente del cliente
- Código de los snippets actuales (favorito→NUEVO, vaciar carrito a la hora, descontar stock en
  pendiente de pago, búsqueda de pedidos por cédula, checkbox “impreso”).
- Lista de plugins que se pueden desactivar tras cada fase.
