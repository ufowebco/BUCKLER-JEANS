# Buckler Jeans — contexto del proyecto

Rediseño de https://buckler.com.co (WordPress + Divi 5 + ACF Pro + WooCommerce 11.2.1, COP).
Marca: **BUCKLER JEANS by Denim Blue** (razón social DENIM BLUE JEANS S.A.S., Medellín). Moda femenina en
denim, dos líneas: **Slim** (tallas regulares 6–12) y **Curvy** (plus size 14–24). Idioma: español (Colombia).

Rama de trabajo: `claude/awesome-cori-fea1y8`. **No crear PR** salvo petición expresa.

**Modo de trabajo (decisión del cliente 2026-10-14):** el sitio está en producción. NO se instala nada
ni se modifica el sitio en vivo hasta el despliegue final. Solo lectura vía MCP (y `dry_run`). Todo se
construye y prueba en local; el despliegue se hace al final, en un solo paso planificado.

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
  60–70 imágenes sin `alt` en home. Plugin SEO: **SEOPress** (namespaces `seopress/v1` y `seopress/mcp`), no visible en el HTML.
- Video de producto: BodyCommerce inyecta en la galería
  `<div class="main-gallery-video"><video class="woocommerce-product-gallery-video">` con MP4 en
  uploads y miniatura `VIDEO-PRODUCTO-1.webp`. La meta key donde guarda la URL no es visible por MCP
  (empieza por `_`): el plugin propio incluye un detector en wp-admin antes de migrar.

- Otros plugins vistos en `/wp-json/` (namespaces): WooCommerce Print Invoice & Delivery Notes (`wcdn/v1`,
  posible origen del consecutivo de factura), Divi Machine / Divi Ajax Filter / Divi Loop Extender
  (Divi Engine), Jetpack Boost, Google for WooCommerce (`wc/gla`), WP Social Reviews, SEOPress,
  `ufoweb-udf`, `uad` (datos dinámicos ACF), `novamira`, `adsagent`, `pac-dcfh`, `ddch`, WP Rollback, WC POS.
- Pedidos: no accesibles por MCP ni REST pública. Consecutivo y check "impreso" pendientes de ver el
  snippet / meta keys. Regla: buckler-core debe usar **las mismas meta keys y el mismo contador** para
  no reiniciar la numeración ni perder el estado de pedidos existentes (HPOS: `$order->get_meta()`).

## Revisión del sitio público (2026-10-14, antes de instalar) → `docs/despliegue.md`
- `tools/review.js [salida]`: páginas clave a 1440/390, enlaces internos, flujo ficha → talla → carrito → vaciar
  (bloquea píxeles/GA para no ensuciar la pauta; no abre el checkout con productos). Línea base:
  `snapshots/review-2026-10-14/report.json`. `FLOW_ONLY=1 PRODUCT=<url>` solo el flujo.
- **Todas las categorías de producto redirigen 301 a `/tienda/`** (`X-Redirect-By: WordPress` → snippet/plugin,
  origen desconocido): bloqueante antes de activar header/footer/shop (enlazan categorías). Además, toda URL
  inexistente → 301 a la portada (soft 404).
- Caché: LiteSpeed + capa propia `X-Ufocache` (TTL 10 h). `/gestion-tienda/` envía DONOTCACHEPAGE + no-cache.
- Tallas en la ficha: botones `li.variable-item` de BodyCommerce (no accesibles por teclado), select oculto.
- "Ocultar agotados" activo (Store API: 99 de 133). `pantalones-sale` eliminada. `/politica-de-envio/` ya 301 OK.
- Header 1114 es el global de las 12 plantillas aunque se titule "Todos Los Políticas Empresa".

## Producción (fase 1, 2026-10-14): buckler-core instalado, módulos apagados salvo lo indicado
- Revisión posterior: 0 diferencias con la línea base (`snapshots/review-2026-10-14-post/`).
- Videos: **132 productos con MP4 en la galería**; ninguna clave de respaldo (no hace falta). El detector daba
  falsos positivos (`_conteo_visitas_woo`, `_sku`, `_product_image_gallery`: números que coinciden con IDs de
  video) → corregido en 0.8.2 (numéricos solo si la clave habla de video). **No usar "Usar como respaldo".**
- Claves reales de pedidos (detector de Ajustes, últimos 40): consecutivo `_wcdn_invoice_number` = "PEDIDO-3841"
  (+ `_wcdn_invoice_number_counter` 841, `_wcdn_invoice_date`, `_wcdn_receipt_pdf`), `_order_number` 3846,
  cédula `_billing_id`, impreso `_impreso_status`=yes, **despachado `_despachado_status`=yes** (otro snippet),
  adjuntos `archivos_adjuntos`. Otros: `_ufo_purchase_tracked`, `_meta_event_id`, `_meta_purchase_tracked_*`.
  Desde 0.8.2 son los **valores por defecto** de `bk_g_fields()`; "Despachado" tiene columna, filtro y check, y
  enviar la guía lo marca. La búsqueda acepta "3841", "#3841" y "PEDIDO-3841".
- Detalle del pedido (0.9.0): cédula y consecutivo siempre visibles ("—" si vacío), origen de la venta (atribución
  de Woo) y "Otros datos del pedido" (`bk_g_extra_meta`: metas no técnicas de snippets/checkout).
