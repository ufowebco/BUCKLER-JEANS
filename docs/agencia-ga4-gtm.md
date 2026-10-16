# Buckler Jeans: GA4, Google Tag Manager y Google Ads (guía para la agencia)

La misma guía está dentro del sitio, en **/gestion-tienda/ → SEO y ADS → Guía para la agencia (GA4 + GTM)**.
El usuario de la agencia entra directo a esa pantalla, y desde ahí se descarga el contenedor para importar.

## IDs

| Herramienta | ID |
|---|---|
| Google Tag Manager | `GTM-NCDM5V29` |
| GA4 (ID de medición) | `G-BR4MXPY7HS` |
| Píxel de Meta | `339783714479962` (lo maneja el plugin Facebook for WooCommerce, con su API de conversiones) |
| Merchant Center | `5730910704` |

## Qué deja el sitio en el dataLayer

Son los eventos estándar de comercio electrónico de GA4. Antes de cada uno el sitio envía `{ ecommerce: null }`.

| Evento | Dónde | Cuándo |
|---|---|---|
| `view_item` | Ficha | Al abrir la ficha |
| `add_to_cart` | Ficha y carrito flotante | Al agregar una prenda (la talla va en `item_variant`) |
| `remove_from_cart` | Carrito flotante y carrito | Al quitar una prenda |
| `view_cart` | Carrito | Al abrir el carrito |
| `begin_checkout` | Finalizar compra | Al abrir el checkout con productos |
| `add_payment_info` | Finalizar compra | Al tocar "Realizar el pedido" (`payment_type`) |
| `purchase` | Gracias por tu compra | Una vez por pedido, solo si el pedido está pagado o en espera. Si la pasarela confirma unos segundos después, el evento se envía en ese momento. `transaction_id` es el número del pedido. |

Cada item lleva `item_id` (REF), `item_name`, `item_brand`, `item_category` (Línea Slim / Curvy), `item_category2`
(la prenda), `item_variant` (la talla), `price` y `quantity`. La moneda es COP.

`purchase` también incluye `user_data.sha256_email_address` y `user_data.sha256_phone_number` en SHA-256
(el celular en formato +57…), para las conversiones mejoradas.

Ejemplo:

```json
{
  "event": "purchase",
  "ecommerce": {
    "transaction_id": "3846",
    "affiliation": "Buckler Jeans · Tienda online",
    "value": 169900, "tax": 0, "shipping": 0, "currency": "COP", "payment_type": "Wompi",
    "items": [{ "item_id": "9912", "item_name": "CHAQUETA DAMA", "item_brand": "Buckler Jeans",
      "item_category": "Línea Slim", "item_category2": "Chaquetas Y Chalecos", "item_variant": "8",
      "price": 169900, "quantity": 1, "index": 0 }]
  },
  "user_data": { "sha256_email_address": "…", "sha256_phone_number": "…" }
}
```

## Pasos

1. **GTM:** ir a Administrador → Importar contenedor, elegir el archivo `buckler-gtm-ga4-ecommerce.json` y usar
   **Combinar → Cambiar el nombre de lo que esté en conflicto**. Se crean:
   - Una etiqueta "Evento de GA4" con el nombre `{{Event}}`, que envía los datos de comercio electrónico desde la
     capa de datos al ID `G-BR4MXPY7HS`.
   - Un activador de evento personalizado con la expresión `^(view_item|add_to_cart|remove_from_cart|view_cart|begin_checkout|add_payment_info|purchase)$`.
   - 5 variables de capa de datos (transaction_id, value, currency y user_data).
2. Si GTM ya tiene otra etiqueta que envía `purchase` a GA4, hay que pausarla. Si no, cada compra se cuenta dos veces.
3. Probar con Vista previa (Tag Assistant): agregar una prenda, abrir el carrito y el checkout. Después, Publicar.
4. **GA4:** marcar `purchase` como **evento clave**.
5. **Google Ads:** vincular GA4 con Ads e importar `purchase` como conversión, con valor dinámico.
   Para las conversiones mejoradas, usar las variables `DLV - user_data.*`.

## Bueno saber

- **Carga diferida:** GTM, gtag y AdsAgent se cargan con la primera interacción (tocar, mover el mouse o desplazarse),
  para que el sitio sea rápido. Los eventos esperan en el dataLayer y no se pierden. En el checkout y en la página
  de gracias, GTM carga de inmediato.
- **Meta:** no agregar el píxel en GTM, porque ya lo envía Facebook for WooCommerce y se duplicaría.
- **gtag directo:** el sitio también carga `G-BR4MXPY7HS` con gtag, solo para las vistas de página. La etiqueta
  importada no envía `page_view`, así que no se duplica.
- **Eventos propios `bk_*`:** siguen existiendo (WhatsApp, tallas, banners, búsquedas…). Están listados en
  SEO y ADS → Clases y eventos rastreables.
- **Contacto:** contacto@ufoweb.co. No hay que pegar códigos en el sitio: se instalan con carga diferida.
