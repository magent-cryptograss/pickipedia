/**
 * Deliver Video — direct upload to delivery-kid from browser.
 *
 * After upload+analysis, creates a ReleaseDraft wiki page (type: video)
 * with venue/performers metadata and redirects there.
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

	// Re-upload mode: ?redraft=<id> reuses an existing draft_id instead of
	// generating a new one. Wiki page is edited (not created) afterward.
	var REDRAFT_ID = new URLSearchParams( window.location.search ).get( 'redraft' );

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

	// Merge-genesis constants for block↔timestamp conversion
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
		labelEl.textContent = '⏳';
		var hexBlock = '0x' + block.toString( 16 );
		fetch( 'https://ethereum-rpc.publicnode.com', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				jsonrpc: '2.0',
				method: 'eth_getBlockByNumber',
				params: [ hexBlock, false ],
				id: 1
			} )
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( resp ) {
				if ( resp.result && resp.result.timestamp ) {
					var ts = parseInt( resp.result.timestamp, 16 );
					var date = new Date( ts * 1000 );
					labelEl.textContent = date.toLocaleDateString( undefined, {
						year: 'numeric', month: 'short', day: 'numeric'
					} );
				} else {
					labelEl.textContent = '';
				}
			} )
			.catch( function () {
				labelEl.textContent = '';
			} );
	}

	function initBlockheightControls() {
		var nowBtn = el( 'dv-blockheight-now' );
		var bhInput = el( 'dv-content-blockheight' );
		var dateLabel = el( 'dv-blockheight-date' );

		if ( !nowBtn || !bhInput ) {
			return;
		}

		// Show date estimate when user types a block number
		bhInput.addEventListener( 'input', function () {
			var val = parseInt( bhInput.value, 10 );
			if ( val > MERGE_BLOCK ) {
				updateBlockDateLabel( val, dateLabel );
			} else {
				dateLabel.textContent = '';
			}
		} );

		// "Current Block" button — estimate from wall-clock time
		nowBtn.addEventListener( 'click', function () {
			var block = timestampToBlock( Math.floor( Date.now() / 1000 ) );
			bhInput.value = block;
			updateBlockDateLabel( block, dateLabel );
		} );

		// Date picker → estimate block from date (local formula, day-level precision)
		var dateInput = el( 'dv-date-input' );
		if ( dateInput ) {
			dateInput.addEventListener( 'change', function () {
				if ( dateInput.value ) {
					var parts = dateInput.value.split( '-' );
					var ts = Math.floor( new Date( parts[ 0 ], parts[ 1 ] - 1, parts[ 2 ], 12, 0, 0 ).getTime() / 1000 );
					var block = timestampToBlock( ts );
					bhInput.value = block;
					updateBlockDateLabel( block, dateLabel );
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
			var titleValue = ( el( 'dv-title' ) || {} ).value || '';
			if ( !titleValue.trim() ) {
				setStatus( 'dv-upload-status', 'Title is required.', 'error' );
				el( 'dv-title' ).focus();
				return;
			}
			doUpload( selectedFiles );
		} );
	}

	// Parse a delivery-kid HTTPException response into a readable string.
	function parseDkError( xhr ) {
		try {
			var err = JSON.parse( xhr.responseText );
			var detail = err.detail;
			if ( typeof detail === 'string' ) {
				return detail;
			}
			if ( detail && detail.error ) {
				return detail.error;
			}
			if ( Array.isArray( detail ) ) {
				return detail.map( function ( d ) { return d.msg || JSON.stringify( d ); } ).join( '; ' );
			}
			return JSON.stringify( err );
		} catch ( e ) {
			return xhr.status + ' ' + xhr.statusText + ': ' + ( xhr.responseText || '' ).slice( 0, 200 );
		}
	}

	// Build a stub draft object so createReleaseDraftPage can write a YAML
	// page even before any files have been analysed. The draft_id matches
	// the one returned by /init, so the subsequent file POST + final YAML
	// update both target the same ReleaseDraft page.
	function stubDraft( draftId, commit ) {
		return {
			draft_id: draftId,
			files: [],
			commit: commit || 'unknown'
		};
	}

	function doUpload( files ) {
		var uploadBtn = el( 'dv-upload-btn' );
		var cancelBtn = el( 'dv-cancel-btn' );
		var progressBar = el( 'dv-upload-progress' );
		var progressFill = progressBar.querySelector( '.uc-progress-fill' );

		uploadBtn.disabled = true;
		setStatus( 'dv-upload-status', 'Initialising draft...', '' );

		// Step 1: re-upload mode reuses the existing draft_id and skips /init.
		// Fresh-upload mode mints a draft_id via /init and creates the
		// ReleaseDraft page BEFORE pushing bytes — so an upload that fails
		// later still leaves an inspectable wiki page + draft.json record.
		if ( REDRAFT_ID ) {
			beginFileUpload( files, REDRAFT_ID, /* createPageFirst */ false, /* commit */ null );
			return;
		}

		fetch( API_URL + '/draft-content/init', {
			method: 'POST',
			headers: AUTH_HEADERS
		} ).then( function ( resp ) {
			if ( !resp.ok ) {
				return resp.text().then( function ( txt ) {
					throw new Error( 'init failed (' + resp.status + '): ' + txt.slice( 0, 200 ) );
				} );
			}
			return resp.json();
		} ).then( function ( draft ) {
			var draftId = draft.draft_id;
			setStatus( 'dv-upload-status', 'Creating ReleaseDraft page...', '' );
			return createReleaseDraftPage( stubDraft( draftId, draft.commit ),
				/* andRedirect */ false ).then( function () {
				return { draftId: draftId, commit: draft.commit };
			} );
		} ).then( function ( res ) {
			beginFileUpload( files, res.draftId, /* createPageFirst */ false, res.commit );
		} ).catch( function ( err ) {
			setStatus( 'dv-upload-status',
				'Could not start upload: ' + ( err && err.message ? err.message : String( err ) ),
				'error' );
			uploadBtn.disabled = false;
		} );
	}

	// Push file bytes to /draft-content with X-Draft-Id pointing at an
	// already-initialised draft. On success, updates the ReleaseDraft page
	// YAML with the analysed file list. On failure, surfaces the error and
	// links the user to the (already-existing) ReleaseDraft page so they
	// can see the upload_log.
	function beginFileUpload( files, draftId, createPageFirst, knownCommit ) {
		var uploadBtn = el( 'dv-upload-btn' );
		var cancelBtn = el( 'dv-cancel-btn' );
		var progressBar = el( 'dv-upload-progress' );
		var progressFill = progressBar.querySelector( '.uc-progress-fill' );

		cancelBtn.style.display = '';
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
		xhr.setRequestHeader( 'X-Draft-Id', draftId );

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
			cancelBtn.style.display = 'none';

			if ( xhr.status !== 200 ) {
				var errMsg = parseDkError( xhr );
				var pageHref = mw.util.getUrl( 'ReleaseDraft:' + draftId );
				setStatus( 'dv-upload-status',
					'Upload failed (' + xhr.status + '): ' + errMsg +
					' — see the draft page for the full upload log: ' + pageHref,
					'error' );
				uploadBtn.disabled = false;
				return;
			}

			var draft = JSON.parse( xhr.responseText );
			if ( knownCommit && !draft.commit ) {
				draft.commit = knownCommit;
			}
			setStatus( 'dv-upload-status',
				'Upload complete. ' + draft.files.length + ' file(s) analysed. Updating draft page...',
				'success' );

			// In re-upload mode (createPageFirst === true) we still call
			// createReleaseDraftPage to write the full YAML — it handles
			// articleexists by editing.
			createReleaseDraftPage( draft, /* andRedirect */ true );
		} );

		xhr.addEventListener( 'error', function () {
			progressBar.style.display = 'none';
			cancelBtn.style.display = 'none';
			var pageHref = mw.util.getUrl( 'ReleaseDraft:' + draftId );
			setStatus( 'dv-upload-status',
				'Network error during upload — the draft page exists at ' + pageHref +
				' and any partial log will be visible there.',
				'error' );
			uploadBtn.disabled = false;
		} );

		xhr.addEventListener( 'abort', function () {
			progressBar.style.display = 'none';
			progressFill.style.width = '0%';
			cancelBtn.style.display = 'none';
			setStatus( 'dv-upload-status', 'Upload cancelled.', '' );
			uploadBtn.disabled = false;
		} );

		cancelBtn.onclick = function () {
			xhr.abort();
		};

		xhr.send( formData );
	}

	// -- Create / update ReleaseDraft wiki page --
	//
	// Called twice in the new flow:
	//   1. Right after /init returns a draft_id, with an empty `draft.files`
	//      list. Writes a stub YAML (status: awaiting_upload) so the page
	//      exists before bytes are pushed. Resolves without redirecting so
	//      the upload can proceed.
	//   2. After the file POST returns 200 with analysed files, with the
	//      full draft. Updates the page YAML and redirects to it.
	//
	// In re-upload mode (REDRAFT_ID is set) only the second call happens.
	function createReleaseDraftPage( draft, andRedirect ) {
		var draftId = draft.draft_id;
		var pageName = 'ReleaseDraft:' + draftId;
		var yaml = buildVideoYaml( draftId, draft );
		var isStub = !draft.files || draft.files.length === 0;

		var summary;
		if ( isStub ) {
			summary = 'Init draft (awaiting upload)';
		} else if ( REDRAFT_ID ) {
			summary = 'Re-upload: ' + draft.files.length + ' file(s) uploaded';
		} else {
			summary = 'Upload complete: ' + draft.files.length + ' file(s) analysed';
		}

		var editParams = {
			action: 'edit',
			title: pageName,
			text: yaml,
			summary: summary
		};

		var api = new mw.Api();
		return api.postWithEditToken( editParams ).then( function () {
			if ( andRedirect ) {
				window.location.href = mw.util.getUrl( pageName );
			}
		} ).catch( function ( code, result ) {
			// `articleexists` only fires when createonly is set; we don't
			// set it any more, so any failure here is real.
			var info = ( result && result.error ) ? result.error.info : code;
			setStatus( 'dv-upload-status',
				'Failed to save draft page: ' + info, 'error' );
			el( 'dv-upload-btn' ).disabled = false;
			throw new Error( info );
		} );
	}

	function buildVideoYaml( draftId, draft ) {
		// Collect metadata from form fields
		var title = ( el( 'dv-title' ) || {} ).value || '';
		var venue = ( el( 'dv-venue' ) || {} ).value || '';
		var performersRaw = ( el( 'dv-performers' ) || {} ).value || '';
		var description = ( el( 'dv-description' ) || {} ).value || '';
		var contentBlockheight = ( el( 'dv-content-blockheight' ) || {} ).value || '';
		var uploadBlockheight = mw.config.get( 'wgUploadBlockheight' ) || '';

		var performers = performersRaw.split( ',' ).map( function ( s ) {
			return s.trim();
		} ).filter( function ( s ) {
			return s.length > 0;
		} );

		var lines = [];
		lines.push( 'draft_id: ' + draftId );
		lines.push( 'type: video' );
		lines.push( 'source: special-deliver-video' );
		// status: explicit for stub pages so renderers know the upload is
		// still pending. After bytes are saved this YAML is rewritten with
		// no status field, falling back to the PHP default ('draft').
		if ( !draft.files || draft.files.length === 0 ) {
			lines.push( 'status: awaiting_upload' );
		}
		// commit: the maybelle-config build hash that delivery-kid reports
		lines.push( 'commit: ' + ( draft.commit || 'unknown' ) );
		lines.push( 'uploader: ' + quoteYamlValue( mw.config.get( 'wgUploadUser' ) || '' ) );
		// blockheight: when the video content was recorded (user-provided, optional)
		lines.push( 'blockheight: ' + ( contentBlockheight ? contentBlockheight : 'null' ) );
		// upload_blockheight: Ethereum block at the moment of upload (auto-captured)
		lines.push( 'upload_blockheight: ' + ( uploadBlockheight ? uploadBlockheight : 'null' ) );
		lines.push( 'content:' );
		lines.push( '    title: ' + quoteYamlValue( title ) );
		lines.push( '    description: ' + quoteYamlValue( description ) );
		lines.push( '    file_type: ""' );
		lines.push( '    venue: ' + quoteYamlValue( venue ) );
		lines.push( '    performers:' );
		performers.forEach( function ( p ) {
			lines.push( '        - ' + quoteYamlValue( p ) );
		} );

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
			if ( f.creation_time ) {
				lines.push( '        creation_time: ' + quoteYamlValue( f.creation_time ) );
			}
		} );

		// If the user hasn't picked a date and the video has creation_time
		// metadata, use it as the default content date.
		var contentDateInput = el( 'dv-content-date' );
		var contentBhInput = el( 'dv-content-blockheight' );
		if ( contentDateInput && !contentDateInput.value && contentBhInput && !contentBhInput.value ) {
			var firstFile = ( draft.files || [] )[ 0 ];
			if ( firstFile && firstFile.creation_time ) {
				// creation_time is ISO 8601, e.g. "2026-03-15T14:30:00.000000Z"
				var dateStr = firstFile.creation_time.substring( 0, 10 );
				if ( dateStr && /^\d{4}-\d{2}-\d{2}$/.test( dateStr ) ) {
					contentDateInput.value = dateStr;
					// Trigger change event so the blockheight converter picks it up
					contentDateInput.dispatchEvent( new Event( 'change' ) );
				}
			}
		}

		return lines.join( '\n' ) + '\n';
	}

	// -- Init --

	// In re-upload mode, fetch the existing ReleaseDraft's YAML and pre-fill
	// the form fields. Without this, every re-upload makes the user re-type
	// the title/venue/performers etc. that were already on the draft.
	function prefillFromExistingDraft() {
		if ( !REDRAFT_ID ) {
			return;
		}
		var api = new mw.Api();
		api.get( {
			action: 'query',
			titles: 'ReleaseDraft:' + REDRAFT_ID,
			prop: 'revisions',
			rvprop: 'content',
			rvslots: 'main',
			formatversion: 2
		} ).then( function ( data ) {
			var pages = ( data.query && data.query.pages ) || [];
			if ( !pages.length || pages[ 0 ].missing ) {
				return;
			}
			var rev = pages[ 0 ].revisions && pages[ 0 ].revisions[ 0 ];
			var yaml = rev && rev.slots && rev.slots.main && rev.slots.main.content;
			if ( yaml ) {
				applyPrefill( yaml );
			}
		} ).catch( function () {
			// Re-upload still works; user will need to re-type metadata.
		} );
	}

	// Best-effort YAML field extraction. ResourceLoader has no YAML lib, but
	// the file is generated by buildVideoYaml() in this same script, so the
	// shape is predictable enough for regex.
	function unquoteYaml( val ) {
		val = val.trim();
		if ( val.length >= 2 && val.charAt( 0 ) === '"' && val.charAt( val.length - 1 ) === '"' ) {
			return val.slice( 1, -1 )
				.replace( /\\"/g, '"' )
				.replace( /\\n/g, '\n' )
				.replace( /\\\\/g, '\\' );
		}
		return val;
	}

	function setVal( id, value ) {
		var input = el( id );
		if ( input && value !== undefined && value !== null ) {
			input.value = value;
		}
	}

	function applyPrefill( yaml ) {
		var titleMatch = yaml.match( /^\s+title:\s*(.+)$/m );
		var venueMatch = yaml.match( /^\s+venue:\s*(.+)$/m );
		var descMatch = yaml.match( /^\s+description:\s*(.+)$/m );
		var bhMatch = yaml.match( /^blockheight:\s*(\S+)$/m );

		if ( titleMatch ) { setVal( 'dv-title', unquoteYaml( titleMatch[ 1 ] ) ); }
		if ( venueMatch ) { setVal( 'dv-venue', unquoteYaml( venueMatch[ 1 ] ) ); }
		if ( descMatch ) { setVal( 'dv-description', unquoteYaml( descMatch[ 1 ] ) ); }
		if ( bhMatch && bhMatch[ 1 ] !== 'null' ) {
			setVal( 'dv-content-blockheight', bhMatch[ 1 ] );
			// Trigger the date-label refresh wired up by initBlockheightControls.
			var bhInput = el( 'dv-content-blockheight' );
			if ( bhInput ) {
				bhInput.dispatchEvent( new Event( 'input' ) );
			}
		}

		var performersBlock = yaml.match( /performers:\s*\n((?:\s+-\s+.+\n?)+)/ );
		if ( performersBlock ) {
			var names = [];
			performersBlock[ 1 ].split( '\n' ).forEach( function ( line ) {
				var m = line.match( /^\s+-\s+(.+)$/ );
				if ( m ) {
					names.push( unquoteYaml( m[ 1 ] ) );
				}
			} );
			if ( names.length ) {
				setVal( 'dv-performers', names.join( ', ' ) );
			}
		}
	}

	function init() {
		if ( !API_URL ) {
			el( 'dv-step-upload' ).innerHTML =
				'<p class="uc-status uc-status-error">Delivery Kid URL not configured.</p>';
			return;
		}

		initUploadStep();
		initBlockheightControls();
		prefillFromExistingDraft();
	}

	mw.loader.using( [ 'mediawiki.util', 'mediawiki.api' ] ).then( init );

}() );