- Fase 2 (activar tienda): pasos en `docs/despliegue.md`. `theme_hf.py` dry run revalidado el 2026-10-14 (válido).

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
**Módulos (Ajustes → Buckler Core, `includes/modules.php`)**: todo APAGADO al instalar; `bk_on('clave')`;
cada módulo activo añade `bk-m-{clave}` al body (CSS/JS invasivo scoped a esa clase). Claves: layout,
clicks, banners, cards, shop, sidecart, gestion, video, sku_ref, abandoned, pages, cache, adminmenu, stats. `define('BK_MODULES_ALL', true)` enciende todo
(solo staging/local). Sin módulos de front no se encola nada. El detector de videos (Herramientas) funciona
siempre: sirve para leer la clave real en producción con todo apagado.
Versión actual 0.16.1 → `dist/buckler-core-0.16.1.zip` (`tools/build-plugin.sh`, valida versión + lint PHP/JS).
- `[bk_hero autoplay="6"]`: carrusel a ancho completo con el repeater ACF `banners_pagina_inicio`
  (opciones). Los subcampos PC/móvil/enlace se detectan desde la definición ACF (nombre o etiqueta).
  `<picture>`: PC 1920×1080 (16:9), móvil 1200×1200 (1:1), corte 767 px. Pausa al hover/foco y botón de pausa.
- `[bk_lineas]`: `linea_slim_pc/mo`, `linea_curvy_pc/mo` a ancho completo, apilados.
- `[bk_icon name="lucide:heart" label=""]`: Iconify local (`tools/icons.txt` → `python3 tools/icons.py`).
- **Hallazgo 2026-10-14 (MCP, 16 de 17 productos revisados):** BodyCommerce guarda el video como **adjunto MP4
  dentro de la galería** (`_product_image_gallery`). buckler-core lo lee de ahí (`bk_gallery_video_id`) y, con el
  módulo video activo y BodyCommerce apagado, lo quita de las fotos (`woocommerce_product_get_gallery_image_ids`,
  solo contexto view) y lo pinta como video. Las tarjetas filtran con `wp_attachment_is_image`.
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
- Tarjeta de producto (`includes/product-card.php`, módulo cards): reemplaza `content-product.php`
  vía `wc_get_template_part` (todas las grillas del loop Woo) + `[bk_productos categoria limite orden
  destacados oferta columnas agotados ids titulo ver_todo eager]`. Datos cacheados en `bk_card_{id}`
  (galería ≤5, tallas en stock, línea, % descuento) y limpiados en cambios de producto/stock. Fotos 2..n con
  `data-bk-src` (se cargan al pasar el cursor/tocar). NUEVO = producto destacado (snippet favorito→Nuevo).
  Línea de cuotas Addi (`addi_installments` en config).
- Carrito flotante (`includes/side-cart.php`, módulo sidecart): panel vacío en footer + Store API
  (cart, add-item, update-item, remove-item; nonce de la cabecera `Nonce`). Añadir en ficha sin recargar con
  respaldo al envío normal. Sugerencias `GET bk/v1/suggest` (talla + línea del último item; candidatos
  cacheados `bk_sc_*`). Icono del header (`data-bk-cart`) abre el panel. No se carga en carrito/checkout.
- Catálogo compartido (`includes/catalog.php`): `bk_product_sizes()` (tallas en stock cacheadas `bk_sizes_{id}`),
  `bk_catalog_flush()` en cambios de producto/stock (sube la opción `bk_catalog_v`).
  **No usar `wc_product_meta_lookup` para precio/oferta de variables** (min_price 0 y onsale 0 en variables).
- Tienda (`includes/shop.php`, módulo shop): `[bk_tienda categoria oferta titulo h1 columnas]`. Contexto:
  categoría del archivo, página de línea, ¡Sale! (`wc_get_product_ids_on_sale`), búsqueda. Universo cacheado
  (`bk_shop_*`, 30 min + versión). Filtros GET: linea (slim|curvy), prenda (agrupa categorías repetidas por
  línea), talla, precio (rangos, filtro `bk_shop_price_buckets`), oferta, orden, pg (24 por página, "Ver más").
  Agotados fuera. noindex,follow con filtros. **Desde 0.9.0 la tienda se pinta sola** (`bk_shop_template`,
  `template_include` 99999 → `templates/shop-page.php` con get_header/get_footer del tema): tienda, categorías,
  búsqueda, páginas de línea (`lines[*].page`) y `sale_page`. No hace falta tocar las plantillas TB (no editables);
  apagar el módulo devuelve el cuerpo TB. Nunca en el editor de Divi (`et_fb`). Filtro `bk_shop_takeover`.
  "Lo nuevo" = `/tienda/?nuevo=1` (categoría `new_cat`). Enlaces de prendas en header/footer según
  `category_links`: `filters` (por defecto, `/linea-x/?prenda=clave`, inmune al redirect de categorías) o `archive`.
  Caché de menús `bk_cats2_*` (formato con `key` de prenda, `bk_prenda_key()`).
