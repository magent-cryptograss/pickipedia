/**
 * Keyboard Shortcuts for Rabbithole Timeline Editor
 *
 * Quick-add mode for fast authoring:
 * - Spacebar: Add marker at current time
 * - Arrow keys: Fine-tune time (±0.1s, ±1s with shift)
 * - 1-9: Quick-select musician by ensemble order
 * - A/B/I/O: Set part (A, B, Intro, Outro)
 * - Tab/Shift+Tab: Navigate markers
 * - Delete/Backspace: Delete selected marker
 * - Ctrl+Z: Undo
 * - Ctrl+Y/Ctrl+Shift+Z: Redo
 * - Ctrl+S: Save to wiki
 * - Escape: Clear selection / cancel editing
 */

class KeyboardShortcuts {
    constructor(editor, editableTrackData, options = {}) {
        this.editor = editor;
        this.data = editableTrackData;
        this.options = options;

        // Callbacks
        this.onSave = options.onSave || null;
        this.onSeek = options.onSeek || null;

        // State
        this.enabled = true;

        // Bind the handler
        this._handleKeydown = this._handleKeydown.bind(this);

        // Attach global listener
        document.addEventListener('keydown', this._handleKeydown);
    }

    /**
     * Enable or disable keyboard shortcuts
     */
    setEnabled(enabled) {
        this.enabled = enabled;
    }

    /**
     * Check if an input element is focused
     */
    _isInputFocused() {
        const active = document.activeElement;
        if (!active) return false;

        const tag = active.tagName.toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select') {
            return true;
        }

        if (active.contentEditable === 'true') {
            return true;
        }

