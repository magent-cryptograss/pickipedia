/**
 * TimelineEditor - UI Component for Rabbithole Metadata Editor
 *
 * Displays timeline markers in a list with:
 * - Click to seek to marker time
 * - Play from marker button
 * - Delete marker button
 * - Inline time editing with arrow key fine-tuning
 * - YAML editing for marker data (flexible, no predefined schema)
 * - Add marker at current time
 * - Visual highlight of current playing position
 */

const jsYaml = require( './js-yaml.min.js' );

class TimelineEditor {
    constructor(containerId, editableTrackData, player, options = {}) {
        this.container = document.getElementById(containerId);
        if (!this.container) {
            console.error('TimelineEditor: Container not found:', containerId);
            return;
        }

        this.data = editableTrackData;
        this.player = player;
        this.options = options;

        // State
        this.currentPlayingTime = 0;
        this.editingMarkerTime = null; // Time of marker being edited
        this.expandedMarkerTime = null; // Time of marker with YAML editor open
        this.rawMode = true; // Start in raw YAML mode

        // Bind methods
        this._onTimeUpdate = this._onTimeUpdate.bind(this);
        this._onDataChange = this._onDataChange.bind(this);

        // Set up callbacks
        this.data.onChange = this._onDataChange;

        // Register with player for time updates
        if (this.player && this.player.onTimeUpdate) {
            this.player.onTimeUpdate(this._onTimeUpdate);
        }

        // Initial render
        this.render();
    }

