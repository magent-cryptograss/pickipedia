/**
 * Upload Album — direct upload to delivery-kid from browser.
 *
 * Three-step flow using /draft-album:
 * 1. Upload audio + cover → delivery-kid analyzes tracks
 * 2. Review: reorder tracks, edit titles, set album metadata
 * 3. Finalize → transcode FLAC/WAV→OGG, embed tags, pin to IPFS
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
	var draftFiles = []; // analyzed files from draft response

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

		// Set auth headers
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
			currentDraftId = draft.draft_id;
			draftFiles = draft.files;

			setStatus( 'ua-upload-status',
				'Draft created. ' + draftFiles.length + ' track(s) analyzed.', 'success' );

			renderReview( draft );
			showStep( 'ua-step-review' );
		} );

		xhr.addEventListener( 'error', function () {
			progressBar.style.display = 'none';
			setStatus( 'ua-upload-status', 'Network error during upload.', 'error' );
			uploadBtn.disabled = false;
		} );

		xhr.send( formData );
	}

	// -- Step 2: Review & Track Ordering --

	function renderReview( draft ) {
		// Show analysis summary
		var info = el( 'ua-draft-info' );
		var totalDuration = 0;
		var totalSize = 0;
		var formats = {};

		draft.files.forEach( function ( f ) {
			if ( f.duration_seconds ) {
				totalDuration += f.duration_seconds;
			}
			if ( f.size_bytes ) {
				totalSize += f.size_bytes;
			}
			formats[ f.format ] = ( formats[ f.format ] || 0 ) + 1;
		} );

		var formatSummary = Object.keys( formats ).map( function ( fmt ) {
			return formats[ fmt ] + ' ' + fmt;
		} ).join( ', ' );

		info.innerHTML =
			'<p>' + draft.files.length + ' tracks &middot; ' +
			formatDuration( totalDuration ) + ' total &middot; ' +
			formatSize( totalSize ) + ' &middot; ' + formatSummary + '</p>' +
			'<p class="uc-draft-expires">Draft expires: ' +
			new Date( draft.expires_at ).toLocaleString() + '</p>';

		// Render track list
		renderTrackList( draft.files );
	}

	function renderTrackList( files ) {
		var container = el( 'ua-track-list' );
		container.innerHTML = '';

		files.forEach( function ( file, idx ) {
			var row = document.createElement( 'div' );
			row.className = 'ua-track-row';
			row.draggable = true;
			row.dataset.idx = idx;

			var title = file.detected_title || file.original_filename.replace( /\.[^.]+$/, '' );

			row.innerHTML =
				'<span class="ua-track-handle" title="Drag to reorder">&#9776;</span>' +
				'<span class="ua-track-num">' + ( idx + 1 ) + '</span>' +
				'<input type="text" class="ua-track-title cdx-text-input__input" ' +
					'value="' + mw.html.escape( title ) + '" ' +
					'data-filename="' + mw.html.escape( file.original_filename ) + '">' +
				'<span class="ua-track-meta">' +
					mw.html.escape( file.format ) + ' &middot; ' +
					formatDuration( file.duration_seconds ) + ' &middot; ' +
					formatSize( file.size_bytes ) +
				'</span>';

			container.appendChild( row );
		} );

		initDragReorder( container );
	}

	function initDragReorder( container ) {
		var dragSrc = null;

		container.addEventListener( 'dragstart', function ( e ) {
			var row = e.target.closest( '.ua-track-row' );
			if ( !row ) {
				return;
			}
			dragSrc = row;
			row.classList.add( 'ua-track-dragging' );
			e.dataTransfer.effectAllowed = 'move';
		} );

		container.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			e.dataTransfer.dropEffect = 'move';
			var row = e.target.closest( '.ua-track-row' );
			if ( row && row !== dragSrc ) {
				var rect = row.getBoundingClientRect();
				var midY = rect.top + rect.height / 2;
				if ( e.clientY < midY ) {
					container.insertBefore( dragSrc, row );
				} else {
					container.insertBefore( dragSrc, row.nextSibling );
				}
			}
		} );

		container.addEventListener( 'dragend', function () {
			if ( dragSrc ) {
				dragSrc.classList.remove( 'ua-track-dragging' );
				dragSrc = null;
			}
			renumberTracks( container );
		} );
	}

	function renumberTracks( container ) {
		var rows = container.querySelectorAll( '.ua-track-row' );
		rows.forEach( function ( row, idx ) {
			row.dataset.idx = idx;
			row.querySelector( '.ua-track-num' ).textContent = idx + 1;
		} );
	}

	function getTrackOrder() {
		var rows = el( 'ua-track-list' ).querySelectorAll( '.ua-track-row' );
		var tracks = [];
		rows.forEach( function ( row ) {
			var input = row.querySelector( '.ua-track-title' );
			tracks.push( {
				filename: input.dataset.filename,
				title: input.value
			} );
		} );
		return tracks;
	}

	function initReviewStep() {
		el( 'ua-finalize-btn' ).addEventListener( 'click', function () {
			var albumTitle = el( 'ua-album-title' ).value.trim();
			var artist = el( 'ua-artist' ).value.trim();

			if ( !albumTitle ) {
				setStatus( 'ua-upload-status', 'Album title is required.', 'error' );
				el( 'ua-album-title' ).focus();
				return;
			}
			if ( !artist ) {
				setStatus( 'ua-upload-status', 'Artist is required.', 'error' );
				el( 'ua-artist' ).focus();
				return;
			}

			startFinalization();
		} );

		el( 'ua-delete-draft-btn' ).addEventListener( 'click', function () {
			if ( !currentDraftId || !confirm( 'Delete this draft? This cannot be undone.' ) ) {
				return;
			}
			fetch( API_URL + '/draft-album/' + currentDraftId, {
				method: 'DELETE',
				headers: AUTH_HEADERS
			} ).then( function () {
				currentDraftId = null;
				draftFiles = [];
				showStep( 'ua-step-upload' );
				setStatus( 'ua-upload-status', 'Draft deleted.', '' );
			} );
		} );
	}

	// -- Step 3: Finalize --

	function startFinalization() {
		if ( !currentDraftId ) {
			return;
		}

		showStep( 'ua-step-progress' );
		setProgress( 0 );
		setStatus( 'ua-progress-status', 'Starting album finalization...', '' );

		var headers = Object.assign( {}, AUTH_HEADERS, {
			'Content-Type': 'application/json'
		} );

		var body = JSON.stringify( {
			album_title: el( 'ua-album-title' ).value.trim(),
			artist: el( 'ua-artist' ).value.trim(),
			year: el( 'ua-year' ).value.trim() || null,
			description: el( 'ua-description' ).value.trim() || null,
			tracks: getTrackOrder()
		} );

		fetch( API_URL + '/draft-album/' + currentDraftId + '/finalize', {
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
			setStatus( 'ua-progress-status', 'Error: ' + err.message, 'error' );
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
			var msg = data.message || '';
			if ( data.track ) {
				msg += ' (' + data.track + ')';
			}
			setStatus( 'ua-progress-status', msg, '' );
		} else if ( event === 'warning' ) {
			setStatus( 'ua-progress-status', 'Warning: ' + data.message, 'warning' );
		} else if ( event === 'complete' ) {
			setProgress( 100 );
			setStatus( 'ua-progress-status', 'Album pinned!', 'success' );
			renderResult( data );
			currentDraftId = null;
			draftFiles = [];
		} else if ( event === 'error' ) {
			setStatus( 'ua-progress-status',
				'Error: ' + ( data.message || 'Unknown error' ), 'error' );
		}
	}

	function setProgress( pct ) {
		var fill = document.querySelector( '#ua-progress-bar .uc-progress-fill' );
		if ( fill ) {
			fill.style.width = pct + '%';
		}
	}

	function renderResult( data ) {
		var resultEl = el( 'ua-result' );
		var releaseUrl = mw.util.getUrl( 'Release:' + data.cid );

		var html = '<div class="uc-result-card">';
		html += '<h4>' + mw.html.escape( data.album_title || 'Album' ) +
			' &mdash; ' + mw.html.escape( data.artist || '' ) + '</h4>';
		html += '<table class="wikitable">';
		html += '<tr><th>CID</th><td class="release-cid-cell">' +
			mw.html.escape( data.cid ) + '</td></tr>';
		if ( data.gateway_url ) {
			html += '<tr><th>Gateway</th><td><a href="' + mw.html.escape( data.gateway_url ) +
				'" target="_blank">' + mw.html.escape( data.gateway_url ) + '</a></td></tr>';
		}
		html += '<tr><th>Pinata</th><td>' + ( data.pinata ? 'Yes' : 'No' ) + '</td></tr>';
		html += '</table>';

		// Track listing
		if ( data.tracks && data.tracks.length ) {
			html += '<h5>Tracks</h5><ol>';
			data.tracks.forEach( function ( t ) {
				html += '<li>' + mw.html.escape( t.title || t.filename ) + '</li>';
			} );
			html += '</ol>';
		}

		html += '<p><a href="' + mw.html.escape( releaseUrl ) +
			'" class="cdx-button cdx-button--action-progressive">Create Release Page</a></p>';
		html += '<button id="ua-start-over" class="cdx-button">Upload Another Album</button>';
		html += '</div>';

		resultEl.innerHTML = html;

		el( 'ua-start-over' ).addEventListener( 'click', function () {
			resultEl.innerHTML = '';
			showStep( 'ua-step-upload' );
		} );
	}

	// -- Init --

	function init() {
		if ( !API_URL ) {
			el( 'ua-step-upload' ).innerHTML =
				'<p class="uc-status uc-status-error">Delivery Kid URL not configured.</p>';
			return;
		}

		initUploadStep();
		initReviewStep();
	}

	mw.loader.using( 'mediawiki.util' ).then( init );

}() );
