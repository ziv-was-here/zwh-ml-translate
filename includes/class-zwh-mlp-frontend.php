<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend: URL routing, permalink filtering, language switcher, RTL, hreflang.
 *
 * URL structure:
 *   Default language → domain.com/post-slug       (no prefix)
 *   Other languages  → domain.com/fr/post-slug
 *                      domain.com/de/post-slug
 */
class ZWH_MLP_Frontend {

	private static $current_lang = null;

	public function __construct() {
		add_action( 'init',              array( $this, 'add_rewrite_rules' ), 20 );
		add_filter( 'query_vars',        array( $this, 'add_query_vars' ) );

		// 'request' fires after rewrite rules but before WP_Query runs —
		// the right place to swap pagename/name for a direct post-ID lookup.
		add_filter( 'request',           array( $this, 'detect_language' ) );

		// Prevent WordPress redirect_canonical from redirecting /fr/slug → /slug.
		// Language-prefixed URLs ARE canonical for their translated posts.
		add_filter( 'redirect_canonical', array( $this, 'prevent_lang_redirect' ), 10, 2 );

		// Allow translated posts to share the same slug as the original.
		// The /lang/ URL prefix makes them unique at the URL level, so WordPress
		// does not need to append -2, -3, etc. to the slug.
		add_filter( 'wp_unique_post_slug', array( $this, 'allow_duplicate_slug_for_translation' ), 10, 6 );

		add_filter( 'post_link',         array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'page_link',         array( $this, 'filter_page_link' ), 10, 2 );
		add_filter( 'post_type_link',    array( $this, 'filter_post_type_link' ), 10, 2 );

		add_action( 'wp_head',           array( $this, 'output_hreflang' ) );
		add_filter( 'language_attributes', array( $this, 'add_rtl_attribute' ) );
		add_filter( 'body_class',          array( $this, 'add_rtl_body_class' ) );
		add_action( 'wp_head',             array( $this, 'output_rtl_style' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );

		add_shortcode( 'zwh_language_switcher', array( $this, 'shortcode_switcher' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );

		// Filter listing pages (home, archive, search) to only show posts in the current language.
		add_action( 'pre_get_posts', array( $this, 'filter_posts_by_language' ) );

		// Replace source strings with translations inside block HTML output.
		add_filter( 'render_block', array( $this, 'translate_block_strings' ), 20, 1 );
	}

	// -------------------------------------------------------------------
	// Rewrite rules & query vars
	// -------------------------------------------------------------------

	/**
	 * When a translation post is being created, return the original slug unchanged
	 * so WordPress does not append -2, -3 etc.
	 * Translations are unique at the URL level via the /lang/ prefix.
	 *
	 * @param string $slug          Slug WordPress wants to use (may include -2 suffix).
	 * @param int    $post_id       ID of the post being saved.
	 * @param string $post_status   Post status.
	 * @param string $post_type     Post type.
	 * @param int    $post_parent   Parent post ID.
	 * @param string $original_slug The slug that was requested before uniqueness checks.
	 * @return string
	 */
	/**
	 * Stop WordPress from redirecting /fr/slug → /slug.
	 * When zwh_lang is set we are already on the correct canonical URL.
	 *
	 * @param string $redirect_url The URL WordPress wants to redirect to.
	 * @param string $requested_url The original requested URL.
	 * @return string|false Return false to cancel the redirect.
	 */
	public function prevent_lang_redirect( $redirect_url, $requested_url ) {
		if ( get_query_var( 'zwh_lang' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Exclude posts that belong to a different language from listing pages.
	 *
	 * On the default-language home/archive: show posts with lang=default OR no lang meta.
	 * On a language-prefixed archive (/he/): show only lang=he posts.
	 * Single posts are handled separately by detect_language — skip those here.
	 */
	public function filter_posts_by_language( $query ) {
		if ( is_admin() || ! $query->is_main_query() || $query->is_singular() ) {
			return;
		}

		// Only filter configured post types.
		$post_types   = ZWH_MLP_Settings::get( 'post_types', array( 'post', 'page' ) );
		$query_pt     = $query->get( 'post_type' );
		// Empty post_type on the main query means WordPress will use its default (post).
		if ( ! empty( $query_pt ) && ! in_array( $query_pt, $post_types, true ) ) {
			return;
		}

		$default_lang = ZWH_MLP_Settings::get_default_lang();
		$current_lang = get_query_var( 'zwh_lang' ) ?: $default_lang;

		if ( $current_lang === $default_lang ) {
			// Default language: show posts whose lang = default OR have no lang meta at all
			// (pre-plugin posts that were never assigned a language).
			$query->set(
				'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
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
				)
			);
		} else {
			// Non-default language: show only posts in that language.
			$query->set(
				'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					array(
						'key'     => ZWH_MLP_META_LANG,
						'value'   => $current_lang,
						'compare' => '=',
					),
				)
			);
		}
	}

	public function allow_duplicate_slug_for_translation( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		// During programmatic translation creation (new post, meta not set yet).
		if ( ZWH_MLP_Admin::is_creating_translation() ) {
			return $original_slug;
		}

		// When manually editing an existing translation post in the editor.
		// The meta is already saved, so we can check it directly.
		if ( $post_id ) {
			$lang         = get_post_meta( $post_id, ZWH_MLP_META_LANG, true );
			$default_lang = ZWH_MLP_Settings::get_default_lang();
			if ( $lang && $lang !== $default_lang ) {
				return $original_slug;
			}
		}

		return $slug;
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'zwh_lang';
		return $vars;
	}

	public function add_rewrite_rules() {
		$active_langs = ZWH_MLP_Settings::get_active_langs();
		$default_lang = ZWH_MLP_Settings::get_default_lang();

		foreach ( $active_langs as $lang ) {
			if ( $lang === $default_lang ) { continue; }

			$p = preg_quote( $lang, '#' );

			// /fr/path/to/page
			add_rewrite_rule(
				'^' . $p . '/(.+?)(/[0-9]+)?/?$',
				'index.php?zwh_lang=' . $lang . '&pagename=$matches[1]&page=$matches[2]',
				'top'
			);
			// /fr/single-slug
			add_rewrite_rule(
				'^' . $p . '/([^/]+)/?$',
				'index.php?zwh_lang=' . $lang . '&name=$matches[1]',
				'top'
			);
			// /fr/ (language home)
			add_rewrite_rule(
				'^' . $p . '/?$',
				'index.php?zwh_lang=' . $lang,
				'top'
			);
		}
	}

	/**
	 * Fires on the 'request' filter — after rewrite rules but before WP_Query runs.
	 * Replaces name/pagename with a direct post-ID lookup so WordPress loads the
	 * correct translated post for both posts AND pages (get_page_by_path would
	 * ignore meta_query, so we bypass it entirely with p=ID).
	 *
	 * @param array $query_vars The parsed query vars from the rewrite rules.
	 * @return array Modified query vars.
	 */
	public function detect_language( $query_vars ) {
		if ( empty( $query_vars['zwh_lang'] ) ) {
			return $query_vars;
		}

		$lang   = $query_vars['zwh_lang'];
		$active = ZWH_MLP_Settings::get_active_langs();
		if ( ! in_array( $lang, $active, true ) ) {
			return $query_vars;
		}

		self::$current_lang = $lang;

		$raw_slug = ! empty( $query_vars['name'] )     ? $query_vars['name']
			    : ( ! empty( $query_vars['pagename'] ) ? $query_vars['pagename'] : '' );

		if ( ! $raw_slug ) {
			return $query_vars;
		}

		// post_name is always the LAST path segment.
		// WordPress may pass the full permalink path (e.g. "blog/uncategorized/my-post")
		// when the site has a posts page or category-based permalink structure.
		// We extract only the final segment for the DB lookup.
		$slug_parts = array_values( array_filter( explode( '/', trim( $raw_slug, '/' ) ) ) );
		$slug       = end( $slug_parts );   // e.g. "my-post"
		$page_path  = $raw_slug;            // kept for hierarchical (page) fallback

		global $wpdb;

		// Use two hardcoded SQL variants — one for anonymous users (publish only),
		// one for logged-in users (publish + draft + pending for preview).
		// This avoids dynamic IN-clause placeholders that confuse PHPCS's argument counter.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( is_user_logged_in() ) {
			// Primary lookup: post_name + language meta (3 statuses).
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					 WHERE p.post_name   = %s
					   AND p.post_status IN ('publish','draft','pending')
					   AND pm.meta_key   = %s
					   AND pm.meta_value = %s
					 LIMIT 1",
					$slug,
					ZWH_MLP_META_LANG,
					$lang
				)
			);
		} else {
			// Primary lookup: publish-only.
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					 WHERE p.post_name   = %s
					   AND p.post_status = 'publish'
					   AND pm.meta_key   = %s
					   AND pm.meta_value = %s
					 LIMIT 1",
					$slug,
					ZWH_MLP_META_LANG,
					$lang
				)
			);
		}

		// Fallback: slug on the translated post may differ from the original.
		// Find the source post by slug, get its group, find the translation.
		if ( ! $post_id ) {
			if ( is_user_logged_in() ) {
				$source_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
						 WHERE post_name = %s AND post_status IN ('publish','draft','pending')
						 LIMIT 1",
						$slug
					)
				);
			} else {
				$source_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
						 WHERE post_name = %s AND post_status = 'publish'
						 LIMIT 1",
						$slug
					)
				);
			}
			if ( $source_id ) {
				$group_id = get_post_meta( (int) $source_id, ZWH_MLP_META_GROUP, true );
				if ( $group_id ) {
					if ( is_user_logged_in() ) {
						$post_id = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT p.ID FROM {$wpdb->posts} p
								 INNER JOIN {$wpdb->postmeta} g  ON g.post_id  = p.ID AND g.meta_key  = %s AND g.meta_value  = %s
								 INNER JOIN {$wpdb->postmeta} ml ON ml.post_id = p.ID AND ml.meta_key = %s AND ml.meta_value = %s
								 WHERE p.post_status IN ('publish','draft','pending')
								 LIMIT 1",
								ZWH_MLP_META_GROUP,
								$group_id,
								ZWH_MLP_META_LANG,
								$lang
							)
						);
					} else {
						$post_id = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT p.ID FROM {$wpdb->posts} p
								 INNER JOIN {$wpdb->postmeta} g  ON g.post_id  = p.ID AND g.meta_key  = %s AND g.meta_value  = %s
								 INNER JOIN {$wpdb->postmeta} ml ON ml.post_id = p.ID AND ml.meta_key = %s AND ml.meta_value = %s
								 WHERE p.post_status = 'publish'
								 LIMIT 1",
								ZWH_MLP_META_GROUP,
								$group_id,
								ZWH_MLP_META_LANG,
								$lang
							)
						);
					}
				}
			}
		}
		// phpcs:enable

		if ( $post_id ) {
			// Replace the entire query with a direct ID lookup — works for both
			// posts and pages without triggering get_page_by_path().
			$post_type = get_post_type( (int) $post_id );
			return array(
				'p'          => (int) $post_id,
				'post_type'  => $post_type ?: 'any',
				'zwh_lang'   => $lang,
			);
		}

		// Post not found — let WordPress show its own 404.
		return $query_vars;
	}

	// -------------------------------------------------------------------
	// Permalink filters
	// -------------------------------------------------------------------

	public function filter_post_link( $url, $post ) {
		return $this->prepend_lang( $url, $post );
	}

	public function filter_page_link( $url, $post_id ) {
		return $this->prepend_lang( $url, get_post( $post_id ) );
	}

	public function filter_post_type_link( $url, $post ) {
		return $this->prepend_lang( $url, $post );
	}

	private function prepend_lang( $url, $post ) {
		if ( ! ( $post instanceof WP_Post ) ) { return $url; }

		$lang         = get_post_meta( $post->ID, ZWH_MLP_META_LANG, true );
		$default_lang = ZWH_MLP_Settings::get_default_lang();
		if ( ! $lang ) { $lang = $default_lang; }

		// Default language: no prefix
		if ( $lang === $default_lang ) { return $url; }

		// For non-hierarchical post types (posts, CPTs), build a clean URL:
		//   home/lang/post-slug/
		// This avoids carrying over /blog/, /%category%/, or any other
		// permalink-structure prefix that WordPress adds to post URLs.
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( $post_type_obj && ! $post_type_obj->hierarchical ) {
			return trailingslashit( home_url( '/' . $lang . '/' . $post->post_name ) );
		}

		// For pages (hierarchical), preserve the path so child pages resolve correctly,
		// but still strip any posts-page base WordPress may have inserted.
		$home = trailingslashit( home_url() );
		if ( 0 === strpos( $url, $home ) ) {
			return $home . $lang . '/' . substr( $url, strlen( $home ) );
		}

		return $url;
	}

	// -------------------------------------------------------------------
	// Current language detection
	// -------------------------------------------------------------------

	public static function get_current_lang() {
		if ( null !== self::$current_lang ) { return self::$current_lang; }

		if ( is_singular() ) {
			global $post;
			if ( $post ) {
				$lang = get_post_meta( $post->ID, ZWH_MLP_META_LANG, true );
				if ( $lang ) { return $lang; }
			}
		}

		return ZWH_MLP_Settings::get_default_lang();
	}

	// -------------------------------------------------------------------
	// RTL support
	// -------------------------------------------------------------------

	public function add_rtl_attribute( $output ) {
		if ( ! ZWH_MLP_Settings::is_rtl( self::get_current_lang() ) ) { return $output; }

		if ( preg_match( '/dir="[^"]*"/', $output ) ) {
			return preg_replace( '/dir="[^"]*"/', 'dir="rtl"', $output );
		}
		return $output . ' dir="rtl"';
	}

	/**
	 * Add 'rtl' and 'zwh-rtl' classes to <body> when the current language is RTL.
	 * Many themes override direction on body directly, so the <html dir="rtl">
	 * attribute alone is not enough — a body class lets theme CSS and plugins react.
	 *
	 * @param array $classes Existing body classes.
	 * @return array
	 */
	public function add_rtl_body_class( $classes ) {
		if ( ZWH_MLP_Settings::is_rtl( self::get_current_lang() ) ) {
			$classes[] = 'rtl';
			$classes[] = 'zwh-rtl';
		}
		return $classes;
	}

	/**
	 * Output a minimal <style> in <head> that enforces RTL direction and alignment
	 * on the body when the current language is RTL. Using high-specificity selectors
	 * ensures the rule overrides most theme stylesheets without !important abuse.
	 */
	public function output_rtl_style() {
		if ( ! ZWH_MLP_Settings::is_rtl( self::get_current_lang() ) ) { return; }
		echo '<style id="zwh-rtl-style">body.zwh-rtl,body.zwh-rtl *{direction:rtl;unicode-bidi:embed;}body.zwh-rtl{text-align:right;}</style>' . "\n";
	}

	// -------------------------------------------------------------------
	// Hreflang tags for SEO
	// -------------------------------------------------------------------

	public function output_hreflang() {
		if ( ! is_singular() ) { return; }
		global $post;
		if ( ! $post ) { return; }

		$group_id = get_post_meta( $post->ID, ZWH_MLP_META_GROUP, true );
		if ( ! $group_id ) { return; }

		$default_lang = ZWH_MLP_Settings::get_default_lang();

		$group_posts = get_posts(
			array(
				'post_type'   => $post->post_type,
				'post_status' => 'publish',
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
			echo '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( get_permalink( $pid ) ) . '">' . "\n";
		}

		// x-default → default language version
		foreach ( $group_posts as $pid ) {
			$lang = get_post_meta( $pid, ZWH_MLP_META_LANG, true );
			if ( ! $lang || $lang === $default_lang ) {
				echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( get_permalink( $pid ) ) . '">' . "\n";
				break;
			}
		}
	}

	// -------------------------------------------------------------------
	// String translations — replace source text in block HTML output
	// -------------------------------------------------------------------

	/**
	 * Called by the render_block filter for every block on the page.
	 * Replaces source strings with their translated equivalents when
	 * the current language is not the default.
	 *
	 * @param string $block_content The rendered block HTML.
	 * @return string
	 */
	public function translate_block_strings( $block_content ) {
		$lang         = self::get_current_lang();
		$default_lang = ZWH_MLP_Settings::get_default_lang();

		if ( $lang === $default_lang || empty( $block_content ) ) {
			return $block_content;
		}

		// ── 1. Swap navigation hrefs to their translated equivalents ──────
		// Find every <a href="..."> in the block, resolve it to a post ID,
		// then check if that post has a translation in the current language.
		// If it does, replace the href with the translated post's permalink.
		$block_content = preg_replace_callback(
			'/href=["\']([^"\']+)["\']/i',
			function( $matches ) use ( $lang, $default_lang ) {
				$url     = $matches[1];
				$post_id = url_to_postid( $url );
				if ( ! $post_id ) { return $matches[0]; }

				// Look up the translation group for this post.
				$group_id = get_post_meta( $post_id, ZWH_MLP_META_GROUP, true );
				if ( ! $group_id ) { return $matches[0]; }

				// Find the translated post in this group.
				static $translation_cache = array();
				$cache_key = $group_id . '|' . $lang;
				if ( ! isset( $translation_cache[ $cache_key ] ) ) {
					global $wpdb;
					// phpcs:disable WordPress.DB.DirectDatabaseQuery
					$trans_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT p.ID FROM {$wpdb->posts} p
							 INNER JOIN {$wpdb->postmeta} g  ON g.post_id  = p.ID AND g.meta_key  = %s AND g.meta_value  = %s
							 INNER JOIN {$wpdb->postmeta} ml ON ml.post_id = p.ID AND ml.meta_key = %s AND ml.meta_value = %s
							 WHERE p.post_status = 'publish'
							 LIMIT 1",
							ZWH_MLP_META_GROUP,
							$group_id,
							ZWH_MLP_META_LANG,
							$lang
						)
					);
					// phpcs:enable
					$translation_cache[ $cache_key ] = $trans_id ? (int) $trans_id : false;
				}

				$trans_id = $translation_cache[ $cache_key ];
				if ( ! $trans_id ) { return $matches[0]; }

				$new_url = get_permalink( $trans_id );
				if ( ! $new_url ) { return $matches[0]; }

				return 'href="' . esc_url( $new_url ) . '"';
			},
			$block_content
		);

		// ── 2. Replace visible text strings (nav labels, headings, buttons) ──
		// Lazy-load the translation map — fetched once per request.
		static $maps = array();
		if ( ! isset( $maps[ $lang ] ) ) {
			$maps[ $lang ] = ZWH_MLP_Strings::get_map( $lang );
		}

		$map = $maps[ $lang ];
		if ( ! empty( $map ) ) {
			// Sort by length descending so longer strings are matched first,
			// preventing a short substring from corrupting a longer match.
			uksort( $map, function( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			} );

			foreach ( $map as $source => $translation ) {
				if ( '' === $source || '' === $translation ) { continue; }
				$block_content = str_replace(
					esc_html( $source ),
					esc_html( $translation ),
					$block_content
				);
			}
		}

		return $block_content;
	}

	// -------------------------------------------------------------------
	// Frontend assets
	// -------------------------------------------------------------------

	public function enqueue_frontend() {
		// Register only — enqueued on demand by render_switcher() so the CSS
		// loads solely on pages that actually output the language switcher.
		wp_register_style( 'zwh-mlp-frontend', ZWH_MLP_URL . 'assets/css/frontend.css', array(), ZWH_MLP_VERSION );
	}

	// -------------------------------------------------------------------
	// Language switcher shortcode
	// [zwh_language_switcher style="inline|dropdown" show="name|code|both"]
	// -------------------------------------------------------------------

	public function shortcode_switcher( $atts ) {
		$atts = shortcode_atts(
			array(
				'style' => 'inline',
				'show'  => 'name',
			),
			$atts,
			'zwh_language_switcher'
		);
		return $this->render_switcher( $atts['style'], $atts['show'] );
	}

	private function render_switcher( $style = 'inline', $show = 'name' ) {
		global $post;
		if ( ! $post ) { return ''; }

		wp_enqueue_style( 'zwh-mlp-frontend' );

		$active_langs = ZWH_MLP_Settings::get_active_langs();
		$default_lang = ZWH_MLP_Settings::get_default_lang();
		$current_lang = self::get_current_lang();
		$group_id     = get_post_meta( $post->ID, ZWH_MLP_META_GROUP, true );

		$lang_urls = array();
		if ( $group_id ) {
			$group_posts = get_posts(
				array(
					'post_type'   => $post->post_type,
					'post_status' => 'publish',
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
				$lang_urls[ $lang ] = get_permalink( $pid );
			}
		} else {
			$lang_urls[ $current_lang ] = get_permalink( $post->ID );
		}

		ob_start();
		echo '<div class="zwh-mlp-switcher zwh-mlp-switcher--' . esc_attr( $style ) . '">';

		if ( 'dropdown' === $style ) {
			echo '<select class="zwh-mlp-dropdown" onchange="window.location.href=this.value">';
			foreach ( $active_langs as $lang ) {
				if ( empty( $lang_urls[ $lang ] ) ) { continue; }
				echo '<option value="' . esc_url( $lang_urls[ $lang ] ) . '" ' . selected( $lang, $current_lang, false ) . '>'
					. esc_html( $this->lang_label( $lang, $show ) ) . '</option>';
			}
			echo '</select>';
		} else {
			foreach ( $active_langs as $lang ) {
				if ( empty( $lang_urls[ $lang ] ) ) { continue; }
				$class = 'zwh-mlp-switcher__item' . ( $lang === $current_lang ? ' current' : '' );
				echo '<a href="' . esc_url( $lang_urls[ $lang ] ) . '" class="' . esc_attr( $class ) . '" hreflang="' . esc_attr( $lang ) . '">'
					. esc_html( $this->lang_label( $lang, $show ) ) . '</a>';
			}
		}

		echo '</div>';
		return ob_get_clean();
	}

	private function lang_label( $lang, $show ) {
		$name = ZWH_MLP_Settings::get_lang_name( $lang );
		if ( 'code' === $show ) { return strtoupper( $lang ); }
		if ( 'both' === $show ) { return $name . ' (' . strtoupper( $lang ) . ')'; }
		return $name;
	}

	// -------------------------------------------------------------------
	// Widget
	// -------------------------------------------------------------------

	public function register_widget() {
		register_widget( 'ZWH_MLP_Switcher_Widget' );
	}
}