- **Páginas limpias** (`includes/pages/pages.php`, `templates/pages/*`, `assets/css/pages.css`, `assets/js/pages.js`,
  módulo `pages`; decisión del cliente 2026-10-14: "reemplaza todas las páginas con tu código, sin residuos"):
  `template_include` 100000 → `templates/pages/document.php` (HTML propio: wp_head limpio de estilos `et-*`,
  `[bk_header]`, contenido, `[bk_footer]`, wp_footer). Rutas (`bk_pages_route()`, filtro `bk_pages_routes`): home,
  shop (tienda/categorías/líneas/¡Sale!/búsqueda), product, cart, checkout (+ gracias), account, faq
  (`faq_page`), policy (CPT politica-empresa, ACF `texto_politica` → opciones "Listado de Políticas"), 404.
  Requiere `layout` (sin él no toma ninguna ruta). Dieta (`bk_pages_diet`, regex filtrable `bk_pages_diet_regex`):
  quita CSS/JS de Divi, Divi Engine/BodyCommerce, Divi Essential, Themify, FiboSearch, Xootix y bloques de Gutenberg
  en esas páginas; conserva Woo, pasarelas, analítica. El tema Divi + hijo sigue activo (snippets intactos).
  Carrito/checkout/mi cuenta: **shortcodes clásicos** (en producción las páginas 14/15 tienen bloques, pero hoy
  Divi muestra el clásico; pasarelas y carritos abandonados usan el clásico). Ficha: plantilla nativa de Woo
  reordenada con hooks (`bk_pdp_hooks`): galería propia scroll-snap + video (galería MP4/meta), H1 "Nombre · Línea",
  REF, cuotas Addi, tallas: el `<select>` se convierte en radiogroup accesible (ordenado numérico, agotadas
  tachadas; oculta el marcado de BodyCommerce), guía de tallas ACF en `<dialog>`, enlace "ver este modelo en la otra
  línea" (`bk_pdp_twin`, mismo título), beneficios, relacionados con `[bk_productos]`, migas Inicio/Línea/Prenda.
  Inicio: `[bk_hero]` + H1 + `[bk_lineas]` + Lo nuevo con pestañas por línea (`new_child`) + ¡Sale! + beneficios.
  FAQ: CPT `pregunta-frecuente` por `caregoria-pf`, `<details>` + JSON-LD FAQPage. Fuente Manrope local
  (`assets/fonts`, OFL) con preload. `[bk_hero]`/`[bk_lineas]` funcionan con `pages` aunque `banners` esté apagado.
  Suite `paginas.js` (37): incluye compra real hasta "¡Gracias por tu compra!".
  Revisión en vivo 2026-10-14 (0.10.1): en producción un plugin envuelve galería+resumen en `div.clearfix` → la
  rejilla se aplica con `:has(> .bk-pg)`; colores de Woo (oliva/morado) anulados con `body.bk-doc`; sin
  `woocommerce_share`; estilos base con `:where()` (antes pisaban el footer); filtros sin scroll interno;
  estilos tardíos `bc-`/`daf-` limpiados también en wp_footer. **La Store API en producción tarda ~2,6–3,4 s por
  petición**: el carrito flotante abre al instante ("Añadiendo…"), reutiliza el nonce de la lectura del contador
  (`window.bkStoreNonce`) o lo pide al tocar el formulario, y no repite la lectura tras añadir (`bkCartSynced`).
  Peticiones por página: ~170 → ~100.
- **0.11.0 (ronda de 9 puntos del cliente, 2026-10-14):** líneas Slim/Curvy en una sola fila (también móvil);
  header con `z-index: 9990` (el desplegable "Tienda" quedaba debajo del contenido); barra "Ordenar / Prendas" de la
  tienda ya no es sticky; botón flotante = **carrito** (`bk_float_cart_button()`, `bk_config('float_button')`
  'cart'|'whatsapp'; no se pinta en carrito/checkout). Migas en todas las rutas: `bk_crumbs($items)` (nav +
  JSON-LD BreadcrumbList), `bk_route_crumbs()`, `bk_shop_crumbs()` (línea + `?prenda`, ¡Sale!, Lo nuevo, búsqueda,
  categorías), filtro `bk_crumbs`. Carrito (`bk_cart_hooks`): rejilla productos | resumen fijo, filas como tarjetas,
  − / + con actualización automática (clic al botón `update_cart` → AJAX de Woo; foco restaurado tras
  `updated_wc_div`), confianza + medios de pago bajo el total, "Seguir comprando", "Combínalo con" (ventas cruzadas o
  lo nuevo de la línea, sin transitorios por carrito) en lugar de cross-sells; carrito vacío con accesos a líneas.
  Buscador en vivo (`includes/search.php`, con `layout`): `GET bk/v1/search-index?v=<md5 bk_catalog_v>` (público,
  `Cache-Control` 1 h, transitorio `bk_search_index` regenerado al cambiar la versión), combobox ARIA en
  `layout.js` (sin tildes, flechas/Enter/Esc, "Ver todos los resultados (N)").
- **0.11.1 (revisión en vivo de la 0.11.0, todo OK):** un snippet del tema añade `<span class="minus|plus">` a cada
  `.quantity` (jQuery, no accesible) → en el carrito se ocultan y quedan los `.bk-qty-btn` (simulado en los fixtures).
  Índice de búsqueda en producción: 99 productos, 40 KB, LiteSpeed lo sirve en caché (0,17 s). Miniaturas sin lazy.
