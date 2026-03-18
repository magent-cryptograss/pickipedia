/**
 * ReleaseDraft interactive form — JS for the ReleaseDraft namespace.
 *
 * Reads draft data from mw.config.get('wgReleaseDraftData'),
 * provides:
 * - Track drag-and-drop reorder
 * - Save button: collect form data → YAML → MediaWiki edit API
 * - Finalize button: send to delivery-kid /draft-album/{id}/finalize (SSE)
 * - Blockheight date converter (Etherscan API)
 */
( function () {
	'use strict';

	var draftData = mw.config.get( 'wgReleaseDraftData' ) || {};

	// Blockheight estimation using a known reference point (more accurate than genesis)
	// The Merge (block 15537394) happened at 2022-09-15T06:42:42Z
	// Post-merge block time is exactly 12 seconds
	var MERGE_BLOCK = 15537394;
	var MERGE_TS = 1663220562; // 2022-09-15T06:42:42Z
	var POST_MERGE_BLOCK_TIME = 12; // exactly 12 seconds post-merge
	// Pre-merge: genesis to merge, ~13.3s average
	var ETH_GENESIS_TS = 1438269973;
	var PRE_MERGE_AVG = 13.3;

	// -- Helpers --

	function el( id ) {
		return document.getElementById( id );
	}

	function setStatus( msg, type ) {
		var statusEl = el( 'rd-progress-status' );
		if ( !statusEl ) {
			return;
		}
		statusEl.textContent = msg;
		statusEl.className = 'rd-progress-status' + ( type ? ' rd-status-' + type : '' );
	}

	// -- Track drag reorder --

	function initTrackDragReorder() {
		var container = el( 'rd-track-list' );
		if ( !container ) {
			return;
		}

		var dragSrc = null;

		container.addEventListener( 'dragstart', function ( e ) {
			var row = e.target.closest( '.rd-track-row' );
			if ( !row || row.getAttribute( 'draggable' ) === 'false' ) {
				return;
			}
			dragSrc = row;
			row.classList.add( 'rd-track-dragging' );
			e.dataTransfer.effectAllowed = 'move';
		} );

		container.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			e.dataTransfer.dropEffect = 'move';
			var row = e.target.closest( '.rd-track-row' );
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
				dragSrc.classList.remove( 'rd-track-dragging' );
				dragSrc = null;
			}
			renumberTracks();
		} );
	}

	function renumberTracks() {
		var container = el( 'rd-track-list' );
		if ( !container ) {
			return;
		}
		var rows = container.querySelectorAll( '.rd-track-row' );
		rows.forEach( function ( row, idx ) {
			row.dataset.idx = idx;
			var num = row.querySelector( '.rd-track-num' );
			if ( num ) {
				num.textContent = idx + 1;
			}
		} );
	}

	// -- Collect form data --

	function collectFormData() {
		var data = JSON.parse( JSON.stringify( draftData ) );
		// Draft type is set by the creating Special page's JS:
		//   Special:UploadAlbum  → type: record   (ext.pickipediaReleases.uploadAlbum.js)
		//   Special:UploadContent → type: other    (ext.pickipediaReleases.upload.js)
		//   Blue Railroad bot    → type: blue-railroad
		var draftType = data.type || 'record';

		if ( draftType === 'record' || draftType === 'album' ) {
			// Album/record fields
			var titleEl = el( 'rd-album-title' );
			var artistEl = el( 'rd-artist' );
			var versionEl = el( 'rd-version' );
			var descEl = el( 'rd-description' );

			if ( !data.album ) {
				data.album = {};
			}
			if ( titleEl ) {
				data.album.title = titleEl.value;
			}
			if ( artistEl ) {
				data.album.artist = artistEl.value;
			}
			if ( versionEl ) {
				data.album.version = versionEl.value;
			}
			if ( descEl ) {
				data.album.description = descEl.value;
			}

			// Tracks — collect in current DOM order (respects drag reorder)
			var trackRows = document.querySelectorAll( '.rd-track-row' );
			var tracks = [];
			trackRows.forEach( function ( row ) {
				var titleInput = row.querySelector( '.rd-track-title' );
				var metaTextarea = row.querySelector( '.rd-track-metadata' );
				var filename = row.dataset.filename || '';

				var original = ( draftData.tracks || [] ).find( function ( t ) {
					return t.filename === filename;
				} ) || {};

				tracks.push( {
					filename: filename,
					title: titleInput ? titleInput.value : ( original.title || '' ),
					metadata: metaTextarea ? metaTextarea.value : ( original.metadata || '' ),
					format: original.format || '',
					duration: original.duration || null,
					size_bytes: original.size_bytes || null
				} );
			} );
			data.tracks = tracks;
		} else {
			// Content fields
			if ( !data.content ) {
				data.content = {};
			}
			var contentTitleEl = el( 'rd-content-title' );
			var contentDescriptionEl = el( 'rd-content-description' );
			var contentFileTypeEl = el( 'rd-content-file-type' );
			var contentSubsequentToEl = el( 'rd-content-subsequent-to' );

			if ( contentTitleEl ) {
				data.content.title = contentTitleEl.value;
			}
			if ( contentDescriptionEl ) {
				data.content.description = contentDescriptionEl.value;
			}
			if ( contentFileTypeEl ) {
				data.content.file_type = contentFileTypeEl.value;
			}
			if ( contentSubsequentToEl ) {
				data.content.subsequent_to = contentSubsequentToEl.value;
			}
		}

		// Blockheight
		var bhEl = el( 'rd-blockheight' );
		if ( bhEl && bhEl.value.trim() ) {
			data.blockheight = parseInt( bhEl.value.trim(), 10 ) || null;
		}

		return data;
	}

	// -- Save draft via MediaWiki API --

	function initSaveButton() {
		var saveBtn = el( 'rd-save-btn' );
		if ( !saveBtn ) {
			return;
		}

		saveBtn.addEventListener( 'click', function () {
			var originalText = saveBtn.textContent;
			saveBtn.disabled = true;
			saveBtn.textContent = 'Saving...';
			saveBtn.classList.add( 'rd-saving' );

			var data = collectFormData();

			// Serialize to YAML-ish format (simple key-value, MediaWiki will store as-is)
			var yaml = serializeToYaml( data );

			var api = new mw.Api();
			api.postWithEditToken( {
				action: 'edit',
				title: mw.config.get( 'wgPageName' ),
				text: yaml,
				summary: 'Update release draft metadata',
				minor: true
			} ).then( function () {
				saveBtn.textContent = 'Saved!';
				saveBtn.classList.remove( 'rd-saving' );
				saveBtn.classList.add( 'rd-saved' );
				setTimeout( function () {
					saveBtn.textContent = originalText;
					saveBtn.classList.remove( 'rd-saved' );
					saveBtn.disabled = false;
				}, 2000 );
			} ).fail( function ( code, result ) {
				saveBtn.textContent = 'Save Failed';
				saveBtn.classList.remove( 'rd-saving' );
				saveBtn.classList.add( 'rd-save-failed' );
				setStatus( 'Save failed: ' + ( result.error ? result.error.info : code ), 'error' );
				setTimeout( function () {
					saveBtn.textContent = originalText;
					saveBtn.classList.remove( 'rd-save-failed' );
					saveBtn.disabled = false;
				}, 3000 );
			} );
		} );
	}

	function serializeToYaml( data ) {
		// Build YAML manually for clean output (no library dependency)
		// This is a prototype for the future Release API (issue #60)
		var lines = [];
		// See collectFormData() for where draftType originates
		var draftType = data.type || 'record';

		// Envelope — common to all draft types
		lines.push( 'draft_id: ' + quote( data.draft_id || '' ) );
		lines.push( 'type: ' + quote( draftType ) );
		lines.push( 'source: ' + quote( data.source || '' ) );
		lines.push( 'commit: ' + quote( data.commit || '' ) );
		lines.push( 'uploader: ' + quote( data.uploader || '' ) );

		if ( data.blockheight ) {
			lines.push( 'blockheight: ' + data.blockheight );
		} else {
			lines.push( 'blockheight: null' );
		}

		// Type-specific payload
		if ( draftType === 'record' || draftType === 'album' ) {
			lines.push( 'album:' );
			var album = data.album || {};
			lines.push( '    title: ' + quote( album.title || '' ) );
			lines.push( '    artist: ' + quote( album.artist || '' ) );
			lines.push( '    version: ' + quote( album.version || '' ) );
			lines.push( '    description: ' + quote( album.description || '' ) );

			lines.push( 'tracks:' );
			( data.tracks || [] ).forEach( function ( track ) {
				lines.push( '    -' );
				lines.push( '        filename: ' + quote( track.filename || '' ) );
				lines.push( '        title: ' + quote( track.title || '' ) );
				if ( track.format ) {
					lines.push( '        format: ' + quote( track.format ) );
				}
				if ( track.duration ) {
					lines.push( '        duration: ' + track.duration );
				}
				if ( track.size_bytes ) {
					lines.push( '        size_bytes: ' + track.size_bytes );
				}
				if ( track.metadata ) {
					lines.push( '        metadata: |' );
					track.metadata.split( '\n' ).forEach( function ( ml ) {
						lines.push( '            ' + ml );
					} );
				} else {
					lines.push( '        metadata: ""' );
				}
			} );
		} else {
			// Content (and future types)
			lines.push( 'content:' );
			var content = data.content || {};
			lines.push( '    title: ' + quote( content.title || '' ) );
			lines.push( '    description: ' + quote( content.description || '' ) );
			lines.push( '    file_type: ' + quote( content.file_type || '' ) );
			lines.push( '    subsequent_to: ' + quote( content.subsequent_to || '' ) );

			if ( data.files && data.files.length > 0 ) {
				lines.push( 'files:' );
				data.files.forEach( function ( f ) {
					lines.push( '    -' );
					lines.push( '        original_filename: ' + quote( f.original_filename || '' ) );
					lines.push( '        media_type: ' + quote( f.media_type || '' ) );
					if ( f.format ) {
						lines.push( '        format: ' + quote( f.format ) );
					}
					if ( f.duration_seconds ) {
						lines.push( '        duration_seconds: ' + f.duration_seconds );
					}
					if ( f.width ) {
						lines.push( '        width: ' + f.width );
					}
					if ( f.height ) {
						lines.push( '        height: ' + f.height );
					}
					if ( f.size_bytes ) {
						lines.push( '        size_bytes: ' + f.size_bytes );
					}
				} );
			}
		}

		return lines.join( '\n' ) + '\n';
	}

	function quote( val ) {
		if ( val === '' || val === null || val === undefined ) {
			return '""';
		}
		val = String( val );
		// Quote if contains special YAML chars
		if ( /[:#\[\]{}&*!|>'"%@`\n]/.test( val ) || val.trim() !== val ) {
			return '"' + val.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ).replace( /\n/g, '\\n' ) + '"';
		}
		return val;
	}

	// -- Finalize via delivery-kid --

	function initFinalizeButton() {
		var finalizeBtn = el( 'rd-finalize-btn' );
		if ( !finalizeBtn ) {
			return;
		}

		// Hide action buttons for logged-out users
		if ( mw.config.get( 'wgUserId' ) === null ) {
			var actionsDiv = el( 'rd-actions' );
			if ( actionsDiv ) {
				actionsDiv.innerHTML = '<p class="uc-status uc-status-error">You must be logged in to save or finalize drafts.</p>';
			}
			return;
		}

		finalizeBtn.addEventListener( 'click', function () {
			var data = collectFormData();
			var draftId = data.draft_id;
			// See collectFormData() for where draftType originates
			var draftType = data.type || 'record';

			if ( !draftId ) {
				showFinalizeError( 'No draft ID — cannot finalize.' );
				return;
			}

			var apiUrl = mw.config.get( 'wgDeliveryKidUrl' );
			if ( !apiUrl ) {
				showFinalizeError( 'Delivery Kid is not configured. An admin needs to set DeliveryKidApiKey in LocalSettings.php.' );
				return;
			}

			var authHeaders = {
				'X-Upload-Token': mw.config.get( 'wgUploadToken' ),
				'X-Upload-User': mw.config.get( 'wgUploadUser' ),
				'X-Upload-Timestamp': String( mw.config.get( 'wgUploadTimestamp' ) )
			};

			var headers = Object.assign( {}, authHeaders, {
				'Content-Type': 'application/json'
			} );

			var endpoint, body;

			if ( draftType === 'record' || draftType === 'album' ) {
				var album = data.album || {};
				if ( !album.title ) {
					showFinalizeError( 'Album title is required to finalize.' );
					el( 'rd-album-title' ).focus();
					return;
				}
				if ( !album.artist ) {
					showFinalizeError( 'Artist is required to finalize.' );
					el( 'rd-artist' ).focus();
					return;
				}

				if ( !confirm( 'Finalize this album? This will transcode, tag, and pin to IPFS.' ) ) {
					return;
				}

				var tracks = ( data.tracks || [] ).map( function ( t ) {
					return {
						filename: t.filename,
						title: t.title,
						metadata: t.metadata || ''
					};
				} );

				endpoint = '/draft-album/' + draftId + '/finalize';
				body = JSON.stringify( {
					album_title: album.title,
					artist: album.artist,
					description: album.description || null,
					tracks: tracks
				} );
			} else {
				// Content finalization
				var content = data.content || {};

				if ( !confirm( 'Finalize and pin to IPFS?' ) ) {
					return;
				}

				endpoint = '/draft-content/' + draftId + '/finalize';
				body = JSON.stringify( {
					title: content.title || null,
					description: content.description || null,
					file_type: content.file_type || null,
					subsequent_to: content.subsequent_to || null,
					transcoding_strategy: 'auto',
					metadata: {}
				} );
			}

			finalizeBtn.disabled = true;
			el( 'rd-save-btn' ).disabled = true;

			var progressDiv = el( 'rd-finalize-progress' );
			if ( progressDiv ) {
				progressDiv.style.display = '';
			}
			setProgress( 0 );
			setActiveStage( 'preparing' );
			setStatus( 'Starting finalization...', '' );
			appendLog( 'Sending finalize request to delivery-kid...' );

			fetch( apiUrl + endpoint, {
				method: 'POST',
				headers: headers,
				body: body
			} ).then( function ( resp ) {
				if ( !resp.ok ) {
					return resp.json().then( function ( err ) {
						var detail = err.detail;
						var msg;
						if ( typeof detail === 'string' ) {
							msg = detail;
						} else if ( detail && detail.error ) {
							msg = detail.error;
						} else {
							msg = JSON.stringify( err );
						}
						throw new Error( msg );
					} );
				}
				return readSSEStream( resp );
			} ).catch( function ( err ) {
				showFinalizeError( 'Finalization error: ' + err.message );
				finalizeBtn.disabled = false;
				el( 'rd-save-btn' ).disabled = false;
			} );
		} );
	}

	function showFinalizeError( msg ) {
		// Always make the progress area visible so the error is seen
		var progressDiv = el( 'rd-finalize-progress' );
		if ( progressDiv ) {
			progressDiv.style.display = '';
		}
		setStageError();
		setStatus( msg, 'error' );
		appendLog( 'ERROR: ' + msg );
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
						var sseData = line.slice( 5 ).trim();
						try {
							handleSSEEvent( currentEvent, JSON.parse( sseData ) );
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

			// Detect stage from message content
			var msg = data.message || '';
			var stage = data.stage || detectStage( msg );
			if ( stage ) {
				setActiveStage( stage );
			}

			var logMsg = msg;
			if ( data.track ) {
				logMsg += ' — ' + data.track;
			}
			setStatus( logMsg, '' );
			appendLog( logMsg );
		} else if ( event === 'warning' ) {
			setStatus( 'Warning: ' + data.message, '' );
			appendLog( '⚠ ' + data.message );
		} else if ( event === 'complete' ) {
			setProgress( 100 );
			setActiveStage( 'complete' );
			setStatus( 'Pinned to IPFS!', 'success' );
			appendLog( 'CID: ' + ( data.cid || 'unknown' ) );
			showFinalizeResult( data );
		} else if ( event === 'transcoding-submitted' ) {
			setProgress( 100 );
			setActiveStage( 'complete' );
			setStatus( 'Cloud transcoding submitted!', 'success' );
			appendLog( 'Source CID: ' + ( data.sourceCid || 'unknown' ) );
			appendLog( 'Job ID: ' + ( data.jobId || 'unknown' ) );
			appendLog( data.message || '' );
			showTranscodingResult( data );
		} else if ( event === 'error' ) {
			setStageError();
			showFinalizeError( 'Error: ' + ( data.message || 'Unknown error' ) );
			appendLog( 'ERROR: ' + ( data.message || 'Unknown error' ) );
			var finalizeBtn = el( 'rd-finalize-btn' );
			var saveBtn = el( 'rd-save-btn' );
			if ( finalizeBtn ) {
				finalizeBtn.disabled = false;
			}
			if ( saveBtn ) {
				saveBtn.disabled = false;
			}
		}
	}

	function detectStage( msg ) {
		var lower = msg.toLowerCase();
		if ( /transcod/.test( lower ) ) {
			return 'transcoding';
		}
		if ( /tag/.test( lower ) || /vorbis/.test( lower ) || /metadata/.test( lower ) ) {
			return 'tagging';
		}
		if ( /pin/.test( lower ) || /upload/.test( lower ) || /ipfs/.test( lower ) ) {
			return 'pinning';
		}
		if ( /prepar/.test( lower ) || /start/.test( lower ) || /validat/.test( lower ) ) {
			return 'preparing';
		}
		return null;
	}

	function setActiveStage( stageName ) {
		var stages = document.querySelectorAll( '.rd-stage' );
		var reached = false;
		var passed = false;
		stages.forEach( function ( stageEl ) {
			var key = stageEl.dataset.stage;
			stageEl.classList.remove( 'rd-stage-active', 'rd-stage-done', 'rd-stage-error' );
			if ( key === stageName ) {
				stageEl.classList.add( 'rd-stage-active' );
				reached = true;
			} else if ( !reached ) {
				stageEl.classList.add( 'rd-stage-done' );
			}
		} );
	}

	function setStageError() {
		var active = document.querySelector( '.rd-stage.rd-stage-active' );
		if ( active ) {
			active.classList.remove( 'rd-stage-active' );
			active.classList.add( 'rd-stage-error' );
		}
	}

	function setProgress( pct ) {
		var fill = document.querySelector( '#rd-progress-bar .uc-progress-fill' );
		if ( fill ) {
			fill.style.width = pct + '%';
		}
	}

	function appendLog( msg ) {
		var log = el( 'rd-progress-log' );
		if ( !log ) {
			return;
		}
		var line = document.createElement( 'div' );
		line.textContent = msg;
		log.appendChild( line );
		log.scrollTop = log.scrollHeight;
	}

	function showFinalizeResult( resultData ) {
		// Save the draft page (preserving current form data) — no Release page creation.
		// The bot will create the Release page when it processes completed drafts.
		var data = collectFormData();
		var yaml = serializeToYaml( data );
		var cid = resultData.cid;

		var api = new mw.Api();
		api.postWithEditToken( {
			action: 'edit',
			title: mw.config.get( 'wgPageName' ),
			text: yaml,
			summary: 'Finalized: pinned to IPFS as ' + ( cid || 'unknown' )
		} ).then( function () {
			var releaseUrl = mw.util.getUrl( 'Release:' + cid );
			setStatus( 'Pinned to IPFS! CID: ' + cid, 'success' );
			appendLog( 'Gateway: ' + ( resultData.gateway_url || '' ) );
			appendLog( 'Release page will be created by the bot.' );
			appendLog( '' );

			// Show a link to the (future) Release page
			var linkHtml = '<p><a href="' + mw.html.escape( releaseUrl ) + '">' +
				'Release:' + mw.html.escape( cid ) + '</a></p>';
			var logEl = el( 'rd-progress-log' );
			if ( logEl ) {
				logEl.innerHTML += linkHtml;
			}
		} ).fail( function ( code, result ) {
			setStatus( 'Pinned to IPFS but failed to save draft: ' +
				( result.error ? result.error.info : code ) + '. CID: ' + cid, 'error' );
		} );
	}

	function showTranscodingResult( data ) {
		// Coconut cloud transcoding was submitted — save draft and show polling UI
		var formData = collectFormData();
		var yaml = serializeToYaml( formData );

		var api = new mw.Api();
		api.postWithEditToken( {
			action: 'edit',
			title: mw.config.get( 'wgPageName' ),
			text: yaml,
			summary: 'Transcoding submitted: job ' + ( data.jobId || 'unknown' )
		} ).then( function () {
			appendLog( 'Draft saved. Transcoding will complete asynchronously.' );
			appendLog( 'When transcoding finishes, the HLS output will be pinned to IPFS.' );
			appendLog( 'The bot will then create the Release page.' );
		} ).fail( function ( code, result ) {
			appendLog( 'Warning: failed to save draft page: ' +
				( result.error ? result.error.info : code ) );
		} );
	}

	// -- Blockheight converter --

	function initBlockheightConverter() {
		var nowBtn = el( 'rd-blockheight-now' );
		var bhInput = el( 'rd-blockheight' );
		var dateLabel = el( 'rd-blockheight-date' );
		var dateInput = el( 'rd-date-input' );

		if ( !nowBtn || !bhInput ) {
			return;
		}

		// "Current Block" button — fetch latest via public Ethereum RPC
		nowBtn.addEventListener( 'click', function () {
			nowBtn.disabled = true;
			nowBtn.textContent = 'Fetching...';

			fetch( 'https://ethereum-rpc.publicnode.com', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					jsonrpc: '2.0',
					method: 'eth_blockNumber',
					params: [],
					id: 1
				} )
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( resp ) {
					if ( resp.result ) {
						var blockNum = parseInt( resp.result, 16 );
						bhInput.value = blockNum;
						// We just fetched the current block, so the date is now
						setBlockDateToNow();
					}
				} )
				.catch( function () {
					// Fallback: estimate from current time
					var now = Math.floor( Date.now() / 1000 );
					var estimated = timestampToBlock( now );
					bhInput.value = estimated;
					updateBlockDate( estimated );
				} )
				.finally( function () {
					nowBtn.disabled = false;
					nowBtn.textContent = 'Current Block';
				} );
		} );

		// When user types a block number, estimate the date
		bhInput.addEventListener( 'change', function () {
			var val = parseInt( bhInput.value, 10 );
			if ( val > 0 ) {
				updateBlockDate( val );
			} else if ( dateLabel ) {
				dateLabel.textContent = '';
			}
		} );

		// Date picker → estimate block number
		if ( dateInput ) {
			dateInput.addEventListener( 'change', function () {
				var dateStr = dateInput.value;
				if ( !dateStr ) {
					return;
				}
				var ts = Math.floor( new Date( dateStr + 'T12:00:00Z' ).getTime() / 1000 );
				var estimated = timestampToBlock( ts );
				if ( estimated > 0 ) {
					bhInput.value = estimated;
					updateBlockDate( estimated );
				}
			} );
		}

		// Show date for existing value on load
		var existingVal = parseInt( bhInput.value, 10 );
		if ( existingVal > 0 ) {
			updateBlockDate( existingVal );
		}
	}

	function blockToTimestamp( blockNumber ) {
		if ( blockNumber >= MERGE_BLOCK ) {
			// Post-merge: exactly 12s per block from the merge point
			return MERGE_TS + ( ( blockNumber - MERGE_BLOCK ) * POST_MERGE_BLOCK_TIME );
		}
		// Pre-merge: estimate from genesis with ~13.3s average
		return ETH_GENESIS_TS + ( blockNumber * PRE_MERGE_AVG );
	}

	function timestampToBlock( ts ) {
		if ( ts >= MERGE_TS ) {
			return MERGE_BLOCK + Math.floor( ( ts - MERGE_TS ) / POST_MERGE_BLOCK_TIME );
		}
		return Math.floor( ( ts - ETH_GENESIS_TS ) / PRE_MERGE_AVG );
	}

	function setBlockDateToNow() {
		var dateLabel = el( 'rd-blockheight-date' );
		if ( !dateLabel ) {
			return;
		}
		var date = new Date();
		dateLabel.textContent = '≈ ' + date.toLocaleDateString( 'en-US', {
			year: 'numeric',
			month: 'long',
			day: 'numeric'
		} );
	}

	function updateBlockDate( blockNumber ) {
		var dateLabel = el( 'rd-blockheight-date' );
		if ( !dateLabel ) {
			return;
		}
		var estimatedTs = blockToTimestamp( blockNumber );
		var date = new Date( estimatedTs * 1000 );
		dateLabel.textContent = '≈ ' + date.toLocaleDateString( 'en-US', {
			year: 'numeric',
			month: 'long',
			day: 'numeric'
		} );
	}

	// -- Init --

	function init() {
		initTrackDragReorder();
		initSaveButton();
		initFinalizeButton();
		initBlockheightConverter();
	}

	mw.loader.using( [ 'mediawiki.util', 'mediawiki.api' ] ).then( init );

}() );
