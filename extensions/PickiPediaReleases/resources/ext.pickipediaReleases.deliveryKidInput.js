/**
 * DeliveryKidUpload — PageForms custom input type for delivery-kid uploads.
 *
 * Renders a dropzone inside a PageForms form field. On file selection,
 * uploads to delivery-kid staging and sets the hidden input value to
 * the draft_id. This allows form submissions (e.g. Blue Railroad) to
 * reference delivery-kid drafts without storing bytes on the wiki server.
 */
( function () {
	'use strict';

	var API_URL = mw.config.get( 'wgDeliveryKidUrl' );
	var AUTH_HEADERS = {
		'X-Upload-Token': mw.config.get( 'wgUploadToken' ),
		'X-Upload-User': mw.config.get( 'wgUploadUser' ),
		'X-Upload-Timestamp': String( mw.config.get( 'wgUploadTimestamp' ) )
	};

	function formatSize( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var size = bytes;
		var i = 0;
		while ( size >= 1024 && i < units.length - 1 ) {
			size /= 1024;
			i++;
		}
		return size.toFixed( 1 ) + ' ' + units[ i ];
	}

	function initWrapper( wrapper ) {
		var inputId = wrapper.dataset.inputId;
		var accept = wrapper.dataset.accept || '*/*';
		var hiddenInput = document.getElementById( inputId );
		var wrapperId = wrapper.id;

		var dropzone = document.getElementById( wrapperId + '-dropzone' );
		var fileInput = document.getElementById( wrapperId + '-file' );
		var progressBar = document.getElementById( wrapperId + '-progress' );
		var statusEl = document.getElementById( wrapperId + '-status' );

		if ( !dropzone || !fileInput || !hiddenInput ) {
			return;
		}

		// Change button (for already-uploaded state)
		var changeBtn = wrapper.querySelector( '.dki-change-btn' );
		if ( changeBtn ) {
			changeBtn.addEventListener( 'click', function () {
				wrapper.querySelector( '.dki-uploaded' ).style.display = 'none';
				dropzone.classList.remove( 'dki-hidden' );
			} );
		}

		// Dropzone events
		dropzone.addEventListener( 'click', function () {
			fileInput.click();
		} );

		dropzone.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			dropzone.classList.add( 'uc-dropzone-active' );
		} );

		dropzone.addEventListener( 'dragleave', function () {
			dropzone.classList.remove( 'uc-dropzone-active' );
		} );

		dropzone.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			dropzone.classList.remove( 'uc-dropzone-active' );
			if ( e.dataTransfer.files.length > 0 ) {
				doUpload( e.dataTransfer.files[ 0 ] );
			}
		} );

		fileInput.addEventListener( 'change', function () {
			if ( fileInput.files.length > 0 ) {
				doUpload( fileInput.files[ 0 ] );
			}
			fileInput.value = '';
		} );

		function setStatus( msg, type ) {
			statusEl.textContent = msg;
			statusEl.className = 'dki-status uc-status' + ( type ? ' uc-status-' + type : '' );
		}

		function doUpload( file ) {
			dropzone.style.display = 'none';
			progressBar.style.display = '';
			setStatus( 'Uploading ' + file.name + ' (' + formatSize( file.size ) + ')...', '' );

			var formData = new FormData();
			formData.append( 'files', file );

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', API_URL + '/draft-content' );

			Object.keys( AUTH_HEADERS ).forEach( function ( key ) {
				xhr.setRequestHeader( key, AUTH_HEADERS[ key ] );
			} );

			var progressFill = progressBar.querySelector( '.uc-progress-fill' );
			xhr.upload.addEventListener( 'progress', function ( e ) {
				if ( e.lengthComputable ) {
					var pct = Math.round( ( e.loaded / e.total ) * 100 );
					progressFill.style.width = pct + '%';
					setStatus( 'Uploading... ' + pct + '%', '' );
				}
			} );

			xhr.addEventListener( 'load', function () {
				progressBar.style.display = 'none';

				if ( xhr.status !== 200 ) {
					var errMsg;
					try {
						var err = JSON.parse( xhr.responseText );
						errMsg = typeof err.detail === 'string' ? err.detail :
							( err.detail && err.detail.error ? err.detail.error : JSON.stringify( err ) );
					} catch ( e ) {
						errMsg = xhr.status + ' ' + xhr.statusText;
					}
					setStatus( 'Upload failed: ' + errMsg, 'error' );
					dropzone.style.display = '';
					return;
				}

				var draft = JSON.parse( xhr.responseText );
				hiddenInput.value = draft.draft_id;
				setStatus( 'Uploaded: ' + file.name + ' (draft ' + draft.draft_id.slice( 0, 8 ) + '...)', 'success' );
			} );

			xhr.addEventListener( 'error', function () {
				progressBar.style.display = 'none';
				setStatus( 'Network error during upload.', 'error' );
				dropzone.style.display = '';
			} );

			xhr.send( formData );
		}
	}

	function init() {
		if ( !API_URL ) {
			return;
		}

		// Initialize all delivery-kid upload wrappers on the page
		var wrappers = document.querySelectorAll( '.dki-wrapper' );
		wrappers.forEach( initWrapper );
	}

	mw.loader.using( [ 'mediawiki.util' ] ).then( init );

}() );
