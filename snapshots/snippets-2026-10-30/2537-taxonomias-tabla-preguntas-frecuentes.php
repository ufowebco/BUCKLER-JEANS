<?php
// 1. Crear la columna en el listado del Post Type 'pregunta-frecuente'
add_filter( 'manage_pregunta-frecuente_posts_columns', 'crear_columna_categoria_pf' );
function crear_columna_categoria_pf( $columns ) {
    // Agregamos la nueva columna. Puedes cambiar 'Categoría' por el título que quieras ver.
    $columns['tax_categoria_pf'] = 'Categoría';
    
    return $columns;
}

// 2. Rellenar la columna con los datos correspondientes a cada pregunta
add_action( 'manage_pregunta-frecuente_posts_custom_column', 'llenar_columna_categoria_pf', 10, 2 );
function llenar_columna_categoria_pf( $column, $post_id ) {
    // Verificamos que estemos en la columna correcta
    if ( 'tax_categoria_pf' === $column ) {
        
        // Extraemos los términos de la taxonomía. 
        // Genera automáticamente los enlaces para filtrar si haces clic en ellos.
        $terminos = get_the_term_list( $post_id, 'caregoria-pf', '', ', ', '' );
        
        // Si hay términos asignados, los imprimimos. Si no, mostramos un guion.
        if ( ! is_wp_error( $terminos ) && ! empty( $terminos ) ) {
            echo $terminos;
        } else {
            echo '<span aria-hidden="true">—</span>';
        }
    }
}