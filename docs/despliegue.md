# Despliegue de buckler-core en buckler.com.co

Lista de verificación para instalar el plugin en producción. Se basa en la revisión del sitio público del
**2026-10-14** (`tools/review.js` → `snapshots/review-2026-10-14/`), que queda como línea base para comparar
después de cada paso.

## Estado del sitio antes de instalar (revisión 2026-10-14)

| Revisión | Resultado |
|---|---|
| Páginas clave (inicio, tienda, líneas, ¡Sales!, categorías, búsqueda, FAQ, política, carrito, mi cuenta, producto) a 1440 y 390 px | Todas responden 200, sin imágenes rotas ni scroll horizontal |
| Enlaces internos (110) | Ninguno roto. `/politica-de-envio/` ya redirige (301) a `/politica-empresa/politica-de-envio/` |
| Flujo ficha → talla → carrito → vaciar → checkout | Funciona (tallas con botones de BodyCommerce; checkout clásico de Divi) |
| Errores JS propios del sitio | Ninguno. Los fallos vistos son dominios externos que el entorno de revisión bloquea (Bold, Rapyd, CleanTalk, Jetpack Stats, unpkg, AdsAgent): verificar en un navegador normal |
| Theme Builder | Sin cambios: 12 plantillas; header 1114 (su título engaña: "Todos Los Políticas Empresa") y footer 119 son globales |
| `audit_site` | Igual que el diagnóstico inicial (código/CSS en Inicio, header, footer y FAQ) |
| Datos que usa buckler-core | Categorías requeridas presentes (`linea-slim`, `linea-curvy`, `linea-nueva` > `slim`/`curvy`, `sale`); atributos `pa_tallas-regular` y `pa_tallas-plus-size`; `pantalones-sale` ya no existe (el editor lo tolera) |
| Catálogo | 133 publicados; la Store API muestra 99 → "Ocultar productos agotados" está activo |
| Rendimiento (línea base) | Escritorio: 6,3 s y 169 peticiones de media · Móvil: 4,5 s y 152 · HTML ~430 KB |
| Caché | LiteSpeed Cache (`X-Litespeed-Cache: hit`) + capa propia `X-Ufocache` (TTL 10 h) |

## Bloqueantes antes del despliegue

1. **Todas las categorías redirigen (301) a `/tienda/`.**
   - Afecta a `/categoria-producto/linea-curvy/`, `/linea-curvy/jeans/`, `/linea-nueva/`, `/sale/`…
   - La cabecera es `X-Redirect-By: WordPress`, es decir, código propio: un snippet en el tema hijo o en un plugin.
   - Consecuencias:
     - Google no puede indexar las categorías: el canonical y el contenido son los de la tienda.
     - El menú y el footer nuevos enlazan a categorías; con el redirect, todos acabarían en la tienda sin filtrar.
   - Qué hacer:
     - [ ] El cliente indica dónde está el redirect y por qué se puso.
     - [ ] Ampliar la plantilla TB "Archivos - Busqueda" (2417) a **todas** las categorías de producto, no solo a las 18/29/65, con `[bk_tienda]` en el cuerpo.
     - [ ] Quitar el redirect en el mismo paso y purgar la caché.
2. **Cualquier URL inexistente redirige (301) a la portada.**
   - Esto hace que los errores 404 parezcan páginas válidas para Google (soft 404).
   - Corregirlo con una página 404 real; queda para el módulo SEO.
   - No bloquea `/gestion-tienda/`: al activar el módulo, la regla propia resuelve la URL antes de llegar al 404.
3. **Copia de seguridad completa** (archivos y base de datos) justo antes de empezar, y anotar desde dónde se restaura.
4. **Herramientas → Salud del sitio**: anotar la versión de PHP y `upload_max_filesize`. El editor reduce las fotos en el navegador, pero un video MP4 pesado podría superar el límite.

## Orden de instalación

1. Subir `dist/buckler-core-<versión>.zip` y activarlo. Todos los módulos quedan apagados, así que no cambia nada en el front.
2. Abrir Herramientas → Buckler: videos.
   - Comprobar el contador "Productos con MP4 en la galería". En la muestra de la revisión, 16 de 17 productos tenían video.
   - Comprobar si aparece alguna clave de respaldo.
3. Activar los módulos de uno en uno. Después de cada uno: purgar LiteSpeed y la capa Ufocache, y luego ejecutar `node tools/review.js snapshots/review-<fecha>` y compararlo con la línea base.
   1. `sku_ref`, `clicks`.
   2. `gestion`: probar `/gestion-tienda/` con un usuario gestor (rol "Gestor de tienda").
   3. `abandoned`: desactivar `woo-save-abandoned-carts` después.
   4. `cards`, `shop`: aplicar los shortcodes en las plantillas TB y resolver antes el bloqueante 1.
   5. `layout`: ejecutar `divi-layouts/theme_hf.py --apply` (header 1114 y footer 119).
   6. `sidecart`: desactivar después el carrito lateral de Xootix.
   7. `video`: desactivar BodyCommerce en el mismo paso.
4. Desactivar los plugins reemplazados según la lista que confirme el cliente.

## Después de cada paso

- [ ] Purgar las dos cachés.
- [ ] `tools/review.js`: estados, errores, enlaces y flujo de carrito sin regresiones.
- [ ] Revisar a mano en un navegador real, sin bloqueo de dominios externos: pagos (Bold, Wompi, Rapyd, Addi) y píxel/GA, que deben dispararse una sola vez.
