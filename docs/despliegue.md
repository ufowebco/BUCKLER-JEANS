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

## Fase 1 hecha (2026-10-14)

- buckler-core 0.8.1 instalado y activo, con todos los módulos apagados.
- Revisión posterior en `snapshots/review-2026-10-14-post/`: **0 diferencias** con la línea base en 28 páginas.
- Se comparó: estados, títulos, H1, robots, canonical, número de productos, errores JS y peticiones fallidas.
- 111 enlaces internos sin errores y el flujo de carrito funciona.

- Videos: 132 productos con MP4 en la galería; las "claves encontradas" eran falsos positivos, corregidos en 0.8.2.
  **No pulsar "Usar como respaldo".**
- Pedidos: claves reales identificadas (ver CLAUDE.md). En 0.8.2 son los valores por defecto del dashboard.

## Fase 2: activar la tienda (decisión del cliente 2026-10-14: "activemos toda la tienda y vamos corrigiendo")

El bloqueante de las categorías ya no frena la activación (0.9.0):
- Menú y footer enlazan las prendas por filtro (`/linea-curvy/?prenda=jeans`); "Lo nuevo" va a `/tienda/?nuevo=1`.
  Esto funciona aunque las categorías sigan redirigiendo a /tienda/ (`category_links = filters` en `config.php`).
- La tienda la dibuja buckler-core (`template_include`, prioridad 99999) en tienda, categorías, búsqueda, `/linea-slim/`,
  `/linea-curvy/` y `/sales/`, con el header y el footer del tema.
  - No hace falta editar las plantillas TB de esos cuerpos, que Divi marca como no editables.
  - Al apagar el módulo `shop` vuelve el cuerpo actual del Theme Builder.

### Pasos
1. **Cliente**: copia de seguridad.
2. **Cliente**: actualizar el plugin a 0.9.0 (Plugins → Añadir nuevo → Subir → Reemplazar).
3. **Cliente**: en Ajustes → Buckler Core, activar `layout`, `clicks`, `cards`, `shop`, `sidecart` y `abandoned`, y guardar.
4. **Cliente**: desactivar en el mismo momento WooCommerce Side Cart Premium (Xootix) y Save Abandoned Carts. Si no, habría carritos y correos dobles.
5. **Cliente**: purgar las dos cachés (LiteSpeed y Ufocache) y avisar.
6. **Claude (MCP)**: `python3 divi-layouts/theme_hf.py --apply` para poner los shortcodes en el header 1114 y el footer 119. El script guarda un snapshot antes y se deshace con `undo_batch`.
7. **Cliente**: purgar caché otra vez.
8. **Claude**: `node tools/review.js snapshots/review-<fecha>-fase2`, comparar con la línea base y corregir.

### Qué queda para después
| Módulo o tarea | Por qué espera |
|---|---|
| `video` | Exige apagar BodyCommerce, y la ficha de producto actual (TB 330) depende de BodyCommerce (botones de talla). Va con la ficha nueva |
| `banners` | El inicio usa módulos de código; hay que rehacer la página 44 |
| `sku_ref` | El snippet actual ya lo hace; se activa al borrar el snippet |
| Plugins de filtros (Divi Machine, Ajax Filter, Themify, filtrado-ufo), Divi Essential | Desactivarlos solo después de confirmar que ninguna página los usa |
| Categorías indexables | Quitar el redirect, pasar `category_links` a `archive` y revisar SEO |

### Deshacer
- Apagar los módulos en Ajustes → Buckler Core.
- Reactivar Xootix y Save Abandoned Carts.
- Header y footer: `undo_batch` con el batch que deja `theme_hf.py`.
- Purgar caché.

## Bloqueantes conocidos (no frenan la fase 2)

1. **Todas las categorías redirigen (301) a `/tienda/`** (`X-Redirect-By: WordPress`, origen desconocido). Mientras siga así,
   las categorías no se indexan; los enlaces propios no dependen de ellas.
2. **Cualquier URL inexistente redirige (301) a la portada** (soft 404). Se resuelve en el módulo SEO.

## Después de cada paso

- [ ] Purgar las dos cachés.
- [ ] `tools/review.js`: estados, errores, enlaces y flujo de carrito sin regresiones.
- [ ] Revisar a mano en un navegador real, sin bloqueo de dominios externos: pagos (Bold, Wompi, Rapyd, Addi) y píxel/GA, que deben dispararse una sola vez.
