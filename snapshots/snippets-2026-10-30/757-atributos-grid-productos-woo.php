<?php
// Función definitiva: Primero REF, luego Tallas (con espaciado corregido)
add_action('woocommerce_after_shop_loop_item_title', 'mostrar_ref_y_tallas_orden_estricto', 9);
function mostrar_ref_y_tallas_orden_estricto() {
    global $product;

    if ( ! is_a( $product, 'WC_Product' ) ) {
        return;
    }

    // CONTENEDOR MAESTRO
    echo '<div class="info-extra-grid" style="display: block; width: 100%; text-align: center; margin-top: 5px; margin-bottom: 10px; clear: both;">';

    // 1. PRIMERO IMPRIMIMOS LA REFERENCIA (SKU)
    $sku = $product->get_sku();
    if ( $sku ) {
        echo '<div class="sku-grid" style="font-size: 0.9em; color: #777; margin-bottom: 6px;">REF: ' . esc_html( $sku ) . '</div>';
    }

    // 2. RASTREAR INVENTARIO DE VARIACIONES
    $terminos_en_stock = array();
    if ( $product->is_type( 'variable' ) ) {
        $variaciones_ids = $product->get_children();
        foreach ( $variaciones_ids as $var_id ) {
            $variacion = wc_get_product( $var_id );
            if ( $variacion && $variacion->is_in_stock() ) {
                $atributos_var = $variacion->get_attributes();
                foreach ( $atributos_var as $taxonomia => $slug ) {
                    $terminos_en_stock[$taxonomia][] = $slug;
                }
            }
        }
    }

    // 3. LUEGO IMPRIMIMOS LAS TALLAS
    $attributes = $product->get_attributes();
    if ( $attributes ) {
        echo '<div class="atributos-grid" style="font-size: 0.85em; color: #777;">';
        
        foreach ( $attributes as $attribute ) {
            $nombre_atributo = wc_attribute_label( $attribute->get_name() );
            $valores_html = array();

            if ( $attribute->is_taxonomy() ) {
                $terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'all' ) );
                
                foreach ( $terms as $term ) {
                    $esta_disponible = true;
                    
                    if ( $product->is_type( 'variable' ) ) {
                        $tax_name = $attribute->get_name();
                        if ( ! isset( $terminos_en_stock[$tax_name] ) || ! in_array( $term->slug, $terminos_en_stock[$tax_name] ) ) {
                            $esta_disponible = false;
                        }
                    }

                    if ( $esta_disponible ) {
                        $valores_html[] = esc_html( $term->name );
                    } else {
                        // Talla agotada
                        $valores_html[] = '<del style="color: #ccc; text-decoration-thickness: 1.5px;">' . esc_html( $term->name ) . '</del>';
                    }
                }
            } else {
                $opciones = $attribute->get_options();
                foreach ( $opciones as $opcion ) {
                    $valores_html[] = esc_html( $opcion );
                }
            }

            // AQUI ESTÁ LA CORRECCIÓN DE ESPACIADO
            if ( ! empty( $valores_html ) ) {
                echo '<div style="margin-top: 2px; text-transform: uppercase; line-height: 1.3;">';
                echo '<strong style="display: block; margin-bottom: 1px;">' . esc_html( $nombre_atributo ) . ':</strong>';
                echo '<span style="display: block;">' . implode( ', ', $valores_html ) . '</span>';
                echo '</div>';
            }
        }
        
        echo '</div>'; // Fin de atributos
    }

    echo '</div>'; // Fin del contenedor maestro
}