<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: meta box, post-list language column, AJAX handlers.
 */
class ZWH_MLP_Admin {

	/**
	 * Set to true while wp_insert_post() is running inside ajax_create_translation().
	 * Used by ZWH_MLP_Frontend::allow_duplicate_slug_for_translation() to skip the
	 * wp_unique_post_slug uniqueness check so translated posts can share the same slug
	 * as the original (they're distinguished by the /lang/ URL prefix).
	 */
	private static $creating_translation = false;

	public static function is_creating_translation() {
		return self::$creating_translation;
	}

	public function __construct() {
		add_action( 'add_meta_boxes',        array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'save_post',             array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'wp_ajax_zwh_mlp_create_translation',   array( $this, 'ajax_create_translation' ) );
		add_action( 'wp_ajax_zwh_mlp_translate_content',    array( $this, 'ajax_translate_content' ) );
		add_action( 'init', array( $this, 'register_columns' ) );
	}

	public function register_columns() {
		$post_types = ZWH_MLP_Settings::get( 'post_types', array( 'post', 'page' ) );
		foreach ( $post_types as $pt ) {
			add_filter( 'manage_' . $pt . '_posts_columns',       array( $this, 'add_lang_column' ) );
			add_action( 'manage_' . $pt . '_posts_custom_column', array( $this, 'render_lang_column' ), 10, 2 );
			add_filter( $pt . '_row_actions', array( $this, 'add_translate_row_action' ), 10, 2 );
		}
		add_action( 'restrict_manage_posts', array( $this, 'render_language_filter' ) );
		add_action( 'parse_query',           array( $this, 'apply_language_filter' ) );
		add_action( 'admin_footer',          array( $this, 'render_translate_modal' ) );
	}

	public function add_meta_box() {
		$post_types = ZWH_MLP_Settings::get( 'post_types', array( 'post', 'page' ) );
		foreach ( $post_types as $pt ) {
			add_meta_box(
				'zwh_mlp_translations',
				'&#127760; Translations',
				array( $this, 'render_meta_box' ),
				$pt,
				'side',
				'high'
			);
		}
	}

