# Pruebas de navegador (Playwright) — entorno local

Requisitos: `tools/local/setup.sh` ejecutado y `php -S localhost:8899 -t $BK_LOCAL/site` corriendo.
Rutas asumidas: `BK_LOCAL=/tmp/claude-0/bk-local` (ajustar en los scripts si cambia).

```bash
php tools/local/tests/seed-cards.php     # galerías, destacado, oferta, talla agotada
php tools/local/tests/seed-shop.php      # ~60 productos con nombres/precios/categorías reales
php tools/local/tests/seed-orders.php    # pedidos de ejemplo, usuaria "gestora" (rol Gestor de tienda)
cd tools/local/tests && for t in shop cards sc ac gestion envio productos tienda-paginas paginas; do NODE_PATH=$(npm root -g) node $t.js; done
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
| `paginas.js` | Páginas limpias (módulo `pages`, antes `php seed-pages.php`): inicio (líneas en una fila), menú Tienda visible, carrito flotante, buscador en vivo, migas + BreadcrumbList, ficha (tallas accesibles, gemelo), carrito (− / + con actualización automática, sugerencias), compra real hasta "Gracias" (promo), barra verde (3/1 anuncios, sellos), reseñas de Google, video automático, fotos diferidas, caché y dieta, mi cuenta (acceso y panel), FAQ + JSON-LD, política, móvil | 82 |
