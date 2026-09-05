<?php

defined( 'ABSPATH' ) || exit;

class GFRA_REST_Controller {

	public function register_routes() {
		register_rest_route( 'gf-resume-autofill/v1', '/parse', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_parse_request' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
	}

	public function check_permission( WP_REST_Request $request ) {
		if ( wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return true;
		}
		return new WP_Error( 'invalid_nonce', __( 'Invalid request.', 'gf-resume-autofill' ), array( 'status' => 403 ) );
	}

	public function handle_parse_request( WP_REST_Request $request ) {
		$addon = GFRA_AddOn::get_instance();

		$form_id  = absint( $request->get_param( 'form_id' ) );
		$field_id = absint( $request->get_param( 'field_id' ) );

		$form = GFAPI::get_form( $form_id );
		if ( ! $form || ! $addon->is_autofill_enabled( $form_id ) ) {
			return new WP_Error( 'invalid_form', __( 'Resume autofill is not enabled for this form.', 'gf-resume-autofill' ), array( 'status' => 400 ) );
		}

		if ( absint( $addon->get_form_setting( $form_id, 'resume_autofill_field_id' ) ) !== $field_id ) {
			return new WP_Error( 'invalid_field', __( 'Invalid field.', 'gf-resume-autofill' ), array( 'status' => 400 ) );
		}

		if ( ! $this->under_rate_limit( $addon ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'gf-resume-autofill' ), array( 'status' => 429 ) );
		}

		$files = $request->get_file_params();
		$file  = $files['resume_file'] ?? null;

		$file_error = $this->validate_file( $file, $addon );
		if ( is_wp_error( $file_error ) ) {
			return $file_error;
		}

		$api_key = $addon->get_decrypted_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'not_configured', __( 'Resume parsing is not configured.', 'gf-resume-autofill' ), array( 'status' => 500 ) );
		}

		$mime_type = $this->detect_mime_type( $file['tmp_name'] );
		$text      = GFRA_Text_Extractor::extract( $file['tmp_name'], $mime_type );

		if ( strlen( trim( $text ) ) < 50 ) {
			return new WP_Error( 'unreadable', __( "Couldn't read this file \xe2\x80\x94 please fill the form manually.", 'gf-resume-autofill' ), array( 'status' => 422 ) );
		}

		$this->record_request( $addon );

		$parsed = GFRA_OpenAI_Client::parse_resume(
			$text,
			$api_key,
			$addon->get_plugin_setting( 'openai_model' ) ?: 'gpt-4o-mini'
		);

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$field_map = $addon->get_field_map( $form_id );
		$values    = GFRA_Field_Mapper::format_for_mapping( $parsed, $field_map );
		$overwrite = 'overwrite' === $addon->get_form_setting( $form_id, 'resume_autofill_overwrite', 'keep' );

		return rest_ensure_response( array(
			'success'   => true,
			'data'      => $values,
			'overwrite' => $overwrite,
		) );
	}

	private function detect_mime_type( $path ) {
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$type  = finfo_file( $finfo, $path );
		finfo_close( $finfo );
		return $type;
	}

	private function validate_file( $file, GFRA_AddOn $addon ) {
		if ( empty( $file ) || UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'gf-resume-autofill' ), array( 'status' => 400 ) );
		}

		$max_bytes = absint( $addon->get_plugin_setting( 'max_file_size_mb' ) ?: 5 ) * MB_IN_BYTES;
		if ( $file['size'] > $max_bytes ) {
			return new WP_Error( 'too_large', __( 'File is too large.', 'gf-resume-autofill' ), array( 'status' => 400 ) );
		}

		$allowed = array(
			'application/pdf',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		);

		if ( ! in_array( $this->detect_mime_type( $file['tmp_name'] ), $allowed, true ) ) {
			return new WP_Error( 'bad_type', __( 'Only PDF and DOCX files are supported.', 'gf-resume-autofill' ), array( 'status' => 400 ) );
		}

		return true;
	}

	private function under_rate_limit( GFRA_AddOn $addon ) {
		$limit = absint( $addon->get_plugin_setting( 'rate_limit_per_day' ) ?: 100 );
		$count = (int) get_transient( $this->rate_limit_key() );
		return $count < $limit;
	}

	private function record_request( GFRA_AddOn $addon ) {
		$key   = $this->rate_limit_key();
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, DAY_IN_SECONDS );
	}

	private function rate_limit_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'gfra_rate_' . md5( $ip );
	}
}