- **0.12.0 (ronda de 10 puntos, 2026-10-15):**
  - Barra de anuncios **verde lima** (`bk-announce`): carrusel de 3 (≥1280 px) / 2 (≥700) / 1 anuncio, alineado a la
    izquierda, avanza de a uno cada 4 s (pausa con cursor/foco, quieto con reduced-motion), clones aria-hidden para el
    bucle; sellos `bk_config('badges')` a la derecha en escritorio y dentro de la rotación en móvil. Sin botón de pausa
    (decisión del cliente; también se quitó del hero). Textos acortados para que quepan en 1/3.
  - Ficha: sin pestañas ni comentarios (`bk_pdp_description`, `comments_open` falso en productos; la pestaña "Guía de
    Tallas" venía de otro plugin), reseñas de Google debajo, fotos 2..n con `data-bk-src` (pages.js las carga al
    acercarse; el lazy nativo bajaba todas en el carrusel horizontal) y póster del video diferido. Video: autoplay
    silenciado en bucle al seleccionarlo, sin controles, clic/Enter/Espacio pausa, icono de play si está pausado.
  - Botón desactivado: anulado el morado de Woo (`:is(.disabled,:disabled,[disabled]):hover`).
  - Reseñas (`includes/reviews.php`, siempre cargado): lee `{prefix}wpsr_reviews` de WP Social Ninja (google,
    aprobadas, ≥4★, transitorio `bk_greviews_N` 6 h) + promedio/total de la opción `wpsr_reviews_google_business_info`
    si existe; respaldo: shortcode `[wp_social_ninja id="3143" platform="reviews"]` (`reviews_template`). **Esquema
    supuesto, verificado en producción con 0.12.0: 78 reseñas, 5,0.** Shortcode `[bk_resenas]`. En ficha y carrito.
    0.12.1: `bk_review_original_text()` quita la traducción automática "(Translated by Google) …" / usa "(Original)".
- **0.13.0 (velocidad, PageSpeed del cliente: 49 escritorio; LCP 3,3 s, SI 8,5 s, TBT 540 ms):** hallazgos en el HTML
  real: snippet del tema que ponía `documentElement.style.opacity = '0'` hasta `load` (o 1,5 s), 8 snippets jQuery del
  diseño Divi, stub "jQuery diferido" de Divi, GTM + gtag G-BR4MXPY7HS, AdsAgent (`frontendInjection.js`, Azure),
  Rapyd/Bold externos y select2 en todas las páginas, fotos de tarjetas **sin srcset** (algo lo quita en producción;
  bajaban 600×900 para 300 px). `includes/pages/speed.php`: `bk_pages_optimize_html()` sobre el documento completo
  (document.php: optimizar → `bk_cache_headers()` → echo): quita snippets por firma (`bk_legacy_snippets`), stub de
  Divi, pasarelas externas fuera de checkout, select2 fuera de formularios; retrasa analítica/píxeles/AdsAgent hasta
  la primera interacción (`type="text/bk-delay"`, cargador `#bk-delay` en orden; `bk_delay_patterns`;
  `bk_config('delay_trackers') === false` lo apaga; nunca en checkout); quita jQuery en home/shop/faq/policy/404 si
  ningún script restante lo usa (`bk_pages_maybe_drop_jquery`, lista `bk_vanilla_scripts`). Hero y barra de anuncios
  no rotan hasta la primera interacción. `bk_ratio_srcset()`/`bk_img_add_srcset()` (helpers) en tarjetas y galería.
  Líneas con sizes 50vw. shop.js solo en la ruta shop. Sin emojis de WP. `woocommerce`/js-cookie/blockUI solo en
  ficha/carrito/checkout/cuenta. Fixtures locales simulan los snippets + GTM. Lighthouse desde este entorno no llega a
  Google (proxy): medir GTM real solo con PageSpeed del cliente. Novamira no carga nada en el front.
  Resultado PageSpeed del cliente con 0.13.0: **99 escritorio**; Lighthouse local móvil 94 (inicio) / 90 (ficha) sin GTM.
- **0.13.1:** botón flotante blanco con sombra (ícono navy, contador navy/lima, hover lima; el navy se perdía sobre el
  footer). Carrito flotante instantáneo: copia `bk_cart_snap` en sessionStorage (solo items/totales/cantidad) que
  alimentan el contador del header (`window.bkCartSnapshot`) y cada render; al abrir pinta la copia y refresca por
  detrás; sin cookie de carrito abre vacío sin pedir nada; sugerencias reutilizadas (`bk_sug`) mientras no cambie el
  carrito. Footer pegado al fondo (body flex + `html:has(body.bk-doc)` navy: sin franja blanca en páginas cortas ni al
  estirar en el celular). Carrito: "Combínalo con" y reseñas fuera del bloque `.woocommerce` (el resumen sticky
  bajaba sobre ellos).
- **0.13.2:** tarjeta lima "Califícanos en Google · Gana 10 %" bajo ¡Sale! en el menú Tienda y en el menú móvil
  (`bk_review_menu_card()`, se oculta si la promo está apagada). Caché verificada en producción: `X-Litespeed-Cache: hit`, 0,13 s.