	public function enqueue( $hook ) {
		$allowed = array( 'post.php', 'post-new.php', 'edit.php' );
		if ( ! in_array( $hook, $allowed, true ) ) {
			return;
		}
		wp_enqueue_style( 'zwh-mlp-admin', ZWH_MLP_URL . 'assets/css/admin.css', array(), ZWH_MLP_VERSION );
		wp_enqueue_script( 'zwh-mlp-admin', ZWH_MLP_URL . 'assets/js/admin.js', array( 'jquery' ), ZWH_MLP_VERSION, true );
		wp_localize_script(
			'zwh-mlp-admin',
			'zwh_mlp',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'zwh_mlp_action' ),
				'hasAutoTranslate' => ZWH_MLP_Settings::get( 'translate_api', 'none' ) !== 'none',
			)
		);
	}

	// -------------------------------------------------------------------
	// Meta box
	// -------------------------------------------------------------------

	public function render_meta_box( $post ) {
		$active_langs = ZWH_MLP_Settings::get_active_langs();
		$default_lang = ZWH_MLP_Settings::get_default_lang();
		$post_lang    = get_post_meta( $post->ID, ZWH_MLP_META_LANG, true );
		if ( ! $post_lang ) { $post_lang = $default_lang; }
		$group_id = get_post_meta( $post->ID, ZWH_MLP_META_GROUP, true );
		$has_api  = ZWH_MLP_Settings::get( 'translate_api', 'none' ) !== 'none';

		// Build translation map: lang_code => post_id
		$translations = array( $post_lang => $post->ID );
		if ( $group_id ) {
			$group_posts = get_posts(
				array(
					'post_type'   => $post->post_type,
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => ZWH_MLP_META_GROUP,
							'value' => $group_id,
						),
					),
				)
			);
			foreach ( $group_posts as $pid ) {
				$lang = get_post_meta( $pid, ZWH_MLP_META_LANG, true );
				if ( ! $lang ) { $lang = $default_lang; }
				$translations[ $lang ] = $pid;
			}
		}
		?>
		<div id="zwh-mlp-box" data-post-id="<?php echo esc_attr( $post->ID ); ?>">

			<p style="margin:0 0 12px;">
				<label for="zwh-post-lang" style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">
					This post is written in:
				</label>
				<select id="zwh-post-lang" name="<?php echo esc_attr( ZWH_MLP_META_LANG ); ?>" style="width:100%;">
					<?php foreach ( $active_langs as $code ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $post_lang, $code ); ?>>
							<?php echo esc_html( ZWH_MLP_Settings::get_lang_name( $code ) ); ?>
							<?php echo $code === $default_lang ? ' (default)' : ''; ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<?php
			// Show "Translate this content" button when:
			// - this post is a non-default-language translation
			// - an AI/API provider is configured
			if ( $has_api && $post_lang !== $default_lang ) :
				// Find the source post to translate from.
				$src_post_id = (int) get_post_meta( $post->ID, ZWH_MLP_META_SOURCE, true );
				$src_lang    = $default_lang;

				// Fall back: look for the default-language post in the same group.
				if ( ! $src_post_id && $group_id ) {
					global $wpdb;
					$src_post_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare(
							"SELECT p.ID FROM {$wpdb->posts} p
							 INNER JOIN {$wpdb->postmeta} g  ON g.post_id = p.ID AND g.meta_key = %s AND g.meta_value = %s
							 INNER JOIN {$wpdb->postmeta} ml ON ml.post_id = p.ID AND ml.meta_key = %s AND ml.meta_value = %s
							 WHERE p.post_status != 'trash'
							 LIMIT 1",
							ZWH_MLP_META_GROUP, $group_id,
							ZWH_MLP_META_LANG,  $default_lang
						)
					);
				}

				if ( $src_post_id ) :
					$src_lang_meta = get_post_meta( $src_post_id, ZWH_MLP_META_LANG, true );
					if ( $src_lang_meta ) { $src_lang = $src_lang_meta; }
					$src_lang_name = ZWH_MLP_Settings::get_lang_name( $src_lang );
					$tgt_lang_name = ZWH_MLP_Settings::get_lang_name( $post_lang );
					?>
					<div style="margin-bottom:12px;padding:10px;background:#f9edd4;border:2px solid #211b0c;border-radius:0;">
						<button type="button" id="zwh-translate-content-btn"
							class="button button-primary"
							style="width:100%;margin-bottom:6px;"
							data-source-id="<?php echo esc_attr( $src_post_id ); ?>"
							data-source-lang="<?php echo esc_attr( $src_lang ); ?>"
							data-target-lang="<?php echo esc_attr( $post_lang ); ?>">
							&#x2728; AI Translate from <?php echo esc_html( $src_lang_name ); ?> → <?php echo esc_html( $tgt_lang_name ); ?>
						</button>
						<p style="margin:0;font-size:11px;color:#50453b;">
							Replaces the current title &amp; content with an AI translation.
						</p>
					</div>
					<?php
				endif;
			endif;
			?>

			<table class="zwh-mlp-table">
				<tbody>
					<?php foreach ( $active_langs as $code ) :
						$is_current = ( $code === $post_lang );
						$trans_id   = isset( $translations[ $code ] ) ? $translations[ $code ] : null;
						$trans_post = ( $trans_id && $trans_id !== $post->ID ) ? get_post( $trans_id ) : null;
						$has_trans  = (bool) $trans_post;
						$lang_name  = ZWH_MLP_Settings::get_lang_name( $code );
						$edit_url   = $trans_id ? get_edit_post_link( $trans_id, 'raw' ) : '';
					?>
					<tr class="zwh-mlp-lang-row <?php echo $is_current ? 'is-current' : ''; ?>" data-lang="<?php echo esc_attr( $code ); ?>">
						<td class="zwh-col-lang">
							<?php if ( ZWH_MLP_Settings::is_rtl( $code ) ) : ?>
								<span class="zwh-rtl-badge">RTL</span>
							<?php endif; ?>
							<strong><?php echo esc_html( $lang_name ); ?></strong>
							<small>(<?php echo esc_html( $code ); ?>)</small>
						</td>
						<td class="zwh-col-status">
							<?php if ( $is_current ) : ?>
								<span class="zwh-badge zwh-badge-current">This post</span>
							<?php elseif ( $has_trans ) : ?>
								<span class="zwh-badge zwh-badge-done"><?php echo esc_html( ucfirst( $trans_post->post_status ) ); ?></span>
							<?php else : ?>
								<span class="zwh-badge zwh-badge-missing">Missing</span>
							<?php endif; ?>
						</td>
						<td class="zwh-col-actions">
							<?php if ( $is_current ) : ?>
								<!-- current post -->
							<?php elseif ( $has_trans ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small" target="_blank">Edit</a>
							<?php else : ?>
								<button type="button" class="button button-small zwh-create-btn"
									data-lang="<?php echo esc_attr( $code ); ?>"
									data-lang-name="<?php echo esc_attr( $lang_name ); ?>">Duplicate</button>
								<?php if ( $has_api ) : ?>
									<button type="button" class="button button-small zwh-translate-btn"
										data-lang="<?php echo esc_attr( $code ); ?>"
										data-lang-name="<?php echo esc_attr( $lang_name ); ?>">&#x2728; AI translate</button>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div id="zwh-mlp-status" hidden></div>

			<p style="margin:10px 0 0;font-size:11px;color:#82756a;">
				Switcher shortcode: <code>[zwh_language_switcher]</code>
			</p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------
	// Save language meta on normal post save
	// -------------------------------------------------------------------

	public function on_save_post( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! isset( $_POST[ ZWH_MLP_META_LANG ] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$lang   = sanitize_text_field( wp_unslash( $_POST[ ZWH_MLP_META_LANG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$active = ZWH_MLP_Settings::get_active_langs();
		if ( ! in_array( $lang, $active, true ) ) { return; }

		update_post_meta( $post_id, ZWH_MLP_META_LANG, $lang );

		if ( ! get_post_meta( $post_id, ZWH_MLP_META_GROUP, true ) ) {
			update_post_meta( $post_id, ZWH_MLP_META_GROUP, wp_generate_uuid4() );
		}
	}

	// -------------------------------------------------------------------
	// AJAX: Create translation (duplicate or auto-translate)
	// -------------------------------------------------------------------

	public function ajax_create_translation() {
		@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged
		check_ajax_referer( 'zwh_mlp_action', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( 'Permission denied.' ); }

		$source_id      = intval( isset( $_POST['post_id'] ) ? $_POST['post_id'] : 0 );
		$target_lang    = sanitize_text_field( wp_unslash( isset( $_POST['lang'] ) ? $_POST['lang'] : '' ) );
		$auto_translate = ! empty( $_POST['auto_translate'] );

		$source = get_post( $source_id );
		if ( ! $source ) { wp_send_json_error( 'Source post not found.' ); }

		if ( ! in_array( $target_lang, ZWH_MLP_Settings::get_active_langs(), true ) ) {
			wp_send_json_error( 'Invalid target language.' );
		}

		// Ensure source has group ID and language
		$group_id = get_post_meta( $source_id, ZWH_MLP_META_GROUP, true );
		if ( ! $group_id ) {
			$group_id = wp_generate_uuid4();
			update_post_meta( $source_id, ZWH_MLP_META_GROUP, $group_id );
		}
		$src_lang = get_post_meta( $source_id, ZWH_MLP_META_LANG, true );
		if ( ! $src_lang ) {
			$src_lang = ZWH_MLP_Settings::get_default_lang();
			update_post_meta( $source_id, ZWH_MLP_META_LANG, $src_lang );
		}

		$new_title   = $source->post_title;
		$new_content = $source->post_content;
		$new_excerpt = $source->post_excerpt;

		if ( $auto_translate ) {
			$api = new ZWH_MLP_API();

			$t = $api->translate( $source->post_title, $src_lang, $target_lang );
			if ( ! is_wp_error( $t ) ) { $new_title = $t; }

			$t = $api->translate_html( $source->post_content, $src_lang, $target_lang );
			if ( ! is_wp_error( $t ) ) { $new_content = $t; }

			if ( ! empty( $source->post_excerpt ) ) {
				$t = $api->translate( $source->post_excerpt, $src_lang, $target_lang );
				if ( ! is_wp_error( $t ) ) { $new_excerpt = $t; }
			}
		}

		// Signal the slug filter that this is a translation so it can reuse the original slug.
		self::$creating_translation = true;
		$new_id = wp_insert_post(
			array(
				'post_type'    => $source->post_type,
				'post_title'   => $new_title,
				'post_content' => $new_content,
				'post_excerpt' => $new_excerpt,
				'post_status'  => 'draft',
				'post_author'  => get_current_user_id(),
				'post_name'    => $source->post_name, // use original slug; filter below enforces it
			)
		);
		self::$creating_translation = false;

		if ( is_wp_error( $new_id ) ) { wp_send_json_error( $new_id->get_error_message() ); }

		update_post_meta( $new_id, ZWH_MLP_META_LANG,   $target_lang );
		update_post_meta( $new_id, ZWH_MLP_META_GROUP,  $group_id );
		update_post_meta( $new_id, ZWH_MLP_META_SOURCE, $source_id );

		$thumb = get_post_thumbnail_id( $source_id );
		if ( $thumb ) { set_post_thumbnail( $new_id, $thumb ); }

		wp_send_json_success(
			array(
				'post_id'  => $new_id,
				'edit_url' => get_edit_post_link( $new_id, 'raw' ),
				'lang'     => $target_lang,
			)
		);
	}

	// -------------------------------------------------------------------
	// Post-list row action: "Translate"
	// -------------------------------------------------------------------

	public function add_translate_row_action( $actions, $post ) {
		$post_types = ZWH_MLP_Settings::get( 'post_types', array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $post_types, true ) ) { return $actions; }
		if ( ! current_user_can( 'edit_post', $post->ID ) ) { return $actions; }

		$post_lang    = get_post_meta( $post->ID, ZWH_MLP_META_LANG, true ) ?: ZWH_MLP_Settings::get_default_lang();
		$active_langs = ZWH_MLP_Settings::get_active_langs();

		// Only show if there's at least one language still missing.
		$group_id = get_post_meta( $post->ID, ZWH_MLP_META_GROUP, true );
		$translated = array( $post_lang );
		if ( $group_id ) {
			$group_posts = get_posts( array(
				'post_type'   => $post->post_type,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => ZWH_MLP_META_GROUP, 'value' => $group_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small result set, admin column only. // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			) );
			foreach ( $group_posts as $pid ) {
				$l = get_post_meta( $pid, ZWH_MLP_META_LANG, true );
				if ( $l ) { $translated[] = $l; }
			}
		}
		$missing = array_diff( $active_langs, $translated );
		if ( empty( $missing ) ) { return $actions; }

		$has_api   = ZWH_MLP_Settings::get( 'translate_api', 'none' ) !== 'none';
		$title     = esc_attr( $post->post_title );
		$lang_name = esc_attr( ZWH_MLP_Settings::get_lang_name( $post_lang ) );

		$actions['zwh_translate'] = sprintf(
			'<a href="#" class="zwh-row-translate" data-post-id="%d" data-post-title="%s" data-post-lang="%s" data-post-lang-name="%s" data-has-api="%s">%s</a>',
			$post->ID,
			$title,
			esc_attr( $post_lang ),
			$lang_name,
			$has_api ? '1' : '0',
			'&#127760; Translate'
		);

		return $actions;
	}

	/**
	 * Modal HTML injected into every applicable admin list screen.
	 */
	public function render_translate_modal() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base ) { return; }

		$post_types = ZWH_MLP_Settings::get( 'post_types', array( 'post', 'page' ) );
		if ( ! in_array( $screen->post_type, $post_types, true ) ) { return; }

		$active_langs = ZWH_MLP_Settings::get_active_langs();
		?>
		<div id="zwh-translate-modal" style="display:none;">
			<div id="zwh-translate-backdrop"></div>
			<div id="zwh-translate-dialog" role="dialog" aria-modal="true" aria-labelledby="zwh-translate-title">
				<h3 id="zwh-translate-title">&#127760; Translate post</h3>
				<p id="zwh-translate-subtitle" style="color:#50453b;font-size:13px;margin:0 0 16px;"></p>

				<label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Translate to:</label>
				<select id="zwh-translate-lang" style="width:100%;margin-bottom:16px;padding:5px 8px;">
					<?php foreach ( $active_langs as $code ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" data-name="<?php echo esc_attr( ZWH_MLP_Settings::get_lang_name( $code ) ); ?>">
							<?php echo esc_html( ZWH_MLP_Settings::get_lang_name( $code ) ); ?> (<?php echo esc_html( $code ); ?>)
						</option>
					<?php endforeach; ?>
				</select>

				<div id="zwh-translate-modal-status" hidden></div>

				<div id="zwh-translate-actions" style="display:flex;gap:8px;margin-top:4px;">
					<button id="zwh-translate-duplicate" class="button button-secondary" style="flex:1;">Duplicate</button>
					<button id="zwh-translate-ai" class="button button-primary" style="flex:1;">&#x2728; AI Translate</button>
					<button id="zwh-translate-close" class="button" style="margin-left:auto;">Cancel</button>
				</div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------
	// Post-list language column
	// -------------------------------------------------------------------

	public function add_lang_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $value ) {
			$new[ $key ] = $value;
			if ( 'title' === $key ) { $new['zwh_mlp_lang'] = '&#127760; Lang'; }
		}
		return $new;
	}

	public function render_lang_column( $column, $post_id ) {
		if ( 'zwh_mlp_lang' !== $column ) { return; }

		$post_type    = get_post_type( $post_id );
		$post_lang    = get_post_meta( $post_id, ZWH_MLP_META_LANG, true ) ?: ZWH_MLP_Settings::get_default_lang();
		$group_id     = get_post_meta( $post_id, ZWH_MLP_META_GROUP, true );
		$active_langs = ZWH_MLP_Settings::get_active_langs();
		$default_lang = ZWH_MLP_Settings::get_default_lang();

		// Build translation map: lang → post object.
		$translations = array( $post_lang => get_post( $post_id ) );
		if ( $group_id ) {
			$group_posts = get_posts( array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'post_type'   => $post_type,
				'post_status' => 'any',
				'numberposts' => -1,
				'meta_query'  => array( array( 'key' => ZWH_MLP_META_GROUP, 'value' => $group_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small result set, admin column only.
			) );
			foreach ( $group_posts as $p ) {
				$l = get_post_meta( $p->ID, ZWH_MLP_META_LANG, true ) ?: $default_lang;
				$translations[ $l ] = $p;
			}
		}

		echo '<div class="zwh-trans-row">';
		foreach ( $active_langs as $lang ) {
			if ( isset( $translations[ $lang ] ) ) {
				$t        = $translations[ $lang ];
				$is_self  = ( $t->ID === $post_id );
				$status   = $t->post_status;
				$edit_url = get_edit_post_link( $t->ID, 'raw' );

				$css_status = in_array( $status, array( 'publish' ), true ) ? 'pub' : 'draft';
				$title_attr = ucfirst( $status ) . ( $is_self ? ' — this post' : '' );

				if ( $is_self ) {
					printf(
						'<span class="zwh-tl-badge zwh-tl-self zwh-tl-%s" title="%s">%s</span>',
						esc_attr( $css_status ),
						esc_attr( $title_attr ),
						esc_html( strtoupper( $lang ) )
					);
				} else {
					printf(
						'<a href="%s" class="zwh-tl-badge zwh-tl-link zwh-tl-%s" title="%s">%s</a>',
						esc_url( $edit_url ),
						esc_attr( $css_status ),
						esc_attr( $title_attr ),
						esc_html( strtoupper( $lang ) )
					);
				}
			} else {
				printf(
					'<span class="zwh-tl-badge zwh-tl-missing" title="Missing">%s</span>',
					esc_html( strtoupper( $lang ) )
				);
			}
		}
		echo '</div>';
	}

	// -------------------------------------------------------------------
	// Language filter above the post list
	// -------------------------------------------------------------------

	public function render_language_filter( $post_type ) {
		$post_types = ZWH_MLP_Settings::get( 'post_types', array( 'post', 'page' ) );
		if ( ! in_array( $post_type, $post_types, true ) ) { return; }

		$active_langs = ZWH_MLP_Settings::get_active_langs();
		if ( count( $active_langs ) < 2 ) { return; }

		$current = isset( $_GET['zwh_lang_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['zwh_lang_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<select name="zwh_lang_filter" id="zwh-lang-filter" style="float:none;">
			<option value=""><?php esc_html_e( 'All languages', 'zwh-ml-translate' ); ?></option>
			<?php foreach ( $active_langs as $lang ) : ?>
				<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $current, $lang ); ?>>
					<?php echo esc_html( ZWH_MLP_Settings::get_lang_name( $lang ) ); ?> (<?php echo esc_html( strtoupper( $lang ) ); ?>)
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function apply_language_filter( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) { return; }

		$lang = isset( $_GET['zwh_lang_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['zwh_lang_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $lang ) { return; }

		$active = ZWH_MLP_Settings::get_active_langs();
		if ( ! in_array( $lang, $active, true ) ) { return; }

		$post_types = ZWH_MLP_Settings::get( 'post_types', array( 'post', 'page' ) );
		$pt         = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $pt, $post_types, true ) ) { return; }

		$default_lang = ZWH_MLP_Settings::get_default_lang();

		if ( $lang === $default_lang ) {
			// Default language: include posts with no lang meta AND posts explicitly set to default.
			$query->set( 'meta_query', array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => ZWH_MLP_META_LANG,
					'value'   => $default_lang,
					'compare' => '=',
				),
				array(
					'key'     => ZWH_MLP_META_LANG,
					'compare' => 'NOT EXISTS',
				),
			) );
		} else {
			$query->set( 'meta_query', array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => ZWH_MLP_META_LANG,
					'value'   => $lang,
					'compare' => '=',
				),
			) );
		}
	}

	// -------------------------------------------------------------------
	// AJAX: Translate the content of the currently-open translation post
	// -------------------------------------------------------------------

	public function ajax_translate_content() {
		@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged
		check_ajax_referer( 'zwh_mlp_action', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( 'Permission denied.' ); }

		$target_post_id = intval( isset( $_POST['post_id'] )    ? $_POST['post_id']    : 0 );
		$source_id      = intval( isset( $_POST['source_id'] )  ? $_POST['source_id']  : 0 );
		$source_lang    = sanitize_text_field( wp_unslash( isset( $_POST['source_lang'] ) ? $_POST['source_lang'] : '' ) );
		$target_lang    = sanitize_text_field( wp_unslash( isset( $_POST['target_lang'] ) ? $_POST['target_lang'] : '' ) );

		$source = get_post( $source_id );
		if ( ! $source ) { wp_send_json_error( 'Source post not found.' ); }
		if ( ! current_user_can( 'read_post', $source_id ) ) { wp_send_json_error( 'Cannot read source post.' ); }

		$active = ZWH_MLP_Settings::get_active_langs();
		if ( ! in_array( $target_lang, $active, true ) ) { wp_send_json_error( 'Invalid target language.' ); }

		$api = new ZWH_MLP_API();

		$title   = $source->post_title;
		$content = $source->post_content;
		$excerpt = $source->post_excerpt;

		$t = $api->translate( $title, $source_lang, $target_lang );
		if ( ! is_wp_error( $t ) ) { $title = $t; }

		if ( ! empty( $content ) ) {
			$t = $api->translate_html( $content, $source_lang, $target_lang );
			if ( ! is_wp_error( $t ) ) { $content = $t; }
		}

		if ( ! empty( $excerpt ) ) {
			$t = $api->translate( $excerpt, $source_lang, $target_lang );
			if ( ! is_wp_error( $t ) ) { $excerpt = $t; }
		}

		// Save translated content directly to the target post so it's persisted
		// even if the user closes the editor without clicking Update.
		$saved = false;
		if ( $target_post_id && current_user_can( 'edit_post', $target_post_id ) ) {
			$update = array(
				'ID'           => $target_post_id,
				'post_title'   => $title,
				'post_content' => $content,
			);
			if ( ! empty( $excerpt ) ) {
				$update['post_excerpt'] = $excerpt;
			}
			$result = wp_update_post( $update, true );
			$saved  = ! is_wp_error( $result );
		}

		wp_send_json_success(
			array(
				'title'   => $title,
				'content' => $content,
				'excerpt' => $excerpt,
				'saved'   => $saved,
			)
		);
	}
}
