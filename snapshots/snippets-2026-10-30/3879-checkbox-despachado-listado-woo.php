<?php
// 1. Añadir la columna "DESPACHADO" a la tabla de pedidos
add_filter( 'manage_edit-shop_order_columns', 'agregar_columna_despachado_woo' );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'agregar_columna_despachado_woo' );
function agregar_columna_despachado_woo( $columns ) {
    $columns['despachado'] = 'DESPACHADO';
    return $columns;
}

// 2. Mostrar la estrella (100% Compatible con el nuevo sistema HPOS)
add_action( 'manage_shop_order_posts_custom_column', 'mostrar_checkbox_despachado_woo', 10, 2 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'mostrar_checkbox_despachado_woo', 10, 2 );
function mostrar_checkbox_despachado_woo( $column, $order_or_post_id ) {
    if ( 'despachado' === $column ) {
        // Magia para soportar HPOS
        $order = is_numeric( $order_or_post_id ) ? wc_get_order( $order_or_post_id ) : $order_or_post_id;
        
        if ( ! is_a( $order, 'WC_Order' ) ) return;
        
        $order_id = $order->get_id(); 
        
        // Revisamos si ya estaba marcado en la base de datos
        $estado_despachado = $order->get_meta( '_despachado_status' );
        $checked = ( $estado_despachado === 'yes' ) ? 'checked="checked"' : '';
        
        // Determinamos el color de la estrella según el estado
        $color_estrella = ( $estado_despachado === 'yes' ) ? '#ffb900' : '#dddddd';
        
        // HTML: Checkbox oculto + Label con forma de estrella (Dashicon)
        echo '<input type="checkbox" id="despachado_' . esc_attr( $order_id ) . '" class="cb-despachado-ajax" data-order-id="' . esc_attr( $order_id ) . '" ' . $checked . ' style="display: none;" />';
        echo '<label for="despachado_' . esc_attr( $order_id ) . '" class="dashicons dashicons-star-filled despachado-star-label" style="font-size: 24px; cursor: pointer; color: ' . $color_estrella . '; transition: color 0.3s;"></label>';
    }
}

// 3. Inyectar el JavaScript (AJAX) para guardar en segundo plano y cambiar el color de la estrella
add_action( 'admin_footer', 'script_ajax_checkbox_despachado_woo' );
function script_ajax_checkbox_despachado_woo() {
    $screen = get_current_screen();
    // Solo cargamos este script en la vista de lista de pedidos
    if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ) ) ) {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        
        // Evitar que el clic en la estrella abra el pedido
        $('.cb-despachado-ajax, .despachado-star-label').on('click', function(e) {
            e.stopPropagation();
        });

        // Detectar el cambio de estado y guardar
        $('.cb-despachado-ajax').on('change', function() {
            var checkbox = $(this);
            var order_id = checkbox.data('order-id');
            var is_checked = checkbox.is(':checked') ? 'yes' : 'no';
            var label = checkbox.next('.despachado-star-label');
            
            // Bloqueamos visualmente la estrella temporalmente
            checkbox.prop('disabled', true);
            label.css('opacity', '0.5');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'guardar_estado_despachado_woo',
                    order_id: order_id,
                    despachado: is_checked,
                    security: '<?php echo wp_create_nonce("despachado_nonce_seguridad"); ?>'
                },
                success: function(response) {
                    // Se guardó exitosamente, desbloqueamos
                    checkbox.prop('disabled', false);
                    label.css('opacity', '1');
                    
                    // Cambiamos el color de la estrella visualmente
                    if (is_checked === 'yes') {
                        label.css('color', '#ffb900'); // Amarillo WordPress
                    } else {
                        label.css('color', '#dddddd'); // Gris claro
                    }
                },
                error: function() {
                    alert('Hubo un error de conexión al guardar el despacho. Por favor, recarga la página.');
                    checkbox.prop('disabled', false);
                    label.css('opacity', '1');
                    // Revertimos el checkbox si falla
                    checkbox.prop('checked', !checkbox.prop('checked')); 
                }
            });
        });
    });
    </script>
    <?php
}

// 4. Procesar la petición en el servidor y actualizar el pedido
add_action( 'wp_ajax_guardar_estado_despachado_woo', 'procesar_estado_despachado_woo_ajax' );
function procesar_estado_despachado_woo_ajax() {
    // Verificamos por seguridad que la petición sea legítima
    check_ajax_referer( 'despachado_nonce_seguridad', 'security' );
    
    // Verificamos permisos
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( 'Sin permisos' );
    }
    
    $order_id = intval( $_POST['order_id'] );
    $despachado = sanitize_text_field( $_POST['despachado'] );
    
    $order = wc_get_order( $order_id );
    if ( $order ) {
        // Guardamos el metadato permanentemente en el pedido
        $order->update_meta_data( '_despachado_status', $despachado );
        $order->save();
        wp_send_json_success();
    }
    
    wp_send_json_error( 'Pedido no encontrado' );
}

