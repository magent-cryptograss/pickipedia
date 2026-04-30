/**
 * Bootstrap for the Recording Metadata editor.
 *
 * Reads the page's YAML from wgRecordingMetadataYaml (set by
 * RecordingMetadataContentHandler::fillParserOutput), mounts the
 * editor inside #rm-editor-root, and wires save through mw.Api.
 *
 * The editor's UI logic is ported from rabbithole/src/editor — see
 * EditableTrackData.js, TimelineEditor.js, keyboard-shortcuts.js.
 */
'use strict';

const jsYaml = require( './js-yaml.min.js' );
const EditableTrackData = require( './EditableTrackData.js' );
const TimelineEditor = require( './TimelineEditor.js' );
const KeyboardShortcuts = require( './keyboard-shortcuts.js' );

/**
 * Adapter that lets TimelineEditor drive a plain HTMLAudioElement.
 *
 * TimelineEditor expects a player exposing `onTimeUpdate(callback)`,
 * `webamp.store.getState()`, and `webamp.store.dispatch({type, ...})`
 * — that's the rabbithole-side WebampChartifacts shape. This adapter
 * wraps an <audio> tag in just enough of that surface to satisfy the
 * editor's seek/play/timeupdate paths.
 */
class AudioPlayerAdapter {
	constructor( audioEl ) {
		this.audioEl = audioEl;
		this._timeUpdateCallback = null;
		audioEl.addEventListener( 'timeupdate', () => {
			if ( this._timeUpdateCallback ) {
				this._timeUpdateCallback( audioEl.currentTime );
			}
		} );
		this.webamp = {
			store: {
				getState: () => ( {
					media: { length: audioEl.duration || 0 }
				} ),
				dispatch: ( action ) => {
					if ( !audioEl.duration ) {
						return;
					}
					if ( action.type === 'SEEK_TO_PERCENT_COMPLETE' ) {
						audioEl.currentTime = ( action.percent / 100 ) * audioEl.duration;
					} else if ( action.type === 'PLAY' ) {
						audioEl.play();
					} else if ( action.type === 'PAUSE' ) {
						audioEl.pause();
					}
				}
			}
		};
	}

	onTimeUpdate( callback ) {
		this._timeUpdateCallback = callback;
	}

	updateTimeline() {
		// No-op — the editor only has its own visualization, no separate
		// player UI on this page that needs the timeline pushed to it.
	}
}

const IPFS_GATEWAY = 'https://ipfs.delivery-kid.cryptograss.live';

async function fetchParentEncodings( parentTitle ) {
	if ( !parentTitle ) {
		return null;
	}
	try {
		const api = new mw.Api();
		const result = await api.get( {
			action: 'query',
			titles: parentTitle,
			prop: 'revisions',
			rvprop: 'content',
			rvslots: 'main',
			format: 'json'
		} );
		const pages = result.query?.pages || {};
		const page = Object.values( pages )[ 0 ];
		if ( !page || page.missing !== undefined ) {
			return null;
		}
		const text = page.revisions?.[ 0 ]?.slots?.main?.[ '*' ] || '';
		const data = jsYaml.load( text ) || {};
		return data.encodings || null;
	} catch ( e ) {
		mw.log.warn( '[recording-metadata] could not fetch parent encodings:', e );
		return null;
	}
}