        return false;
    }

    /**
     * Main keydown handler
     */
    _handleKeydown(e) {
        if (!this.enabled) return;

        // Don't capture when typing in inputs (except for specific shortcuts)
        const inInput = this._isInputFocused();

        // Ctrl+S: Save (works even in inputs)
        if (e.key === 's' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            this._handleSave();
            return;
        }

        // Ctrl+Z: Undo (works even in inputs)
        if (e.key === 'z' && (e.ctrlKey || e.metaKey) && !e.shiftKey) {
            if (!inInput) {
                e.preventDefault();
                this._handleUndo();
                return;
            }
        }

        // Ctrl+Y or Ctrl+Shift+Z: Redo
        if ((e.key === 'y' && (e.ctrlKey || e.metaKey)) ||
            (e.key === 'z' && (e.ctrlKey || e.metaKey) && e.shiftKey)) {
            if (!inInput) {
                e.preventDefault();
                this._handleRedo();
                return;
            }
        }

        // Skip other shortcuts when in input
        if (inInput) return;

        // Escape: Clear selection
        if (e.key === 'Escape') {
            this._handleEscape();
            return;
        }

        // Spacebar: Add marker at current time
        if (e.key === ' ' || e.code === 'Space') {
            e.preventDefault();
            this._handleAddMarker();
            return;
        }

        // Arrow keys: Navigate or adjust time
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            if (this.data.selectedMarkerTime !== null) {
                // Adjust selected marker time
                const delta = e.shiftKey ? 1 : 0.1;
                const direction = e.key === 'ArrowUp' ? 1 : -1;
                this.data.nudgeSelectedMarker(delta * direction);
                this._notifyTimelineChange();
            }
            return;
        }

        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
            e.preventDefault();
            if (e.shiftKey) {
                // Navigate between markers
                if (e.key === 'ArrowRight') {
                    this.data.selectNextMarker();
                } else {
                    this.data.selectPreviousMarker();
                }
            } else if (this.data.selectedMarkerTime !== null) {
                // Fine adjust time
                const delta = e.key === 'ArrowRight' ? 0.1 : -0.1;
                this.data.nudgeSelectedMarker(delta);
                this._notifyTimelineChange();
            }
            return;
        }

        // Tab: Navigate markers
        if (e.key === 'Tab') {
            e.preventDefault();
            if (e.shiftKey) {
                this.data.selectPreviousMarker();
            } else {
                this.data.selectNextMarker();
            }
            this._seekToSelected();
            return;
        }

        // Delete/Backspace: Delete selected marker
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (this.data.selectedMarkerTime !== null) {
                e.preventDefault();
                this.data.deleteMarker(this.data.selectedMarkerTime);
                this._notifyTimelineChange();
            }
            return;
        }

        // Number keys 1-9: Quick-set solo to musician by position
        if (e.key >= '1' && e.key <= '9') {
            const index = parseInt(e.key) - 1;
            this._setQuickSolo(index);
            return;
        }

        // Part keys: A, B, I (intro), O (outro), C
        const partKeys = {
            'a': 'A',
            'b': 'B',
            'c': 'C',
            'i': 'intro',
            'o': 'outro'
        };

        if (partKeys[e.key.toLowerCase()] && !e.ctrlKey && !e.metaKey) {
            this._setQuickPart(partKeys[e.key.toLowerCase()]);
            return;
        }

        // P: Toggle play/pause
        if (e.key.toLowerCase() === 'p' && !e.ctrlKey && !e.metaKey) {
            this._togglePlayPause();
            return;
        }

        // Enter: Play from selected marker
        if (e.key === 'Enter') {
            this._playFromSelected();
            return;
        }
    }

    /**
     * Handle save shortcut
     */
    _handleSave() {
        if (this.onSave) {
            this.onSave();
        }
    }

    /**
     * Handle undo
     */
    _handleUndo() {
        this.data.undo();
        this._notifyTimelineChange();
    }

    /**
     * Handle redo
     */
    _handleRedo() {
        this.data.redo();
        this._notifyTimelineChange();
    }

    /**
     * Handle escape - clear selection
     */
    _handleEscape() {
        this.data.selectedMarkerTime = null;
        if (this.editor) {
            this.editor.render();
        }
    }

    /**
     * Handle add marker at current time
     */
    _handleAddMarker() {
        if (this.editor) {
            this.editor.addMarkerAtCurrentTime();
        }
    }

    /**
     * Quick-set solo to musician by index
     */
    _setQuickSolo(index) {
        const musicians = this.data.getMusicianNames();
        if (index >= musicians.length) return;

        const time = this.data.selectedMarkerTime;
        if (time === null) {
            // If no marker selected, try to add one at current time
            if (this.editor) {
                this.editor.addMarkerAtCurrentTime();
            }
            return;
        }

        const musicianName = musicians[index];
        this.data.updateMarkerData(time, { solo: musicianName });
        this._notifyTimelineChange();
    }

    /**
     * Quick-set part for selected marker
     */
    _setQuickPart(part) {
        const time = this.data.selectedMarkerTime;
        if (time === null) return;

        this.data.updateMarkerData(time, { part });
        this._notifyTimelineChange();
    }

    /**
     * Toggle play/pause
     */
    _togglePlayPause() {
        if (!this.editor || !this.editor.player) return;

        const player = this.editor.player;
        if (player.webamp && player.webamp.store) {
            const state = player.webamp.store.getState();
            if (state.media.status === 'PLAYING') {
                player.webamp.store.dispatch({ type: 'PAUSE' });
            } else {
                player.webamp.store.dispatch({ type: 'PLAY' });
            }
        }
    }

    /**
     * Play from the selected marker
     */
    _playFromSelected() {
        if (this.data.selectedMarkerTime === null) return;
        if (this.editor) {
            this.editor.seekAndPlay(this.data.selectedMarkerTime);
        }
    }

    /**
     * Seek to the selected marker
     */
    _seekToSelected() {
        if (this.data.selectedMarkerTime === null) return;
        if (this.editor) {
            this.editor.seekTo(this.data.selectedMarkerTime);
        }
    }

    /**
     * Notify timeline change for instant preview
     */
    _notifyTimelineChange() {
        if (this.editor && this.editor._notifyTimelineChange) {
            this.editor._notifyTimelineChange();
        }
        if (this.editor) {
            this.editor.render();
        }
    }

    /**
     * Get help text for display
     */
    getHelpHTML() {
        return `
            <div class="keyboard-help">
                <h5>Keyboard Shortcuts</h5>
                <ul>
                    <li><kbd>Space</kbd> Add marker at current time</li>
                    <li><kbd>Up/Down</kbd> Adjust time (±0.1s, ±1s with Shift)</li>
                    <li><kbd>Tab</kbd> Navigate markers</li>
                    <li><kbd>1-9</kbd> Set solo to musician</li>
                    <li><kbd>A/B/I/O</kbd> Set part</li>
                    <li><kbd>Enter</kbd> Play from marker</li>
                    <li><kbd>P</kbd> Toggle play/pause</li>
                    <li><kbd>Del</kbd> Delete marker</li>
                    <li><kbd>Ctrl+Z</kbd> Undo</li>
                    <li><kbd>Ctrl+S</kbd> Save to wiki</li>
                </ul>
            </div>
        `;
    }

    /**
     * Clean up
     */
    dispose() {
        document.removeEventListener('keydown', this._handleKeydown);
    }
}

module.exports = KeyboardShortcuts;