    /**
     * Format time as mm:ss.t (tenths)
     */
    formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        const tenths = Math.floor((seconds % 1) * 10);
        return `${mins}:${secs.toString().padStart(2, '0')}.${tenths}`;
    }

    /**
     * Parse time string back to seconds
     */
    parseTime(timeStr) {
        const match = timeStr.match(/^(\d+):(\d{2})(?:\.(\d))?$/);
        if (!match) return null;

        const mins = parseInt(match[1], 10);
        const secs = parseInt(match[2], 10);
        const tenths = match[3] ? parseInt(match[3], 10) : 0;

        return mins * 60 + secs + tenths / 10;
    }

    /**
     * Render the timeline editor UI
     */
    render() {
        if (this.rawMode) {
            this._renderRawMode();
        } else {
            this._renderListMode();
        }
    }

    /**
     * Render raw YAML mode - single textarea with entire timeline
     */
    _renderRawMode() {
        // Build the full data object to show
        const fullData = {
            timeline: this.data.workingData.timeline,
            standardSectionLength: this.data.workingData.standardSectionLength
        };

        const yamlStr = jsYaml.dump(fullData, {
            indent: 2,
            lineWidth: -1,
            sortKeys: (a, b) => {
                // Sort timeline keys numerically
                const numA = parseFloat(a);
                const numB = parseFloat(b);
                if (!isNaN(numA) && !isNaN(numB)) return numA - numB;
                return a.localeCompare(b);
            }
        });

        this.container.innerHTML = `
            <div class="timeline-editor raw-mode">
                <div class="editor-header">
                    <h3>Timeline YAML</h3>
                    <div class="editor-actions">
                        <button class="btn-toggle-mode" title="Switch to list view">
                            List View
                        </button>
                    </div>
                </div>

                <div class="raw-yaml-container">
                    <textarea class="raw-yaml-input" spellcheck="false">${this._escapeHtml(yamlStr)}</textarea>
                    <div class="raw-yaml-error"></div>
                </div>

                <div class="raw-yaml-actions">
                    <button class="btn-apply-raw-yaml">Apply Changes</button>
                    <span class="yaml-hint">Edit YAML, then Apply (or Ctrl+Enter)</span>
                    <span class="current-time-display">Current: ${this.formatTime(this.currentPlayingTime)}</span>
                </div>

                <div class="editor-footer">
                    <div class="dirty-indicator ${this.data.isDirty ? 'dirty' : ''}">
                        ${this.data.isDirty ? 'Unsaved changes' : 'Saved'}
                    </div>
                    <div class="editor-controls">
                        <button class="btn-undo" ${this.data.undoStack.length === 0 ? 'disabled' : ''} title="Undo (Ctrl+Z)">
                            Undo
                        </button>
                        <button class="btn-redo" ${this.data.redoStack.length === 0 ? 'disabled' : ''} title="Redo (Ctrl+Y)">
                            Redo
                        </button>
                        <button class="btn-revert" ${!this.data.isDirty ? 'disabled' : ''} title="Revert all changes">
                            Revert
                        </button>
                    </div>
                </div>
            </div>
        `;

        this._attachRawModeListeners();
    }

    /**
     * Attach event listeners for raw YAML mode
     */
    _attachRawModeListeners() {
        const textarea = this.container.querySelector('.raw-yaml-input');
        const errorDiv = this.container.querySelector('.raw-yaml-error');
        const applyBtn = this.container.querySelector('.btn-apply-raw-yaml');
        const toggleBtn = this.container.querySelector('.btn-toggle-mode');

        // Toggle to list mode
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                this.rawMode = false;
                this.render();
            });
        }

        // Apply button
        if (applyBtn) {
            applyBtn.addEventListener('click', () => {
                this._applyRawYaml(textarea.value, errorDiv);
            });
        }

        // Ctrl+Enter to apply
        if (textarea) {
            textarea.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    this._applyRawYaml(textarea.value, errorDiv);
                }
                // Don't let keyboard shortcuts fire while editing
                e.stopPropagation();
            });

            // Clear error on input
            textarea.addEventListener('input', () => {
                if (errorDiv) errorDiv.textContent = '';
            });
        }

        // Undo/Redo/Revert
        this._attachUndoRedoListeners();
    }

    /**
     * Apply raw YAML to the entire timeline
     */
    _applyRawYaml(yamlStr, errorDiv) {
        try {
            const parsed = jsYaml.load(yamlStr);

            if (typeof parsed !== 'object' || parsed === null) {
                throw new Error('YAML must be an object');
            }

            if (!parsed.timeline || typeof parsed.timeline !== 'object') {
                throw new Error('Must have a "timeline" object');
            }

            // Save undo state
            this.data._saveUndoState();

            // Replace timeline
            this.data.workingData.timeline = parsed.timeline;

            // Update standardSectionLength if provided
            if (parsed.standardSectionLength !== undefined) {
                this.data.workingData.standardSectionLength = parsed.standardSectionLength;
            }

            // Notify changes
            this.data._notifyChange();
            this._notifyTimelineChange();

            // Show success
            if (errorDiv) {
                errorDiv.style.color = '#4a7c59';
                errorDiv.textContent = 'Applied!';
                setTimeout(() => {
                    if (errorDiv) errorDiv.textContent = '';
                }, 1500);
            }

        } catch (e) {
            if (errorDiv) {
                errorDiv.style.color = '#c00';
                errorDiv.textContent = `YAML error: ${e.message}`;
            }
        }
    }

    /**
     * Render list mode (original UI)
     */
    _renderListMode() {
        const entries = this.data.getTimelineEntries();
        const musicians = this.data.getMusicianNames();

        this.container.innerHTML = `
            <div class="timeline-editor">
                <div class="editor-header">
                    <h3>Timeline Editor</h3>
                    <div class="editor-actions">
                        <button class="btn-toggle-mode" title="Switch to raw YAML view">
                            Raw YAML
                        </button>
                        <button class="btn-add-marker" title="Add marker at current time (Space)">
                            + Add Marker
                        </button>
                    </div>
                </div>

                <div class="marker-list">
                    ${entries.map((entry, index) => this.renderMarkerRow(entry, index, musicians)).join('')}
                </div>

                <div class="editor-footer">
                    <div class="dirty-indicator ${this.data.isDirty ? 'dirty' : ''}">
                        ${this.data.isDirty ? 'Unsaved changes' : 'Saved'}
                    </div>
                    <div class="editor-controls">
                        <button class="btn-undo" ${this.data.undoStack.length === 0 ? 'disabled' : ''} title="Undo (Ctrl+Z)">
                            Undo
                        </button>
                        <button class="btn-redo" ${this.data.redoStack.length === 0 ? 'disabled' : ''} title="Redo (Ctrl+Y)">
                            Redo
                        </button>
                        <button class="btn-revert" ${!this.data.isDirty ? 'disabled' : ''} title="Revert all changes">
                            Revert
                        </button>
                    </div>
                </div>
            </div>
        `;

        this._attachEventListeners();
        this._highlightCurrentMarker();
    }

    /**
     * Attach undo/redo/revert listeners (shared between modes)
     */
    _attachUndoRedoListeners() {
        const undoBtn = this.container.querySelector('.btn-undo');
        if (undoBtn) {
            undoBtn.addEventListener('click', () => {
                this.data.undo();
                this._notifyTimelineChange();
            });
        }

        const redoBtn = this.container.querySelector('.btn-redo');
        if (redoBtn) {
            redoBtn.addEventListener('click', () => {
                this.data.redo();
                this._notifyTimelineChange();
            });
        }

        const revertBtn = this.container.querySelector('.btn-revert');
        if (revertBtn) {
            revertBtn.addEventListener('click', () => {
                if (confirm('Revert all changes to original data?')) {
                    this.data.revert();
                    this._notifyTimelineChange();
                }
            });
        }
    }

    /**
     * Render a single marker row
     */
    renderMarkerRow(entry, index, musicians) {
        const { time, data } = entry;
        const isSelected = this.data.selectedMarkerTime === time;
        const isPlaying = this._isMarkerPlaying(time);
        const isExpanded = this.expandedMarkerTime === time;

        // Build marker info string (compact summary)
        let info = [];
        if (data.solo) info.push(`solo: ${data.solo}`);
        if (data.pickup) info.push(`pickup: ${data.pickup}`);
        if (data.part) info.push(`part: ${data.part}`);
        // Show other keys briefly
        const otherKeys = Object.keys(data).filter(k => !['solo', 'pickup', 'part'].includes(k));
        if (otherKeys.length > 0) info.push(`+${otherKeys.length} more`);

        const infoStr = info.join(' | ') || '(empty)';

        // Convert data to YAML for editing
        const yamlStr = Object.keys(data).length > 0
            ? jsYaml.dump(data, { indent: 2, lineWidth: -1 }).trim()
            : '';

        return `
            <div class="marker-row ${isSelected ? 'selected' : ''} ${isPlaying ? 'playing' : ''} ${isExpanded ? 'expanded' : ''}"
                 data-time="${time}"
                 data-index="${index}">

                <div class="marker-header">
                    <div class="marker-time-section">
                        <button class="btn-play-from" data-time="${time}" title="Play from this marker">
                            ▶
                        </button>
                        <input type="text"
                               class="marker-time-input"
                               value="${this.formatTime(time)}"
                               data-time="${time}"
                               title="Click to edit, arrow keys to fine-tune"
                               readonly>
                    </div>

                    <div class="marker-info" data-time="${time}">
                        ${infoStr}
                    </div>

                    <div class="marker-actions">
                        <button class="btn-toggle-yaml" data-time="${time}" title="${isExpanded ? 'Collapse' : 'Edit YAML'}">
                            ${isExpanded ? '▲' : '▼'}
                        </button>
                        <button class="btn-delete-marker" data-time="${time}" title="Delete this marker">
                            ✕
                        </button>
                    </div>
                </div>

                ${isExpanded ? `
                <div class="marker-yaml-editor">
                    <textarea class="yaml-input" data-time="${time}"
                              placeholder="solo: Musician Name&#10;part: A&#10;musicians:&#10;  band: in"
                              spellcheck="false">${this._escapeHtml(yamlStr)}</textarea>
                    <div class="yaml-error" data-time="${time}"></div>
                    <div class="yaml-actions">
                        <button class="btn-apply-yaml" data-time="${time}">Apply</button>
                        <span class="yaml-hint">Edit YAML above, then Apply (or Ctrl+Enter)</span>
                    </div>
                </div>
                ` : ''}
            </div>
        `;
    }

    /**
     * Escape HTML entities
     */
    _escapeHtml(str) {
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;');
    }

    /**
     * Attach event listeners to the rendered UI
     */
    _attachEventListeners() {
        // Add marker button
        const addBtn = this.container.querySelector('.btn-add-marker');
        if (addBtn) {
            addBtn.addEventListener('click', () => this.addMarkerAtCurrentTime());
        }

        // Play from marker buttons
        this.container.querySelectorAll('.btn-play-from').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const time = parseFloat(btn.dataset.time);
                this.seekAndPlay(time);
            });
        });

        // Click on marker row to select/seek
        this.container.querySelectorAll('.marker-row').forEach(row => {
            row.addEventListener('click', (e) => {
                if (e.target.classList.contains('btn-delete-marker') ||
                    e.target.classList.contains('btn-edit-marker') ||
                    e.target.classList.contains('btn-play-from') ||
                    e.target.classList.contains('marker-time-input')) {
                    return;
                }
                const time = parseFloat(row.dataset.time);
                this.data.selectedMarkerTime = time;
                this.seekTo(time);
                this.render();
            });
        });

        // Time input click to enable editing
        this.container.querySelectorAll('.marker-time-input').forEach(input => {
            input.addEventListener('click', (e) => {
                e.stopPropagation();
                this._startEditingTime(input);
            });

            input.addEventListener('keydown', (e) => {
                this._handleTimeInputKeydown(e, input);
            });

            input.addEventListener('blur', (e) => {
                this._finishEditingTime(input);
            });
        });

        // Delete marker buttons
        this.container.querySelectorAll('.btn-delete-marker').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const time = parseFloat(btn.dataset.time);
                if (confirm(`Delete marker at ${this.formatTime(time)}?`)) {
                    this.data.deleteMarker(time);
                    this._notifyTimelineChange();
                }
            });
        });

        // Toggle YAML editor buttons
        this.container.querySelectorAll('.btn-toggle-yaml').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const time = parseFloat(btn.dataset.time);
                this.expandedMarkerTime = this.expandedMarkerTime === time ? null : time;
                this.render();
            });
        });

        // YAML textareas - handle Ctrl+Enter to apply
        this.container.querySelectorAll('.yaml-input').forEach(textarea => {
            textarea.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    const time = parseFloat(textarea.dataset.time);
                    this._applyYaml(time, textarea.value);
                }
                // Prevent keyboard shortcuts while editing YAML
                e.stopPropagation();
            });

            // Clear error on input
            textarea.addEventListener('input', () => {
                const time = parseFloat(textarea.dataset.time);
                const errorDiv = this.container.querySelector(`.yaml-error[data-time="${time}"]`);
                if (errorDiv) errorDiv.textContent = '';
            });
        });

        // Apply YAML buttons
        this.container.querySelectorAll('.btn-apply-yaml').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const time = parseFloat(btn.dataset.time);
                const textarea = this.container.querySelector(`.yaml-input[data-time="${time}"]`);
                if (textarea) {
                    this._applyYaml(time, textarea.value);
                }
            });
        });

        // Undo/Redo/Revert buttons
        const undoBtn = this.container.querySelector('.btn-undo');
        if (undoBtn) {
            undoBtn.addEventListener('click', () => {
                this.data.undo();
                this._notifyTimelineChange();
            });
        }

        const redoBtn = this.container.querySelector('.btn-redo');
        if (redoBtn) {
            redoBtn.addEventListener('click', () => {
                this.data.redo();
                this._notifyTimelineChange();
            });
        }

        const revertBtn = this.container.querySelector('.btn-revert');
        if (revertBtn) {
            revertBtn.addEventListener('click', () => {
                if (confirm('Revert all changes to original data?')) {
                    this.data.revert();
                    this._notifyTimelineChange();
                }
            });
        }
    }

    /**
     * Start editing a time input
     */
    _startEditingTime(input) {
        input.readOnly = false;
        input.select();
        this.editingMarkerTime = parseFloat(input.dataset.time);
    }

    /**
     * Handle keydown in time input
     */
    _handleTimeInputKeydown(e, input) {
        const time = parseFloat(input.dataset.time);

        if (e.key === 'Escape') {
            input.value = this.formatTime(time);
            input.readOnly = true;
            input.blur();
            return;
        }

        if (e.key === 'Enter') {
            this._finishEditingTime(input);
            input.blur();
            return;
        }

        // Arrow key fine-tuning
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            const delta = e.shiftKey ? 1 : 0.1; // 1s with shift, 0.1s without
            const direction = e.key === 'ArrowUp' ? 1 : -1;
            const newTime = Math.max(0, time + (delta * direction));

            // Update the marker time
            this.data.updateMarkerTime(time, newTime);
            input.dataset.time = newTime;
            input.value = this.formatTime(newTime);
            this._notifyTimelineChange();
        }
    }

    /**
     * Finish editing a time input
     */
    _finishEditingTime(input) {
        const oldTime = parseFloat(input.dataset.time);
        const newTime = this.parseTime(input.value);

        input.readOnly = true;
        this.editingMarkerTime = null;

        if (newTime === null || newTime === oldTime) {
            input.value = this.formatTime(oldTime);
            return;
        }

        this.data.updateMarkerTime(oldTime, newTime);
        this._notifyTimelineChange();
    }

    /**
     * Check if a marker is currently "playing" (within its time range)
     */
    _isMarkerPlaying(markerTime) {
        const entries = this.data.getTimelineEntries();
        const index = entries.findIndex(e => e.time === markerTime);

        if (index === -1) return false;

        const nextTime = entries[index + 1]?.time ?? Infinity;
        return this.currentPlayingTime >= markerTime && this.currentPlayingTime < nextTime;
    }

    /**
     * Highlight the currently playing marker
     */
    _highlightCurrentMarker() {
        this.container.querySelectorAll('.marker-row').forEach(row => {
            const time = parseFloat(row.dataset.time);
            row.classList.toggle('playing', this._isMarkerPlaying(time));
        });
    }

    /**
     * Add a new marker at the current playback time
     */
    addMarkerAtCurrentTime() {
        const time = this.currentPlayingTime;

        // Default marker with empty data
        this.data.addMarker(time, {});
        this._notifyTimelineChange();

        // Expand the YAML editor for the new marker
        this.expandedMarkerTime = time;
        this.render();

        // Focus the textarea
        setTimeout(() => {
            const textarea = this.container.querySelector(`.yaml-input[data-time="${time}"]`);
            if (textarea) textarea.focus();
        }, 50);
    }

    /**
     * Apply YAML content to a marker
     */
    _applyYaml(time, yamlStr) {
        const errorDiv = this.container.querySelector(`.yaml-error[data-time="${time}"]`);

        try {
            // Parse YAML
            const parsed = yamlStr.trim() ? jsYaml.load(yamlStr) : {};

            if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
                throw new Error('YAML must be an object (key: value pairs)');
            }

            // Replace marker data entirely with parsed YAML
            // First, save undo state
            this.data._saveUndoState();

            // Replace the marker data
            const key = String(Math.round(time * 10) / 10);
            this.data.workingData.timeline[key] = parsed;

            // Notify changes
            this.data._notifyChange();
            this._notifyTimelineChange();

            // Clear error, keep expanded to show success
            if (errorDiv) {
                errorDiv.textContent = '';
                errorDiv.style.color = '#4a7c59';
                errorDiv.textContent = 'Applied!';
                setTimeout(() => {
                    if (errorDiv) errorDiv.textContent = '';
                }, 1500);
            }

        } catch (e) {
            if (errorDiv) {
                errorDiv.style.color = '#c00';
                errorDiv.textContent = `YAML error: ${e.message}`;
            }
        }
    }

    /**
     * Seek to a specific time
     */
    seekTo(time) {
        if (!this.player || !this.player.webamp || !this.player.webamp.store) {
            return;
        }

        const state = this.player.webamp.store.getState();
        const duration = state.media.length || this.data.workingData.duration || 180;
        const percent = (time / duration) * 100;

        this.player.webamp.store.dispatch({
            type: 'SEEK_TO_PERCENT_COMPLETE',
            percent: Math.min(100, Math.max(0, percent))
        });
    }

    /**
     * Seek to a time and start playing
     */
    seekAndPlay(time) {
        this.seekTo(time);

        if (this.player && this.player.webamp && this.player.webamp.store) {
            this.player.webamp.store.dispatch({ type: 'PLAY' });
        }
    }

    /**
     * Called when playback time updates
     */
    _onTimeUpdate(currentTime, arrangement) {
        this.currentPlayingTime = currentTime;

        // Update time display in raw mode without full re-render
        if (this.rawMode) {
            const timeDisplay = this.container.querySelector('.current-time-display');
            if (timeDisplay) {
                timeDisplay.textContent = `Current: ${this.formatTime(currentTime)}`;
            }
        } else {
            this._highlightCurrentMarker();
        }
    }

    /**
     * Called when data changes
     */
    _onDataChange(timeline) {
        this.render();
    }

    /**
     * Notify the player of timeline changes (for instant preview)
     */
    _notifyTimelineChange() {
        if (this.player && this.player.updateTimeline) {
            this.player.updateTimeline(this.data.timeline);
        }
    }

    /**
     * Clean up
     */
    dispose() {
        this.data.onChange = null;
        if (this.player) {
            this.player._timeUpdateCallback = null;
        }
    }
}

module.exports = TimelineEditor;
