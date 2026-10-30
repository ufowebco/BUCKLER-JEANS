<?php
// 1. EL CONTADOR: Registra una visita cada vez que alguien entra a un producto
add_action( 'template_redirect', 'registrar_visitas_productos_woo' );
function registrar_visitas_productos_woo() {
    // Solo contamos si estamos en la página individual de un producto
    if ( is_product() ) {
        global $post;
        if ( ! $post ) return;

        // Evitar contar las visitas del administrador (opcional, pero recomendado)
        if ( current_user_can( 'manage_options' ) ) return;

        $id_producto = $post->ID;
        // Obtenemos el conteo actual
        $visitas_actuales = get_post_meta( $id_producto, '_conteo_visitas_woo', true );
        $visitas_actuales = $visitas_actuales ? (int) $visitas_actuales : 0;

        // Sumamos 1 y guardamos
        $visitas_actuales++;
        update_post_meta( $id_producto, '_conteo_visitas_woo', $visitas_actuales );
    }
}

// 2. EL WIDGET: Registrar el panel en el escritorio de WordPress
add_action( 'wp_dashboard_setup', 'crear_widget_productos_top' );
function crear_widget_productos_top() {
    wp_add_dashboard_widget(
        'widget_productos_mas_visitados', // ID del widget
        'Top 10 Productos Más Visitados', // Título del panel
        'mostrar_contenido_widget_productos_top' // Función que lo dibuja
    );
}

// 3. EL DISEÑO: Extraer los datos y dibujarlos en una tabla (Ahora con REF/SKU)
function mostrar_contenido_widget_productos_top() {
    // Hacemos una consulta buscando los 10 productos con el número más alto en nuestro contador
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => 10,
        'meta_key'       => '_conteo_visitas_woo',
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'post_status'    => 'publish'
    );
    
    $query_top = new WP_Query( $args );

    if ( $query_top->have_posts() ) {
        echo '<table style="width:100%; border-collapse: collapse; margin-top: 10px;">';
        
        // Cabecera de la tabla con los colores de tu interfaz
        echo '<thead style="background-color: #1b1c1d; color: #d0e210;">';
        echo '<tr>';
        echo '<th style="padding: 10px; text-align: left; border-radius: 4px 0 0 0;">Producto</th>';
        echo '<th style="padding: 10px; text-align: center;">REF</th>';
        echo '<th style="padding: 10px; text-align: center; border-radius: 0 4px 0 0;">Vistas</th>';
        echo '</tr>';
        echo '</thead>';
        
        echo '<tbody>';
        
        $contador = 1;
        while ( $query_top->have_posts() ) {
            $query_top->the_post();
            
            $id_producto = get_the_ID();
            $visitas = get_post_meta( $id_producto, '_conteo_visitas_woo', true );
            
            // Extraer el SKU (Referencia) nativo de WooCommerce
            $producto_woo = wc_get_product( $id_producto );
            $sku = $producto_woo ? $producto_woo->get_sku() : '';
            $texto_sku = $sku ? esc_html( $sku ) : '<span style="color:#aaa; font-style:italic; font-size: 0.9em;">Sin REF</span>';
            
            // Alternar colores en las filas para mejor lectura
            $fondo = ( $contador % 2 == 0 ) ? '#f9f9f9' : '#ffffff';
            
            echo '<tr style="background-color: ' . $fondo . '; border-bottom: 1px solid #eeeeee;">';
            
            // 1. Columna: Nombre del producto con enlace directo para editarlo
            echo '<td style="padding: 10px;"><a href="' . get_edit_post_link() . '" style="text-decoration: none; font-weight: 600; color: #0073aa;">' . get_the_title() . '</a></td>';
            
            // 2. Columna: REF (SKU)
            echo '<td style="padding: 10px; text-align: center; color: #666; font-size: 0.95em;">' . $texto_sku . '</td>';
            
            // 3. Columna: Número de visitas
            echo '<td style="padding: 10px; text-align: center; font-weight: bold; color: #555;">' . esc_html( $visitas ) . '</td>';
            
            echo '</tr>';
            
            $contador++;
        }
        
        echo '</tbody></table>';
    } else {
        echo '<p style="padding: 15px; color: #777;">Todavía no hay datos. Los productos comenzarán a aparecer aquí conforme los clientes los visiten.</p>';
    }
    
    wp_reset_postdata();
}