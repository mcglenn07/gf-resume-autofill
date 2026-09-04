<?php

defined( 'ABSPATH' ) || exit;

GFForms::include_addon_framework();

class GFRA_AddOn extends GFAddOn {

	protected $_version                     = GFRA_VERSION;
	protected $_min_gravityforms_version    = '2.5';
	protected $_slug                        = 'gf-resume-autofill';
	protected $_path                        = 'gf-resume-autofill/gf-resume-autofill.php';
	protected $_full_path                   = GFRA_PLUGIN_FILE;
	protected $_title                       = 'Resume Autofill for Gravity Forms';
	protected $_short_title                 = 'Resume Autofill';

	const MASKED_KEY = '********************'; // plain ASCII — no multibyte escaping ambiguity in either quote style

	private static $_instance = null;

	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function init() {
		parent::init();

		add_filter( 'gform_pre_render', array( $this, 'inject_resume_disclosure' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		$rest_controller = new GFRA_REST_Controller();
		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );

		if ( ! defined( 'GFRA_ENCRYPTION_KEY' ) ) {
			add_action( 'admin_notices', array( $this, 'render_encryption_key_notice' ) );
		}
	}

	public function enqueue_frontend_assets() {
		wp_register_script( 'gfra-frontend', GFRA_PLUGIN_URL . 'assets/js/gfra-frontend.js', array( 'jquery' ), $this->_version, true );
		wp_register_style( 'gfra-frontend', GFRA_PLUGIN_URL . 'assets/css/gfra-frontend.css', array(), $this->_version );
		wp_localize_script( 'gfra-frontend', 'gfraSettings', array(
			'restUrl' => esc_url_raw( rest_url( 'gf-resume-autofill/v1/parse' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		) );
		wp_enqueue_script( 'gfra-frontend' );
		wp_enqueue_style( 'gfra-frontend' );
	}

	public function render_encryption_key_notice() {
		echo '<div class="notice notice-warning"><p>' . esc_html__(
			'Resume Autofill for Gravity Forms: for stronger key security, define GFRA_ENCRYPTION_KEY in wp-config.php. Falling back to a derived key for now.',
			'gf-resume-autofill'
		) . '</p></div>';
	}

	// -----------------------------------------------------------------
	// Plugin-wide settings
	// -----------------------------------------------------------------

	public function plugin_settings_fields() {
		return array(
			array(
				'title'  => esc_html__( 'Resume Autofill Settings', 'gf-resume-autofill' ),
				'fields' => array(
					array(
						'name'              => 'openai_api_key',
						'label'             => esc_html__( 'OpenAI API Key', 'gf-resume-autofill' ),
						'type'              => 'text',
						'input_type'        => 'password',
						'class'             => 'medium',
						'required'          => true,
						'feedback_callback' => array( $this, 'verify_openai_key' ),
						'tooltip'           => esc_html__( 'Stored encrypted. Never sent to the browser.', 'gf-resume-autofill' ),
					),
					array(
						'name'          => 'openai_model',
						'label'         => esc_html__( 'Model', 'gf-resume-autofill' ),
						'type'          => 'select',
						'choices'       => array(
							array( 'label' => 'GPT-4o mini (recommended, lower cost)', 'value' => 'gpt-4o-mini' ),
							array( 'label' => 'GPT-4o (higher accuracy)', 'value' => 'gpt-4o' ),
						),
						'default_value' => 'gpt-4o-mini',
					),
					array(
						'name'          => 'default_disclosure_text',
						'label'         => esc_html__( 'Default Disclosure Text', 'gf-resume-autofill' ),
						'type'          => 'textarea',
						'class'         => 'large',
						'default_value' => esc_html__(
							'Your resume will be processed by an AI service (OpenAI) to automatically fill in the fields below. It is not used to train their models and is retained by them for up to 30 days for abuse-monitoring purposes.',
							'gf-resume-autofill'
						),
					),
					array(
						'name'          => 'max_file_size_mb',
						'label'         => esc_html__( 'Max File Size for Parsing (MB)', 'gf-resume-autofill' ),
						'type'          => 'text',
						'input_type'    => 'number',
						'class'         => 'small',
						'default_value' => '5',
					),
					array(
						'name'          => 'rate_limit_per_day',
						'label'         => esc_html__( 'Max Parses Per Day (per IP)', 'gf-resume-autofill' ),
						'type'          => 'text',
						'input_type'    => 'number',
						'class'         => 'small',
						'default_value' => '100',
						'tooltip'       => esc_html__( 'Caps cost exposure from bots hitting the pre-submit parse endpoint.', 'gf-resume-autofill' ),
					),
					array(
						'name'    => 'enable_logging',
						'label'   => esc_html__( 'Debug Logging', 'gf-resume-autofill' ),
						'type'    => 'checkbox',
						'choices' => array(
							array( 'label' => esc_html__( 'Log parse requests/errors via the GF Logging add-on', 'gf-resume-autofill' ), 'name' => 'enable_logging' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Masks the stored key on redisplay so the settings screen never shows the
	 * raw ciphertext, and treats a resubmit of the mask as "unchanged."
	 * This save/redisplay pairing hasn't been exercised against a live GF
	 * install yet — worth testing before relying on it.
	 */
	public function settings_text( $field, $echo = true ) {
		if ( 'openai_api_key' === ( $field['name'] ?? '' ) ) {
			$stored = $this->get_plugin_setting( 'openai_api_key' );
			if ( ! empty( $stored ) ) {
				$field['value'] = self::MASKED_KEY;
			}
		}
		$html = parent::settings_text( $field, false );
		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- parent already escapes field markup
		}
		return $html;
	}

	public function update_plugin_settings( $settings ) {
		$incoming = $settings['openai_api_key'] ?? '';
		$existing = $this->get_plugin_setting( 'openai_api_key' );

		if ( '' === $incoming || self::MASKED_KEY === $incoming ) {
			$settings['openai_api_key'] = $existing; // unchanged from the masked placeholder
		} else {
			$settings['openai_api_key'] = GFRA_Encryption::encrypt( $incoming );
		}

		return parent::update_plugin_settings( $settings );
	}

	public function verify_openai_key( $value ) {
		if ( self::MASKED_KEY === $value ) {
			return true; // unchanged from a previously-verified stored value
		}
		if ( empty( $value ) ) {
			return false;
		}
		$response = wp_remote_get( 'https://api.openai.com/v1/models', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $value ),
			'timeout' => 15,
		) );
		return ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
	}

	public function get_decrypted_api_key() {
		return GFRA_Encryption::decrypt( $this->get_plugin_setting( 'openai_api_key' ) );
	}

	// -----------------------------------------------------------------
	// Per-form settings
	// -----------------------------------------------------------------

	public function form_settings_fields( $form ) {
		return array(
			array(
				'title'  => esc_html__( 'Resume Autofill', 'gf-resume-autofill' ),
				'fields' => array(
					array(
						'name'    => 'resume_autofill_enable',
						'label'   => esc_html__( 'Enable Resume Autofill', 'gf-resume-autofill' ),
						'type'    => 'checkbox',
						'choices' => array(
							array( 'label' => esc_html__( 'Enable for this form', 'gf-resume-autofill' ), 'name' => 'resume_autofill_enable' ),
						),
					),
					array(
						'name'       => 'resume_autofill_field_id',
						'label'      => esc_html__( 'Resume Upload Field', 'gf-resume-autofill' ),
						'type'       => 'field_select',
						'args'       => array( 'field_type' => array( 'fileupload' ) ),
						'dependency' => array( 'field' => 'resume_autofill_enable', 'values' => array( '1' ) ),
						'tooltip'    => esc_html__( 'Which File Upload field on this form accepts the resume.', 'gf-resume-autofill' ),
					),
					array(
						'name'          => 'resume_autofill_overwrite',
						'label'         => esc_html__( 'If a field already has a value', 'gf-resume-autofill' ),
						'type'          => 'radio',
						'choices'       => array(
							array( 'label' => esc_html__( "Keep the existing value (don't overwrite)", 'gf-resume-autofill' ), 'value' => 'keep' ),
							array( 'label' => esc_html__( 'Overwrite with parsed resume data', 'gf-resume-autofill' ), 'value' => 'overwrite' ),
						),
						'default_value' => 'keep',
						'dependency'    => array( 'field' => 'resume_autofill_enable', 'values' => array( '1' ) ),
					),
					array(
						'name'          => 'resume_autofill_disclosure_text',
						'label'         => esc_html__( 'Disclosure Text (optional override)', 'gf-resume-autofill' ),
						'type'          => 'textarea',
						'class'         => 'large',
						'dependency'    => array( 'field' => 'resume_autofill_enable', 'values' => array( '1' ) ),
						'tooltip'       => esc_html__( 'Leave blank to use the default disclosure text from the plugin-wide settings.', 'gf-resume-autofill' ),
					),
					array(
						'name'       => 'resume_autofill_field_map',
						'label'      => esc_html__( 'Field Mapping', 'gf-resume-autofill' ),
						'type'       => 'field_map',
						'dependency' => array( 'field' => 'resume_autofill_enable', 'values' => array( '1' ) ),
						'field_map'  => array(
							array( 'name' => 'personal.first_name',             'label' => esc_html__( 'First Name', 'gf-resume-autofill' ) ),
							array( 'name' => 'personal.last_name',              'label' => esc_html__( 'Last Name', 'gf-resume-autofill' ) ),
							array( 'name' => 'personal.email',                  'label' => esc_html__( 'Email', 'gf-resume-autofill' ) ),
							array( 'name' => 'personal.phone',                  'label' => esc_html__( 'Phone', 'gf-resume-autofill' ) ),
							array( 'name' => 'personal.address.street',         'label' => esc_html__( 'Street Address', 'gf-resume-autofill' ) ),
							array( 'name' => 'personal.address.city',           'label' => esc_html__( 'City', 'gf-resume-autofill' ) ),
							array( 'name' => 'personal.address.state',          'label' => esc_html__( 'State', 'gf-resume-autofill' ) ),
							array( 'name' => 'personal.address.zip',            'label' => esc_html__( 'ZIP', 'gf-resume-autofill' ) ),
							array( 'name' => 'personal.address.country',        'label' => esc_html__( 'Country', 'gf-resume-autofill' ) ),
							array( 'name' => 'personal.linkedin_url',           'label' => esc_html__( 'LinkedIn URL', 'gf-resume-autofill' ) ),
							array( 'name' => 'personal.portfolio_url',          'label' => esc_html__( 'Portfolio URL', 'gf-resume-autofill' ) ),
							array( 'name' => 'summary',                         'label' => esc_html__( 'Summary', 'gf-resume-autofill' ) ),
							array( 'name' => 'most_recent_position.job_title',  'label' => esc_html__( 'Current Job Title', 'gf-resume-autofill' ) ),
							array( 'name' => 'most_recent_position.employer',   'label' => esc_html__( 'Current Employer', 'gf-resume-autofill' ) ),
							array( 'name' => 'most_recent_position.start_date', 'label' => esc_html__( 'Current Position Start Date', 'gf-resume-autofill' ) ),
							array( 'name' => 'work_experience',  'label' => esc_html__( 'Work Experience', 'gf-resume-autofill' ), 'field_type' => array( 'text' ) ),
							array( 'name' => 'education',        'label' => esc_html__( 'Education', 'gf-resume-autofill' ),        'field_type' => array( 'text' ) ),
							array( 'name' => 'skills',           'label' => esc_html__( 'Skills', 'gf-resume-autofill' ),           'field_type' => array( 'text' ) ),
							array( 'name' => 'certifications',   'label' => esc_html__( 'Certifications', 'gf-resume-autofill' ),   'field_type' => array( 'text' ) ),
						),
					),
				),
			),
		);
	}

	// -----------------------------------------------------------------
	// Shared helpers
	// -----------------------------------------------------------------

	public function is_autofill_enabled( $form_id ) {
		return (bool) $this->get_form_setting( $form_id, 'resume_autofill_enable' );
	}

	public function get_form_setting( $form_id, $key, $default = null ) {
		$form     = GFAPI::get_form( $form_id );
		$settings = $form ? $this->get_form_settings( $form ) : array();
		return $settings[ $key ] ?? $default;
	}

	public function get_disclosure_text( $form_id ) {
		$form_text = $this->get_form_setting( $form_id, 'resume_autofill_disclosure_text' );
		return $form_text ?: $this->get_plugin_setting( 'default_disclosure_text' );
	}

	public function inject_resume_disclosure( $form ) {
		$form_id = $form['id'];

		if ( ! $this->is_autofill_enabled( $form_id ) ) {
			return $form;
		}

		$field_id = absint( $this->get_form_setting( $form_id, 'resume_autofill_field_id' ) );

		foreach ( $form['fields'] as &$field ) {
			if ( (int) $field->id !== $field_id ) {
				continue;
			}

			$disclosure  = $this->get_disclosure_text( $form_id );
			$checkbox_id = 'gfra-consent-' . $form_id . '-' . $field_id;

			$notice = sprintf(
				'<div class="gfra-disclosure"><p>%s</p><label><input type="checkbox" class="gfra-consent-checkbox" id="%s" /> %s</label></div>',
				esc_html( $disclosure ),
				esc_attr( $checkbox_id ),
				esc_html__( 'I agree to let AI process my resume to autofill this form.', 'gf-resume-autofill' )
			);

			$field->description         = ( $field->description ?? '' ) . $notice;
			$field->descriptionPlacement = 'below';
			$field->cssClass              = trim( ( $field->cssClass ?? '' ) . ' gfra-resume-field' );
		}

		return $form;
	}
}
