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
clicks, banners, cards, shop, sidecart, gestion, video, sku_ref, abandoned. `define('BK_MODULES_ALL', true)` enciende todo
(solo staging/local). Sin módulos de front no se encola nada. El detector de videos (Herramientas) funciona
siempre: sirve para leer la clave real en producción con todo apagado.
Versión actual 0.9.1 → `dist/buckler-core-0.9.1.zip` (`tools/build-plugin.sh`, valida versión + lint PHP/JS).
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
  Suites Playwright en `tools/local/tests/` (shop 25, cards 23, sidecart 16, carritos abandonados 21, gestión 35, envío 28, productos 53, páginas de tienda 13 = 214 OK).

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