- **0.14.0 (PageSpeed móvil 92, escritorio 99; navegación agéntica 3/4; CrUX de 28 días aún con TTFB 2,7 s de antes
  de la caché):**
  - Franja bajo el footer = HTML de PhotoSwipe (`div.pswp`) sin su CSS → `remove_action('wp_footer','woocommerce_photoswipe')`.
  - Orden fijo de prendas en menú, footer, filtros y llms.txt: `prenda_order` (jeans, pantalones, prendas-cortas,
    faldas, set-y-overol, chaquetas-y-chalecos) y `prenda_last` (linea-basic); `bk_prenda_rank()`, `bk_order_prendas()`.
    Caché de menús renombrada `bk_cats3_*`.
  - Agéntica: `ard-schema` fallaba porque `/.well-known/ai-catalog.json` redirigía a la portada (soft 404 del sitio,
    `X-Redirect-By: WordPress`). `bk_pages_no_soft404()` (filtro `wp_redirect` 999): si es 404 y el destino es la
    portada → 404 real con la plantilla propia. `/llms.txt` generado (`bk_llms_txt()`, filtro `bk_llms_txt`).
  - Velocidad móvil: CSS de buckler-core en un solo archivo por combinación (`bk_pages_bundle_css()`,
    `uploads/bk-cache/bk-{hash}.css`, url(../fonts) → absolutas; filtro `bk_bundle_css`); banners 2..n diferidos
    (`bk_defer_media()`, core.js los carga al armar/rotar).
  - Caché: venta que no agota talla → solo `X-LiteSpeed-Purge: tag=bk_p{padre}` (flag por el filtro
    `woocommerce_update_product_stock_query`); agotar/reponer talla, ediciones, altas/bajas → `*`. Fichas etiquetadas
    `bk_p{id}`. Tras `*` se programa `bk_cache_warm` (+45 s): inicio, tienda, Lo nuevo, líneas, ¡Sale!, FAQ y 16
    productos recientes (≤50 s, UA móvil).
  - Local: `tools/local/router.php` para `php -S` (imita el .htaccess: /llms.txt y 404 pasan por WP; estáticos que
    faltan → 404). Arranque: `PHP_CLI_SERVER_WORKERS=6 php -S localhost:8899 -t site /home/user/BUCKLER-JEANS/tools/local/router.php`.
    Fixtures simulan el soft 404.
- **0.14.1:** fotos de ficha "ampliadas" (p. ej. /producto/chaqueta-dama/): **Divi mete en el srcset sus recortes
  16:9** (`et-pb-image--responsive--*`, 980×551 / 480×270) y el navegador los elegía para fotos 2:3. `bk_img_add_srcset()`
  ahora SIEMPRE reemplaza el srcset: proporción tomada del width/height del `<img>`, solo tamaños de esa proporción
  (metadatos + los del srcset original con -AnchoxAlto que coincidan + full). Fixture local simula el srcset de Divi.
- **0.14.2:** la franja seguía: en producción **otro plugin imprime el HTML de PhotoSwipe** (`div.pswp`,
  `#photoswipe-fullscreen-dialog`) en todas las páginas con productos (Woo solo lo hace en fichas y lo engancha en
  `wp_enqueue_scripts`, después de template_include). Ahora: `remove_action` en la dieta por ruta +
  `bk_strip_element()` lo quita del HTML final + CSS `body.bk-doc .pswp{display:none}` de respaldo. Fixtures: tema
  con lightbox y un "plugin" que lo imprime en wp_footer (reproduce las 24 coincidencias de producción).
- **0.14.3:** el soft 404 de producción NO usa wp_redirect (sin X-Redirect-By, `header('Location: '.home_url())`
  directo, después de la cabecera Link de la REST → template_redirect ≥ 11), así que el filtro no bastaba.
  `bk_pages_render_404()` en `template_redirect` prioridad 10 (después de wp_old_slug_redirect/redirect_canonical,
  antes de ese código) pinta la 404 propia con estado 404 y termina. Fixture local imita el header() directo.
- **0.15.0 — Administrador del menú (módulo `adminmenu`, solo admin; doc completa en `docs/admin-menu.md`):**
  `includes/admin-menu/{admin-menu.php,tool.php}`, `assets/css/admin-menu{,-tool}.css`, `assets/js/admin-menu-tool.js`.
  Colores reales leídos de Divi por MCP (`list_global_styles`): navy #1a3955 / lima #e4ec6a. Menú 200 px solo ≥ 961 px
  expandido; logo (Mediateca, enlace configurable — el pedido decía "Museo del Aguardiente", se usó el sitio de
  Buckler); Herramientas → Administrador del menú (orden, ocultos, submenús, separadores, encabezados, global/por
  usuario, vista previa). Original = `$menu` ordenado por posición + `menu_order` de otros plugins (Woo). Encabezados:
  entrada `#bk-head-*` sin `wp-menu-separator` (WP borra separadores adyacentes) convertida a texto por JS.
  Restricción IA/buckler-core por ID (usuarios `sebas_ufoweb` = ID 1 visto en la REST pública; `sebas_ufoweb.co` no
  aparece en la REST pública porque no publica: se verifica en el servidor) → 403 en URL y acciones; páginas por
  carpeta de plugin (reflexión + realpath, sirve con enlaces simbólicos). `BK_AM_SAFE_MODE` para recuperar acceso.
  Hallazgo de seguridad: `/wp-json/wp/v2/users` era público (exponía nombres de usuario).