/**
 * Language Switcher Widget
 */
class ZWH_MLP_Switcher_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'zwh_mlp_switcher',
			'Language Switcher',
			array( 'description' => 'Language switcher for ZWH Multilingual sites.' )
		);
	}

	public function widget( $args, $instance ) {
		echo wp_kses_post( $args['before_widget'] );
		$title = apply_filters( 'widget_title', isset( $instance['title'] ) ? $instance['title'] : '' );
		if ( $title ) { echo wp_kses_post( $args['before_title'] . $title . $args['after_title'] ); }
		$style = isset( $instance['style'] ) ? $instance['style'] : 'inline';
		$show  = isset( $instance['show'] )  ? $instance['show']  : 'name';
		echo do_shortcode( '[zwh_language_switcher style="' . esc_attr( $style ) . '" show="' . esc_attr( $show ) . '"]' );
		echo wp_kses_post( $args['after_widget'] );
	}

	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		$style = isset( $instance['style'] ) ? $instance['style'] : 'inline';
		$show  = isset( $instance['show'] )  ? $instance['show']  : 'name';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">Title:</label>
			<input class="widefat" type="text"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>">Style:</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<option value="inline"   <?php selected( $style, 'inline' ); ?>>Inline links</option>
				<option value="dropdown" <?php selected( $style, 'dropdown' ); ?>>Dropdown</option>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show' ) ); ?>">Display:</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'show' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show' ) ); ?>">
				<option value="name" <?php selected( $show, 'name' ); ?>>Language name</option>
				<option value="code" <?php selected( $show, 'code' ); ?>>Language code</option>
				<option value="both" <?php selected( $show, 'both' ); ?>>Name + code</option>
			</select>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		return array(
			'title' => sanitize_text_field( isset( $new_instance['title'] ) ? $new_instance['title'] : '' ),
			'style' => in_array( isset( $new_instance['style'] ) ? $new_instance['style'] : '', array( 'inline', 'dropdown' ), true ) ? $new_instance['style'] : 'inline',
			'show'  => in_array( isset( $new_instance['show'] )  ? $new_instance['show']  : '', array( 'name', 'code', 'both' ), true )   ? $new_instance['show']  : 'name',
		);
	}
}
