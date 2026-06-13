<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation API abstraction.
 * Supports: DeepL, Google Translate, OpenAI (GPT-4o), Claude (Anthropic), Google Gemini.
 */
class ZWH_MLP_API {

	/**
	 * Translate plain text.
	 *
	 * @param string $text        Text to translate.
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @return string|WP_Error
	 */
	public function translate( $text, $source_lang, $target_lang ) {
		if ( '' === trim( $text ) ) {
			return $text;
		}
		$api = ZWH_MLP_Settings::get( 'translate_api', 'none' );
		switch ( $api ) {
			case 'deepl':  return $this->translate_deepl( $text, $source_lang, $target_lang );
			case 'google': return $this->translate_google( $text, $source_lang, $target_lang );
			case 'openai': return $this->translate_via_ai( $text, $source_lang, $target_lang, 'openai' );
			case 'claude': return $this->translate_via_ai( $text, $source_lang, $target_lang, 'claude' );
			case 'gemini': return $this->translate_via_ai( $text, $source_lang, $target_lang, 'gemini' );
			default:       return $text;
		}
	}

	/**
	 * Translate HTML content, preserving markup.
	 *
	 * @param string $html        HTML content.
	 * @param string $source_lang Source language code.
	 * @param string $target_lang Target language code.
	 * @return string|WP_Error
	 */
	public function translate_html( $html, $source_lang, $target_lang ) {
		if ( '' === trim( $html ) ) {
			return $html;
		}
		$api = ZWH_MLP_Settings::get( 'translate_api', 'none' );
		// DeepL and Google handle HTML natively
		if ( in_array( $api, array( 'deepl', 'google' ), true ) ) {
			return $this->translate( $html, $source_lang, $target_lang );
		}
		// AI providers: instruct them to preserve tags
		return $this->translate_via_ai( $html, $source_lang, $target_lang, $api, true );
	}

	// -------------------------------------------------------------------
	// Provider implementations
	// -------------------------------------------------------------------