- **0.15.1 — `includes/security.php` (siempre activo; desactivar con `add_filter('bk_hide_users','__return_false')`):**
  sin sesión → REST `/wp/v2/users*` 401 (con sesión igual: editor de bloques, MCP con usuario), `/?author=N` y archivos
  de autor 404, sin sitemap de usuarios, oEmbed sin autor, error de acceso genérico. Suite `seguridad.js` (7).
  Suite `adminmenu.js` (34) + `seed-adminmenu.php` (usuarios y plugin de IA simulado en local).
  - Promo "Califícanos en Google y gana 10 %" (`review_promo` on/incentive/discount, `review_url` =
    g.page/r/CRGTFeM2O9V5EAE/review): bloque de reseñas, gracias, Mi cuenta y anuncio de la barra. **Aviso dado al
    cliente: Google prohíbe reseñas incentivadas**; `incentive => false` deja solo la invitación.
  - Mi cuenta: acceso en 2 columnas (formulario + "En tu cuenta puedes", ayuda por WhatsApp); con sesión, plantillas
    propias `templates/woocommerce/myaccount/{navigation,dashboard}.php` vía `wc_get_template` (solo ruta account):
    usuaria + menú con iconos (sin Descargas, nombres claros; chips horizontales en móvil), último pedido con estado,
    guía `_bk_tracking` y miniaturas, accesos rápidos, promo. Rejilla con columnas explícitas.
  - Velocidad: `bk_pages_diet_route()` (filtro `bk_pages_diet_rules`): pasarelas/Addi solo en checkout, YayPricing
    solo ficha/carrito/checkout, selectWoo y CSS general de Woo solo en carrito/checkout/cuenta, fuera photoswipe/
    flexslider/zoom/wc-single-product/comment-reply/cart-fragments/zxcvbn (800 KB en Mi cuenta)/Google Fonts/
    Divi/tema hijo, dashicons sin barra de admin.
  - **Caché (módulo `cache`, hallazgo 2026-10-15: en producción ya no había caché de HTML — sin LiteSpeed Cache ni
    Ufocache — y Facebook ×2 + otra cookie `database_validation` ponían Set-Cookie en cada página; TTFB ~2 s):**
    `includes/page-cache.php`. document.php va entero en búfer; `bk_cache_headers()` al final: rutas home/shop/
    product/faq/policy, GET, sin sesión/carrito/avisos → quita Set-Cookie, `X-LiteSpeed-Cache-Control: public,
    max-age=36000`, `X-LiteSpeed-Tag: bk,bk_{ruta}`, `X-Bk-Cache: store`; resto `no-cache`. Purga `X-LiteSpeed-Purge: *`
    en `bk_catalog_flushed` (nuevo action de bk_catalog_flush), save_post, ACF, módulos, menús, ajustes Woo; si no hay
    respuesta HTTP (cron) queda `bk_cache_purge_pending` para la siguiente. Botón "Vaciar caché de páginas" en
    Ajustes → Buckler Core. WordPress corta las peticiones HEAD antes de la plantilla (probar con GET).
