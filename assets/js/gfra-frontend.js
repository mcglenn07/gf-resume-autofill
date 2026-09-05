( function( $ ) {
	'use strict';

	function maybeUpload( $fieldWrap ) {
		var $input   = $fieldWrap.find( 'input[type="file"]' );
		var $consent = $fieldWrap.find( '.gfra-consent-checkbox' );
		var file     = $input[ 0 ] && $input[ 0 ].files && $input[ 0 ].files[ 0 ];

		if ( ! file || ! $consent.length || ! $consent.is( ':checked' ) ) {
			return;
		}

		var match = ( $fieldWrap.attr( 'id' ) || '' ).match( /field_(\d+)_(\d+)/ );
		if ( ! match ) {
			return;
		}
		var formId  = match[ 1 ];
		var fieldNo = match[ 2 ];

		var $status = $fieldWrap.find( '.gfra-status' );
		if ( ! $status.length ) {
			$status = $( '<div class="gfra-status"></div>' );
			$fieldWrap.find( '.gfra-disclosure' ).after( $status );
		}
		$status.text( 'Reading your resume…' );

		var formData = new FormData();
		formData.append( 'resume_file', file );
		formData.append( 'form_id', formId );
		formData.append( 'field_id', fieldNo );

		$.ajax( {
			url: gfraSettings.restUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			beforeSend: function( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', gfraSettings.nonce );
			}
		} ).done( function( response ) {
			if ( ! response || ! response.success ) {
				$status.text( '' );
				return;
			}

			$.each( response.data, function( targetFieldId, value ) {
				var $target = $( '#input_' + formId + '_' + targetFieldId );
				if ( $target.length && ( response.overwrite || ! $target.val() ) ) {
					$target.val( value ).trigger( 'change' );
				}
			} );

			$status.text( 'Form fields updated from your resume — please review before submitting.' );
		} ).fail( function( xhr ) {
			var message = ( xhr.responseJSON && xhr.responseJSON.message ) ||
				'Could not process this file — please fill the form manually.';
			$status.text( message );
		} );
	}

	$( document ).on( 'change', '.gfra-resume-field input[type="file"], .gfra-resume-field .gfra-consent-checkbox', function() {
		maybeUpload( $( this ).closest( '.gfield' ) );
	} );

} )( jQuery );
