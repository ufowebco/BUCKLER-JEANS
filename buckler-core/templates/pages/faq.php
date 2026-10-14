<?php
/**
 * Preguntas frecuentes (CPT pregunta-frecuente) con datos estructurados FAQPage.
 *
 * @package BucklerCore
 */

defined( 'ABSPATH' ) || exit;

$bk_groups = bk_faq_groups();
$bk_schema = array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array() );
?>
<div class="bk-wrap bk-faq">
	<?php bk_route_crumbs(); ?>
	<h1 class="bk-page-title"><?php esc_html_e( 'Preguntas frecuentes', 'buckler-core' ); ?></h1>
	<p class="bk-lead">
		<?php esc_html_e( '¿No encuentras tu respuesta?', 'buckler-core' ); ?>
		<a href="<?php echo esc_url( bk_whatsapp_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Escríbenos por WhatsApp', 'buckler-core' ); ?></a>
	</p>
	<?php if ( count( $bk_groups ) > 1 ) : ?>
		<nav class="bk-chips" aria-label="<?php esc_attr_e( 'Temas', 'buckler-core' ); ?>">
			<?php foreach ( $bk_groups as $bk_g ) : ?>
				<a href="#faq-<?php echo esc_attr( $bk_g['slug'] ); ?>"><?php echo esc_html( $bk_g['name'] ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
	<?php foreach ( $bk_groups as $bk_g ) : ?>
		<section id="faq-<?php echo esc_attr( $bk_g['slug'] ); ?>" class="bk-faq__group">
			<h2><?php echo esc_html( $bk_g['name'] ); ?></h2>
			<?php foreach ( $bk_g['items'] as $bk_i ) : ?>
				<?php $bk_schema['mainEntity'][] = array( '@type' => 'Question', 'name' => wp_strip_all_tags( $bk_i['q'] ), 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( $bk_i['a'] ) ) ); ?>
				<details class="bk-faq__item">
					<summary><?php echo esc_html( wp_strip_all_tags( $bk_i['q'] ) ); ?></summary>
					<div class="bk-prose"><?php echo $bk_i['a']; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_kses_post. ?></div>
				</details>
			<?php endforeach; ?>
		</section>
	<?php endforeach; ?>
	<?php if ( $bk_schema['mainEntity'] ) : ?>
		<script type="application/ld+json"><?php echo wp_json_encode( $bk_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
	<?php endif; ?>
</div>
