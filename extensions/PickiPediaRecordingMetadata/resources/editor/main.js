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

	// No player on this page (yet) — TimelineEditor's player coupling is
	// guarded with `if (this.player)`, so passing null degrades gracefully:
	// the seek/play buttons become no-ops, the time-update highlight stops.
	const editor = new TimelineEditor( 'rm-editor-root', editableData, null );

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
			contentmodel: 'recording-metadata-yaml',
			contentformat: 'text/x-yaml'
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