	private function translate_deepl( $text, $source_lang, $target_lang ) {
		$api_key = ZWH_MLP_Settings::get( 'deepl_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'zwh_mlp_no_key', 'DeepL API key not configured.' );
		}

		$is_free  = ZWH_MLP_Settings::get( 'deepl_free', true );
		$endpoint = $is_free
			? 'https://api-free.deepl.com/v2/translate'
			: 'https://api.deepl.com/v2/translate';

		$dl_source = strtoupper( str_replace( '-', '_', $source_lang ) );
		$dl_target = strtoupper( str_replace( '-', '_', $target_lang ) );
		if ( 'EN' === $dl_target )  { $dl_target = 'EN-US'; }
		if ( 'PT' === $dl_target )  { $dl_target = 'PT-BR'; }

		$is_html     = $text !== wp_strip_all_tags( $text );
		$tag_handling = $is_html ? 'html' : 'off';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'DeepL-Auth-Key ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'text'         => array( $text ),
						'source_lang'  => $dl_source,
						'target_lang'  => $dl_target,
						'tag_handling' => $tag_handling,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) { return $response; }
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'zwh_mlp_deepl_error', isset( $data['message'] ) ? $data['message'] : 'DeepL error (HTTP ' . $code . ')' );
		}
		return isset( $data['translations'][0]['text'] ) ? $data['translations'][0]['text'] : $text;
	}

	private function translate_google( $text, $source_lang, $target_lang ) {
		$api_key = ZWH_MLP_Settings::get( 'google_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'zwh_mlp_no_key', 'Google Translate API key not configured.' );
		}

		$is_html = $text !== wp_strip_all_tags( $text );

		$response = wp_remote_post(
			'https://translation.googleapis.com/language/translate/v2?key=' . $api_key,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'q'      => $text,
						'source' => $source_lang,
						'target' => $target_lang,
						'format' => $is_html ? 'html' : 'text',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) { return $response; }
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'zwh_mlp_google_error', isset( $data['error']['message'] ) ? $data['error']['message'] : 'Google Translate error (HTTP ' . $code . ')' );
		}
		return isset( $data['data']['translations'][0]['translatedText'] ) ? $data['data']['translations'][0]['translatedText'] : $text;
	}

	private function translate_via_ai( $text, $source_lang, $target_lang, $provider, $is_html = null ) {
		if ( null === $is_html ) {
			$is_html = $text !== wp_strip_all_tags( $text );
		}
		$src_name  = ZWH_MLP_Settings::get_lang_name( $source_lang );
		$tgt_name  = ZWH_MLP_Settings::get_lang_name( $target_lang );
		$html_note = $is_html
			? ' Preserve all HTML tags and attributes exactly — only translate text content inside tags.'
			: '';

		$system = 'You are a professional translator.' . $html_note
			. ' Return ONLY the translated text with no commentary, preamble, or explanation.';
		$user   = 'Translate the following from ' . $src_name . ' to ' . $tgt_name . ":\n\n" . $text;

		switch ( $provider ) {
			case 'openai': return $this->call_openai( $system, $user );
			case 'claude': return $this->call_claude( $system, $user );
			case 'gemini': return $this->call_gemini( $system, $user );
			default:       return $text;
		}
	}

	// -------------------------------------------------------------------
	// Low-level API callers
	// -------------------------------------------------------------------

	private function call_openai( $system, $user ) {
		$api_key = ZWH_MLP_Settings::get( 'openai_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'zwh_mlp_no_key', 'OpenAI API key not configured.' );
		}
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 90,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'model'    => 'gpt-4o',
						'messages' => array(
							array( 'role' => 'system', 'content' => $system ),
							array( 'role' => 'user',   'content' => $user ),
						),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) { return $response; }
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'zwh_mlp_openai_error', isset( $data['error']['message'] ) ? $data['error']['message'] : 'OpenAI error (HTTP ' . $code . ')' );
		}
		return isset( $data['choices'][0]['message']['content'] ) ? trim( $data['choices'][0]['message']['content'] ) : '';
	}

	private function call_claude( $system, $user ) {
		$api_key = ZWH_MLP_Settings::get( 'claude_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'zwh_mlp_no_key', 'Claude API key not configured.' );
		}
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 90,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'model'      => 'claude-sonnet-4-6',
						'max_tokens' => 8192,
						'system'     => $system,
						'messages'   => array(
							array( 'role' => 'user', 'content' => $user ),
						),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) { return $response; }
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'zwh_mlp_claude_error', isset( $data['error']['message'] ) ? $data['error']['message'] : 'Claude error (HTTP ' . $code . ')' );
		}
		return isset( $data['content'][0]['text'] ) ? trim( $data['content'][0]['text'] ) : '';
	}

	private function call_gemini( $system, $user ) {
		$api_key = ZWH_MLP_Settings::get( 'gemini_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'zwh_mlp_no_key', 'Gemini API key not configured.' );
		}
		$model = 'gemini-2.5-flash';
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 90,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'system_instruction' => array(
							'parts' => array( array( 'text' => $system ) ),
						),
						'contents'         => array(
							array( 'parts' => array( array( 'text' => $user ) ) ),
						),
						'generationConfig' => array(
							'maxOutputTokens' => 8192,
							'thinkingConfig'  => array( 'thinkingBudget' => 0 ),
						),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) { return $response; }
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'zwh_mlp_gemini_error', isset( $data['error']['message'] ) ? $data['error']['message'] : 'Gemini error (HTTP ' . $code . ')' );
		}
		$parts = isset( $data['candidates'][0]['content']['parts'] ) ? $data['candidates'][0]['content']['parts'] : array();
		foreach ( $parts as $part ) {
			if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
				return trim( $part['text'] );
			}
		}
		return new WP_Error( 'zwh_mlp_gemini_error', 'Empty response from Gemini.' );
	}
}
