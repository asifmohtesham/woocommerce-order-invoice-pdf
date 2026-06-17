<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

trait BilingualLabelTrait {

	protected function bilingual_enabled(): bool {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->is_enabled( $this );
	}

	protected function bilingual_secondary( string $slug ): string {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->secondary_label( $slug, $this );
	}

	protected function bilingual_rtl(): bool {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->is_rtl( $this );
	}

	/**
	 * Echo a label, bilingual when enabled. $slug must match get_title_for().
	 */
	public function render_label( string $slug ): void {
		$primary = $this->get_title_for( $slug );
		if ( ! $this->bilingual_enabled() ) {
			echo esc_html( $primary );
			return;
		}
		$secondary = $this->bilingual_secondary( $slug );
		if ( '' === $secondary ) {
			echo esc_html( $primary );
			return;
		}
		$dir = $this->bilingual_rtl() ? 'rtl' : 'ltr';
		printf(
			'<span class="woi-lbl-primary">%s</span><span class="woi-lbl-secondary" dir="%s">%s</span>',
			esc_html( $primary ),
			esc_attr( $dir ),
			esc_html( $secondary )
		);
	}
}
