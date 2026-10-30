<?php
function agregar_iconos_personalizados_herramientas() {
    wp_add_dashboard_widget(
        'iconos_personalizados_widget_herramientas', // ID
        'HERRAMIENTAS Y SOPORTE UFOWEB.CO',              // Título
        'contenido_de_mis_iconos_herramientas'
    );
}
add_action('wp_dashboard_setup', 'agregar_iconos_personalizados_herramientas');

function contenido_de_mis_iconos_herramientas() {
    ?>
    <style>
        .ufo-dashboard-wrapper {
            background-color: #1b1c1d;
            padding: 25px;
            border-radius: 8px;
            color: #ffffff;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }

        .ufo-section-title {
            color: #d0e210 !important; /* El !important evita que WordPress oculte el color */
            font-size: 15px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 0 0 15px 0 !important;
            padding-bottom: 5px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .ufo-icon-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 30px;
        }

        .ufo-icon-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            width: 105px;
            padding: 15px 10px;
            background-color: #e4e4e4; /* Fondo gris claro para que resalten los iconos negros */
            border: 2px solid transparent;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .ufo-icon-item:hover {
            transform: translateY(-4px);
            background-color: #ffffff; /* Se aclara a blanco al pasar el cursor */
            border-color: #d0e210;
        }

        .ufo-icon-item img {
            width: 45px;
            height: 45px;
            object-fit: contain;
            margin-bottom: 12px;
            transition: transform 0.3s ease;
        }

        .ufo-icon-item:hover img {
            transform: scale(1.1);
        }

        .ufo-icon-item span {
            color: #1b1c1d; /* Texto oscuro para contrastar con el fondo gris */
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            line-height: 1.3;
            transition: color 0.3s ease;
        }

        .ufo-info-box {
            background-color: rgba(0, 0, 0, 0.3);
            border-left: 4px solid #d0e210;
            padding: 15px 20px;
            border-radius: 4px;
            margin-bottom: 30px;
        }

        .ufo-info-box ul {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .ufo-info-box li {
            font-size: 13px;
            color: #cccccc;
            margin-bottom: 8px;
            position: relative;
            padding-left: 15px;
        }

        .ufo-info-box li::before {
            content: "•";
            color: #d0e210;
            position: absolute;
            left: 0;
            font-weight: bold;
        }

        .ufo-info-box li:last-child {
            margin-bottom: 0;
        }

        .ufo-info-action {
            display: block;
            margin-top: 15px;
            font-size: 13px;
            color: #ffffff;
        }

        .ufo-info-action a {
            color: #d0e210;
            text-decoration: none;
            font-weight: bold;
        }

        .ufo-info-action a:hover {
            text-decoration: underline;
        }
    </style>

    <div class="ufo-dashboard-wrapper">
        
        <h3 class="ufo-section-title">Herramientas Web</h3>
        <div class="ufo-icon-grid">
            <a href="https://compresorimagenes.ufoweb.co/" class="ufo-icon-item" target="_blank">
                <img src="https://filedn.com/lin6NsbiCfi8woDha4XJweb/ICONOS/IMAGENES%20(1).png" alt="Compresor Imágenes">
                <span>Compresor<br>Imágenes</span>
            </a>

            <a href="https://comprimirpdf.ufoweb.co/" class="ufo-icon-item" target="_blank">
                <img src="https://filedn.com/lin6NsbiCfi8woDha4XJweb/ICONOS/PDF%20ICO%20(1).png" alt="Compresor PDF">
                <span>Compresor<br>PDF</span>
            </a>
            
            <a href="https://codigosqr.ufoweb.co/" class="ufo-icon-item" target="_blank">
                <img src="https://filedn.com/lin6NsbiCfi8woDha4XJweb/ICONOS/QR%20(1).png" alt="Generador QR">
                <span>Generador<br>QR</span>
            </a>
            
            <a href="https://linkchat.ufoweb.co/" class="ufo-icon-item" target="_blank">
                <img src="https://filedn.com/lin6NsbiCfi8woDha4XJweb/ICONOS/whatsapp%20busi%20(1).png" alt="Generador Link WS">
                <span>Generador<br>Link WS</span>
            </a>
        </div>

        <div class="ufo-info-box">
            <ul>
                <li>Las imágenes deben subirse de máximo 2.000px x 2.000px.</li>
                <li>Comprimir las imágenes para no ralentizar el Sitio Web.</li>
                <li>Subir en formato JPG o PNG, el Sitio Web las convierte automáticamente en formato Webp.</li>
                <li>Si tiene dudas, comuníquese con Soporte.</li>
            </ul>
            <span class="ufo-info-action">
                Puede comprimir las imágenes con nuestra herramienta: <a href="https://compresorimagenes.ufoweb.co/" target="_blank">IR AL COMPRESOR</a>
            </span>
        </div>

        <h3 class="ufo-section-title">Soporte y Ayuda</h3>
        <div class="ufo-icon-grid" style="margin-bottom: 0;">
            <a href="https://u.pcloud.link/publink/show?code=kZ9IbA5ZJ7ge74cXvth8xYN60Mjsfj4snInk" class="ufo-icon-item" target="_blank">
                <img src="https://filedn.com/lin6NsbiCfi8woDha4XJweb/ICONOS/TUTORIALES.png" alt="Tutoriales">
                <span>Tutoriales</span>
            </a>

            <a href="https://wa.me/573192539966" class="ufo-icon-item" target="_blank">
                <img src="https://filedn.com/lin6NsbiCfi8woDha4XJweb/ICONOS/WHATSAPP.png" alt="Soporte WhatsApp">
                <span>Soporte</span>
            </a>
            
            <a href="https://go.climbo.com/ufoweb" class="ufo-icon-item" target="_blank">
                <img src="https://filedn.com/lin6NsbiCfi8woDha4XJweb/ICONOS/calificanos.png" alt="Calificanos">
                <span>¡Califícanos!</span>
            </a>
        </div>

    </div>
    <?php
}