- **0.16.0 — ronda gestion-tienda (10 puntos, 2026-10-15):**
  - Velocidad: `includes/fast-rest.php` instala el mu-plugin `bk-fast-rest.php` (con módulos gestion o stats; se quita al
    apagarlos/desactivar; `define('BK_FAST_REST', false)` lo anula): en GET `bk/v1/gestion/(summary|orders|products|
    product-form|abandoned|seo/visits)` y POST `bk/v1/hit` solo carga WooCommerce, buckler-core, ACF y plugins de
    número/estado de pedido (filtro `bk_fast_rest_keep`). Las escrituras cargan todo. Cabecera `X-Bk-Fast: 1` para
    verificar. Resumen con 1 consulta en HPOS (`bk_g_sum_orders`) y conteos con paginate; resumen dentro de la página
    (`bkG.boot.summary`); JS con caché en memoria (stale-while-revalidate), detalle precargado al pasar el cursor;
    `gestion-product.js` y `gestion-extra.js` se cargan bajo demanda (`bkG.js`).
  - Pedidos: sin columna Consecutivo (se busca igual y sale en detalle/impresión); checks por `data-flag`
    (paid_ok|printed|dispatched); **Pago confirmado** = campo `pago` de `bk_g_fields()` (`_bk_pago_confirmado`, 'yes'/'no')
    con filtro; botón Imprimir al final. Impresión: `/gestion-tienda/?imprimir=ID[,ID]` (`bk_g_print_render`, A4/carta,
    `&auto=1` imprime solo); en escritorio iframe oculto, en celular pestaña; imprimir marca Impreso.
  - Historial (`includes/gestion/orders-extra.php`): meta `_bk_history` (≤80 eventos {t, ev, v, by, via}) desde
    `woocommerce_order_status_changed` (cualquier origen) + checks, guía (`guia`) y archivos; pedidos viejos: estados
    leídos de las notas ("Order status changed from…"/"Estado del pedido cambiado de…"). `bk_g_history()`.
  - Archivos ACF `archivos_adjuntos`: `bk_g_files()` lee todas las filas/subcampos (ID, URL o texto; respaldo postmeta),
    siempre visible; subir (PDF/JPG/PNG/WebP ≤15 MB, `POST orders/{id}/files`) y quitar (`/files/delete`, reordena) con
    formato ACF (`{n}`, `{n}_{i}_{sub}` y claves `_…` si ACF está activo; subcampo de tipo file/image de la definición).
  - Productos: sección "Todas las categorías" (árbol `allCats` de `product-form`, `all_cats` en la ficha) sincronizada
    con Prenda y línea; si llega `all_cats` manda eso (+ línea + prendas); Nuevo/Sale siguen ajustándose solo si cambian.
    Descripción: `bk_gp_desc_for_editor()` aplica wpautop si es texto plano (los saltos no se veían en el editor; el
    front ya los mostraba).
  - Ajustes fuera del menú (sigue en `#ajustes` para admin, enlace al pie); su tarjeta de correo pasó a Correos.
  - **Carritos** (`GET gestion/abandoned`), **Correos** (`includes/gestion/emails.php`: correos de pedido de Woo con
    WC_Email clonado sin trigger + guía + 3 de carrito; vista previa con pedido elegido y prueba "[Prueba]", 20/h),
    **SEO y ADS** (`includes/gestion/seo-ads.php`): visitas (módulo stats), etiquetas (lee el HTML público de inicio,
    tienda y un producto + `gtm.js` del contenedor; detecta GTM, GA4, Ads, píxel de Meta y duplicados, eventos fbq/gtag/
    dataLayer, compras con `_meta_purchase_tracked*`/atribución de 30 días, SEO técnico, robots/sitemap/llms/feeds;
    transitorio `bk_gs_tags` 6 h) y clases/eventos rastreables.
  - Módulo **stats** (`includes/stats.php`): script inline `#bk-stats` en wp_footer → `sendBeacon` a `POST bk/v1/hit`
    (sin cookies; omite `navigator.webdriver`, robots por UA y usuarios del equipo vía cookie logged_in). Tablas
    `{prefix}bk_stats` (day, kind total|page|src|device|city|camp|landing, k, views, visitors) y `{prefix}bk_stats_seen`
    (day, h = sha256(IP|UA|sal del mes)[0..16]); sal `bk_stats_salt_Ym` (la anterior se borra). Limpieza diaria (AS).
    Ciudades: base DB-IP Lite en `uploads/bk-geo/city.mmdb` (botón de admin, descarga en segundo plano, renovación
    mensual; MaxMind\Db\Reader de Woo). Sin base → solo ciudades de compra (pedidos). Desde este entorno db-ip.com da 403.
  - dataLayer (`window.bkTrack` en layout.js; sidecart.js): bk_add_to_cart (ecommerce GA4), bk_begin_checkout,
    bk_select_item, bk_search, bk_whatsapp_click, bk_size_select, bk_size_guide_open, bk_other_line_click,
    bk_review_click, bk_banner_click.
  - Suite `gestion16.js` (61). Servidor local con `PHP_CLI_SERVER_WORKERS=6` (la revisión de etiquetas se pide a sí misma).
- **0.16.1 — SEO propio en las páginas limpias (`includes/pages/seo.php`, `bk_seo_apply()` en document.php antes de
  optimizar):** hallazgos en producción (tabla SEO técnico): ficha SIN JSON-LD Product (SEOPress lo quita; en local Woo
  sí lo genera), tienda "Tienda -" sin descripción, fichas "REF: 9912 - CHAQUETA DAMA - Buckler Jenas by Denimblue",
  inicio sin og:image. Ahora: títulos "Nombre · Línea [· REF] | Buckler Jeans" (≤70) y descripciones ≤155 que terminan
  limpias (`bk_seo_desc`: frase + "Envíos gratis… Addi" si cabe) para ficha, tienda, líneas (+ ?prenda), ¡Sale!, Lo
  nuevo, categorías y búsqueda; og:title/og:description iguales; og:image del inicio = primer banner (full) o logo;
  "Buckler Jenas" → "Buckler Jeans" en el head; JSON-LD Product propio solo si no hay otro (Offer u AggregateOffer COP,
  disponibilidad, REF, marca, fotos, envío gratis CO; sin calificaciones: las reseñas de Google son de la tienda).
  Respeta `_seopress_titles_title/_desc` escritos a mano (la descripción se recorta). Apagar: filtro `bk_seo_enabled`.
  Fixtures simulan SEOPress (opción `bk_test_keep_wc_ld` para probar sin duplicar). Suite `seo.js` (17).
  **Pendiente del cliente:** corregir "Jenas" en Ajustes → Generales → Título del sitio.
