jQuery(document).ready(function($) {
    
    // 1. Empaquetamos la lógica en una función reutilizable
    function agregarEtiquetasDescuento() {
        $('.yaydp-product-discounted-data').each(function() {
            var $contenedor = $(this);
            var $producto = $contenedor.closest('li.product');
            
            // Validación: Si la etiqueta ya existe en este producto, no hacemos nada para no duplicarla
            if ($producto.find('.etiqueta-oferta-forzada').length > 0) {
                return;
            }

            var rate = parseFloat($contenedor.attr('data-min-rate'));

            // Si el rate es menor a 1, hay descuento
            if (rate > 0 && rate < 1) {
                var porcentaje = Math.round((1 - rate) * 100); 
                var etiquetaHTML = '<span class="etiqueta-oferta-forzada">' + porcentaje + '% Off</span>';
                
                // Inyectamos la etiqueta
                $producto.prepend(etiquetaHTML);
            }
        });
    }

    // 2. Ejecutamos la función la primera vez que carga la página
    agregarEtiquetasDescuento();

    // 3. Volvemos a ejecutarla cada vez que Divi/WooCommerce traigan nuevos productos (Paginación)
    $(document).ajaxComplete(function() {
        // Le damos un pequeñísimo respiro de 200 milisegundos para asegurar que el HTML nuevo ya se "pintó"
        setTimeout(agregarEtiquetasDescuento, 200);
    });

});