<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * String Translations — admin UI + data access.
 *
 * Stores an array of string pairs in the wp_option 'zwh_mlp_strings':
 *   [ ['source' => 'Home', 'he' => 'בית', 'fr' => 'Accueil'], ... ]
 *
 * The frontend class reads this at render time and replaces source strings
 * with their translations inside block HTML output.
 */
class ZWH_MLP_Strings {

	const OPTION_KEY = 'zwh_mlp_strings';
	const PAGE_SLUG  = 'zwh-mlp-strings';

	public function __construct() {
		add_action( 'admin_menu',         array( $this, 'add_menu_page' ) );
		add_action( 'admin_init',         array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_zwh_mlp_ai_translate_string', array( $this, 'ajax_translate_string' ) );
	}

	// -------------------------------------------------------------------
	// Data helpers
	// -------------------------------------------------------------------

	/**
	 * Returns the full strings array.
	 * Each row: ['source' => '...', 'he' => '...', 'fr' => '...', ...]
	 *
	 * @return array
	 */
	public static function get_strings() {
		return (array) get_option( self::OPTION_KEY, array() );
	}

	/**
	 * Get all translations for a specific language as a flat source→translation map.
	 *
	 * @param string $lang ISO 639-1 code.
	 * @return array
	 */
	public static function get_map( $lang ) {
		$map = array();
		foreach ( self::get_strings() as $row ) {
			if ( ! empty( $row['source'] ) && ! empty( $row[ $lang ] ) ) {
				$map[ $row['source'] ] = $row[ $lang ];
			}
		}
		return $map;
	}

	// -------------------------------------------------------------------
	// Admin page
	// -------------------------------------------------------------------

	public function add_menu_page() {
		add_submenu_page(
			'options-general.php',
			'String Translations',
			'String Translations',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) { return; }
		wp_enqueue_script(
			'zwh-mlp-strings',
			ZWH_MLP_URL . 'assets/js/strings.js',
			array( 'jquery' ),
			ZWH_MLP_VERSION,
			true
		);
		wp_localize_script(
			'zwh-mlp-strings',
			'zwh_mlp_strings',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'zwh_mlp_action' ),
				'hasApi'   => ZWH_MLP_Settings::get( 'translate_api', 'none' ) !== 'none',
				'langs'    => ZWH_MLP_Settings::get_active_langs(),
				'langNames'=> self::lang_name_map(),
			)
		);
	}

	private static function lang_name_map() {
		$map   = array();
		$langs = ZWH_MLP_Settings::get_active_langs();
		foreach ( $langs as $code ) {
			$map[ $code ] = ZWH_MLP_Settings::get_lang_name( $code );
		}
		return $map;
	}

	public function handle_save() {
		if ( ! isset( $_POST['zwh_mlp_strings_nonce'] ) ) { return; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zwh_mlp_strings_nonce'] ) ), 'zwh_mlp_save_strings' ) ) { return; }
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		$rows    = isset( $_POST['zwh_strings'] ) ? (array) wp_unslash( $_POST['zwh_strings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized individually below.
		$langs   = ZWH_MLP_Settings::get_active_langs();
		$cleaned = array();

		foreach ( $rows as $row ) {
			$source = sanitize_text_field( isset( $row['source'] ) ? $row['source'] : '' );
			if ( '' === $source ) { continue; }
			$entry = array( 'source' => $source );
			foreach ( $langs as $lang ) {
				$entry[ $lang ] = sanitize_text_field( isset( $row[ $lang ] ) ? $row[ $lang ] : '' );
			}
			$cleaned[] = $entry;
		}

		update_option( self::OPTION_KEY, $cleaned );
		add_settings_error( 'zwh_mlp_strings', 'saved', 'String translations saved.', 'updated' );
	}

	public function render_page() {
		$active_langs = ZWH_MLP_Settings::get_active_langs();
		$default_lang = ZWH_MLP_Settings::get_default_lang();
		$strings      = self::get_strings();
		$has_api      = ZWH_MLP_Settings::get( 'translate_api', 'none' ) !== 'none';
		?>
		<div class="wrap">
			<h1>🔤 String Translations</h1>
			<p>Add any text that appears in your templates, navigation, or theme blocks. The plugin will replace it with the correct translation on the frontend.</p>

			<?php settings_errors( 'zwh_mlp_strings' ); ?>

			<form method="post" id="zwh-strings-form">
				<?php wp_nonce_field( 'zwh_mlp_save_strings', 'zwh_mlp_strings_nonce' ); ?>

				<table class="widefat fixed striped" id="zwh-strings-table">
					<thead>
						<tr>
							<th style="width:28%;">Source (<?php echo esc_html( ZWH_MLP_Settings::get_lang_name( $default_lang ) ); ?>)</th>
							<?php foreach ( $active_langs as $lang ) : if ( $lang === $default_lang ) continue; ?>
								<th><?php echo esc_html( ZWH_MLP_Settings::get_lang_name( $lang ) ); ?> (<?php echo esc_html( $lang ); ?>)</th>
							<?php endforeach; ?>
							<th style="width:60px;"></th>
						</tr>
					</thead>
					<tbody id="zwh-strings-body">
						<?php foreach ( $strings as $i => $row ) : ?>
							<?php $this->render_row( $i, $row, $active_langs, $default_lang, $has_api ); ?>
						<?php endforeach; ?>
						<?php if ( empty( $strings ) ) : ?>
							<?php $this->render_row( 0, array(), $active_langs, $default_lang, $has_api ); ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p>
					<button type="button" id="zwh-add-string" class="button">+ Add string</button>
					<?php submit_button( 'Save all strings', 'primary', 'submit', false, array( 'style' => 'margin-left:8px;' ) ); ?>
				</p>
			</form>

			<hr>
			<h2>How to use</h2>
			<ol>
				<li>Enter a string exactly as it appears in your theme — e.g. a navigation label, button text, or heading.</li>
				<li>Enter the translation for each language.</li>
				<li>Save. The plugin replaces the source text automatically on the frontend when visitors view a translated page.</li>
			</ol>
			<p><strong>Tips:</strong> Strings are case-sensitive. For navigation menus, enter the exact label text. HTML tags are not needed — just the visible text.</p>
		</div>

		<script id="zwh-string-row-template" type="text/html">
			<?php $this->render_row( '__INDEX__', array(), $active_langs, $default_lang, $has_api ); ?>
		</script>
		<?php
	}

	private function render_row( $index, $row, $active_langs, $default_lang, $has_api ) {
		$source = isset( $row['source'] ) ? $row['source'] : '';
		?>
		<tr class="zwh-string-row">
			<td>
				<input type="text" name="zwh_strings[<?php echo esc_attr( $index ); ?>][source]"
					value="<?php echo esc_attr( $source ); ?>"
					placeholder="e.g. Home"
					class="widefat zwh-string-source"
					style="font-weight:600;">
			</td>
			<?php foreach ( $active_langs as $lang ) : if ( $lang === $default_lang ) continue; ?>
				<td style="position:relative;">
					<input type="text" name="zwh_strings[<?php echo esc_attr( $index ); ?>][<?php echo esc_attr( $lang ); ?>]"
						value="<?php echo esc_attr( isset( $row[ $lang ] ) ? $row[ $lang ] : '' ); ?>"
						placeholder="<?php echo esc_attr( ZWH_MLP_Settings::get_lang_name( $lang ) ); ?>"
						class="widefat zwh-string-trans"
						data-lang="<?php echo esc_attr( $lang ); ?>"
						<?php echo ZWH_MLP_Settings::is_rtl( $lang ) ? 'dir="rtl"' : ''; ?>>
					<?php if ( $has_api ) : ?>
						<button type="button" class="zwh-ai-string-btn" title="AI translate"
							data-lang="<?php echo esc_attr( $lang ); ?>"
							style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:14px;padding:0;line-height:1;">✨</button>
					<?php endif; ?>
				</td>
			<?php endforeach; ?>
			<td>
				<button type="button" class="button button-small zwh-remove-string" title="Remove row">&times;</button>
			</td>
		</tr>
		<?php
	}

	// -------------------------------------------------------------------
	// AJAX: AI translate a single string
	// -------------------------------------------------------------------

	public function ajax_translate_string() {
		check_ajax_referer( 'zwh_mlp_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }

		$source      = sanitize_text_field( wp_unslash( isset( $_POST['source'] ) ? $_POST['source'] : '' ) );
		$target_lang = sanitize_text_field( wp_unslash( isset( $_POST['target_lang'] ) ? $_POST['target_lang'] : '' ) );
		$source_lang = ZWH_MLP_Settings::get_default_lang();

		if ( empty( $source ) || empty( $target_lang ) ) { wp_send_json_error( 'Missing params.' ); }

		$api    = new ZWH_MLP_API();
		$result = $api->translate( $source, $source_lang, $target_lang );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'translation' => $result ) );
	}
}
