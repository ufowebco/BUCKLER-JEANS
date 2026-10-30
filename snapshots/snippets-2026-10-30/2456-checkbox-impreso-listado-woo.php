<?php
// 1. Añadir la columna "IMPRESO" a la tabla de pedidos
add_filter( 'manage_edit-shop_order_columns', 'agregar_columna_impreso_woo' );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'agregar_columna_impreso_woo' );
function agregar_columna_impreso_woo( $columns ) {
    $columns['impreso'] = 'IMPRESO';
    return $columns;
}

// 2. Mostrar el checkbox (100% Compatible con el nuevo sistema HPOS)
add_action( 'manage_shop_order_posts_custom_column', 'mostrar_checkbox_impreso_woo', 10, 2 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'mostrar_checkbox_impreso_woo', 10, 2 );
function mostrar_checkbox_impreso_woo( $column, $order_or_post_id ) {
    if ( 'impreso' === $column ) {
        // Magia para soportar HPOS: Si es un número (sistema viejo) lo usamos, si es un objeto (HPOS) extraemos el ID.
        $order = is_numeric( $order_or_post_id ) ? wc_get_order( $order_or_post_id ) : $order_or_post_id;
        
        if ( ! is_a( $order, 'WC_Order' ) ) return;
        
        $order_id = $order->get_id(); // <-- Aquí sacamos el ID real de forma segura
        
        // Revisamos si ya estaba marcado en la base de datos
        $estado_impreso = $order->get_meta( '_impreso_status' );
        $checked = ( $estado_impreso === 'yes' ) ? 'checked="checked"' : '';
        
        echo '<input type="checkbox" class="cb-impreso-ajax" data-order-id="' . esc_attr( $order_id ) . '" ' . $checked . ' style="cursor: pointer; transform: scale(1.2);" />';
    }
}

// 3. Inyectar el JavaScript (AJAX) para guardar en segundo plano
add_action( 'admin_footer', 'script_ajax_checkbox_impreso_woo' );
function script_ajax_checkbox_impreso_woo() {
    $screen = get_current_screen();
    // Solo cargamos este script en la vista de lista de pedidos
    if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ) ) ) {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        
        // Evitar que el clic en el checkbox abra el pedido
        $('.cb-impreso-ajax').on('click', function(e) {
            e.stopPropagation();
        });

        // Detectar el cambio de estado y guardar
        $('.cb-impreso-ajax').on('change', function() {
            var checkbox = $(this);
            var order_id = checkbox.data('order-id');
            var is_checked = checkbox.is(':checked') ? 'yes' : 'no';
            
            // Bloqueamos visualmente el checkbox para evitar doble clic accidental
            checkbox.prop('disabled', true);
            checkbox.css('opacity', '0.5');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'guardar_estado_impreso_woo',
                    order_id: order_id,
                    impreso: is_checked,
                    security: '<?php echo wp_create_nonce("impreso_nonce_seguridad"); ?>'
                },
                success: function(response) {
                    // Se guardó exitosamente, desbloqueamos
                    checkbox.prop('disabled', false);
                    checkbox.css('opacity', '1');
                    
                    // Feedback visual: un pequeño resplandor verde confirmando que se guardó
                    checkbox.css('box-shadow', '0 0 8px #46b450');
                    setTimeout(function() { 
                        checkbox.css('box-shadow', 'none'); 
                    }, 1000);
                },
                error: function() {
                    alert('Hubo un error de conexión al guardar. Por favor, recarga la página.');
                    checkbox.prop('disabled', false);
                    checkbox.css('opacity', '1');
                }
            });
        });
    });
    </script>
    <?php
}

// 4. Procesar la petición en el servidor y actualizar el pedido
add_action( 'wp_ajax_guardar_estado_impreso_woo', 'procesar_estado_impreso_woo_ajax' );
function procesar_estado_impreso_woo_ajax() {
    // Verificamos por seguridad que la petición sea legítima
    check_ajax_referer( 'impreso_nonce_seguridad', 'security' );
    
    // Verificamos permisos
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( 'Sin permisos' );
    }
    
    $order_id = intval( $_POST['order_id'] );
    $impreso = sanitize_text_field( $_POST['impreso'] );
    
    $order = wc_get_order( $order_id );
    if ( $order ) {
        // Guardamos el metadato permanentemente en el pedido
        $order->update_meta_data( '_impreso_status', $impreso );
        $order->save();
        wp_send_json_success();
    }
    
    wp_send_json_error( 'Pedido no encontrado' );
}