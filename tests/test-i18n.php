<?php
/**
 * Translation tests.
 *
 * @package AIVisibilityDashboard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test code.

/**
 * Bundled translations.
 */
class Test_AIVD_I18n extends AIVD_Test_Case {

	/**
	 * Bundled Spanish messages.
	 *
	 * @return array
	 */
	private function messages() {
		$file = include dirname( __DIR__ ) . '/languages/ai-visibility-dashboard-es_ES.l10n.php';
		return $file['messages'];
	}

	/**
	 * Placeholders of a format string, in a comparable form.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	private static function placeholders( $text ) {
		preg_match_all( '/%(?:(\d+)\$)?[sd]/', $text, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $i => $match ) {
			$out[] = ( isset( $match[1] ) && '' !== $match[1] ? $match[1] : (string) ( $i + 1 ) ) . substr( $match[0], -1 );
		}
		sort( $out );
		return $out;
	}

	public function test_every_source_string_is_translated() {
		$pot = file_get_contents( dirname( __DIR__ ) . '/languages/ai-visibility-dashboard.pot' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		preg_match_all( '/^msgid "(.+)"$/m', $pot, $m );
		$messages = $this->messages();
		$missing  = array();
		foreach ( $m[1] as $id ) {
			$id    = stripcslashes( $id );
			$found = isset( $messages[ $id ] );
			if ( ! $found ) {
				foreach ( array_keys( $messages ) as $key ) {
					if ( 0 === strpos( $key, $id . "\0" ) ) {
						$found = true;
						break;
					}
				}
			}
			if ( ! $found ) {
				$missing[] = $id;
			}
		}
		$this->assertSame( array(), $missing );
	}

	public function test_translations_keep_their_placeholders() {
		foreach ( $this->messages() as $source => $translation ) {
			$sources      = explode( "\0", $source );
			$translations = explode( "\0", $translation );
			$expected     = self::placeholders( end( $sources ) );
			foreach ( $translations as $text ) {
				// A singular form may drop a number placeholder (e.g. "1 consulta").
				$this->assertEmpty( array_diff( self::placeholders( $text ), $expected ), 'Unknown placeholder in: ' . $text );
			}
			$this->assertSame( $expected, self::placeholders( end( $translations ) ), 'Placeholders differ in: ' . $source );
		}
	}

	public function test_spanish_is_loaded_for_spanish_sites() {
		$this->assertTrue( load_textdomain( 'ai-visibility-dashboard', dirname( __DIR__ ) . '/languages/ai-visibility-dashboard-es_ES.mo' ) );
		// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Checks the translation itself.
		$this->assertSame( 'Últimos datos disponibles, de hace %s.', __( 'Last data available, from %s ago.', 'ai-visibility-dashboard' ) );
		unload_textdomain( 'ai-visibility-dashboard' );
	}
}
