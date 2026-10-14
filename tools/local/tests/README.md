# Pruebas de navegador (Playwright) — entorno local

Requisitos: `tools/local/setup.sh` ejecutado y `php -S localhost:8899 -t $BK_LOCAL/site` corriendo.
Rutas asumidas: `BK_LOCAL=/tmp/claude-0/bk-local` (ajustar en los scripts si cambia).

```bash
php tools/local/tests/seed-cards.php     # galerías, destacado, oferta, talla agotada
php tools/local/tests/seed-shop.php      # ~60 productos con nombres/precios/categorías reales
cd tools/local/tests && for t in shop cards sc ac; do NODE_PATH=$(npm root -g) node $t.js; done
```

| Suite | Qué prueba | Comprobaciones |
|---|---|---|
| `shop.js` | Filtros, conteos, orden, "Ver más", historial, noindex, sin JS, móvil | 25 |
| `cards.js` | Slider, fotos diferidas, tallas, Nuevo, descuento, dead click | 23 |
| `sc.js` | Carrito flotante, añadir sin recargar, sugerencias por talla, rage click | 16 |
| `ac.js` | Carritos abandonados de punta a punta (captura → correo → recuperar → compra → baja) | 21 |
