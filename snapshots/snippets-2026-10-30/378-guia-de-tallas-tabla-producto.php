<?php
add_filter( 'woocommerce_product_tabs', 'guia_tallas_tab', 98 );

function guia_tallas_tab( $tabs ) {
    // Verificamos si la imagen existe en la página de opciones para no mostrar una pestaña vacía
    $imagen_guia = get_field('imagen_guia_de_tallas', 'option');

    if ( $imagen_guia ) {
        $tabs['guia_tallas'] = array(
            'title'    => __('Guía de Tallas', 'woocommerce'),
            'priority' => 40,
            'callback' => 'guia_tallas_tab_content'
        );
    }

    return $tabs;
}

function guia_tallas_tab_content() {
    $imagen_guia = get_field('imagen_guia_de_tallas', 'option');

    if ( $imagen_guia ) {
        // Extraemos la URL según el formato de ACF
        $img_url = ( is_array( $imagen_guia ) && isset( $imagen_guia['url'] ) ) ? $imagen_guia['url'] : $imagen_guia;
        
        // HTML de la imagen con IDs específicos para el script
        echo '<div id="contenedor-zoom-guia" class="tabla_producto" style="text-align: center; padding: 15px 0; overflow: hidden; border-radius: 8px; display: inline-block; position: relative;">';
        echo '<img src="' . esc_url( $img_url ) . '" alt="Guía de Tallas" id="imagen-zoom-dinamico" style="max-width:100%; height:auto; display: block;" />';
        echo '</div>';
        
        // El script que hace la magia de rastrear el mouse
        ?>
        <script>
        (function() {
            var container = document.getElementById('contenedor-zoom-guia');
            var img = document.getElementById('imagen-zoom-dinamico');

            if (container && img) {
                // Cuando el mouse se mueve adentro
                container.addEventListener('mousemove', function(e) {
                    var rect = container.getBoundingClientRect();
                    var x = e.clientX - rect.left; // Posición X
                    var y = e.clientY - rect.top;  // Posición Y
                    
                    // Convertir a porcentajes
                    var xPercent = (x / rect.width) * 100;
                    var yPercent = (y / rect.height) * 100;
                    
                    // Mover el punto de origen del zoom hacia donde está el mouse
                    img.style.transformOrigin = xPercent + '% ' + yPercent + '%';
                });

                // Cuando el mouse sale, vuelve al centro
                container.addEventListener('mouseleave', function() {
                    img.style.transformOrigin = 'center center';
                });
            }
        })();
        </script>
        <?php
    }
}