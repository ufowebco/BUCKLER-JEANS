<script>
jQuery(document).ready(function($) {
    // Verificar si existe el campo de cantidad y no existen ya los botones
    if ($('.quantity input.qty').length && !$('.quantity .minus').length) {
        
        // Añadir botones
        $('.quantity').prepend('<span class="minus">-</span>');
        $('.quantity').append('<span class="plus">+</span>');

        // Lógica de suma y resta
        $(document).on('click', '.plus, .minus', function() {
            var $qty = $(this).closest('.quantity').find('.qty');
            var currentVal = parseFloat($qty.val());
            var max = parseFloat($qty.attr('max'));
            var min = parseFloat($qty.attr('min'));
            var step = $qty.attr('step');

            if (!currentVal || currentVal === '' || currentVal === 'NaN') currentVal = 0;
            if (max === '' || max === 'NaN') max = '';
            if (min === '' || min === 'NaN') min = 0;
            if (step === 'any' || step === '' || step === undefined || parseFloat(step) === 'NaN') step = 1;

            if ($(this).is('.plus')) {
                if (max && (currentVal >= max)) {
                    $qty.val(max);
                } else {
                    $qty.val((currentVal + parseFloat(step))).trigger('change');
                }
            } else {
                if (min && (currentVal <= min)) {
                    $qty.val(min);
                } else if (currentVal > 0) {
                    $qty.val((currentVal - parseFloat(step))).trigger('change');
                }
            }
        });
    }
});
</script>