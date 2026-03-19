/**
 * Deliver Record — direct upload to delivery-kid from browser.
 *
 * After upload+analysis, creates a ReleaseDraft wiki page and redirects there.
 * The ReleaseDraft namespace handles review, metadata editing, and finalization.
 */
( function () {
	'use strict';

	var API_URL = mw.config.get( 'wgDeliveryKidUrl' );
	var AUTH_HEADERS = {
		'X-Upload-Token': mw.config.get( 'wgUploadToken' ),
		'X-Upload-User': mw.config.get( 'wgUploadUser' ),
		'X-Upload-Timestamp': String( mw.config.get( 'wgUploadTimestamp' ) )
	};

	// -- Helpers --

	function el( id ) {
		return document.getElementById( id );
	}

	function setStatus( elementId, message, type ) {
		var e = el( elementId );
		e.textContent = message;
		e.className = 'uc-status' + ( type ? ' uc-status-' + type : '' );
	}

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

	// -- File Upload --

	function initUploadStep() {
		var dropzone = el( 'ua-dropzone' );
		var fileInput = el( 'ua-file-input' );
		var fileList = el( 'ua-file-list' );
		var uploadBtn = el( 'ua-upload-btn' );
		var selectedFiles = [];

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
			addFiles( e.dataTransfer.files );
		} );

		fileInput.addEventListener( 'change', function () {
			addFiles( fileInput.files );
			fileInput.value = '';
		} );

		function addFiles( newFiles ) {
			for ( var i = 0; i < newFiles.length; i++ ) {
				selectedFiles.push( newFiles[ i ] );
			}
			renderFileList();
		}

		function renderFileList() {
			fileList.innerHTML = '';
			selectedFiles.forEach( function ( file, idx ) {
				var item = document.createElement( 'div' );
				item.className = 'uc-file-item';
				item.innerHTML =
					'<span class="uc-file-name">' + mw.html.escape( file.name ) + '</span>' +
					'<span class="uc-file-size">' + formatSize( file.size ) + '</span>' +
					'<button class="uc-file-remove" data-idx="' + idx + '">&times;</button>';
				fileList.appendChild( item );
			} );

			fileList.querySelectorAll( '.uc-file-remove' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					selectedFiles.splice( parseInt( btn.dataset.idx ), 1 );
					renderFileList();
				} );
			} );

			uploadBtn.disabled = selectedFiles.length === 0;
		}

		uploadBtn.addEventListener( 'click', function () {
			if ( selectedFiles.length === 0 ) {
				return;
			}
			doUpload( selectedFiles );
		} );
	}

	function doUpload( files ) {
		var uploadBtn = el( 'ua-upload-btn' );
		var progressBar = el( 'ua-upload-progress' );
		var progressFill = progressBar.querySelector( '.uc-progress-fill' );

		uploadBtn.disabled = true;
		progressBar.style.display = '';
		setStatus( 'ua-upload-status', 'Uploading ' + files.length + ' file(s)...', '' );

		var formData = new FormData();
		files.forEach( function ( file ) {
			formData.append( 'files', file );
		} );

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', API_URL + '/draft-album' );

		Object.keys( AUTH_HEADERS ).forEach( function ( key ) {
			xhr.setRequestHeader( key, AUTH_HEADERS[ key ] );
		} );

		xhr.upload.addEventListener( 'progress', function ( e ) {
			if ( e.lengthComputable ) {
				var pct = Math.round( ( e.loaded / e.total ) * 100 );
				progressFill.style.width = pct + '%';
				setStatus( 'ua-upload-status',
					'Uploading... ' + formatSize( e.loaded ) + ' / ' + formatSize( e.total ) +
					' (' + pct + '%)', '' );
			}
		} );

		xhr.addEventListener( 'load', function () {
			progressBar.style.display = 'none';

			if ( xhr.status !== 200 ) {
				var errMsg;
				try {
					var err = JSON.parse( xhr.responseText );
					var detail = err.detail;
					if ( typeof detail === 'string' ) {
						errMsg = detail;
					} else if ( detail && detail.error ) {
						errMsg = detail.error;
					} else if ( Array.isArray( detail ) ) {
						errMsg = detail.map( function ( d ) { return d.msg || JSON.stringify( d ); } ).join( '; ' );
					} else {
						errMsg = JSON.stringify( err );
					}
				} catch ( e ) {
					errMsg = xhr.status + ' ' + xhr.statusText + ': ' + xhr.responseText.slice( 0, 200 );
				}
				setStatus( 'ua-upload-status',
					'Upload failed (' + xhr.status + '): ' + errMsg, 'error' );
				uploadBtn.disabled = false;
				return;
			}

			var draft = JSON.parse( xhr.responseText );
			setStatus( 'ua-upload-status',
				'Draft created. ' + draft.files.length + ' track(s) analyzed. Creating draft page...', 'success' );

			createReleaseDraftPage( draft );
		} );

		xhr.addEventListener( 'error', function () {
			progressBar.style.display = 'none';
			setStatus( 'ua-upload-status', 'Network error during upload.', 'error' );
			uploadBtn.disabled = false;
		} );

		xhr.send( formData );
	}

	// -- Create ReleaseDraft wiki page and redirect --

	function createReleaseDraftPage( draft ) {
		var draftId = draft.draft_id;
		var pageName = 'ReleaseDraft:' + draftId;

		// Build initial YAML from delivery-kid analysis
		var tracks = ( draft.files || [] ).map( function ( f ) {
			var title = f.detected_title || f.original_filename.replace( /\.[^.]+$/, '' );
			return {
				filename: f.original_filename,
				title: title,
				format: f.format || '',
				duration: f.duration_seconds || null,
				size_bytes: f.size_bytes || null,
				metadata: ''
			};
		} );

		var yaml = buildAlbumYaml( draftId, draft.commit || 'unknown', tracks );

		var api = new mw.Api();
		api.postWithEditToken( {
			action: 'edit',
			title: pageName,
			text: yaml,
			summary: 'New album draft: ' + draft.files.length + ' tracks uploaded',
			createonly: true
		} ).then( function () {
			// Redirect to the new draft page
			window.location.href = mw.util.getUrl( pageName );
		} ).fail( function ( code, result ) {
			if ( code === 'articleexists' ) {
				// Draft page already exists (maybe re-upload?) — just redirect
				window.location.href = mw.util.getUrl( pageName );
			} else {
				setStatus( 'ua-upload-status',
					'Failed to create draft page: ' + ( result.error ? result.error.info : code ), 'error' );
				el( 'ua-upload-btn' ).disabled = false;
			}
		} );
	}

	function buildAlbumYaml( draftId, commit, tracks ) {
		var lines = [];
		lines.push( 'draft_id: ' + draftId );
		lines.push( 'type: record' );
		lines.push( 'source: special-deliver-record' );
		lines.push( 'commit: ' + commit );
		lines.push( 'uploader: ' + quoteYamlValue( mw.config.get( 'wgUploadUser' ) || '' ) );
		lines.push( 'blockheight: null' );
		var uploadBh = mw.config.get( 'wgUploadBlockheight' );
		if ( uploadBh ) {
			lines.push( 'upload_blockheight: ' + uploadBh );
		}
		lines.push( 'album:' );
		lines.push( '    title: ""' );
		lines.push( '    artist: ""' );
		lines.push( '    version: ""' );
		lines.push( '    description: ""' );
		lines.push( 'tracks:' );

		tracks.forEach( function ( track ) {
			lines.push( '    -' );
			lines.push( '        filename: ' + quoteYamlValue( track.filename ) );
			lines.push( '        title: ' + quoteYamlValue( track.title ) );
			if ( track.format ) {
				lines.push( '        format: ' + quoteYamlValue( track.format ) );
			}
			if ( track.duration ) {
				lines.push( '        duration: ' + track.duration );
			}
			if ( track.size_bytes ) {
				lines.push( '        size_bytes: ' + track.size_bytes );
			}
			lines.push( '        metadata: ""' );
		} );

		return lines.join( '\n' ) + '\n';
	}

	/**
	 * Escape a string for safe inclusion in hand-built YAML.
	 * Wraps in double quotes if the value contains YAML-special characters.
	 * No YAML library is available in ResourceLoader, so we do this manually.
	 */
	function quoteYamlValue( val ) {
		if ( val === '' || val === null || val === undefined ) {
			return '""';
		}
		val = String( val );
		if ( /[:#\[\]{}&*!|>'"%@`\n]/.test( val ) || val.trim() !== val ) {
			return '"' + val.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ).replace( /\n/g, '\\n' ) + '"';
		}
		return val;
	}

	// -- Init --

	function init() {
		if ( !API_URL ) {
			el( 'ua-step-upload' ).innerHTML =
				'<p class="uc-status uc-status-error">Delivery Kid URL not configured.</p>';
			return;
		}

		initUploadStep();
	}

	mw.loader.using( [ 'mediawiki.util', 'mediawiki.api' ] ).then( init );

}() );
