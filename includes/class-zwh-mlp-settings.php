<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZWH_MLP_Settings {

	const OPTION_KEY = 'zwh_mlp_settings';

	/** Full list of supported languages (ISO 639-1 codes) */
	public static $languages = array(
		'af'    => 'Afrikaans',
		'ar'    => 'Arabic',
		'bg'    => 'Bulgarian',
		'cs'    => 'Czech',
		'da'    => 'Danish',
		'de'    => 'German',
		'el'    => 'Greek',
		'en'    => 'English',
		'es'    => 'Spanish',
		'et'    => 'Estonian',
		'fi'    => 'Finnish',
		'fr'    => 'French',
		'he'    => 'Hebrew',
		'hi'    => 'Hindi',
		'hr'    => 'Croatian',
		'hu'    => 'Hungarian',
		'id'    => 'Indonesian',
		'it'    => 'Italian',
		'ja'    => 'Japanese',
		'ko'    => 'Korean',
		'lt'    => 'Lithuanian',
		'lv'    => 'Latvian',
		'nl'    => 'Dutch',
		'no'    => 'Norwegian',
		'pl'    => 'Polish',
		'pt'    => 'Portuguese',
		'ro'    => 'Romanian',
		'ru'    => 'Russian',
		'sk'    => 'Slovak',
		'sl'    => 'Slovenian',
		'sq'    => 'Albanian',
		'sr'    => 'Serbian',
		'sv'    => 'Swedish',
		'th'    => 'Thai',
		'tr'    => 'Turkish',
		'uk'    => 'Ukrainian',
		'vi'    => 'Vietnamese',
		'zh'    => 'Chinese (Simplified)',
		'zh-TW' => 'Chinese (Traditional)',
	);

	/** Languages that read right-to-left */
	public static $rtl_langs = array( 'ar', 'he', 'fa', 'ur' );

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_' . self::OPTION_KEY, array( $this, 'flush_on_save' ), 10, 2 );
	}

	/**
	 * Flush rewrite rules automatically when active languages or default language change.
	 * Prevents the 404 that happens when a new language is added but the user
	 * forgets to visit Settings → Permalinks to flush manually.
	 */
	public function flush_on_save( $old_value, $new_value ) {
		$old_langs   = isset( $old_value['active_langs'] ) ? (array) $old_value['active_langs'] : array();
		$new_langs   = isset( $new_value['active_langs'] ) ? (array) $new_value['active_langs'] : array();
		$old_default = isset( $old_value['default_lang'] ) ? $old_value['default_lang'] : 'en';
		$new_default = isset( $new_value['default_lang'] ) ? $new_value['default_lang'] : 'en';

		if ( $old_langs === $new_langs && $old_default === $new_default ) {
			return;
		}

		// Re-register rules for the new language set before flushing, because
		// init (where add_rewrite_rules normally runs) already fired with the
		// OLD settings value for this request.
		foreach ( $new_langs as $lang ) {
			if ( $lang === $new_default ) {
				continue;
			}
			$p = preg_quote( $lang, '#' );
			add_rewrite_rule( '^' . $p . '/(.+?)(/[0-9]+)?/?$', 'index.php?zwh_lang=' . $lang . '&pagename=$matches[1]&page=$matches[2]', 'top' );
			add_rewrite_rule( '^' . $p . '/([^/]+)/?$',         'index.php?zwh_lang=' . $lang . '&name=$matches[1]',                       'top' );
			add_rewrite_rule( '^' . $p . '/?$',                 'index.php?zwh_lang=' . $lang,                                              'top' );
		}

		flush_rewrite_rules();
	}

	public function add_menu() {
		add_options_page(
			'ZWH Multilingual',
			'Multilingual',
			'manage_options',
			'zwh-mlp-settings',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( 'zwh_mlp_group', self::OPTION_KEY, array( $this, 'sanitize' ) );
	}

	public function sanitize( $input ) {
		$out      = array();
		$all_codes = array_keys( self::$languages );

		// Default language
		$def = sanitize_text_field( isset( $input['default_lang'] ) ? $input['default_lang'] : 'en' );
		$out['default_lang'] = in_array( $def, $all_codes, true ) ? $def : 'en';

		// Active languages
		$active = is_array( isset( $input['active_langs'] ) ? $input['active_langs'] : null ) ? $input['active_langs'] : array();
		$active = array_filter(
			$active,
			function ( $l ) use ( $all_codes ) {
				return in_array( sanitize_text_field( $l ), $all_codes, true );
			}
		);
		$active = array_values( array_map( 'sanitize_text_field', $active ) );
		if ( ! in_array( $out['default_lang'], $active, true ) ) {
			array_unshift( $active, $out['default_lang'] );
		}
		$out['active_langs'] = $active;

		// Post types to translate
		$pts = is_array( isset( $input['post_types'] ) ? $input['post_types'] : null ) ? $input['post_types'] : array();
		$out['post_types'] = array_filter(
			array_map( 'sanitize_key', $pts ),
			'post_type_exists'
		);
		if ( empty( $out['post_types'] ) ) {
			$out['post_types'] = array( 'post', 'page' );
		}

		// Translation API
		$valid_apis = array( 'none', 'deepl', 'google', 'openai', 'claude', 'gemini' );
		$api = sanitize_text_field( isset( $input['translate_api'] ) ? $input['translate_api'] : 'none' );
		$out['translate_api'] = in_array( $api, $valid_apis, true ) ? $api : 'none';

		// API keys
		$out['deepl_key']  = sanitize_text_field( isset( $input['deepl_key'] ) ? $input['deepl_key'] : '' );
		$out['deepl_free'] = ! empty( $input['deepl_free'] );
		$out['google_key'] = sanitize_text_field( isset( $input['google_key'] ) ? $input['google_key'] : '' );
		$out['openai_key'] = sanitize_text_field( isset( $input['openai_key'] ) ? $input['openai_key'] : '' );
		$out['claude_key'] = sanitize_text_field( isset( $input['claude_key'] ) ? $input['claude_key'] : '' );
		$out['gemini_key'] = sanitize_text_field( isset( $input['gemini_key'] ) ? $input['gemini_key'] : '' );

		return $out;
	}

	public static function get( $key, $default = '' ) {
		static $cache = null;
		if ( null === $cache ) {
			$cache = get_option( self::OPTION_KEY, array() );
		}
		return isset( $cache[ $key ] ) ? $cache[ $key ] : $default;
	}

	public static function get_active_langs() {
		return self::get( 'active_langs', array( 'en' ) );
	}

	public static function get_default_lang() {
		return self::get( 'default_lang', 'en' );
	}

	public static function get_lang_name( $code ) {
		return isset( self::$languages[ $code ] ) ? self::$languages[ $code ] : strtoupper( $code );
	}

	public static function is_rtl( $code ) {
		return in_array( $code, self::$rtl_langs, true );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts         = get_option( self::OPTION_KEY, array() );
		$active_langs = isset( $opts['active_langs'] ) ? $opts['active_langs'] : array( 'en' );
		$default_lang = isset( $opts['default_lang'] ) ? $opts['default_lang'] : 'en';
		$post_types   = isset( $opts['post_types'] ) ? $opts['post_types'] : array( 'post', 'page' );
		$trans_api    = isset( $opts['translate_api'] ) ? $opts['translate_api'] : 'none';

		$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="wrap zwh-mlp-wrap">

			<style>
				/* "The Weathered Chronicle" design system — zivwashere.com */
				@import url('https://fonts.googleapis.com/css2?family=Epilogue:ital,wght@0,400;0,600;0,700;1,700;1,800&display=swap');

				.zwh-mlp-wrap {
					--zwh-paper: #fff8f1; --zwh-ink: #211b0c; --zwh-ink-soft: #50453b;
					--zwh-red: #aa361d; --zwh-red-deep: #7f1802;
					--zwh-blue: #42617d; --zwh-blue-light: #bdddfe;
					--zwh-gold: #daa430; --zwh-cream: #f9edd4; --zwh-cream-low: #fff2da; --zwh-cream-high: #ede1c9;
					--zwh-font: 'Epilogue', -apple-system, "Segoe UI", Roboto, sans-serif;
					max-width: 960px;
					margin: 20px 20px 40px 0;
					padding: 28px 32px 36px;
					background: var(--zwh-paper);
					border: 2px solid var(--zwh-ink);
					box-shadow: 6px 6px 0 var(--zwh-ink);
					font-family: var(--zwh-font);
					color: var(--zwh-ink);
				}

				/* Product header */
				.zwh-product-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; flex-wrap: wrap; padding-bottom: 20px; margin-bottom: 24px; border-bottom: 2px solid var(--zwh-ink); }
				.zwh-product-brand { display: flex; align-items: center; gap: 16px; }
				.zwh-product-mark { display: flex; align-items: center; justify-content: center; width: 52px; height: 52px; flex-shrink: 0; background: var(--zwh-blue); border: 2px solid var(--zwh-ink); box-shadow: 3px 3px 0 var(--zwh-ink); color: #fff; font-size: 24px; }
				.zwh-product-title { margin: 0; padding: 0; font-size: 1.7rem; font-weight: 800; font-style: italic; line-height: 1.1; text-transform: uppercase; color: var(--zwh-ink); }
				.zwh-product-tagline { margin: 4px 0 0; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--zwh-ink-soft); }
				.zwh-product-meta { display: flex; align-items: center; gap: 10px; }
				.zwh-version-badge { display: inline-block; padding: 3px 9px; background: var(--zwh-gold); border: 2px solid var(--zwh-ink); font-size: 11px; font-weight: 700; letter-spacing: .05em; }
				.zwh-brand-link { display: inline-block; padding: 3px 9px; background: var(--zwh-ink); border: 2px solid var(--zwh-ink); color: var(--zwh-paper) !important; font-size: 11px; font-weight: 700; font-style: italic; letter-spacing: .05em; text-decoration: none; transition: transform .12s ease, box-shadow .12s ease; }
				.zwh-brand-link:hover, .zwh-brand-link:focus { transform: translate(-2px,-2px); box-shadow: 2px 2px 0 var(--zwh-red); color: #fff !important; }

				/* Sections — hand-cut paper panels */
				.zwh-mlp-section { background: var(--zwh-cream-low); border: 2px solid var(--zwh-ink); border-radius: 0; padding: 20px 24px; margin-bottom: 28px; box-shadow: 4px 4px 0 var(--zwh-ink); }
				.zwh-mlp-section h2 { margin: 0 0 16px; font-size: 13px; font-weight: 800; font-style: italic; text-transform: uppercase; letter-spacing: .04em; color: var(--zwh-ink); border-bottom: 2px solid var(--zwh-ink); padding-bottom: 10px; }
				.zwh-mlp-section .form-table th { width: 160px; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--zwh-ink); font-family: var(--zwh-font); }
				.zwh-mlp-wrap .description { color: var(--zwh-ink-soft); }
				.zwh-mlp-wrap a { color: var(--zwh-blue); font-weight: 600; }
				.zwh-mlp-wrap code { background: var(--zwh-cream-high); color: var(--zwh-red-deep); }

				/* Inputs */
				.zwh-mlp-wrap select, .zwh-mlp-wrap input[type="text"], .zwh-mlp-wrap input[type="password"] { font-family: var(--zwh-font); background: #fff; border: 2px solid var(--zwh-ink); border-radius: 0; color: var(--zwh-ink); box-shadow: none; }
				.zwh-mlp-wrap select:focus, .zwh-mlp-wrap input[type="text"]:focus, .zwh-mlp-wrap input[type="password"]:focus { border-color: var(--zwh-red); background: var(--zwh-paper); outline: none; box-shadow: none; }
				.zwh-mlp-wrap input[type="checkbox"] { border: 2px solid var(--zwh-ink); border-radius: 0; }
				.zwh-mlp-wrap input[type="checkbox"]:checked { background: var(--zwh-red); border-color: var(--zwh-ink); }

				/* Language chips */
				.zwh-mlp-lang-list { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
				.zwh-mlp-lang-item { display: flex; align-items: center; gap: 6px; background: var(--zwh-cream); border: 2px solid var(--zwh-ink); border-radius: 0; padding: 5px 10px; font-size: 13px; font-weight: 600; }
				.zwh-mlp-lang-item.is-default { background: var(--zwh-blue-light); }
				.zwh-mlp-add-lang { display: flex; gap: 8px; align-items: center; margin-top: 10px; }
				.zwh-api-group { display: none; }
				.zwh-api-group.active { display: block; }

				/* Buttons */
				.zwh-mlp-wrap .button { font-family: var(--zwh-font); font-weight: 700; font-style: italic; text-transform: uppercase; letter-spacing: .03em; border: 2px solid var(--zwh-ink); border-radius: 0; background: var(--zwh-cream); color: var(--zwh-ink); box-shadow: none; transition: transform .12s ease, box-shadow .12s ease; }
				.zwh-mlp-wrap .button:hover, .zwh-mlp-wrap .button:focus { background: var(--zwh-cream-high); color: var(--zwh-ink); border-color: var(--zwh-ink); transform: translate(-2px,-2px); box-shadow: 3px 3px 0 var(--zwh-ink); }
				.zwh-mlp-wrap .button-primary { background: var(--zwh-red) !important; color: #fff !important; border: 2px solid var(--zwh-ink) !important; text-shadow: none; }
				.zwh-mlp-wrap .button-primary:hover, .zwh-mlp-wrap .button-primary:focus { background: var(--zwh-red-deep) !important; color: #fff !important; }

				/* Layout: form + sidebar */
				.zwh-layout { display: flex; gap: 32px; align-items: flex-start; }
				.zwh-form-col { flex: 1; min-width: 0; }
				.zwh-sidebar-col { width: 250px; flex-shrink: 0; }
				.zwh-sidebar-box { background: var(--zwh-cream); border: 2px solid var(--zwh-ink); padding: 16px 18px; box-shadow: 4px 4px 0 var(--zwh-ink); }
				.zwh-sidebar-box h3 { margin: 0 0 14px; font-size: 12px; font-weight: 800; font-style: italic; text-transform: uppercase; letter-spacing: .05em; color: var(--zwh-ink); border-bottom: 2px solid var(--zwh-ink); padding-bottom: 10px; }
				.zwh-provider-status { list-style: none; margin: 0; padding: 0; }
				.zwh-provider-status li { display: flex; align-items: center; gap: 10px; padding: 8px 6px; font-size: 13px; }
				.zwh-provider-status li:nth-child(even) { background: var(--zwh-cream-low); }
				.zwh-status-dot { width: 10px; height: 10px; border-radius: 0; border: 2px solid var(--zwh-ink); flex-shrink: 0; }
				.zwh-status-dot.has-key { background: var(--zwh-blue); }
				.zwh-status-dot.no-key { background: var(--zwh-cream-high); }
				.zwh-provider-name { flex: 1; font-weight: 600; }
				.zwh-provider-name span { display: block; font-size: 11px; font-weight: 400; color: var(--zwh-ink-soft); }
				.zwh-key-badge { font-size: 10px; padding: 2px 7px; border-radius: 0; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; border: 2px solid var(--zwh-ink); }
				.zwh-key-badge.saved { background: var(--zwh-blue-light); color: var(--zwh-ink); }
				.zwh-key-badge.missing { background: var(--zwh-paper); color: var(--zwh-ink-soft); border-color: var(--zwh-ink-soft); }
				.zwh-active-badge { font-size: 10px; background: var(--zwh-red); color: #fff; border: 2px solid var(--zwh-ink); border-radius: 0; padding: 1px 6px; font-weight: 700; font-style: italic; text-transform: uppercase; letter-spacing: .04em; }

				@media screen and (max-width: 960px) {
					.zwh-layout { flex-direction: column; }
					.zwh-sidebar-col { width: 100%; }
				}
				@media screen and (max-width: 782px) {
					.zwh-mlp-wrap { padding: 20px 16px 28px; margin-right: 10px; }
				}
			</style>

			<header class="zwh-product-header">
				<div class="zwh-product-brand">
					<span class="zwh-product-mark" aria-hidden="true">&#127760;</span>
					<div>
						<h1 class="zwh-product-title">ZWH Multilingual</h1>
						<p class="zwh-product-tagline">Write once. Publish in every language.</p>
					</div>
				</div>
				<div class="zwh-product-meta">
					<span class="zwh-version-badge">v<?php echo esc_html( defined( 'ZWH_MLP_VERSION' ) ? ZWH_MLP_VERSION : '1.0.0' ); ?></span>
					<a class="zwh-brand-link" href="https://zivwashere.com" target="_blank" rel="noopener">zivwashere.com</a>
				</div>
			</header>

			<div class="zwh-layout">

			<div class="zwh-form-col">
			<form method="post" action="options.php">
				<?php settings_fields( 'zwh_mlp_group' ); ?>

				<!-- Languages -->
				<div class="zwh-mlp-section">
					<h2>Languages</h2>
					<table class="form-table"><tbody>
						<tr>
							<th scope="row">Default language</th>
							<td>
								<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_lang]" id="zwh-default-lang">
									<?php foreach ( self::$languages as $code => $name ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $default_lang, $code ); ?>>
											<?php echo esc_html( $name . ' (' . $code . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">Your site's original language. Uses normal URLs (<code>/post-slug</code>). All other languages get a prefix (<code>/fr/post-slug</code>).</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Active languages</th>
							<td>
								<div id="zwh-lang-list" class="zwh-mlp-lang-list">
									<?php foreach ( $active_langs as $code ) : ?>
										<span class="zwh-mlp-lang-item <?php echo $code === $default_lang ? 'is-default' : ''; ?>" data-code="<?php echo esc_attr( $code ); ?>">
											<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[active_langs][]" value="<?php echo esc_attr( $code ); ?>">
											<?php echo esc_html( self::get_lang_name( $code ) . ' (' . $code . ')' ); ?>
											<?php if ( $code === $default_lang ) : ?>
												<span style="font-size:10px;color:#42617d;font-weight:700;font-style:italic;text-transform:uppercase;">default</span>
											<?php else : ?>
												<button type="button" class="zwh-remove-lang" data-code="<?php echo esc_attr( $code ); ?>" style="background:none;border:none;cursor:pointer;color:#aa361d;font-weight:700;padding:0;line-height:1;font-size:13px;" aria-label="Remove">&#10005;</button>
											<?php endif; ?>
										</span>
									<?php endforeach; ?>
								</div>
								<div class="zwh-mlp-add-lang">
									<select id="zwh-add-lang-select">
										<option value="">&#8212; Add a language &#8212;</option>
										<?php foreach ( self::$languages as $code => $name ) : ?>
											<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name . ' (' . $code . ')' ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="button" class="button" id="zwh-add-lang-btn">+ Add</button>
								</div>
							</td>
						</tr>
					</tbody></table>
				</div>

				<!-- Post types -->
				<div class="zwh-mlp-section">
					<h2>Translatable post types</h2>
					<div style="display:flex;flex-wrap:wrap;gap:14px;">
						<?php foreach ( $all_post_types as $pt ) : ?>
							<label style="display:flex;align-items:center;gap:6px;font-size:13px;">
								<input type="checkbox"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[post_types][]"
									value="<?php echo esc_attr( $pt->name ); ?>"
									<?php checked( in_array( $pt->name, $post_types, true ) ); ?>>
								<?php echo esc_html( $pt->label ); ?>
								<code style="font-size:11px;color:#6b7280;"><?php echo esc_html( $pt->name ); ?></code>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Translation API -->
				<div class="zwh-mlp-section">
					<h2>Translation API (for auto-translate)</h2>
					<table class="form-table"><tbody>
						<tr>
							<th scope="row">Provider</th>
							<td>
								<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[translate_api]" id="zwh-api-select">
									<option value="none"   <?php selected( $trans_api, 'none' ); ?>>None (manual only)</option>
									<option value="deepl"  <?php selected( $trans_api, 'deepl' ); ?>>DeepL</option>
									<option value="google" <?php selected( $trans_api, 'google' ); ?>>Google Translate</option>
									<option value="openai" <?php selected( $trans_api, 'openai' ); ?>>OpenAI (GPT-4o)</option>
									<option value="claude" <?php selected( $trans_api, 'claude' ); ?>>Claude (Anthropic)</option>
									<option value="gemini" <?php selected( $trans_api, 'gemini' ); ?>>Google Gemini</option>
								</select>
							</td>
						</tr>
					</tbody></table>

					<div id="zwh-api-deepl" class="zwh-api-group <?php echo 'deepl' === $trans_api ? 'active' : ''; ?>">
						<table class="form-table"><tbody>
							<tr>
								<th scope="row">API key</th>
								<td>
									<input type="password" class="regular-text" autocomplete="new-password"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[deepl_key]"
										value="<?php echo esc_attr( isset( $opts['deepl_key'] ) ? $opts['deepl_key'] : '' ); ?>">
									<p class="description">Free tier: 500,000 chars/month at <a href="https://www.deepl.com/pro-api" target="_blank">deepl.com/pro-api</a></p>
								</td>
							</tr>
							<tr>
								<th scope="row">Plan</th>
								<td>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[deepl_free]" value="1"
											<?php checked( isset( $opts['deepl_free'] ) ? $opts['deepl_free'] : true ); ?>>
										Free tier (api-free.deepl.com) — uncheck for paid plan
									</label>
								</td>
							</tr>
						</tbody></table>
					</div>

					<div id="zwh-api-google" class="zwh-api-group <?php echo 'google' === $trans_api ? 'active' : ''; ?>">
						<table class="form-table"><tbody>
							<tr>
								<th scope="row">API key</th>
								<td>
									<input type="password" class="regular-text" autocomplete="new-password"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[google_key]"
										value="<?php echo esc_attr( isset( $opts['google_key'] ) ? $opts['google_key'] : '' ); ?>">
									<p class="description">Enable Cloud Translation API at <a href="https://console.cloud.google.com/" target="_blank">console.cloud.google.com</a></p>
								</td>
							</tr>
						</tbody></table>
					</div>

					<div id="zwh-api-openai" class="zwh-api-group <?php echo 'openai' === $trans_api ? 'active' : ''; ?>">
						<table class="form-table"><tbody>
							<tr>
								<th scope="row">API key</th>
								<td>
									<input type="password" class="regular-text" autocomplete="new-password"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openai_key]"
										value="<?php echo esc_attr( isset( $opts['openai_key'] ) ? $opts['openai_key'] : '' ); ?>">
									<p class="description">Uses GPT-4o — <a href="https://platform.openai.com" target="_blank">platform.openai.com</a></p>
								</td>
							</tr>
						</tbody></table>
					</div>

					<div id="zwh-api-claude" class="zwh-api-group <?php echo 'claude' === $trans_api ? 'active' : ''; ?>">
						<table class="form-table"><tbody>
							<tr>
								<th scope="row">API key</th>
								<td>
									<input type="password" class="regular-text" autocomplete="new-password"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[claude_key]"
										value="<?php echo esc_attr( isset( $opts['claude_key'] ) ? $opts['claude_key'] : '' ); ?>">
									<p class="description">Uses Claude Sonnet — <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a></p>
								</td>
							</tr>
						</tbody></table>
					</div>

					<div id="zwh-api-gemini" class="zwh-api-group <?php echo 'gemini' === $trans_api ? 'active' : ''; ?>">
						<table class="form-table"><tbody>
							<tr>
								<th scope="row">API key</th>
								<td>
									<input type="password" class="regular-text" autocomplete="new-password"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[gemini_key]"
										value="<?php echo esc_attr( isset( $opts['gemini_key'] ) ? $opts['gemini_key'] : '' ); ?>">
									<p class="description">Free tier at <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com</a></p>
								</td>
							</tr>
						</tbody></table>
					</div>
				</div>

				<?php submit_button( 'Save settings' ); ?>
				<p class="description">
					After saving, go to
					<a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">Settings &rarr; Permalinks</a>
					and click Save to flush rewrite rules so language URLs work correctly.
				</p>
			</form>
			</div><!-- /.zwh-form-col -->

			<!-- Sidebar: saved API keys -->
			<div class="zwh-sidebar-col">
				<div class="zwh-sidebar-box">
					<h3>Saved API keys</h3>
					<ul class="zwh-provider-status">
						<?php
						$zwh_providers = array(
							'deepl'  => array( 'DeepL',            'Machine translation', 'deepl_key' ),
							'google' => array( 'Google Translate', 'Cloud Translation',   'google_key' ),
							'openai' => array( 'OpenAI',           'GPT-4o',              'openai_key' ),
							'claude' => array( 'Claude',           'Anthropic',           'claude_key' ),
							'gemini' => array( 'Gemini',           'Google AI',           'gemini_key' ),
						);
						foreach ( $zwh_providers as $pid => $pinfo ) :
							$p_has_key   = ! empty( $opts[ $pinfo[2] ] );
							$p_is_active = ( $trans_api === $pid );
						?>
						<li>
							<span class="zwh-status-dot <?php echo $p_has_key ? 'has-key' : 'no-key'; ?>"></span>
							<span class="zwh-provider-name">
								<?php echo esc_html( $pinfo[0] ); ?>
								<span><?php echo esc_html( $pinfo[1] ); ?></span>
							</span>
							<?php if ( $p_is_active ) : ?>
								<span class="zwh-active-badge">Active</span>
							<?php else : ?>
								<span class="zwh-key-badge <?php echo $p_has_key ? 'saved' : 'missing'; ?>">
									<?php echo $p_has_key ? 'Saved' : 'No key'; ?>
								</span>
							<?php endif; ?>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div><!-- /.zwh-sidebar-col -->

			</div><!-- /.zwh-layout -->
		</div>

		<script>
		(function () {
			var apiSelect = document.getElementById( 'zwh-api-select' );
			var langList  = document.getElementById( 'zwh-lang-list' );
			var addBtn    = document.getElementById( 'zwh-add-lang-btn' );
			var addSel    = document.getElementById( 'zwh-add-lang-select' );
			var optKey    = '<?php echo esc_js( self::OPTION_KEY ); ?>';

			if ( apiSelect ) {
				apiSelect.addEventListener( 'change', function () {
					document.querySelectorAll( '.zwh-api-group' ).forEach( function ( el ) {
						el.classList.remove( 'active' );
					} );
					var t = document.getElementById( 'zwh-api-' + this.value );
					if ( t ) { t.classList.add( 'active' ); }
				} );
			}

			if ( addBtn && addSel && langList ) {
				addBtn.addEventListener( 'click', function () {
					var code = addSel.value;
					if ( ! code ) { return; }
					if ( langList.querySelector( '[data-code="' + code + '"]' ) ) { addSel.value = ''; return; }
					var label = addSel.options[ addSel.selectedIndex ].text;
					var item  = document.createElement( 'span' );
					item.className    = 'zwh-mlp-lang-item';
					item.dataset.code = code;
					item.innerHTML    = '<input type="hidden" name="' + optKey + '[active_langs][]" value="' + code + '">'
						+ label
						+ ' <button type="button" class="zwh-remove-lang" data-code="' + code + '" style="background:none;border:none;cursor:pointer;color:#aa361d;font-weight:700;padding:0;line-height:1;font-size:13px;" aria-label="Remove">&#10005;</button>';
					langList.appendChild( item );
					addSel.value = '';
				} );
			}

			document.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'zwh-remove-lang' ) && langList ) {
					var item = langList.querySelector( '[data-code="' + e.target.dataset.code + '"]' );
					if ( item ) { item.remove(); }
				}
			} );
		}());
		</script>
		<?php
	}
}