- Gestión de tienda (`includes/gestion/`, módulo gestion) en **/gestion-tienda/** (decisión del cliente):
  página independiente (no carga tema/Divi), login requerido, capacidad `bk_gestion` (rol `bk_gestor`
  "Gestor de tienda", también admin y shop_manager; se crea al guardar módulos). La gestora no entra a wp-admin.
  REST `bk/v1/gestion/*` (summary, orders, orders/{id}, products, products/{id}, variations/{id}, settings).
  Campos de pedido configurables en la opción `bk_gestion_fields` (consecutivo, impreso `_bk_impreso` por
  defecto, cédula [lista de claves], adjuntos `archivos_adjuntos`) + detector de claves de los últimos 40
  pedidos (pestaña Ajustes, solo admin). **Nunca meta_query OR con varias claves en HPOS** (JOIN por
  cláusula → explosión combinatoria, colgó la BD con 26 pedidos): usar `bk_g_meta_ids()` (SQL directo
  indexado) + `post__in`/`post__not_in`. Respuestas viejas descartadas por número de secuencia en JS.
- IDs del footer con prefijo `bk-ft-` (antes chocaban con los filtros `bk-f-`).
- SKU → REF (`includes/woo-tweaks.php`): `gettext_woocommerce` + `ngettext_woocommerce`, regex `\bSKU(s?)\b`.
- Carritos abandonados (`includes/abandoned-cart/`, reemplaza woo-save-abandoned-carts):
  tabla `{prefix}bk_abandoned_carts`; captura en checkout vía `?wc-ajax=bk_ac_capture` (casilla de
  autorización Ley 1581 por defecto, o modo aviso); 3 correos con Action Scheduler (grupo `buckler-ac`,
  hook `bk_ac_send` args [id, paso]) a 2/24/48 h; enlace firmado HMAC restaura el carrito en cualquier
  dispositivo y lleva al checkout con UTM; compra → `converted`/`recovered` (+ nota y meta `_bk_ac_recovered`
  en el pedido); pago fallido/cancelado → reactiva los correos pendientes; cupón opcional por paso
  (único, 1 uso, restringido al correo, excluye rebajas); baja con List-Unsubscribe; limpieza diaria
  (retención 60 días); exportar/borrar datos personales. Admin: WooCommerce → Carritos abandonados.
  Plantilla: `templates/emails/abandoned-cart.php` (logo PNG porque Outlook no muestra WebP).
  Ajustes en la opción `bk_ac_settings`. Suite e2e: 21 comprobaciones OK.
- Guía de envío (`includes/gestion/shipping.php`, módulo gestion): en el detalle del pedido, transportadora +
  número de guía → vista previa del correo (iframe) → "Confirmar y enviar". Guarda la meta `_bk_tracking`
  {carrier, number, date, sent_by}, deja nota, opcionalmente pasa a Completado **sin** el correo estándar de
  Woo. Rutas `POST bk/v1/gestion/orders/{id}/shipment` (confirm=false → vista previa) y
  `POST bk/v1/gestion/shipment-sample` (solo admin: vista previa / correo de prueba "[Prueba]").
  Plantilla `templates/emails/order-shipped.php`. Transportadoras en `bk_config('carriers')`; `track` vacío
  (enlace al sitio de la transportadora) hasta tener la URL de rastreo con `{guia}`. La clienta ve la guía en
  Mi cuenta → pedido (`woocommerce_order_details_after_order_table`).
- Editor de productos (`includes/gestion/product-editor.php` + `assets/js/gestion-product.js`, módulo gestion):
  `#productos/nuevo`, `#productos/editar/ID`, `#productos/nuevo?desde=ID` (mismo modelo en la otra línea: REF
  816 ↔ 816 PLUS, prenda equivalente por nombre normalizado, sin fotos ni stock). Producto variable con el
  atributo de la línea (`lines[*].attribute`), una variación por talla (stock gestionado); talla desactivada →
  variación `private` (no se borra). Categorías: línea + prenda; Nuevo = destacado + `linea-nueva` + hija
  (`lines[*].new_child`); rebaja → `sale` + hija equivalente. **Solo se tocan si cambian** (crear, interruptor
  Nuevo, precio): guardar sin cambios no altera nada. La estrella de la lista usa `bk_gp_set_new`.
  Subida propia `POST bk/v1/gestion/media` (la gestora no tiene `upload_files`): jpg/png/webp/mp4 validados por
  contenido; tamaños en WebP (`image_editor_output_format` solo en esa subida); el navegador reduce fotos a
  2400 px. Alt automático con el nombre. El video se guarda en `_bk_video_id` **y** al final de la galería
  (compatible con BodyCommerce mientras siga activo). Descripción: editor básico (negrita/lista), HTML limpio.
- Pruebas locales: `tools/local/setup.sh` → MariaDB + WordPress + **WooCommerce 11.2.1 real** (git del repo
  oficial, sin wordpress.org) + HPOS + checkout clásico + productos/tallas reales. Los correos se guardan
  en `uploads/bk-mails/`. El admin React de Woo no se compila (avisos rojos solo en local).
  Tema local `tools/local/theme-bk-classic` (clásico, imita Divi) para que Woo use plantillas clásicas.
  Suites Playwright en `tools/local/tests/` (shop 25, cards 23, sidecart 16, carritos abandonados 21, gestión 36, envío 27, productos 53, páginas de tienda 13, páginas limpias 100, administrador del menú 34, seguridad 7, gestión 0.16 61, SEO 17 = 433 OK).

## Decisiones del cliente
- 2026-10-14: propuesta de diseño **aprobada**.
- Banners: se mantienen las imágenes ACF con texto dentro (rellenar el alt en la Mediateca).
- Header y footer: los diseña Claude (shortcodes en buckler-core).
- 2026-10-14: snippet SKU→REF entregado; se pidió reemplazar WCDN, SEO propio (quitar SEOPress),
  carritos abandonados (2 h / 24 h / 48 h, cupón por confirmar) y dashboard de pedidos/productos.
- ACF "Archivos Pedidos" (`archivos_adjuntos`, repeater en pedidos) debe mantenerse en el dashboard.
- Dashboard en `/gestion-tienda/`.

## Pendiente del cliente
- Código de los snippets actuales (favorito→NUEVO, vaciar carrito a la hora, descontar stock en
  pendiente de pago, búsqueda de pedidos por cédula, checkbox “impreso”).
- Lista de plugins que se pueden desactivar tras cada fase.
