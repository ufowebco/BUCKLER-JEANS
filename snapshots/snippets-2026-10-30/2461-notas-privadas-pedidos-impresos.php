<?php
// Usamos múltiples ganchos por si la plantilla "receipt" omite alguno
add_action( 'wcdn_after_items', 'imprimir_notas_privadas_wcdn_receipt', 99, 1 );
add_action( 'wcdn_after_content', 'imprimir_notas_privadas_wcdn_receipt', 99, 1 );

function imprimir_notas_privadas_wcdn_receipt( $order = null ) {
    // 1. Evitamos que el cuadro se imprima dos veces
    static $ya_impreso = false;
    if ( $ya_impreso ) {
        return;
    }

    // 2. Extraemos el ID del pedido directo de tu URL
    $order_id = 0;
    if ( isset( $_GET['print-order'] ) && is_numeric( $_GET['print-order'] ) ) {
        $order_id = intval( $_GET['print-order'] );
    } elseif ( $order && is_callable( array( $order, 'get_id' ) ) ) {
        $order_id = $order->get_id();
    }

    if ( ! $order_id ) {
        return;
    }

    // 3. Obtenemos todas las notas internas
    $notas_privadas = wc_get_order_notes( array(
        'order_id' => $order_id,
        'type'     => 'internal'
    ) );

    // 4. NUEVO FILTRO INTELIGENTE: Manuales + Excepciones del sistema (Wompi)
    $notas_a_imprimir = array();
    foreach ( $notas_privadas as $nota ) {
        // Verificamos si fue escrita por un humano
        $es_manual = ( $nota->added_by !== 'system' );
        
        // Verificamos si es del sistema pero contiene información de pago
        $es_pago = ( stripos( $nota->content, 'Wompi' ) !== false || stripos( $nota->content, 'TRANSACTION' ) !== false );
        
        if ( $es_manual || $es_pago ) {
            $notas_a_imprimir[] = $nota;
        }
    }

    // 5. Imprimimos la caja
    if ( ! empty( $notas_a_imprimir ) ) {
        $ya_impreso = true; 
        
        echo '<div style="margin-top: 20px; padding: 15px; border: 2px solid #000; background-color: #fff; page-break-inside: avoid;">';
        echo '<h3 style="margin: 0 0 10px 0; font-size: 15px; font-weight: bold; text-transform: uppercase;">⚠️ Notas Internas e ID de Pago</h3>';
        echo '<ul style="list-style: none; padding: 0; margin: 0; font-size: 13px;">';
        
        foreach ( $notas_a_imprimir as $nota ) {
            // Le agregamos la hora para mayor precisión en los pagos
            $fecha_formateada = date_i18n( 'd/m/Y H:i', strtotime( $nota->date_created ) );
            
            // Cambiamos el nombre del autor si es del sistema para que se vea más profesional
            $autor = ( $nota->added_by === 'system' ) ? 'Sistema (Pasarela de Pago)' : esc_html( $nota->added_by );
            
            echo '<li style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px dashed #ccc;">';
            echo '<strong style="display:block; margin-bottom: 3px;">' . $autor . ' [' . $fecha_formateada . ']:</strong>';
            echo wp_kses_post( $nota->content );
            echo '</li>';
        }
        
        echo '</ul>';
        echo '</div>';
    }
}