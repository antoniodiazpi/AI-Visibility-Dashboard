<?php
/**
 * Dashboard widget.
 *
 * @package AIVisibilityDashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "AI visibility" widget on the WordPress Dashboard.
 *
 * Server-side only: no JavaScript, no external assets. Charts are inline SVG
 * and HTML, with native tooltips and the figures always printed as text.
 */
class AIVD_Widget {

	const ID = 'aivd_widget';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	/**
	 * Adds the widget for users allowed to see it.
	 */
	public static function register() {
		if ( ! current_user_can( AIVD_Settings::widget_capability() ) ) {
			return;
		}
		wp_add_dashboard_widget( self::ID, __( 'AI visibility', 'ai-visibility-dashboard' ), array( __CLASS__, 'render' ) );
	}

	/**
	 * Reporting window for this view: a valid ?aivd_range= or the default.
	 *
	 * @return int
	 */
	private static function current_range() {
		// Read-only view switch, no state changes: a nonce is not needed.
		return isset( $_GET['aivd_range'] ) ? AIVD_Settings::range( absint( $_GET['aivd_range'] ) ) : AIVD_Settings::range(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Widget body.
	 *
	 * Never waits for Mencoro when there is something to show: out-of-date data
	 * is shown with its age while an update runs in the background.
	 */
	public static function render() {
		echo '<div class="aivd">';

		if ( ! AIVD_Settings::is_configured() ) {
			self::render_setup();
			echo '</div>';
			return;
		}

		$range = self::current_range();
		$state = AIVD_Data::state( $range );
		$data  = $state['payload'];

		if ( ! is_array( $data ) ) {
			self::render_header( null, $range );
			self::render_flash();
			self::render_no_data( $state, $range );
			echo '</div>';
			return;
		}

		self::render_header( $data, $range );
		self::render_flash();
		self::render_status( $state, $range );

		if ( ! empty( $data['no_ai_engine'] ) ) {
			$message = ! empty( $data['no_selected'] )
				? __( 'This project does not track any of the AI engines chosen in the settings. Tick other engines in Settings → AI Visibility.', 'ai-visibility-dashboard' )
				: __( 'This Mencoro project does not track any AI engine yet (ChatGPT, Claude, Perplexity or Google AI). Add them to its tracked queries to see data here.', 'ai-visibility-dashboard' );
			echo '<div class="aivd-state"><p>' . esc_html( $message ) . '</p></div>';
			self::render_footer( $data, $range );
			echo '</div>';
			return;
		}

		if ( null === $data['kpis']['share_of_voice']['value'] && null === $data['kpis']['mention_rate']['value'] ) {
			echo '<div class="aivd-state"><p>' . esc_html__( 'No AI answers were checked in this period yet, so there is nothing to measure. Mencoro checks most queries once a day: come back tomorrow or choose a longer period.', 'ai-visibility-dashboard' ) . '</p></div>';
			self::render_footer( $data, $range );
			echo '</div>';
			return;
		}

		if ( '' !== $data['dirty_since'] ) {
			echo '<p class="aivd-note">' . esc_html__( 'Mencoro is recalculating part of this period: figures may still change slightly.', 'ai-visibility-dashboard' ) . '</p>';
		}

		self::render_kpis( $data );
		self::render_sample( $data );
		self::render_series( $data );
		self::render_competitors( $data );
		self::render_sentiment( $data );
		self::render_sources( $data );
		self::render_movers( $data );
		self::render_glossary();
		self::render_footer( $data, $range );

		echo '</div>';
	}

	/**
	 * Nothing to show yet: either the first update is running or it failed.
	 *
	 * @param array $state AIVD_Data::state().
	 * @param int   $range Window in days.
	 */
	private static function render_no_data( array $state, $range ) {
		if ( 'pending' === $state['status'] ) {
			echo '<div class="aivd-state" role="status"><p>' . esc_html__( 'Your data is on its way from Mencoro.', 'ai-visibility-dashboard' ) . '</p><p>';
			echo '<a href="' . esc_url( add_query_arg( 'aivd_range', $range, admin_url( 'index.php' ) ) . '#' . self::ID ) . '">' . esc_html__( 'Reload in a few seconds', 'ai-visibility-dashboard' ) . '</a>';
			echo '</p></div>';
			return;
		}

		$message = $state['error'] instanceof WP_Error ? $state['error']->get_error_message() : '';
		echo '<div class="aivd-state aivd-state--error" role="alert"><p>' . esc_html( $message ) . '</p>';
		if ( $state['retry_at'] > time() ) {
			echo '<p class="aivd-muted">' . esc_html(
				sprintf(
					/* translators: %s: time until the next attempt, e.g. "5 mins". */
					__( 'Next automatic attempt in %s.', 'ai-visibility-dashboard' ),
					human_time_diff( time(), $state['retry_at'] )
				)
			) . '</p>';
		}
		echo '<p>';
		self::render_refresh_link( $range, __( 'Try again', 'ai-visibility-dashboard' ) );
		if ( current_user_can( 'manage_options' ) ) {
			echo ' · <a href="' . esc_url( AIVD_Settings::page_url() ) . '">' . esc_html__( 'Settings', 'ai-visibility-dashboard' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Out-of-date or incomplete data, said plainly above the figures.
	 *
	 * @param array $state AIVD_Data::state().
	 * @param int   $range Window in days.
	 */
	private static function render_status( array $state, $range ) {
		if ( 'stale' === $state['status'] ) {
			$parts = array(
				sprintf(
					/* translators: %s: age of the data, e.g. "8 hours". */
					__( 'Last data available, from %s ago.', 'ai-visibility-dashboard' ),
					human_time_diff( $state['fetched_at'], time() )
				),
			);
			if ( $state['error'] instanceof WP_Error && $state['retry_at'] > time() ) {
				$parts[] = $state['error']->get_error_message();
				$parts[] = sprintf(
					/* translators: %s: time until the next attempt, e.g. "5 mins". */
					__( 'Next automatic attempt in %s.', 'ai-visibility-dashboard' ),
					human_time_diff( time(), $state['retry_at'] )
				);
			} elseif ( $state['cron_late'] ) {
				$parts[] = __( 'Automatic updates do not seem to run on this site (WP-Cron). Use Refresh to update now.', 'ai-visibility-dashboard' );
			} elseif ( $state['refreshing'] ) {
				$parts[] = __( 'An update is running in the background: reload the Dashboard in a minute to see it.', 'ai-visibility-dashboard' );
			}
			echo '<div class="aivd-status aivd-status--stale" role="status"><p>' . esc_html( implode( ' ', $parts ) ) . ' ';
			self::render_refresh_link( $range, __( 'Refresh now', 'ai-visibility-dashboard' ) );
			echo '</p></div>';
			return;
		}

		if ( $state['partial'] ) {
			echo '<div class="aivd-status" role="status"><p>' . esc_html__( 'Some sections could not be loaded this time. They will be retried on the next update.', 'ai-visibility-dashboard' ) . '</p></div>';
		}
	}

	/**
	 * One-time message left by the Refresh link.
	 */
	private static function render_flash() {
		$key     = 'aivd_widget_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( $key );
			echo '<div class="aivd-status" role="status"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * What the figures are based on, so they are read as a sample.
	 *
	 * @param array $data Payload.
	 */
	private static function render_sample( array $data ) {
		$labels = AIVD_Admin::engine_labels();
		$names  = array();
		foreach ( $data['engines'] as $engine ) {
			$names[] = isset( $labels[ $engine ] ) ? $labels[ $engine ] : $engine;
		}
		$text = sprintf(
			/* translators: 1: number of tracked queries, 2: list of AI engines, 3: first day, 4: last day. */
			_n(
				'Based on %1$s tracked query on %2$s, %3$s – %4$s (UTC). The figures describe how these engines answer your tracked queries, not every search.',
				'Based on %1$s tracked queries on %2$s, %3$s – %4$s (UTC). The figures describe how these engines answer your tracked queries, not every search.',
				(int) $data['counts']['queries'],
				'ai-visibility-dashboard'
			),
			number_format_i18n( (int) $data['counts']['queries'] ),
			implode( ', ', $names ),
			self::date_label( $data['date_from'] ),
			self::date_label( $data['date_to'] )
		);
		echo '<p class="aivd-sample">' . esc_html( $text ) . '</p>';
	}

	/**
	 * First-run message.
	 */
	private static function render_setup() {
		echo '<div class="aivd-state aivd-state--setup">';
		echo '<p class="aivd-setup-title">' . esc_html__( 'How do ChatGPT, Claude, Perplexity and Google AI talk about your brand?', 'ai-visibility-dashboard' ) . '</p>';
		echo '<p>' . esc_html__( 'Connect your Mencoro project to see your Share of Voice, mention rate, sentiment, competitors and the sources AI answers cite, right here.', 'ai-visibility-dashboard' ) . '</p>';
		if ( current_user_can( 'manage_options' ) ) {
			echo '<p><a class="button button-primary" href="' . esc_url( AIVD_Settings::page_url() ) . '">' . esc_html__( 'Connect Mencoro', 'ai-visibility-dashboard' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'Ask an administrator to connect it in Settings → AI Visibility.', 'ai-visibility-dashboard' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Project name, engines and period switch.
	 *
	 * @param array|null $data  Payload.
	 * @param int        $range Window in days.
	 */
	private static function render_header( $data, $range ) {
		echo '<div class="aivd-head">';
		echo '<div class="aivd-head__title">';
		if ( is_array( $data ) && '' !== $data['project']['name'] ) {
			echo '<strong>' . esc_html( $data['project']['name'] ) . '</strong>';
		}
		if ( is_array( $data ) && ! empty( $data['engines'] ) ) {
			$labels = AIVD_Admin::engine_labels();
			$names  = array();
			foreach ( $data['engines'] as $engine ) {
				$names[] = isset( $labels[ $engine ] ) ? $labels[ $engine ] : $engine;
			}
			echo '<span class="aivd-muted">' . esc_html( implode( ' · ', $names ) ) . '</span>';
		}
		echo '</div>';

		echo '<nav class="aivd-range" aria-label="' . esc_attr__( 'Period', 'ai-visibility-dashboard' ) . '">';
		foreach ( AIVD_Settings::RANGES as $days ) {
			$url = add_query_arg( 'aivd_range', $days, admin_url( 'index.php' ) ) . '#' . self::ID;
			/* translators: %d: number of days, shown on a compact period switch, e.g. "28 d". */
			$label = sprintf( __( '%d d', 'ai-visibility-dashboard' ), $days );
			/* translators: %d: number of days. */
			$title = sprintf( _n( 'Last %d day', 'Last %d days', $days, 'ai-visibility-dashboard' ), $days );
			if ( $days === $range ) {
				echo '<span class="aivd-range__item is-current" aria-current="true" title="' . esc_attr( $title ) . '">' . esc_html( $label ) . '</span>';
			} else {
				echo '<a class="aivd-range__item" href="' . esc_url( $url ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $label ) . '</a>';
			}
		}
		echo '</nav>';
		echo '</div>';
	}

	/**
	 * Four headline tiles.
	 *
	 * @param array $data Payload.
	 */
	private static function render_kpis( array $data ) {
		$k = $data['kpis'];

		$tiles = array(
			array(
				'label' => __( 'Share of Voice', 'ai-visibility-dashboard' ),
				'value' => self::fmt( $k['share_of_voice']['value'], 1, '%' ),
				'trend' => $k['share_of_voice']['trend'],
				'unit'  => 'pp',
				'dec'   => 1,
				'hint'  => __( 'Your share of the brand mentions in AI answers, against the competitors you track.', 'ai-visibility-dashboard' ),
			),
			array(
				'label' => __( 'Mention rate', 'ai-visibility-dashboard' ),
				'value' => self::fmt( $k['mention_rate']['value'], 0, '%' ),
				'trend' => $k['mention_rate']['trend'],
				'unit'  => 'pp',
				'dec'   => 0,
				/* translators: 1: queries with a mention, 2: tracked queries. */
				'hint'  => sprintf( __( 'Share of AI checks where your brand appears. %1$d of %2$d tracked queries mention you.', 'ai-visibility-dashboard' ), $data['counts']['with_mention'], $data['counts']['queries'] ),
			),
			array(
				'label' => __( 'Average position', 'ai-visibility-dashboard' ),
				'value' => null === $k['position']['value'] ? '—' : '#' . number_format_i18n( $k['position']['value'], 1 ),
				'trend' => $k['position']['trend'],
				'unit'  => '',
				'dec'   => 1,
				'hint'  => __( 'Where your brand appears inside the answer, on average. Lower is better.', 'ai-visibility-dashboard' ),
			),
			array(
				'label' => __( 'Positivity', 'ai-visibility-dashboard' ),
				'value' => null === $k['positivity']['value'] ? '—' : number_format_i18n( $k['positivity']['value'], 0 ) . '<small>/100</small>',
				'trend' => $k['positivity']['trend'],
				'unit'  => '',
				'dec'   => 0,
				'hint'  => __( 'Tone of the mentions, from 0 (negative) to 100 (positive).', 'ai-visibility-dashboard' ),
			),
		);

		echo '<div class="aivd-kpis">';
		foreach ( $tiles as $tile ) {
			echo '<div class="aivd-kpi" title="' . esc_attr( $tile['hint'] ) . '">';
			echo '<span class="aivd-kpi__label">' . esc_html( $tile['label'] ) . '</span>';
			echo '<span class="aivd-kpi__value">' . wp_kses( $tile['value'], array( 'small' => array() ) ) . '</span>';
			self::render_delta( $tile['trend'], $tile['unit'], $tile['dec'] );
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Change against the previous period. Positive always means improved.
	 *
	 * @param float|null $trend Trend.
	 * @param string     $unit  'pp' for percentage points, '' otherwise.
	 * @param int        $dec   Decimals.
	 */
	private static function render_delta( $trend, $unit, $dec ) {
		if ( null === $trend ) {
			echo '<span class="aivd-delta aivd-delta--none">' . esc_html__( 'no previous period', 'ai-visibility-dashboard' ) . '</span>';
			return;
		}

		$abs    = abs( (float) $trend );
		$amount = number_format_i18n( $abs, $dec ) . ( 'pp' === $unit ? ' ' . __( 'pp', 'ai-visibility-dashboard' ) : '' );

		if ( round( $abs, $dec ) == 0 ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Comparing a rounded float with zero.
			echo '<span class="aivd-delta aivd-delta--flat"><span aria-hidden="true">= </span>' . esc_html__( 'no change', 'ai-visibility-dashboard' ) . '</span>';
			return;
		}

		if ( $trend > 0 ) {
			/* translators: %s: amount, e.g. "2.1 pp". */
			$sr = sprintf( __( 'Improved by %s against the previous period', 'ai-visibility-dashboard' ), $amount );
			echo '<span class="aivd-delta aivd-delta--up" title="' . esc_attr( $sr ) . '"><span aria-hidden="true">▲ </span>' . esc_html( $amount ) . '<span class="screen-reader-text"> ' . esc_html( $sr ) . '</span></span>';
		} else {
			/* translators: %s: amount, e.g. "2.1 pp". */
			$sr = sprintf( __( 'Worse by %s against the previous period', 'ai-visibility-dashboard' ), $amount );
			echo '<span class="aivd-delta aivd-delta--down" title="' . esc_attr( $sr ) . '"><span aria-hidden="true">▼ </span>' . esc_html( $amount ) . '<span class="screen-reader-text"> ' . esc_html( $sr ) . '</span></span>';
		}
	}

	/**
	 * Share of Voice over time, as a small area chart.
	 *
	 * @param array $data Payload.
	 */
	private static function render_series( array $data ) {
		echo '<section class="aivd-section">';
		echo '<h3 class="aivd-h">' . esc_html__( 'Share of Voice over time', 'ai-visibility-dashboard' ) . '</h3>';

		if ( is_string( $data['series'] ) ) {
			echo '<p class="aivd-muted">' . esc_html( $data['series'] ) . '</p></section>';
			return;
		}

		$points = array();
		foreach ( $data['series'] as $i => $point ) {
			if ( null !== $point['value'] ) {
				$points[ $i ] = $point;
			}
		}

		if ( count( $points ) < 2 ) {
			echo '<p class="aivd-muted">' . esc_html__( 'Not enough days with data to draw a trend yet.', 'ai-visibility-dashboard' ) . '</p></section>';
			return;
		}

		$width  = 320;
		$height = 72;
		$pad_x  = 6;
		$pad_y  = 8;
		$count  = count( $data['series'] );
		$max    = max( wp_list_pluck( $points, 'value' ) );
		$top    = max( 1, $max * 1.15 );

		$x = static function ( $i ) use ( $count, $width, $pad_x ) {
			return $count > 1 ? $pad_x + ( $width - 2 * $pad_x ) * $i / ( $count - 1 ) : $width / 2;
		};
		$y = static function ( $v ) use ( $top, $height, $pad_y ) {
			return $height - $pad_y - ( $height - 2 * $pad_y ) * $v / $top;
		};

		// Split into runs so a day without data leaves a gap instead of a false line.
		$runs = array();
		$run  = array();
		foreach ( $data['series'] as $i => $point ) {
			if ( null === $point['value'] ) {
				if ( $run ) {
					$runs[] = $run;
				}
				$run = array();
				continue;
			}
			$run[] = array( $x( $i ), $y( $point['value'] ) );
		}
		if ( $run ) {
			$runs[] = $run;
		}

		$first  = reset( $points );
		$last   = end( $points );
		$last_i = key( $points );

		$summary = sprintf(
			/* translators: 1: first value, 2: last value, 3: highest value. */
			__( 'Share of Voice went from %1$s to %2$s in this period, with a high of %3$s.', 'ai-visibility-dashboard' ),
			self::fmt( $first['value'], 1, '%' ),
			self::fmt( $last['value'], 1, '%' ),
			self::fmt( $max, 1, '%' )
		);

		echo '<svg class="aivd-spark" viewBox="0 0 ' . (int) $width . ' ' . (int) $height . '" role="img" aria-label="' . esc_attr( $summary ) . '">';
		echo '<line class="aivd-spark__base" x1="0" x2="' . (int) $width . '" y1="' . esc_attr( self::n( $y( 0 ) ) ) . '" y2="' . esc_attr( self::n( $y( 0 ) ) ) . '" />';
		foreach ( $runs as $segment ) {
			$line = array();
			foreach ( $segment as $p ) {
				$line[] = self::n( $p[0] ) . ',' . self::n( $p[1] );
			}
			if ( count( $segment ) > 1 ) {
				$first_x = $segment[0][0];
				$last_x  = $segment[ count( $segment ) - 1 ][0];
				$area    = self::n( $first_x ) . ',' . self::n( $y( 0 ) ) . ' ' . implode( ' ', $line ) . ' ' . self::n( $last_x ) . ',' . self::n( $y( 0 ) );
				echo '<polygon class="aivd-spark__area" points="' . esc_attr( $area ) . '" />';
				echo '<polyline class="aivd-spark__line" points="' . esc_attr( implode( ' ', $line ) ) . '" />';
			}
		}
		// Invisible, larger hit targets give every bucket a native tooltip.
		foreach ( $points as $i => $point ) {
			echo '<circle class="aivd-spark__hit" cx="' . esc_attr( self::n( $x( $i ) ) ) . '" cy="' . esc_attr( self::n( $y( $point['value'] ) ) ) . '" r="7"><title>' . esc_html( self::date_label( $point['date'] ) . ': ' . self::fmt( $point['value'], 1, '%' ) ) . '</title></circle>';
		}
		echo '<circle class="aivd-spark__end" cx="' . esc_attr( self::n( $x( $last_i ) ) ) . '" cy="' . esc_attr( self::n( $y( $last['value'] ) ) ) . '" r="4" />';
		echo '</svg>';

		echo '<div class="aivd-spark__axis"><span>' . esc_html( self::date_label( $data['series'][0]['date'] ) ) . '</span><span>' . esc_html( self::date_label( $data['series'][ $count - 1 ]['date'] ) . ' · ' . self::fmt( $last['value'], 1, '%' ) ) . '</span></div>';
		echo '</section>';
	}

	/**
	 * Share of Voice against each competitor.
	 *
	 * @param array $data Payload.
	 */
	private static function render_competitors( array $data ) {
		if ( count( $data['competitors'] ) < 2 ) {
			return;
		}
		$max = max( 1, max( wp_list_pluck( $data['competitors'], 'sov' ) ) );

		echo '<section class="aivd-section">';
		echo '<h3 class="aivd-h">' . esc_html__( 'You and your competitors', 'ai-visibility-dashboard' ) . '</h3>';
		echo '<ul class="aivd-bars">';
		foreach ( $data['competitors'] as $row ) {
			$value = self::fmt( $row['sov'], 1, '%' );
			$width = max( 1, round( 100 * $row['sov'] / $max, 1 ) );
			echo '<li class="aivd-bar' . ( $row['is_brand'] ? ' is-brand' : '' ) . '" title="' . esc_attr( $row['name'] . ': ' . $value ) . '">';
			echo '<span class="aivd-bar__name">' . esc_html( $row['name'] );
			if ( $row['is_brand'] ) {
				echo ' <span class="aivd-tag">' . esc_html__( 'you', 'ai-visibility-dashboard' ) . '</span>';
			}
			echo '</span>';
			echo '<span class="aivd-bar__track"><span class="aivd-bar__fill" style="width:' . esc_attr( $width ) . '%"></span></span>';
			echo '<span class="aivd-bar__value">' . esc_html( $value ) . '</span>';
			echo '</li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Positive, neutral and negative split of the mentions.
	 *
	 * @param array $data Payload.
	 */
	private static function render_sentiment( array $data ) {
		$c     = $data['counts'];
		$total = $c['positive'] + $c['neutral'] + $c['negative'];
		if ( $total < 1 ) {
			return;
		}

		$parts = array(
			'positive' => array( $c['positive'], __( 'Positive', 'ai-visibility-dashboard' ) ),
			'neutral'  => array( $c['neutral'], __( 'Neutral', 'ai-visibility-dashboard' ) ),
			'negative' => array( $c['negative'], __( 'Negative', 'ai-visibility-dashboard' ) ),
		);

		echo '<section class="aivd-section">';
		echo '<h3 class="aivd-h">' . esc_html__( 'Tone of the mentions', 'ai-visibility-dashboard' ) . '</h3>';
		echo '<div class="aivd-stack" aria-hidden="true">';
		foreach ( $parts as $key => $part ) {
			if ( $part[0] > 0 ) {
				$pct = 100 * $part[0] / $total;
				echo '<span class="aivd-stack__seg aivd-stack__seg--' . esc_attr( $key ) . '" style="flex-grow:' . esc_attr( self::n( $pct ) ) . '" title="' . esc_attr( $part[1] . ': ' . number_format_i18n( $part[0] ) . ' (' . number_format_i18n( $pct, 0 ) . '%)' ) . '"></span>';
			}
		}
		echo '</div><ul class="aivd-legend">';
		foreach ( $parts as $key => $part ) {
			$pct = 100 * $part[0] / $total;
			echo '<li><span class="aivd-swatch aivd-swatch--' . esc_attr( $key ) . '" aria-hidden="true"></span>' . esc_html( $part[1] ) . ' <strong>' . esc_html( number_format_i18n( $pct, 0 ) ) . '%</strong> <span class="aivd-muted">(' . esc_html( number_format_i18n( $part[0] ) ) . ')</span></li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Domains the AI answers cite most.
	 *
	 * @param array $data Payload.
	 */
	private static function render_sources( array $data ) {
		echo '<section class="aivd-section">';
		echo '<h3 class="aivd-h">' . esc_html__( 'Sources the AI answers cite', 'ai-visibility-dashboard' ) . '</h3>';

		if ( is_string( $data['sources'] ) ) {
			echo '<p class="aivd-muted">' . esc_html( $data['sources'] ) . '</p></section>';
			return;
		}
		if ( empty( $data['sources'] ) ) {
			echo '<p class="aivd-muted">' . esc_html__( 'No cited sources in this period.', 'ai-visibility-dashboard' ) . '</p></section>';
			return;
		}

		$shown = count( $data['sources'] );
		$total = isset( $data['sources_total'] ) ? (int) $data['sources_total'] : 0;
		if ( $total > $shown ) {
			echo '<p class="aivd-caption">' . esc_html(
				sprintf(
					/* translators: 1: sources listed, 2: all sources cited in the period. */
					__( 'The %1$s most cited of %2$s sources in this period.', 'ai-visibility-dashboard' ),
					number_format_i18n( $shown ),
					number_format_i18n( $total )
				)
			) . '</p>';
		}

		$own_listed = false;
		echo '<ol class="aivd-list">';
		foreach ( $data['sources'] as $row ) {
			$own_listed = $own_listed || $row['is_own'];
			echo '<li class="aivd-list__row' . ( $row['is_own'] ? ' is-own' : '' ) . '">';
			echo '<span class="aivd-list__main">' . esc_html( $row['domain'] );
			if ( $row['is_own'] ) {
				echo ' <span class="aivd-tag">' . esc_html__( 'your site', 'ai-visibility-dashboard' ) . '</span>';
			}
			echo '</span>';
			/* translators: %s: number of citations. */
			echo '<span class="aivd-list__value">' . esc_html( sprintf( _n( '%s citation', '%s citations', $row['citations'], 'ai-visibility-dashboard' ), number_format_i18n( $row['citations'] ) ) ) . '</span>';
			echo '</li>';
		}
		echo '</ol>';
		if ( ! $own_listed ) {
			echo '<p class="aivd-note">' . esc_html(
				sprintf(
					/* translators: %s: number of sources listed. */
					__( 'Your site is not in this list, which only shows the top %s. That does not mean it is never cited. Look at the pages cited and whether they cover topics where your content adds value.', 'ai-visibility-dashboard' ),
					number_format_i18n( $shown )
				)
			) . '</p>';
		}
		echo '</section>';
	}

	/**
	 * Tracked queries with the largest gains and losses.
	 *
	 * @param array $data Payload.
	 */
	private static function render_movers( array $data ) {
		$up   = $data['movers_up'];
		$down = $data['movers_down'];
		if ( ( is_array( $up ) && empty( $up ) ) && ( is_array( $down ) && empty( $down ) ) ) {
			return;
		}

		echo '<section class="aivd-section">';
		echo '<h3 class="aivd-h">' . esc_html__( 'Queries that moved most', 'ai-visibility-dashboard' ) . '</h3>';
		echo '<p class="aivd-caption">' . esc_html__( 'Share of Voice against the previous period. Small changes can be noise: AI answers vary from day to day.', 'ai-visibility-dashboard' ) . '</p>';
		echo '<div class="aivd-movers">';
		self::render_mover_list( __( 'Gaining', 'ai-visibility-dashboard' ), $up );
		self::render_mover_list( __( 'Losing', 'ai-visibility-dashboard' ), $down );
		echo '</div></section>';
	}

	/**
	 * One list of movers.
	 *
	 * @param string       $title Heading.
	 * @param array|string $rows  Rows or an error message.
	 */
	private static function render_mover_list( $title, $rows ) {
		$labels = AIVD_Admin::engine_labels();
		echo '<div class="aivd-movers__col"><p class="aivd-movers__title">' . esc_html( $title ) . '</p>';
		if ( is_string( $rows ) ) {
			echo '<p class="aivd-muted">' . esc_html( $rows ) . '</p></div>';
			return;
		}
		if ( empty( $rows ) ) {
			echo '<p class="aivd-muted">' . esc_html__( 'None in this period.', 'ai-visibility-dashboard' ) . '</p></div>';
			return;
		}
		echo '<ul class="aivd-list">';
		foreach ( $rows as $row ) {
			$engine = isset( $labels[ $row['engine'] ] ) ? $labels[ $row['engine'] ] : $row['engine'];
			$meta   = trim( $engine . ( '' !== $row['country'] ? ' · ' . strtoupper( $row['country'] ) : '' ) );
			echo '<li class="aivd-list__row">';
			echo '<span class="aivd-list__main"><span class="aivd-query">' . esc_html( $row['query'] ) . '</span><span class="aivd-muted">' . esc_html( $meta ) . '</span></span>';
			self::render_delta( $row['trend'], 'pp', 1 );
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Short definitions, collapsed by default.
	 */
	private static function render_glossary() {
		echo '<details class="aivd-glossary"><summary>' . esc_html__( 'What do these figures mean?', 'ai-visibility-dashboard' ) . '</summary><dl>';
		$items = array(
			__( 'Share of Voice', 'ai-visibility-dashboard' ) => __( 'Your share of the brand mentions in AI answers, against the competitors you track in Mencoro. Higher is better.', 'ai-visibility-dashboard' ),
			__( 'Mention rate', 'ai-visibility-dashboard' ) => __( 'Out of every AI answer checked, how many mention your brand. Higher is better. Mencoro calls it Coverage.', 'ai-visibility-dashboard' ),
			__( 'Average position', 'ai-visibility-dashboard' ) => __( 'Where your brand appears inside the answer when it is mentioned. 1 means first. Lower is better.', 'ai-visibility-dashboard' ),
			__( 'Positivity', 'ai-visibility-dashboard' ) => __( 'Tone of the mentions, from 0 (all negative) to 100 (all positive); 50 means they balance out. Mencoro calls it Favorability.', 'ai-visibility-dashboard' ),
			__( 'pp', 'ai-visibility-dashboard' )         => __( 'Percentage points: the change of a percentage against the previous period of the same length.', 'ai-visibility-dashboard' ),
			__( 'Sample', 'ai-visibility-dashboard' )     => __( 'Every figure comes from the queries, countries and engines you track in Mencoro. A mention is not a click, a visit or a sale, and a change is a signal to look into, not proof of its cause.', 'ai-visibility-dashboard' ),
		);
		foreach ( $items as $term => $definition ) {
			echo '<dt>' . esc_html( $term ) . '</dt><dd>' . esc_html( $definition ) . '</dd>';
		}
		echo '</dl></details>';
	}

	/**
	 * Source, freshness and actions.
	 *
	 * @param array $data  Payload.
	 * @param int   $range Window in days.
	 */
	private static function render_footer( array $data, $range ) {
		echo '<div class="aivd-foot"><span>';
		printf(
			/* translators: 1: link to Mencoro, 2: time since the last update, e.g. "2 hours". */
			esc_html__( 'Data from %1$s · updated %2$s ago', 'ai-visibility-dashboard' ),
			'<a href="https://mencoro.com/" target="_blank" rel="noopener noreferrer">Mencoro</a>',
			esc_html( human_time_diff( (int) $data['fetched_at'], time() ) )
		);
		echo '</span><span>';
		self::render_refresh_link( $range, __( 'Refresh', 'ai-visibility-dashboard' ) );
		if ( current_user_can( 'manage_options' ) ) {
			echo ' · <a href="' . esc_url( AIVD_Settings::page_url() ) . '">' . esc_html__( 'Settings', 'ai-visibility-dashboard' ) . '</a>';
		}
		echo '</span></div>';
	}

	/**
	 * Link that clears the cache.
	 *
	 * @param int    $range Window in days.
	 * @param string $label Link text.
	 */
	private static function render_refresh_link( $range, $label ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'aivd_refresh',
					'aivd_range' => $range,
				),
				admin_url( 'admin-post.php' )
			),
			'aivd_refresh'
		);
		echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Formats a nullable number.
	 *
	 * @param float|null $value    Value.
	 * @param int        $decimals Decimals.
	 * @param string     $suffix   Suffix.
	 * @return string
	 */
	private static function fmt( $value, $decimals, $suffix = '' ) {
		return null === $value ? '—' : number_format_i18n( (float) $value, $decimals ) . $suffix;
	}

	/**
	 * Locale-independent number for SVG attributes.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function n( $value ) {
		return number_format( (float) $value, 1, '.', '' );
	}

	/**
	 * Short localized date.
	 *
	 * @param string $raw Y-m-d or ISO date.
	 * @return string
	 */
	private static function date_label( $raw ) {
		$time = strtotime( (string) $raw );
		return $time ? wp_date( 'j M', $time, new DateTimeZone( 'UTC' ) ) : (string) $raw;
	}
}
