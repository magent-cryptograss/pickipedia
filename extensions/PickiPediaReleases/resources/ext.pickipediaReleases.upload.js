/**
 * Upload Content — direct upload to delivery-kid from browser.
 *
 * Wiki generates an HMAC token. JS uploads directly to delivery-kid.
 * No bytes pass through PHP.
 */
( function () {
	'use strict';

	var API_URL = mw.config.get( 'wgDeliveryKidUrl' );
	var AUTH_HEADERS = {
		'X-Upload-Token': mw.config.get( 'wgUploadToken' ),
		'X-Upload-User': mw.config.get( 'wgUploadUser' ),
		'X-Upload-Timestamp': String( mw.config.get( 'wgUploadTimestamp' ) )
	};

	var currentDraftId = null;

	// -- Helpers --

	function el( id ) {
		return document.getElementById( id );
	}

	function setStatus( elementId, message, type ) {
		var e = el( elementId );
		e.textContent = message;
		e.className = 'uc-status' + ( type ? ' uc-status-' + type : '' );
	}

	function showStep( stepId ) {
		document.querySelectorAll( '.uc-step' ).forEach( function ( s ) {
			s.classList.remove( 'uc-step-active' );
		} );
		el( stepId ).classList.add( 'uc-step-active' );
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

	function formatDuration( seconds ) {
		if ( !seconds ) {
			return '';
		}
		var m = Math.floor( seconds / 60 );
		var s = Math.floor( seconds % 60 );
		return m + ':' + ( s < 10 ? '0' : '' ) + s;
	}

	// -- Step 1: File Upload --

	function initUploadStep() {
		var dropzone = el( 'uc-dropzone' );
		var fileInput = el( 'uc-file-input' );
		var fileList = el( 'uc-file-list' );
		var uploadBtn = el( 'uc-upload-btn' );
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
		var uploadBtn = el( 'uc-upload-btn' );
		var progressBar = el( 'uc-upload-progress' );
		var progressFill = progressBar.querySelector( '.uc-progress-fill' );

		uploadBtn.disabled = true;
		progressBar.style.display = '';
		setStatus( 'uc-upload-status', 'Uploading ' + files.length + ' file(s)...', '' );

		var formData = new FormData();
		files.forEach( function ( file ) {
			formData.append( 'files', file );
		} );

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', API_URL + '/draft-content' );

		// Set auth headers
		Object.keys( AUTH_HEADERS ).forEach( function ( key ) {
			xhr.setRequestHeader( key, AUTH_HEADERS[ key ] );
		} );

		xhr.upload.addEventListener( 'progress', function ( e ) {
			if ( e.lengthComputable ) {
				var pct = Math.round( ( e.loaded / e.total ) * 100 );
				progressFill.style.width = pct + '%';
				setStatus( 'uc-upload-status',
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
				setStatus( 'uc-upload-status',
					'Upload failed (' + xhr.status + '): ' + errMsg, 'error' );
				uploadBtn.disabled = false;
				return;
			}

			var draft = JSON.parse( xhr.responseText );
			currentDraftId = draft.draft_id;

			setStatus( 'uc-upload-status',
				'Draft created: ' + currentDraftId.slice( 0, 8 ) + '...', 'success' );

			renderReview( draft );
			showStep( 'uc-step-review' );
		} );

		xhr.addEventListener( 'error', function () {
			progressBar.style.display = 'none';
			setStatus( 'uc-upload-status', 'Network error during upload.', 'error' );
			uploadBtn.disabled = false;
		} );

		xhr.send( formData );
	}

	// -- Step 2: Review & Metadata --

	function renderReview( draft ) {
		var info = el( 'uc-draft-info' );
		var html = '<table class="wikitable">';
		html += '<tr><th>File</th><th>Type</th><th>Format</th><th>Duration</th><th>Size</th></tr>';

		var hasVideo = false;
		draft.files.forEach( function ( f ) {
			html += '<tr>';
			html += '<td>' + mw.html.escape( f.original_filename ) + '</td>';
			html += '<td>' + mw.html.escape( f.media_type ) + '</td>';
			html += '<td>' + mw.html.escape( f.format ) + '</td>';
			html += '<td>' + formatDuration( f.duration_seconds ) + '</td>';
			html += '<td>' + formatSize( f.size_bytes ) + '</td>';
			html += '</tr>';

			if ( f.width && f.height ) {
				html += '<tr><td></td><td colspan="4">' + f.width + 'x' + f.height;
				if ( f.video_codec ) {
					html += ' &middot; ' + mw.html.escape( f.video_codec );
				}
				if ( f.audio_codec ) {
					html += ' &middot; ' + mw.html.escape( f.audio_codec );
				}
				html += '</td></tr>';
			}

			if ( f.media_type === 'video' ) {
				hasVideo = true;
			}
		} );

		html += '</table>';
		html += '<p class="uc-draft-expires">Draft expires: ' +
			new Date( draft.expires_at ).toLocaleString() + '</p>';
		info.innerHTML = html;

		// Pre-fill title
		if ( draft.files.length === 1 && draft.files[ 0 ].detected_title ) {
			el( 'uc-title' ).value = draft.files[ 0 ].detected_title;
		}

		el( 'uc-hls-field' ).style.display = hasVideo ? '' : 'none';
	}

	function initReviewStep() {
		el( 'uc-finalize-btn' ).addEventListener( 'click', function () {
			startFinalization();
		} );

		el( 'uc-delete-draft-btn' ).addEventListener( 'click', function () {
			if ( !currentDraftId || !confirm( 'Delete this draft? This cannot be undone.' ) ) {
				return;
			}
			fetch( API_URL + '/draft-content/' + currentDraftId, {
				method: 'DELETE',
				headers: AUTH_HEADERS
			} ).then( function () {
				currentDraftId = null;
				showStep( 'uc-step-upload' );
				setStatus( 'uc-upload-status', 'Draft deleted.', '' );
			} );
		} );
	}

	// -- Step 3: Finalize --

	function startFinalization() {
		if ( !currentDraftId ) {
			return;
		}

		showStep( 'uc-step-progress' );
		setProgress( 0 );
		setStatus( 'uc-progress-status', 'Starting finalization...', '' );

		var headers = Object.assign( {}, AUTH_HEADERS, {
			'Content-Type': 'application/json'
		} );

		var body = JSON.stringify( {
			title: el( 'uc-title' ).value || null,
			description: el( 'uc-description' ).value || null,
			file_type: el( 'uc-file-type' ).value || null,
			subsequent_to: el( 'uc-subsequent-to' ).value || null,
			transcode_hls: el( 'uc-transcode-hls' ).checked,
			metadata: {}
		} );

		fetch( API_URL + '/draft-content/' + currentDraftId + '/finalize', {
			method: 'POST',
			headers: headers,
			body: body
		} ).then( function ( resp ) {
			if ( !resp.ok ) {
				return resp.json().then( function ( err ) {
					throw new Error( err.detail || resp.statusText );
				} );
			}
			return readSSEStream( resp );
		} ).catch( function ( err ) {
			setStatus( 'uc-progress-status', 'Error: ' + err.message, 'error' );
		} );
	}

	function readSSEStream( resp ) {
		var reader = resp.body.getReader();
		var decoder = new TextDecoder();
		var buffer = '';

		function pump() {
			return reader.read().then( function ( result ) {
				if ( result.done ) {
					return;
				}

				buffer += decoder.decode( result.value, { stream: true } );
				var lines = buffer.split( '\n' );
				buffer = lines.pop();

				var currentEvent = '';
				for ( var i = 0; i < lines.length; i++ ) {
					var line = lines[ i ].trim();
					if ( line.indexOf( 'event:' ) === 0 ) {
						currentEvent = line.slice( 6 ).trim();
					} else if ( line.indexOf( 'data:' ) === 0 ) {
						var data = line.slice( 5 ).trim();
						try {
							handleSSEEvent( currentEvent, JSON.parse( data ) );
						} catch ( e ) {
							// skip malformed
						}
					}
				}

				return pump();
			} );
		}

		return pump();
	}

	function handleSSEEvent( event, data ) {
		if ( event === 'progress' ) {
			setProgress( data.progress || 0 );
			setStatus( 'uc-progress-status', data.message || '', '' );
		} else if ( event === 'complete' ) {
			setProgress( 100 );
			setStatus( 'uc-progress-status', 'Pinning complete!', 'success' );
			renderResult( data );
			currentDraftId = null;
		} else if ( event === 'error' ) {
			setStatus( 'uc-progress-status',
				'Error: ' + ( data.message || 'Unknown error' ), 'error' );
		}
	}

	function setProgress( pct ) {
		var fill = document.querySelector( '#uc-progress-bar .uc-progress-fill' );
		if ( fill ) {
			fill.style.width = pct + '%';
		}
	}

	function renderResult( data ) {
		var resultEl = el( 'uc-result' );
		var releaseUrl = mw.util.getUrl( 'Release:' + data.cid );

		var html = '<div class="uc-result-card">';
		html += '<h4>Content Pinned Successfully</h4>';
		html += '<table class="wikitable">';
		if ( data.title ) {
			html += '<tr><th>Title</th><td>' + mw.html.escape( data.title ) + '</td></tr>';
		}
		html += '<tr><th>CID</th><td class="release-cid-cell">' +
			mw.html.escape( data.cid ) + '</td></tr>';
		if ( data.gateway_url ) {
			html += '<tr><th>Gateway</th><td><a href="' + mw.html.escape( data.gateway_url ) +
				'" target="_blank">' + mw.html.escape( data.gateway_url ) + '</a></td></tr>';
		}
		html += '<tr><th>Pinata</th><td>' + ( data.pinata ? 'Yes' : 'No' ) + '</td></tr>';
		if ( data.subsequent_to ) {
			html += '<tr><th>Supersedes</th><td>' +
				mw.html.escape( data.subsequent_to ) + '</td></tr>';
		}
		html += '</table>';
		html += '<p><a href="' + mw.html.escape( releaseUrl ) +
			'" class="cdx-button cdx-button--action-progressive">View Release Page</a></p>';
		html += '<button id="uc-start-over" class="cdx-button">Upload More Content</button>';
		html += '</div>';

		resultEl.innerHTML = html;

		el( 'uc-start-over' ).addEventListener( 'click', function () {
			resultEl.innerHTML = '';
			showStep( 'uc-step-upload' );
		} );
	}

	// -- Init --

	function init() {
		if ( !API_URL ) {
			el( 'uc-step-upload' ).innerHTML =
				'<p class="uc-status uc-status-error">Delivery Kid URL not configured.</p>';
			return;
		}

		initUploadStep();
		initReviewStep();
	}

	mw.loader.using( 'mediawiki.util' ).then( init );

}() );
