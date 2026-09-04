<?php

defined( 'ABSPATH' ) || exit;

class GFRA_Field_Mapper {

	public static function extract_by_dot_path( array $data, $path ) {
		$value = $data;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
			} else {
				return null;
			}
		}

		return $value;
	}

	/**
	 * $field_map: schema dot-path => GF field ID (as saved by the field_map setting).
	 * Formatting happens here, server-side, so the frontend never branches on
	 * scalar vs. array — every mapped output is just a string.
	 */
	public static function format_for_mapping( array $parsed, array $field_map ) {
		$output = array();

		foreach ( $field_map as $schema_key => $field_id ) {
			if ( empty( $field_id ) ) {
				continue; // unmapped = skip
			}

			$value = self::extract_by_dot_path( $parsed, $schema_key );

			switch ( $schema_key ) {
				case 'skills':
				case 'certifications':
					$output[ $field_id ] = implode( ', ', array_filter( (array) $value ) );
					break;

				case 'work_experience':
					$output[ $field_id ] = implode( '; ', array_filter( array_map( function( $job ) {
						if ( empty( $job['job_title'] ) && empty( $job['employer'] ) ) {
							return '';
						}
						return sprintf(
							'%s at %s (%s \xe2\x80\x93 %s)',
							$job['job_title'] ?? '',
							$job['employer'] ?? '',
							$job['start_date'] ?? '',
							! empty( $job['is_current'] ) ? 'Present' : ( $job['end_date'] ?? '' )
						);
					}, (array) $value ) ) );
					break;

				case 'education':
					$output[ $field_id ] = implode( '; ', array_filter( array_map( function( $edu ) {
						if ( empty( $edu['institution'] ) ) {
							return '';
						}
						return sprintf(
							'%s, %s (%s)',
							$edu['degree'] ?? '',
							$edu['institution'] ?? '',
							$edu['end_date'] ?? ''
						);
					}, (array) $value ) ) );
					break;

				default:
					$output[ $field_id ] = (string) ( $value ?? '' );
			}
		}

		return $output;
	}
}
