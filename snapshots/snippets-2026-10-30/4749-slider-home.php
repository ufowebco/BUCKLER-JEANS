<?php
add_shortcode('slider_banners_opciones', 'render_slider_banners_opciones_func');
function render_slider_banners_opciones_func() {
    // Verificamos si ACF está activo y la función get_field existe (para páginas de opciones se usa 'option')
    if (!function_exists('get_field')) {
        return '<p>ACF no está activo.</p>';
    }

    // Comprobamos si el campo repetidor tiene elementos
    if (!have_rows('banners_pagina_inicio', 'option')) {
        return '';
    }

    ob_start();
    ?>
    <div class="ufo-fade-slider-container">
        <!-- Slider para PC y Tablets -->
        <div class="ufo-fade-slider ufo-desktop-slider">
            <?php 
            $i = 0;
            while (have_rows('banners_pagina_inicio', 'option')): the_row();
                $imagen_pc = get_sub_field('banner_pc');
                if ($imagen_pc): 
                    $active_class = ($i === 0) ? 'active' : '';
                    ?>
                    <div class="ufo-slide <?php echo $active_class; ?>">
                        <img src="<?php echo esc_url($imagen_pc['url']); ?>" alt="<?php echo esc_attr($imagen_pc['alt']); ?>" class="ufo-slider-img">
                    </div>
                    <?php 
                    $i++;
                endif;
            endwhile; 
            ?>
        </div>

        <!-- Slider para Móviles -->
        <div class="ufo-fade-slider ufo-mobile-slider">
            <?php 
            $j = 0;
            while (have_rows('banners_pagina_inicio', 'option')): the_row();
                $imagen_movil = get_sub_field('banner_moviles');
                if ($imagen_movil): 
                    $active_class_m = ($j === 0) ? 'active' : '';
                    ?>
                    <div class="ufo-slide <?php echo $active_class_m; ?>">
                        <img src="<?php echo esc_url($imagen_movil['url']); ?>" alt="<?php echo esc_attr($imagen_movil['alt']); ?>" class="ufo-slider-img">
                    </div>
                    <?php 
                    $j++;
                endif;
            endwhile; 
            ?>
        </div>
    </div>

    <style>
        .ufo-fade-slider-container {
            position: relative;
            width: 100%;
            overflow: hidden;
        }
        .ufo-fade-slider {
            position: relative;
            width: 100%;
        }
        .ufo-slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            opacity: 0;
            visibility: hidden;
            transition: opacity 1s ease-in-out, visibility 1s ease-in-out;
            z-index: 1;
        }
        .ufo-slide.active {
            position: relative; /* Mantiene la altura del contenedor basada en la primera imagen activa */
            opacity: 1;
            visibility: visible;
            z-index: 2;
        }
        .ufo-slider-img {
            width: 100%;
            height: auto;
            display: block;
        }

        /* Control de visibilidad según pantalla */
        .ufo-mobile-slider {
            display: none;
        }
        @media screen and (max-width: 768px) {
            .ufo-desktop-slider {
                display: none;
            }
            .ufo-mobile-slider {
                display: block;
            }
        }
    </style>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const sliders = document.querySelectorAll('.ufo-fade-slider');
            
            sliders.forEach(slider => {
                const slides = slider.querySelectorAll('.ufo-slide');
                if (slides.length <= 1) return; // Si solo hay una imagen, no hace falta rotar
                
                let currentIndex = 0;
                
                setInterval(() => {
                    slides[currentIndex].classList.remove('active');
                    currentIndex = (currentIndex + 1) % slides.length;
                    slides[currentIndex].classList.add('active');
                }, 4000); // Cambia de imagen cada 4 segundos (ajústalo a tu gusto)
            });
        });
    </script>
    <?php
    return ob_get_clean();
}