function init() {
	const root = document.getElementById( 'rm-editor-root' );
	if ( !root ) {
		return;
	}

	const yamlText = mw.config.get( 'wgRecordingMetadataYaml' ) || '';
	let trackData;
	try {
		const parsed = jsYaml.load( yamlText ) || {};
		trackData = {
			title: parsed.title || mw.config.get( 'wgTitle' ) || 'untitled',
			timeline: parsed.timeline || {},
			ensemble: parsed.ensemble || {},
			standardSectionLength: parsed.standardSectionLength
		};
	} catch ( err ) {
		root.innerHTML = '<div class="rm-yaml-error">YAML parse error: ' +
			mw.html.escape( err.message ) + '</div>';
		return;
	}

	// Set up dirty-state UI hook + editor instance.
	const saveBtn = document.getElementById( 'rm-save-btn' );
	const saveStatus = document.getElementById( 'rm-save-status' );

	const editableData = new EditableTrackData( trackData, {
		recordingTitle: trackData.title,
		// localStorage backup is per-recording; for wiki-hosted editing the
		// wiki revision IS the backup, but keep this on as a safety net.
		enableBackup: true,
		onDirtyChange: function ( isDirty ) {
			if ( saveBtn ) {
				saveBtn.disabled = !isDirty;
				saveBtn.textContent = isDirty ? 'Save' : 'Saved';
				saveBtn.classList.toggle( 'rm-dirty', isDirty );
			}
		}
	} );

	// Editor first; we'll wire audio in after fetching parent encodings.
	const editor = new TimelineEditor( 'rm-editor-root', editableData, null );

	// Audio playback: fetch the parent Release page's YAML, find the OGG
	// encoding CID, drop an <audio> on the page and wrap it in an adapter
	// the TimelineEditor can drive. If anything fails (no parent, no OGG,
	// fetch error), we fall back to a player-less editor — TimelineEditor's
	// player coupling is guarded with `if (this.player)`.
	const parentTitle = mw.config.get( 'wgRecordingMetadataParentTitle' );

	const audioMount = document.createElement( 'div' );
	audioMount.className = 'rm-audio-mount';
	root.parentNode.insertBefore( audioMount, root );

	fetchParentEncodings( parentTitle ).then( ( encodings ) => {
		if ( !encodings || !encodings.ogg ) {
			audioMount.innerHTML = '<p class="rm-audio-missing">No OGG encoding found for parent ' +
				mw.html.escape( parentTitle || '?' ) + ' — editor runs in YAML-only mode.</p>';
			return;
		}
		const audioEl = document.createElement( 'audio' );
		audioEl.controls = true;
		audioEl.preload = 'metadata';
		audioEl.className = 'rm-audio';
		audioEl.src = IPFS_GATEWAY + '/ipfs/' + encodings.ogg;
		audioMount.appendChild( audioEl );

		// Hand the adapter to the editor. The editor was constructed with
		// null; we mutate its `.player` and attach the timeupdate callback
		// so seek-to-marker and play-from-marker start working. (The editor
		// reads this.player on each call, so this is safe — no re-render
		// needed.)
		const player = new AudioPlayerAdapter( audioEl );
		editor.player = player;
		player.onTimeUpdate( editor._onTimeUpdate );
	} );

	const shortcuts = new KeyboardShortcuts( editor, editableData, {
		onSave: save
	} );

	const helpEl = document.getElementById( 'rm-keyboard-help' );
	if ( helpEl ) {
		helpEl.innerHTML = shortcuts.getHelpHTML();
	}

	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', save );
	}

	function save() {
		if ( !editableData.isDirty ) {
			return;
		}
		const newYaml = serialize( editableData.workingData );
		const title = mw.config.get( 'wgPageName' );

		if ( saveBtn ) {
			saveBtn.disabled = true;
			saveBtn.textContent = 'Saving…';
		}
		if ( saveStatus ) {
			saveStatus.textContent = '';
			saveStatus.className = '';
		}

		const api = new mw.Api();
		api.postWithEditToken( {
			action: 'edit',
			title: title,
			text: newYaml,
			summary: 'Update recording metadata via editor',
			contentmodel: 'recording-metadata-yaml'
		} ).then( function () {
			editableData.markSaved();
			if ( saveStatus ) {
				saveStatus.textContent = 'Saved.';
				saveStatus.className = 'rm-save-status-success';
			}
			if ( saveBtn ) {
				saveBtn.textContent = 'Saved';
				saveBtn.disabled = true;
			}
		} ).fail( function ( code, result ) {
			const msg = ( result && result.error && result.error.info ) || code;
			if ( saveStatus ) {
				saveStatus.textContent = 'Save failed: ' + msg;
				saveStatus.className = 'rm-save-status-error';
			}
			if ( saveBtn ) {
				saveBtn.textContent = 'Save';
				saveBtn.disabled = false;
			}
		} );
	}

	function serialize( data ) {
		// Trim out empty/null fields the editor doesn't manage so the
		// stored YAML stays minimal.
		const out = {
			timeline: data.timeline || {},
			ensemble: data.ensemble || {}
		};
		if ( data.standardSectionLength != null ) {
			out.standardSectionLength = data.standardSectionLength;
		}
		if ( data.title ) {
			out.title = data.title;
		}
		return jsYaml.dump( out, { sortKeys: false, lineWidth: 120 } );
	}
}

mw.hook( 'wikipage.content' ).add( function () {
	// wikipage.content fires on initial render and after edits-via-VE; both
	// times we want a fresh editor instance for whatever's in the DOM now.
	init();
} );
