<?php
/* 1. Crear el menú en el escritorio de WordPress */
add_action('admin_menu', 'menu_admin_inventario_rapido');
function menu_admin_inventario_rapido() {
    add_menu_page(
        'Gestión de Inventario',
        'Inventario Rápido',
        'manage_woocommerce',
        'inventario-rapido',
        'renderizar_pagina_inventario_rapido',
        'dashicons-store',
        55
    );
}

/* 2. Interfaz visual del Gestor */
function renderizar_pagina_inventario_rapido() {
    if (!class_exists('WooCommerce')) {
        echo '<div class="wrap"><h1>Requiere WooCommerce</h1></div>';
        return;
    }

    $total_productos = wp_count_posts('product')->publish;
    
    $categorias_raw = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
    ));

    function ordenar_categorias_jerarquia($categorias, $padre = 0, $nivel = 0) {
        $resultado = array();
        foreach ($categorias as $cat) {
            if ($cat->parent == $padre) {
                $cat->nivel = $nivel;
                $resultado[] = $cat;
                $resultado = array_merge($resultado, ordenar_categorias_jerarquia($categorias, $cat->term_id, $nivel + 1));
            }
        }
        return $resultado;
    }
    
    $categorias_ordenadas = ordenar_categorias_jerarquia($categorias_raw);
    ?>
    <style>
        :root {
            --color-primario: var(--wp-admin-theme-color, #2271b1);
            --color-hover: var(--wp-admin-theme-color-darker-10, #135e96);
            --gris-fondo: #f6f7f7;
            --gris-borde: #c3c4c7;
            --texto-oscuro: #1d2327;
            --texto-medio: #3c434a;
            --texto-claro: #646970;
            --carpeta-fondo: #2c3338;
            --carpeta-hover: #1d2327;
        }

        .ui-inventario { width: 100%; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, sans-serif; }
        .header-panel { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-right: 20px; }
        .header-panel h1 { font-size: 24px; font-weight: 600; color: var(--texto-oscuro); margin: 0; }
        .badge-total { font-size: 13px; background: rgba(0,0,0,0.04); color: var(--texto-medio); border: 1px solid var(--gris-borde); padding: 6px 14px; border-radius: 20px; font-weight: 600; }
        
        .buscador-wrapper { margin-bottom: 25px; display: flex; align-items: center; gap: 15px; }
        .input-container { position: relative; width: 100%; max-width: 600px; display: flex; align-items: center; }
        
        .ui-inventario input[type="text"].buscador-moderno { 
            width: 100% !important; 
            padding: 14px 45px 14px 45px !important; 
            font-size: 14px !important; 
            border: 1px solid var(--gris-borde) !important; 
            border-radius: 40px !important; 
            transition: all 0.3s ease !important; 
            background: #fff !important; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.04) !important; 
            color: var(--texto-oscuro) !important; 
            box-sizing: border-box !important;
            line-height: 1.5 !important;
            margin: 0 !important;
            height: auto !important;
        }
        .ui-inventario input[type="text"].buscador-moderno:focus { 
            border-color: var(--color-primario) !important; 
            box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.15) !important; 
            outline: none !important; 
        }
        .icon-search { 
            position: absolute; 
            left: 18px; 
            top: 50%; 
            transform: translateY(-50%); 
            font-size: 18px; 
            color: var(--texto-claro); 
            pointer-events: none; 
            z-index: 10;
        }
        .btn-limpiar { 
            position: absolute; 
            right: 14px; 
            background: #f0f0f1; 
            border: none; 
            font-size: 11px; 
            color: var(--texto-claro); 
            cursor: pointer; 
            width: 24px; 
            height: 24px; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            transition: all 0.2s; 
            z-index: 10;
        }
        .btn-limpiar:hover { background: #dcdcde; color: var(--texto-oscuro); }
        
        .modern-table { width: 100%; border-collapse: separate; border-spacing: 0 6px; padding-right: 20px; }
        .modern-table th { background: transparent; color: var(--texto-claro); font-weight: 600; font-size: 12px; padding: 0 15px 8px 15px; text-align: left; border: none; letter-spacing: 0.3px; }
        .modern-table td { background: #fff; padding: 12px 15px; vertical-align: middle; border-top: 1px solid transparent; border-bottom: 1px solid transparent; }
        .modern-table tr td:first-child { border-left: 1px solid transparent; border-top-left-radius: 8px; border-bottom-left-radius: 8px; }
        .modern-table tr td:last-child { border-right: 1px solid transparent; border-top-right-radius: 8px; border-bottom-right-radius: 8px; }
        
        .fila-simple, .fila-padre { box-shadow: 0 1px 3px rgba(0,0,0,0.04); transition: transform 0.2s, box-shadow 0.2s; border: 1px solid rgba(0,0,0,0.02); }
        .fila-simple:hover, .fila-padre:hover { box-shadow: 0 4px 10px rgba(0,0,0,0.08); transform: translateY(-1px); z-index: 2; position: relative; }
        .fila-variacion td { background: #fafafa; border-bottom: 1px solid var(--gris-fondo); padding-top: 8px; padding-bottom: 8px; }
        
        .fila-categoria td { background: var(--carpeta-fondo) !important; color: #fff; cursor: pointer; transition: background 0.2s; border-radius: 8px; }
        .fila-categoria:hover td { background: var(--carpeta-hover) !important; }
        .cat-count { background: rgba(255,255,255,0.1); color: #fff; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 500; margin-left: 10px; }
        .estado-carga { color: #a7aaad; font-size: 12px; font-weight: 500; transition: color 0.2s; }
        .fila-categoria:hover .estado-carga { color: #fff; }
        
        .ui-inventario input[type="text"].input-moderno, .ui-inventario input[type="number"].input-moderno { border: 1px solid var(--gris-borde); border-radius: 6px; padding: 6px 10px; font-size: 13px; width: 100%; background: #fff; color: var(--texto-oscuro); transition: border 0.2s; margin: 0; box-shadow: none; height: auto; }
        .ui-inventario input[type="text"].input-moderno:focus, .ui-inventario input[type="number"].input-moderno:focus { border-color: var(--color-primario); outline: none; box-shadow: 0 0 0 1px var(--color-primario); }
        
        .btn-moderno { background: var(--color-primario); color: #fff; border: none; border-radius: 6px; padding: 6px 14px; font-weight: 500; font-size: 13px; cursor: pointer; transition: background 0.2s ease; }
        .btn-moderno:hover { background: var(--color-hover); color: #fff; }
        .btn-moderno:active { transform: translateY(1px); }
        
        .tag-variacion { font-size: 11px; background: rgba(0,0,0,0.04); color: var(--texto-medio); border: 1px solid var(--gris-borde); padding: 2px 8px; border-radius: 4px; font-weight: 500; margin-left: 8px; cursor: pointer; transition: all 0.2s; }
        .tag-variacion:hover { background: rgba(0,0,0,0.08); border-color: var(--texto-claro); }
    </style>

    <div class="wrap ui-inventario">
        <div class="header-panel">
            <h1>Edición Rápida de Inventario</h1>
            <span class="badge-total">Total en tienda: <?php echo esc_html($total_productos); ?></span>
        </div>

        <div class="buscador-wrapper">
            <div class="input-container">
                <span class="icon-search">🔍</span>
                <input type="text" id="buscador-productos" class="buscador-moderno" placeholder="Buscar carpetas o productos desplegados...">
                <button type="button" id="btn-limpiar" class="btn-limpiar" style="display:none;" title="Borrar búsqueda">✖</button>
            </div>
            <span style="font-size: 13px; color: var(--texto-claro); font-weight: 400;">(Filtra por nombre de carpeta o productos desplegados)</span>
        </div>
        
        <table class="modern-table" id="tabla-inventario">
            <thead>
                <tr>
                    <th style="width: 70px;">ID</th>
                    <th style="width: 130px;">SKU</th>
                    <th>Producto / Variación</th>
                    <th style="width: 110px;">Precio Reg.</th>
                    <th style="width: 110px;">Precio Reb.</th>
                    <th style="width: 90px;">Stock</th>
                    <th style="width: 140px;">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categorias_ordenadas as $cat): 
                    $padding_izquierdo = 15 + ($cat->nivel * 35);
                    $icono_carpeta = $cat->nivel > 0 ? '↳ 📂' : '📁';
                ?>
                    <tr class="fila-categoria" data-cat-id="<?php echo $cat->term_id; ?>" data-cargado="false">
                        <td colspan="7" style="padding-left: <?php echo $padding_izquierdo; ?>px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <span class="icono-carpeta" style="margin-right: 8px; font-size: 15px;"><?php echo $icono_carpeta; ?></span>
                                    <strong style="font-size: 14px; letter-spacing: 0.3px;"><?php echo esc_html($cat->name); ?></strong>
                                    <span class="cat-count"><?php echo $cat->count; ?></span>
                                </div>
                                <span class="estado-carga">▶ Desplegar</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var tabla = document.getElementById('tabla-inventario');
            var buscador = document.getElementById('buscador-productos');
            var btnLimpiar = document.getElementById('btn-limpiar');
            
            function cerrarOtrasCategorias(catIdAbierta) {
                var todasLasCarpetas = document.querySelectorAll('.fila-categoria');
                todasLasCarpetas.forEach(function(cat) {
                    var idActual = cat.getAttribute('data-cat-id');
                    if (idActual !== catIdAbierta) {
                        var productos = document.querySelectorAll('.cat-hijo-' + idActual);
                        if (productos.length > 0 && productos[0].style.display !== 'none') {
                            productos.forEach(p => p.style.display = 'none');
                            var estadoCarga = cat.querySelector('.estado-carga');
                            var icono = cat.querySelector('.icono-carpeta');
                            var esSubcat = icono.innerText.includes('↳');
                            estadoCarga.innerText = '▶ Desplegar';
                            icono.innerText = esSubcat ? '↳ 📁' : '📁';
                        }
                    }
                });
            }

            function cerrarTodasLasCategorias() {
                var todasLasCarpetas = document.querySelectorAll('.fila-categoria');
                todasLasCarpetas.forEach(function(cat) {
                    var idActual = cat.getAttribute('data-cat-id');
                    var productos = document.querySelectorAll('.cat-hijo-' + idActual);
                    if (productos.length > 0) {
                        productos.forEach(p => p.style.display = 'none');
                        var estadoCarga = cat.querySelector('.estado-carga');
                        var icono = cat.querySelector('.icono-carpeta');
                        var esSubcat = icono.innerText.includes('↳');
                        estadoCarga.innerText = '▶ Desplegar';
                        icono.innerText = esSubcat ? '↳ 📁' : '📁';
                    }
                });
            }

            tabla.addEventListener('click', function(e) {
                // CLIC EN CATEGORÍA
                var filaCat = e.target.closest('.fila-categoria');
                if (filaCat) {
                    var catId = filaCat.getAttribute('data-cat-id');
                    var cargado = filaCat.getAttribute('data-cargado') === 'true';
                    var estadoCarga = filaCat.querySelector('.estado-carga');
                    var icono = filaCat.querySelector('.icono-carpeta');
                    var esSubcategoria = icono.innerText.includes('↳');

                    if (!cargado) {
                        cerrarOtrasCategorias(catId);
                        estadoCarga.innerHTML = '<span style="color: var(--color-primario);">⏳ Cargando...</span>';
                        var formData = new FormData();
                        formData.append('action', 'cargar_productos_categoria');
                        formData.append('cat_id', catId);

                        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                            method: 'POST',
                            body: formData
                        }).then(response => response.json()).then(data => {
                            if (data.success) {
                                filaCat.setAttribute('data-cargado', 'true');
                                estadoCarga.innerText = '▼ Ocultar';
                                icono.innerText = esSubcategoria ? '↳ 📂' : '📂';
                                filaCat.insertAdjacentHTML('afterend', data.data);
                            }
                        });
                    } else {
                        var productosCat = document.querySelectorAll('.cat-hijo-' + catId);
                        var estanOcultos = productosCat.length > 0 && productosCat[0].style.display === 'none';
                        
                        if (estanOcultos) {
                            cerrarOtrasCategorias(catId);
                            productosCat.forEach(function(fila) {
                                if (!fila.classList.contains('fila-variacion')) fila.style.display = 'table-row';
                            });
                        } else {
                            productosCat.forEach(fila => fila.style.display = 'none');
                        }
                        estadoCarga.innerText = estanOcultos ? '▼ Ocultar' : '▶ Desplegar';
                        icono.innerText = estanOcultos ? (esSubcategoria ? '↳ 📂' : '📂') : (esSubcategoria ? '↳ 📁' : '📁');
                    }
                    return;
                }

                // CLIC EN VARIACIONES
                var tagVar = e.target.closest('.tag-variacion');
                var filaPadre = e.target.closest('.fila-padre');
                if (tagVar && filaPadre) {
                    var idPadre = filaPadre.getAttribute('data-padre-id');
                    var variaciones = document.querySelectorAll('.hijo-de-' + idPadre);
                    var varsOcultas = variaciones[0].style.display === 'none';
                    variaciones.forEach(v => v.style.display = varsOcultas ? 'table-row' : 'none');
                    tagVar.innerText = varsOcultas ? '▼ Ocultar Variaciones' : '▶ Ver Variaciones';
                    return;
                }

                // CLIC EN GUARDAR
                var btnGuardar = e.target.closest('.btn-moderno');
                if (btnGuardar) {
                    var id = btnGuardar.getAttribute('data-id');
                    var tr = btnGuardar.closest('tr'); 
                    
                    var sku = tr.querySelector('.sku-input').value;
                    var precio = tr.querySelector('.precio-input').value;
                    var rebaja = tr.querySelector('.rebaja-input').value;
                    var stock = tr.querySelector('.stock-input').value;
                    var estado = tr.querySelector('.mensaje-estado');
                    
                    var textoOriginal = btnGuardar.innerText;
                    btnGuardar.innerText = '⏳';
                    estado.innerText = '';

                    var formDataGuardar = new FormData();
                    formDataGuardar.append('action', 'guardar_inventario_rapido');
                    formDataGuardar.append('producto_id', id);
                    formDataGuardar.append('sku', sku);
                    formDataGuardar.append('precio', precio);
                    formDataGuardar.append('rebaja', rebaja);
                    formDataGuardar.append('stock', stock);

                    fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                        method: 'POST',
                        body: formDataGuardar
                    }).then(response => response.json()).then(data => {
                        btnGuardar.innerText = textoOriginal;
                        if (data.success) {
                            estado.style.color = '#00a32a'; 
                            estado.innerText = '¡Listo!';
                            setTimeout(() => estado.innerText = '', 2500);
                        } else {
                            estado.style.color = '#d63638'; 
                            estado.innerText = 'Error';
                        }
                    });
                }
            });

            // --- LÓGICA INTELIGENTE DEL BUSCADOR ---
            function procesarBusqueda() {
                var txt = buscador.value.toLowerCase();
                var todasLasCarpetas = document.querySelectorAll('.fila-categoria');
                
                btnLimpiar.style.display = txt.length > 0 ? 'flex' : 'none';

                if(txt === '') {
                    // Mostrar todas las carpetas y colapsar su contenido
                    todasLasCarpetas.forEach(cat => cat.style.display = 'table-row');
                    cerrarTodasLasCategorias(); 
                } else {
                    // Evaluar carpeta por carpeta
                    todasLasCarpetas.forEach(function(cat) {
                        var catId = cat.getAttribute('data-cat-id');
                        var nombreCat = cat.querySelector('strong').innerText.toLowerCase();
                        var coincideCat = nombreCat.includes(txt); // Coincide el nombre de la carpeta
                        
                        var productos = document.querySelectorAll('.cat-hijo-' + catId);
                        var algunProductoCoincide = false;

                        productos.forEach(function(fila) {
                            var nombre = fila.querySelector('.nombre-producto').innerText.toLowerCase();
                            var sku = fila.querySelector('.sku-input').value.toLowerCase();
                            
                            if (nombre.includes(txt) || sku.includes(txt)) {
                                fila.style.display = 'table-row';
                                algunProductoCoincide = true;
                                
                                // Si coincide una variación, asegurar que su fila padre sea visible
                                if (fila.classList.contains('fila-variacion')) {
                                    var clasePadre = Array.from(fila.classList).find(c => c.startsWith('hijo-de-'));
                                    if (clasePadre) {
                                        var idPadre = clasePadre.replace('hijo-de-', '');
                                        var nodoPadre = document.querySelector('.fila-padre[data-padre-id="' + idPadre + '"]');
                                        if (nodoPadre) nodoPadre.style.display = 'table-row';
                                    }
                                }
                            } else {
                                fila.style.display = 'none';
                            }
                        });

                        // Mostrar la carpeta solo si su nombre coincide O si tiene algún producto visible
                        if (coincideCat || algunProductoCoincide) {
                            cat.style.display = 'table-row';
                        } else {
                            cat.style.display = 'none'; // Ocultar carpetas irrelevantes
                        }
                    });
                }
            }

            buscador.addEventListener('input', procesarBusqueda);

            btnLimpiar.addEventListener('click', function() {
                buscador.value = '';
                procesarBusqueda();
                buscador.focus();
            });
        });
    </script>
    <?php
}

/* 3. AJAX: Generar HTML de productos por Categoría */
add_action('wp_ajax_cargar_productos_categoria', 'ajax_cargar_productos_categoria');
function ajax_cargar_productos_categoria() {
    if (!current_user_can('manage_woocommerce')) wp_send_json_error();

    $cat_id = intval($_POST['cat_id']);
    $cat_slug = get_term($cat_id)->slug;
    
    $args = array(
        'limit' => 500, 
        'status' => 'publish',
        'category' => array($cat_slug),
    );
    $productos = wc_get_products($args);

    ob_start(); 
    
    foreach ($productos as $producto) {
        $es_variable = $producto->is_type('variable');
        
        if ($es_variable) {
            ?>
            <tr class="fila-padre cat-hijo-<?php echo $cat_id; ?>" data-padre-id="<?php echo $producto->get_id(); ?>">
                <td><strong style="color: var(--texto-claro);">#<?php echo $producto->get_id(); ?></strong></td>
                <td><input type="text" class="input-moderno sku-input" value="<?php echo esc_attr($producto->get_sku()); ?>"></td>
                <td class="nombre-producto">
                    <strong style="font-size: 13px; color: var(--texto-oscuro);"><?php echo esc_html($producto->get_name()); ?></strong>
                    <span class="tag-variacion">▶ Ver Variaciones</span>
                </td>
                <td colspan="3" style="color: var(--texto-claro); font-size: 12px; text-align: center; font-style: italic;">Abre las variaciones para editar</td>
                <td>
                    <input type="hidden" class="precio-input"> <input type="hidden" class="rebaja-input"> <input type="hidden" class="stock-input">
                </td>
            </tr>
            <?php
            $hijos_ids = $producto->get_children();
            foreach ($hijos_ids as $id_hijo) {
                $variacion = wc_get_product($id_hijo);
                if ($variacion && $variacion->get_status() === 'publish') {
                    $stock_actual = $variacion->get_manage_stock() ? $variacion->get_stock_quantity() : '';
                    ?>
                    <tr class="fila-variacion cat-hijo-<?php echo $cat_id; ?> hijo-de-<?php echo $producto->get_id(); ?>" style="display: none;">
                        <td style="color: var(--texto-claro); text-align: right; font-size: 12px;">↳ #<?php echo $variacion->get_id(); ?></td>
                        <td><input type="text" class="input-moderno sku-input" value="<?php echo esc_attr($variacion->get_sku()); ?>" placeholder="SKU"></td>
                        <td class="nombre-producto" style="padding-left: 20px; font-size: 12px; color: var(--texto-medio);"><?php echo esc_html($variacion->get_name()); ?></td>
                        <td><input type="number" step="0.01" class="input-moderno precio-input" value="<?php echo esc_attr($variacion->get_regular_price()); ?>"></td>
                        <td><input type="number" step="0.01" class="input-moderno rebaja-input" value="<?php echo esc_attr($variacion->get_sale_price()); ?>"></td>
                        <td><input type="number" class="input-moderno stock-input" value="<?php echo esc_attr($stock_actual); ?>" placeholder="Ilimit."></td>
                        <td style="display: flex; align-items: center; gap: 10px;">
                            <button type="button" class="btn-moderno" data-id="<?php echo $variacion->get_id(); ?>">Guardar</button>
                            <span class="mensaje-estado" style="font-weight: 600; font-size: 12px;"></span>
                        </td>
                    </tr>
                    <?php
                }
            }
        } elseif ($producto->is_type('simple')) {
            $stock_actual = $producto->get_manage_stock() ? $producto->get_stock_quantity() : '';
            ?>
            <tr class="fila-simple cat-hijo-<?php echo $cat_id; ?>">
                <td><strong style="color: var(--texto-claro);">#<?php echo $producto->get_id(); ?></strong></td>
                <td><input type="text" class="input-moderno sku-input" value="<?php echo esc_attr($producto->get_sku()); ?>"></td>
                <td class="nombre-producto"><strong style="font-size: 13px; color: var(--texto-oscuro);"><?php echo esc_html($producto->get_name()); ?></strong></td>
                <td><input type="number" step="0.01" class="input-moderno precio-input" value="<?php echo esc_attr($producto->get_regular_price()); ?>"></td>
                <td><input type="number" step="0.01" class="input-moderno rebaja-input" value="<?php echo esc_attr($producto->get_sale_price()); ?>"></td>
                <td><input type="number" class="input-moderno stock-input" value="<?php echo esc_attr($stock_actual); ?>" placeholder="Ilimit."></td>
                <td style="display: flex; align-items: center; gap: 10px;">
                    <button type="button" class="btn-moderno" data-id="<?php echo $producto->get_id(); ?>">Guardar</button>
                    <span class="mensaje-estado" style="font-weight: 600; font-size: 12px;"></span>
                </td>
            </tr>
            <?php
        }
    }
    
    $html = ob_get_clean(); 
    wp_send_json_success($html);
}

/* 4. AJAX: Guardar en Base de Datos */
add_action('wp_ajax_guardar_inventario_rapido', 'guardar_inventario_rapido_ajax');
function guardar_inventario_rapido_ajax() {
    if (!current_user_can('manage_woocommerce')) wp_send_json_error();

    $producto_id = intval($_POST['producto_id']);
    $sku = sanitize_text_field($_POST['sku']);
    $precio = sanitize_text_field($_POST['precio']);
    $rebaja = sanitize_text_field($_POST['rebaja']);
    $stock = sanitize_text_field($_POST['stock']);

    if ($producto_id) {
        $producto = wc_get_product($producto_id);
        if ($producto) {
            $producto->set_sku($sku);
            $producto->set_regular_price($precio);
            
            if ($rebaja !== '') {
                $producto->set_sale_price($rebaja); $producto->set_price($rebaja);
            } else {
                $producto->set_sale_price(''); $producto->set_price($precio);
            }
            
            if ($stock !== '') {
                $producto->set_manage_stock(true);
                $producto->set_stock_quantity($stock);
                $producto->set_stock_status(intval($stock) <= 0 ? 'outofstock' : 'instock');
            } else {
                $producto->set_manage_stock(false);
                $producto->set_stock_quantity('');
                $producto->set_stock_status('instock'); 
            }
            
            $producto->save();
            wp_send_json_success();
        }
    }
    wp_send_json_error();
}
?>