<?php
/**
 * Política (CPT politica-empresa).
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

$bk_others = get_posts( array( 'post_type' => 'politica-empresa', 'post_status' => 'publish', 'posts_per_page' => 20, 'orderby' => 'menu_order title', 'order' => 'ASC' ) );
?>
<div class="bk-wrap bk-doc-page">
	<?php while ( have_posts() ) : the_post(); ?>
		<article class="bk-prose">
			<h1 class="bk-page-title"><?php echo esc_html( trim( wp_strip_all_tags( get_the_title() ) ) ); ?></h1>
			<?php echo bk_policy_text( get_post() ); // phpcs:ignore WordPress.Security.EscapeOutput -- wp_kses_post. ?>
		</article>
	<?php endwhile; ?>
	<?php if ( count( $bk_others ) > 1 ) : ?>
		<nav class="bk-side-nav" aria-label="<?php esc_attr_e( 'Políticas', 'buckler-core' ); ?>">
			<h2><?php esc_html_e( 'Políticas', 'buckler-core' ); ?></h2>
			<ul>
				<?php foreach ( $bk_others as $bk_p ) : ?>
					<li><a href="<?php echo esc_url( get_permalink( $bk_p ) ); ?>"<?php echo (int) $bk_p->ID === get_queried_object_id() ? ' aria-current="page"' : ''; ?>><?php echo esc_html( trim( wp_strip_all_tags( get_the_title( $bk_p ) ) ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>
</div>
