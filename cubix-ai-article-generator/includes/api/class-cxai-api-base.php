<?php
/**
 * Abstract base class for AI API providers.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_API_Base
 *
 * Providers implement chat(); single-prompt generation derives from it.
 * All HTTP goes through the shared wp_remote_post() wrapper — no cURL and
 * no external HTTP libraries.
 */
abstract class CXAI_API_Base {

	/**
	 * Decrypted API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Model identifier.
	 *
	 * @var string
	 */
	protected $model;

	/**
	 * Sampling temperature (0–1).
	 *
	 * @var float
	 */
	protected $temperature;

	/**
	 * Maximum tokens to generate.
	 *
	 * @var int
	 */
	protected $max_tokens;

	/**
	 * Optional system instruction.
	 *
	 * @var string
	 */
	protected $system = '';

	/**
	 * Constructor.
	 *
	 * @param string $api_key Decrypted API key.
	 * @param string $model   Model identifier.
	 * @param array  $args    Optional. temperature, max_tokens, system.
	 */
	public function __construct( $api_key, $model, array $args = array() ) {
		$this->api_key     = $api_key;
		$this->model       = $model;
		$this->temperature = isset( $args['temperature'] ) ? (float) $args['temperature'] : 0.7;
		$this->max_tokens  = isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 2048;
		$this->system      = isset( $args['system'] ) ? (string) $args['system'] : '';
	}

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	abstract public function get_slug();

	/**
	 * Run a multi-turn chat completion.
	 *
	 * @param array $messages List of ['role' => 'user'|'assistant', 'content' => string].
	 * @return string|WP_Error Assistant reply text on success.
	 */
	abstract public function chat( array $messages );

	/**
	 * Whether this provider works without an API key.
	 *
	 * @return bool
	 */
	public function is_free() {
		return false;
	}

	/**
	 * Set the system instruction.
	 *
	 * @param string $system System prompt.
	 * @return void
	 */
	public function set_system( $system ) {
		$this->system = (string) $system;
	}

	/**
	 * Generate content from a single prompt.
	 *
	 * @param string $prompt User prompt.
	 * @return string|WP_Error
	 */
	public function generate( $prompt ) {
		return $this->chat(
			array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			)
		);
	}

	/**
	 * Send a minimal request to verify the key / endpoint works.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		// Thinking models (e.g. newer Gemini) spend tokens before emitting
		// text, so a tiny budget would come back empty.
		$this->max_tokens = 512;
		$result           = $this->generate( 'Reply with the single word: OK' );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Whether a key has been configured (always true for free providers).
	 *
	 * @return bool
	 */
	public function has_key() {
		return $this->is_free() || '' !== trim( (string) $this->api_key );
	}

	/**
	 * Shared JSON POST helper built on the WP HTTP API.
	 *
	 * @param string $url     Endpoint URL.
	 * @param array  $headers Request headers.
	 * @param array  $body    Request body.
	 * @return array|WP_Error Decoded JSON body on success.
	 */
	protected function request( $url, array $headers, array $body ) {
		if ( ! $this->has_key() ) {
			return new WP_Error(
				'cxai_missing_key',
				__( 'No API key configured for this engine. Add one on the AI Engines tab.', 'cubix-ai-article-generator' )
			);
		}

		$args = array(
			'timeout' => 90,
			'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'body'    => wp_json_encode( $body ),
		);

		$response = wp_remote_post( esc_url_raw( $url ), $args );

		// One quick retry when the model is momentarily overloaded
		// (503 / 529) — demand spikes usually clear within seconds.
		if ( ! is_wp_error( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 503 === $code || 529 === $code ) {
				sleep( 2 );
				$response = wp_remote_post( esc_url_raw( $url ), $args );
			}
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'cxai_http_error',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Could not reach the API: %s', 'cubix-ai-article-generator' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'cxai_api_error', $this->extract_error_message( $code, $data ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'cxai_bad_response',
				__( 'The API returned an unexpected response format.', 'cubix-ai-article-generator' )
			);
		}

		return $data;
	}

	/**
	 * Build a human-readable error message from an API error payload.
	 *
	 * @param int        $code HTTP status code.
	 * @param array|null $data Decoded response body.
	 * @return string
	 */
	protected function extract_error_message( $code, $data ) {
		$detail     = '';
		$error_code = '';

		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				$detail = (string) $data['error']['message'];
			} elseif ( isset( $data['message'] ) ) {
				$detail = (string) $data['message'];
			}

			if ( isset( $data['error']['code'] ) ) {
				$error_code = (string) $data['error']['code'];
			} elseif ( isset( $data['error']['type'] ) ) {
				$error_code = (string) $data['error']['type'];
			}
		}

		$haystack = strtolower( $error_code . ' ' . $detail );

		// Billing / quota problems masquerade under several statuses
		// (429, sometimes even 500), so detect them by content first.
		if ( false !== strpos( $haystack, 'insufficient_quota' )
			|| false !== strpos( $haystack, 'exceeded your current quota' )
			|| false !== strpos( $haystack, 'billing' )
			|| false !== strpos( $haystack, 'insufficient balance' )
			|| false !== strpos( $haystack, 'purchase credits' ) ) {
			$hint = __( 'No usable credit on this account.', 'cubix-ai-article-generator' );
		} elseif ( 401 === $code || 403 === $code ) {
			$hint = __( 'The API key was rejected — check it is correct and active.', 'cubix-ai-article-generator' );
		} elseif ( 404 === $code ) {
			$hint = __( 'That model was not found for this key — pick a different model.', 'cubix-ai-article-generator' );
		} elseif ( 429 === $code ) {
			$hint = __( 'Rate limit reached — wait a moment and try again.', 'cubix-ai-article-generator' );
		} elseif ( $code >= 500 ) {
			$hint = __( 'The AI service reported an internal problem — this is usually temporary, but on brand-new unfunded accounts it can also mean billing is not set up.', 'cubix-ai-article-generator' );
		} else {
			$hint = __( 'The request was not accepted.', 'cubix-ai-article-generator' );
		}

		// Always surface the engine's own words, clearly attributed.
		if ( '' !== $detail ) {
			return sprintf(
				/* translators: 1: plain-language hint, 2: raw API message, 3: HTTP status code. */
				__( '%1$s Engine response: "%2$s" (HTTP %3$d)', 'cubix-ai-article-generator' ),
				$hint,
				sanitize_text_field( $detail ),
				$code
			);
		}

		return sprintf(
			/* translators: 1: plain-language hint, 2: HTTP status code. */
			__( '%1$s (HTTP %2$d, no further detail from the engine)', 'cubix-ai-article-generator' ),
			$hint,
			$code
		);
	}

	/**
	 * Normalize a message list to safe role/content pairs.
	 *
	 * @param array $messages Raw messages.
	 * @return array
	 */
	protected function normalize_messages( array $messages ) {
		$clean = array();

		foreach ( $messages as $message ) {
			if ( ! isset( $message['role'], $message['content'] ) ) {
				continue;
			}

			$clean[] = array(
				'role'    => ( 'assistant' === $message['role'] ) ? 'assistant' : 'user',
				'content' => (string) $message['content'],
			);
		}

		return $clean;
	}
}
