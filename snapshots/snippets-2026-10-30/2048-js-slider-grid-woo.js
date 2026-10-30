(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var flecha = e.target.closest('.woo-prev, .woo-next');
        if (!flecha) return;

        // La tarjeta del producto es un <a>; sin esto el clic navega.
        e.preventDefault();
        e.stopPropagation();

        var contenedor = flecha.closest('.woo-loop-galeria-contenedor');
        var slider = contenedor ? contenedor.querySelector('.woo-loop-slider') : null;
        if (!slider) return;

        var ancho = slider.clientWidth;
        var maximo = slider.scrollWidth - ancho;
        var haciaAdelante = flecha.classList.contains('woo-next');
        var destino;

        if (haciaAdelante) {
            // Si ya está en la última foto, vuelve a la primera (circular)
            destino = slider.scrollLeft >= maximo - 5 ? 0 : slider.scrollLeft + ancho;
        } else {
            // Si está en la primera, salta a la última
            destino = slider.scrollLeft <= 5 ? maximo : slider.scrollLeft - ancho;
        }

        slider.scrollTo({ left: destino, behavior: 'smooth' });
    }, true); // fase de captura + delegación: sobrevive al filtrado AJAX
})();
