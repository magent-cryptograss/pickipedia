/**
 * Deliver Blue Railroad — exercise video upload to delivery-kid.
 *
 * After upload+analysis, creates a ReleaseDraft wiki page (type: blue-railroad)
 * with exercise, venue, participants metadata and redirects there.
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

	// -- Ethereum block height --

	var MERGE_BLOCK = 15537394;
	var MERGE_TIMESTAMP = 1663224179;
	var SLOT_TIME = 12;

	function timestampToBlock( ts ) {
		return MERGE_BLOCK + Math.floor( ( ts - MERGE_TIMESTAMP ) / SLOT_TIME );
	}

	function blockToTimestamp( block ) {
		return MERGE_TIMESTAMP + ( block - MERGE_BLOCK ) * SLOT_TIME;
	}

	function updateBlockDateLabel( block, labelEl ) {
		if ( !labelEl || !block ) {
			return;
		}
		var ts = blockToTimestamp( block );
		var date = new Date( ts * 1000 );
		labelEl.textContent = '≈ ' + date.toLocaleDateString( undefined, {
			year: 'numeric', month: 'short', day: 'numeric'
		} );
	}

	function initBlockheightControls() {
		var nowBtn = el( 'dv-blockheight-now' );
		var bhInput = el( 'dv-content-blockheight' );
		var dateLabel = el( 'dv-blockheight-date' );

		if ( !nowBtn || !bhInput ) {
			return;
		}

		bhInput.addEventListener( 'input', function () {
			var val = parseInt( bhInput.value, 10 );
			if ( val > MERGE_BLOCK ) {
				updateBlockDateLabel( val, dateLabel );
			} else {
				dateLabel.textContent = '';
			}
		} );

		nowBtn.addEventListener( 'click', function () {
			var block = timestampToBlock( Math.floor( Date.now() / 1000 ) );
			bhInput.value = block;
			updateBlockDateLabel( block, dateLabel );
		} );

		// Date picker → block height conversion (Etherscan API with local fallback)
		var dateInput = el( 'dv-date-input' );
		if ( dateInput ) {
			dateInput.addEventListener( 'change', function () {
				if ( dateInput.value ) {
					var parts = dateInput.value.split( '-' );
					var ts = Math.floor( new Date( parts[ 0 ], parts[ 1 ] - 1, parts[ 2 ], 12, 0, 0 ).getTime() / 1000 );
					// Local estimate while API is in flight
					var block = timestampToBlock( ts );
					bhInput.value = block;
					updateBlockDateLabel( block, dateLabel );
					// Fetch exact block
					fetch( 'https://api.etherscan.io/api?module=block&action=getblocknobytime' +
						'&timestamp=' + ts + '&closest=before' )
						.then( function ( r ) { return r.json(); } )
						.then( function ( resp ) {
							if ( resp.status === '1' && resp.result ) {
								var exact = parseInt( resp.result, 10 );
								if ( exact > 0 ) {
									bhInput.value = exact;
									updateBlockDateLabel( exact, dateLabel );
								}
							}
						} )
						.catch( function () {} );
				}
			} );
		}
	}

	// -- File Upload --

	function initUploadStep() {
		var dropzone = el( 'dv-dropzone' );
		var fileInput = el( 'dv-file-input' );
		var fileList = el( 'dv-file-list' );
		var uploadBtn = el( 'dv-upload-btn' );
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
			// Validate mandatory fields
			var exercise = ( el( 'dv-exercise' ) || {} ).value || '';
			if ( !exercise ) {
				setStatus( 'dv-upload-status', 'Exercise is required.', 'error' );
				el( 'dv-exercise' ).focus();
				return;
			}
			var blockheight = ( el( 'dv-content-blockheight' ) || {} ).value || '';
			if ( !blockheight.trim() ) {
				setStatus( 'dv-upload-status', 'Block height is required.', 'error' );
				el( 'dv-content-blockheight' ).focus();
				return;
			}
			doUpload( selectedFiles );
		} );
	}

	function doUpload( files ) {
		var uploadBtn = el( 'dv-upload-btn' );
		var progressBar = el( 'dv-upload-progress' );
		var progressFill = progressBar.querySelector( '.uc-progress-fill' );

		uploadBtn.disabled = true;
		progressBar.style.display = '';
		setStatus( 'dv-upload-status', 'Uploading ' + files.length + ' file(s)...', '' );

		var formData = new FormData();
		files.forEach( function ( file ) {
			formData.append( 'files', file );
		} );

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', API_URL + '/draft-content' );

		Object.keys( AUTH_HEADERS ).forEach( function ( key ) {
			xhr.setRequestHeader( key, AUTH_HEADERS[ key ] );
		} );

		xhr.upload.addEventListener( 'progress', function ( e ) {
			if ( e.lengthComputable ) {
				var pct = Math.round( ( e.loaded / e.total ) * 100 );
				progressFill.style.width = pct + '%';
				setStatus( 'dv-upload-status',
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
				setStatus( 'dv-upload-status',
					'Upload failed (' + xhr.status + '): ' + errMsg, 'error' );
				uploadBtn.disabled = false;
				return;
			}

			var draft = JSON.parse( xhr.responseText );
			setStatus( 'dv-upload-status',
				'Draft created. Creating draft page...', 'success' );

			createReleaseDraftPage( draft );
		} );

		xhr.addEventListener( 'error', function () {
			progressBar.style.display = 'none';
			setStatus( 'dv-upload-status', 'Network error during upload.', 'error' );
			uploadBtn.disabled = false;
		} );

		xhr.send( formData );
	}

	// -- Create ReleaseDraft wiki page and redirect --

	function createReleaseDraftPage( draft ) {
		var draftId = draft.draft_id;
		var pageName = 'ReleaseDraft:' + draftId;

		var yaml = buildBlueRailroadYaml( draftId, draft );

		var exercise = ( el( 'dv-exercise' ) || {} ).value || '';
		var api = new mw.Api();
		api.postWithEditToken( {
			action: 'edit',
			title: pageName,
			text: yaml,
			summary: 'New Blue Railroad draft: ' + exercise,
			createonly: true
		} ).then( function () {
			window.location.href = mw.util.getUrl( pageName );
		} ).fail( function ( code, result ) {
			if ( code === 'articleexists' ) {
				window.location.href = mw.util.getUrl( pageName );
			} else {
				setStatus( 'dv-upload-status',
					'Failed to create draft page: ' + ( result.error ? result.error.info : code ), 'error' );
				el( 'dv-upload-btn' ).disabled = false;
			}
		} );
	}

	function buildBlueRailroadYaml( draftId, draft ) {
		var exercise = ( el( 'dv-exercise' ) || {} ).value || '';
		var contentBlockheight = ( el( 'dv-content-blockheight' ) || {} ).value || '';
		var uploadBlockheight = mw.config.get( 'wgUploadBlockheight' ) || '';
		var venue = ( el( 'dv-venue' ) || {} ).value || '';
		var recorder = ( el( 'dv-recorder' ) || {} ).value || '';
		var notes = ( el( 'dv-notes' ) || {} ).value || '';
		var participantsRaw = ( el( 'dv-participants' ) || {} ).value || '';

		var participants = participantsRaw.split( '\n' ).map( function ( s ) {
			return s.trim();
		} ).filter( function ( s ) {
			return s.length > 0;
		} );

		var lines = [];
		lines.push( 'draft_id: ' + draftId );
		lines.push( 'type: blue-railroad' );
		lines.push( 'source: special-deliver-blue-railroad' );
		lines.push( 'commit: ' + ( draft.commit || 'unknown' ) );
		lines.push( 'uploader: ' + quoteYamlValue( mw.config.get( 'wgUploadUser' ) || '' ) );
		lines.push( 'blockheight: ' + ( contentBlockheight ? contentBlockheight : 'null' ) );
		if ( uploadBlockheight ) {
			lines.push( 'upload_blockheight: ' + uploadBlockheight );
		}
		lines.push( 'content:' );
		lines.push( '    exercise: ' + quoteYamlValue( exercise ) );
		lines.push( '    file_type: video' );
		if ( venue ) {
			lines.push( '    venue: ' + quoteYamlValue( venue ) );
		}
		if ( recorder ) {
			lines.push( '    recorder: ' + quoteYamlValue( recorder ) );
		}
		if ( notes ) {
			lines.push( '    notes: ' + quoteYamlValue( notes ) );
		}
		if ( participants.length > 0 ) {
			lines.push( '    participants:' );
			participants.forEach( function ( p ) {
				lines.push( '        - ' + quoteYamlValue( p ) );
			} );
		}

		lines.push( 'files:' );
		( draft.files || [] ).forEach( function ( f ) {
			lines.push( '    -' );
			lines.push( '        original_filename: ' + quoteYamlValue( f.original_filename ) );
			lines.push( '        media_type: ' + quoteYamlValue( f.media_type || '' ) );
			lines.push( '        format: ' + quoteYamlValue( f.format || '' ) );
			if ( f.duration_seconds ) {
				lines.push( '        duration_seconds: ' + f.duration_seconds );
			}
			if ( f.width ) {
				lines.push( '        width: ' + f.width );
			}
			if ( f.height ) {
				lines.push( '        height: ' + f.height );
			}
			if ( f.video_codec ) {
				lines.push( '        video_codec: ' + quoteYamlValue( f.video_codec ) );
			}
			if ( f.audio_codec ) {
				lines.push( '        audio_codec: ' + quoteYamlValue( f.audio_codec ) );
			}
			if ( f.size_bytes ) {
				lines.push( '        size_bytes: ' + f.size_bytes );
			}
		} );

		return lines.join( '\n' ) + '\n';
	}

	// -- Init --

	function init() {
		if ( !API_URL ) {
			el( 'dv-step-upload' ).innerHTML =
				'<p class="uc-status uc-status-error">Delivery Kid URL not configured.</p>';
			return;
		}

		initUploadStep();
		initBlockheightControls();
	}

	mw.loader.using( [ 'mediawiki.util', 'mediawiki.api' ] ).then( init );

}() );
