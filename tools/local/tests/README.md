# Pruebas de navegador (Playwright) — entorno local

Requisitos: `tools/local/setup.sh` ejecutado y `php -S localhost:8899 -t $BK_LOCAL/site` corriendo.
Rutas asumidas: `BK_LOCAL=/tmp/claude-0/bk-local` (ajustar en los scripts si cambia).

```bash
php tools/local/tests/seed-cards.php     # galerías, destacado, oferta, talla agotada
php tools/local/tests/seed-shop.php      # ~60 productos con nombres/precios/categorías reales
php tools/local/tests/seed-orders.php    # pedidos de ejemplo, usuaria "gestora" (rol Gestor de tienda)
cd tools/local/tests && for t in shop cards sc ac gestion envio productos tienda-paginas paginas adminmenu seguridad gestion16 seo ronda17; do NODE_PATH=$(npm root -g) node $t.js; done
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
| `seguridad.js` | Nombres de usuario ocultos a visitantes: REST de usuarios 401, ?author 404, sin sitemap de usuarios, Store API pública, error de acceso genérico, editor con sesión intacto | 7 |
