# Pruebas de navegador (Playwright) — entorno local

Requisitos: `tools/local/setup.sh` ejecutado y `php -S localhost:8899 -t $BK_LOCAL/site` corriendo.
Rutas asumidas: `BK_LOCAL=/tmp/claude-0/bk-local` (ajustar en los scripts si cambia).

```bash
php tools/local/tests/seed-cards.php     # galerías, destacado, oferta, talla agotada
php tools/local/tests/seed-shop.php      # ~60 productos con nombres/precios/categorías reales
php tools/local/tests/seed-orders.php    # pedidos de ejemplo, usuaria "gestora" (rol Gestor de tienda)
cd tools/local/tests && for t in shop cards sc ac gestion envio productos tienda-paginas paginas adminmenu seguridad gestion16 seo ronda17 ronda171 ronda172 pedidos-nuevos ronda176 ronda177 ronda178 ronda179 ronda180 ronda181 ronda182 ronda184 ronda185 ronda186 ronda187; do NODE_PATH=$(npm root -g) node $t.js; done
```

| Suite | Qué prueba | Comprobaciones |
|---|---|---|
| `shop.js` | Filtros, conteos, orden, "Ver más", historial, noindex, sin JS, móvil | 25 |
| `cards.js` | Slider, fotos diferidas, tallas, Nuevo, descuento, dead click | 23 |
| `sc.js` | Carrito flotante, añadir sin recargar, sugerencias por talla, rage click | 16 |
| `ac.js` | Carritos abandonados de punta a punta (captura → correo → recuperar → compra → baja) | 21 |
| `gestion.js` | Dashboard /gestion-tienda/: acceso, roles, pedidos (cédula, estado, impreso, consecutivo, adjuntos, notas), productos (tallas, oferta, Nuevo), ajustes | 26 |
| `envio.js` | Guía de envío: validación, vista previa sin envío, confirmar, correo único (sin el de Woo), nota, Completado, lista, Ajustes (vista previa y prueba), permisos, móvil | 28 |
| `productos.js` | Editor de productos: crear (validaciones, línea, tallas, prendas, precio/rebaja), subir/ordenar/quitar fotos (foto de 11 MB reducida en el navegador), video, descripción, categorías Nuevo/Sale, ficha pública sin MP4 en las fotos, editar, estrella, REF duplicada, cambios sin guardar, versión en la otra línea, producto real intacto, móvil. Usa `seed-editor.php` + `inspect-product.php` | 53 |
| `tienda-paginas.js` | Tienda pintada por buckler-core en tienda, líneas, ¡Sale!, Lo nuevo y búsqueda (un H1); enlaces de prendas por filtro en header/footer | 13 |
| `paginas.js` | Páginas limpias (módulo `pages`, antes `php seed-pages.php`): inicio (líneas en una fila), menú Tienda visible, carrito flotante, buscador en vivo, migas + BreadcrumbList, ficha (tallas accesibles, gemelo), carrito (− / + con actualización automática, sugerencias), compra real hasta "Gracias" (promo), barra verde (3/1 anuncios, sellos), reseñas de Google, video automático, fotos diferidas, caché y dieta, mi cuenta (acceso y panel), FAQ + JSON-LD, política, móvil | 100 |
| `adminmenu.js` | Administrador del menú (antes `php seed-adminmenu.php`): diseño 200 px y colores, foco, contraído, celular, reordenar (teclado y arrastrar), ocultar, encabezado/separador, submenú, por usuario y restablecer, restricción de IA/buckler-core (403 en URL y acciones), logo (pestaña nueva, sin deformar), listado de usuarios sin ID | 34 |
| `gestion16.js` | Gestión 0.16: API rápida (X-Bk-Fast), carga diferida, menú (sin Ajustes), pedidos sin Consecutivo, Pago confirmado (lista, filtro, detalle), impresión (hoja, varios, sin sesión, marco oculto, marca Impreso), historial (panel, notas antiguas, cambios externos), archivos ACF (subir PDF, quitar, formato ACF), todas las categorías, saltos de línea, Carritos, Correos (vista previa Woo/propios, prueba), contador de visitas (robots/equipo excluidos, origen, campaña, sin cookies), etiquetas y SEO técnico, selectores y dataLayer, móvil | 61 |
| `seo.js` | SEO propio: títulos y descripciones de tienda, líneas, prenda, ¡Sale!, Lo nuevo, búsqueda y ficha (≤70/≤155, og iguales), JSON-LD Product sin duplicar, errata del sitio, og:image del inicio, títulos manuales de SEOPress respetados | 17 |
| `ronda17.js` | Rueda del mouse en tarjetas, buscador, San Andrés, 404 → inicio, acceso propio de la gestión y salir, Gestor_ADS solo SEO, alerta de etiquetas, Ayuda, Contenido (banners/guía/políticas/preguntas en formato ACF), Descuentos (campaña, por cantidad, ficha, carrito, pedido, pausa, formulario), ¡Sale! sin garantía, correo con el diseño de la marca, móvil | 60 |
| `ronda171.js` | Correo de primero en el checkout, carrito abandonado al cerrar la página o cambiar de pestaña, solo con celular, correo dado de baja y datos extra (sin autorización: guardado sin correos), Descuentos con el módulo apagado (aviso, botón y encendido al guardar), etiquetas y precios tachados en ficha y tienda, precio sin "Price range", Ayuda con 16 capturas numeradas (índice, carga diferida, celular, usuario de SEO) | 29 |
| `ronda172.js` | Descuento por cantidad: recuadro en la ficha aunque otro plugin borre los ganchos (simula BodyCommerce), aviso de ¡Sale!, píldora en tarjetas, barra, carrito combinando 2 productos distintos, formulario y API con "combinando" por defecto, carrito abandonado sin casilla (siempre activo), botón Actualizar listado, archivos ACF del pedido (ajuste vacío, HPOS, 📎 en la lista) | 18 |
| `pedidos-nuevos.js` | Pedidos nuevos en la gestión: estado propio de otro plugin en la API rápida, sin caché, botón "Buscar pedidos nuevos", revisión automática a los 10 min, resumen recalculado, recarga | 10 |
| `ronda176.js` | Tallas ordenadas en las tarjetas (también si están guardadas al revés), checkout sin Población ni Código postal (también tras elegir departamento), pedido sin esos campos, departamento en la gestión, aviso bajo el correo | 9 |
| `ronda177.js` | Entrar a la gestión aunque otro plugin mande al inicio (3 formas, gestora y Gestor_ADS), fotos con EXIF derechas, girar fotos, total de reseñas del perfil de Google, Mi cuenta legible con el CSS del tema de producción (1440/390), Ayuda paso a paso y buscador, reseñas sin total ni fecha | 32 |
| `ronda178.js` | Acceso propio /ingreso/ (wp-admin sin sesión, destino por rol, contraseña mala), redirección ajena en wp_loaded evitada en la gestión (gestora, Gestor_ADS, admin), registro con el archivo del plugin y con header() directo, registro en Ajustes → Buckler Core | 18 |
| `ronda179.js` | Favicon en acceso, gestión e impresión; editar datos de la clienta en el pedido (valores actuales, correo inválido, guardado, misma dirección / envío distinto, historial y nota, sin cambios, sin sesión, celular) | 17 |
| `ronda180.js` | wp-admin/wp-login.php ocultos sin sesión (rutas, POST, sin caché), admin-ajax/admin-post/carrito intactos, /ingreso/ a wp-admin, salir, interruptor, contraseña olvidada y enlace con clave, páginas con contraseña; centro de ayuda (módulos, capturas por tarea, marcas ↔ pasos, ampliar, buscar, celular) | 36 |
| `ronda181.js` | Recordatorios de pago: checkout que queda pendiente, programación 1 h/24 h/48 h, Woo no lo cancela a los 60 min, correo con enlace firmado, sin repetir, gestión (estado, vista previa, enviar, detener/reanudar, copiar enlace, espera de 30 min), pagar desde el enlace (sin verificar correo; sin firma sí), cancelación a las 48 h, Carritos Pendiente de pago → Compró / cancelado sin pagar, Correos | 31 |
| `ronda182.js` | Datos estructurados para Search Console: SKU válido, un Offer por talla, agotados con precio, deliveryTime, San Andrés, política de cambios (y ¡Sale! sin cambio), sin Product si no hay precio | 12 |
| `ronda184.js` | Transportador Local: sin número de guía, correo "Tu pedido llega hoy", Mi cuenta, historial, plantilla en Correos; las demás siguen exigiendo guía | 16 |
| `ronda185.js` | Píxel de Meta (Facebook for WooCommerce simulado): detección, aviso de dos plugins, AddToCart del carrito flotante una sola vez con eventID | 9 |
| `ronda186.js` | GA4 en el dataLayer: view_item, add_to_cart, view_cart, remove_from_cart, begin_checkout, add_payment_info, purchase una vez (y al confirmarse el pago), user_data SHA-256, guía para la agencia y contenedor GTM | 18 |
| `ronda187.js` | Guía de tallas y siluetas en texto: dos botones, pestaña de la línea, Encuentra tu talla, siluetas dibujadas con "Este modelo", falda sin siluetas, celular, editor en Gestión y restaurar | 24 |

Capturas de la Ayuda: `NODE_PATH=$(npm root -g) node ../help-shots.js` (escribe `buckler-core/assets/img/ayuda/*.webp`).
| `seguridad.js` | Nombres de usuario ocultos a visitantes: REST de usuarios 401, ?author 404, sin sitemap de usuarios, Store API pública, error de acceso genérico, editor con sesión intacto | 7 |
