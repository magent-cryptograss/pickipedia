/**
 * EditableTrackData - State Management for Rabbithole Editor
 *
 * Manages the editable state of track timeline data with:
 * - Original data (immutable reference from wiki)
 * - Working data (live edits with instant preview)
 * - Dirty state detection
 * - localStorage auto-backup
 * - Undo/redo support
 */

const BACKUP_INTERVAL = 10000; // Auto-backup every 10 seconds
const STORAGE_KEY_PREFIX = 'rabbithole-editor-';

class EditableTrackData {
    constructor(trackData, options = {}) {
        // Store deep copies to prevent mutation
        this.originalData = this._deepClone(trackData);
        this.workingData = this._deepClone(trackData);

        // Track the recording title for storage keys
        this.recordingTitle = options.recordingTitle || trackData.title || 'untitled';

        // Undo/redo stacks
        this.undoStack = [];
        this.redoStack = [];
        this.maxUndoSteps = options.maxUndoSteps || 50;

        // Callbacks
        this.onChange = options.onChange || null;
        this.onDirtyChange = options.onDirtyChange || null;

        // Selection state for the editor
        this.selectedMarkerTime = null;

        // Auto-backup
        this.backupInterval = null;
        if (options.enableBackup !== false) {
            this._startAutoBackup();
        }

        // Try to restore from backup
        if (options.restoreBackup !== false) {
            this._restoreFromBackup();
        }
    }

    /**
     * Check if there are unsaved changes
     */
    get isDirty() {
        return JSON.stringify(this.workingData.timeline) !==
               JSON.stringify(this.originalData.timeline);
    }

    /**
     * Get the current timeline
     */
    get timeline() {
        return this.workingData.timeline;
    }

    /**
     * Get sorted timeline entries as array
     * @returns {Array<{time: number, data: Object}>}
     */
    getTimelineEntries() {
        return Object.entries(this.workingData.timeline)
            .map(([timeStr, data]) => ({
                time: parseFloat(timeStr),
                data
            }))
            .sort((a, b) => a.time - b.time);
    }

    /**
     * Get a specific timeline marker
     * @param {number} time - Marker time
     * @returns {Object|null}
     */
    getMarker(time) {
        const key = this._timeToKey(time);
        return this.workingData.timeline[key] || null;
    }

    /**
     * Update a timeline marker's time
     * @param {number} oldTime - Current time
     * @param {number} newTime - New time
     */
    updateMarkerTime(oldTime, newTime) {
        const oldKey = this._timeToKey(oldTime);
        const newKey = this._timeToKey(newTime);

        if (oldKey === newKey) return;

        const data = this.workingData.timeline[oldKey];
        if (!data) return;

        this._saveUndoState();

        delete this.workingData.timeline[oldKey];
        this.workingData.timeline[newKey] = data;

        // Update selection
        if (this.selectedMarkerTime === oldTime) {
            this.selectedMarkerTime = newTime;
        }

        this._notifyChange();
    }

    /**
     * Update a timeline marker's data
     * @param {number} time - Marker time
     * @param {Object} updates - Partial data to merge
     */
    updateMarkerData(time, updates) {
        const key = this._timeToKey(time);

        if (!this.workingData.timeline[key]) {
            return;
        }

        this._saveUndoState();

        this.workingData.timeline[key] = {
            ...this.workingData.timeline[key],
            ...updates
        };

        this._notifyChange();
    }

    /**
     * Add a new timeline marker
     * @param {number} time - Time for the marker
     * @param {Object} data - Marker data
     */
    addMarker(time, data = {}) {
        const key = this._timeToKey(time);

        this._saveUndoState();

        this.workingData.timeline[key] = data;
        this.selectedMarkerTime = time;

        this._notifyChange();
    }

    /**
     * Delete a timeline marker
     * @param {number} time - Marker time
     */
    deleteMarker(time) {
        const key = this._timeToKey(time);

        if (!this.workingData.timeline[key]) {
            return;
        }

        this._saveUndoState();

        delete this.workingData.timeline[key];

        // Clear selection if deleted marker was selected
        if (this.selectedMarkerTime === time) {
            this.selectedMarkerTime = null;
        }

        this._notifyChange();
    }

    /**
     * Nudge the selected marker's time
     * @param {number} delta - Time delta in seconds (positive = later, negative = earlier)
     */
    nudgeSelectedMarker(delta) {
        if (this.selectedMarkerTime === null) return;

        const newTime = Math.max(0, this.selectedMarkerTime + delta);
        this.updateMarkerTime(this.selectedMarkerTime, newTime);
    }

    /**
     * Select the next marker in time order
     */
    selectNextMarker() {
        const entries = this.getTimelineEntries();
        if (entries.length === 0) return;

        if (this.selectedMarkerTime === null) {
            this.selectedMarkerTime = entries[0].time;
        } else {
            const currentIndex = entries.findIndex(e => e.time === this.selectedMarkerTime);
            const nextIndex = (currentIndex + 1) % entries.length;
            this.selectedMarkerTime = entries[nextIndex].time;
        }

        this._notifyChange();
    }

    /**
     * Select the previous marker in time order
     */
    selectPreviousMarker() {
        const entries = this.getTimelineEntries();
        if (entries.length === 0) return;

        if (this.selectedMarkerTime === null) {
            this.selectedMarkerTime = entries[entries.length - 1].time;
        } else {
            const currentIndex = entries.findIndex(e => e.time === this.selectedMarkerTime);
            const prevIndex = currentIndex <= 0 ? entries.length - 1 : currentIndex - 1;
            this.selectedMarkerTime = entries[prevIndex].time;
        }

        this._notifyChange();
    }

    /**
     * Select marker closest to given time
     * @param {number} time - Target time
     */
    selectMarkerNear(time) {
        const entries = this.getTimelineEntries();
        if (entries.length === 0) return;

        let closest = entries[0];
        let closestDist = Math.abs(entries[0].time - time);

        for (const entry of entries) {
            const dist = Math.abs(entry.time - time);
            if (dist < closestDist) {
                closest = entry;
                closestDist = dist;
            }
        }

        this.selectedMarkerTime = closest.time;
        this._notifyChange();
    }

    /**
     * Undo the last change
     */
    undo() {
        if (this.undoStack.length === 0) return;

        const previousState = this.undoStack.pop();
        this.redoStack.push(this._deepClone(this.workingData.timeline));
        this.workingData.timeline = previousState;

        this._notifyChange();
    }

    /**
     * Redo the last undone change
     */
    redo() {
        if (this.redoStack.length === 0) return;

        const nextState = this.redoStack.pop();
        this.undoStack.push(this._deepClone(this.workingData.timeline));
        this.workingData.timeline = nextState;

        this._notifyChange();
    }

    /**
     * Discard all changes and revert to original
     */
    revert() {
        this._saveUndoState();
        this.workingData = this._deepClone(this.originalData);
        this.selectedMarkerTime = null;
        this._notifyChange();
    }

    /**
     * Mark current working state as the new original (after successful save)
     */
    markSaved() {
        this.originalData = this._deepClone(this.workingData);
        this._clearBackup();
        this._notifyDirtyChange();
    }

    /**
     * Update original data from external source (e.g., after fetching from wiki)
     * @param {Object} trackData - New track data from source
     */
    updateOriginal(trackData) {
        this.originalData = this._deepClone(trackData);
        // Also update working data if not dirty
        if (!this.isDirty) {
            this.workingData = this._deepClone(trackData);
        }
        this._notifyChange();
    }

    /**
     * Get the ensemble (musician list)
     */
    get ensemble() {
        return this.workingData.ensemble || {};
    }

    /**
     * Get the musician names in order
     */
    getMusicianNames() {
        return Object.keys(this.ensemble);
    }

    /**
     * Clean up resources
     */
    dispose() {
        if (this.backupInterval) {
            clearInterval(this.backupInterval);
        }
    }

    // Private methods

    _timeToKey(time) {
        // Round to 1 decimal place for storage keys
        return String(Math.round(time * 10) / 10);
    }

    _deepClone(obj) {
        return JSON.parse(JSON.stringify(obj));
    }

    _saveUndoState() {
        this.undoStack.push(this._deepClone(this.workingData.timeline));
        if (this.undoStack.length > this.maxUndoSteps) {
            this.undoStack.shift();
        }
        // Clear redo stack on new change
        this.redoStack = [];
    }

    _notifyChange() {
        if (this.onChange) {
            this.onChange(this.workingData.timeline);
        }
        this._notifyDirtyChange();
    }

    _notifyDirtyChange() {
        if (this.onDirtyChange) {
            this.onDirtyChange(this.isDirty);
        }
    }

    _getStorageKey() {
        const slug = this.recordingTitle.toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
        return STORAGE_KEY_PREFIX + slug;
    }

    _startAutoBackup() {
        this.backupInterval = setInterval(() => {
            if (this.isDirty) {
                this._saveBackup();
            }
        }, BACKUP_INTERVAL);
    }

    _saveBackup() {
        try {
            const backup = {
                timeline: this.workingData.timeline,
                timestamp: Date.now(),
                recordingTitle: this.recordingTitle
            };
            localStorage.setItem(this._getStorageKey(), JSON.stringify(backup));
            console.debug('Auto-backup saved for', this.recordingTitle);
        } catch (e) {
            console.warn('Failed to save backup:', e);
        }
    }

    _restoreFromBackup() {
        try {
            const stored = localStorage.getItem(this._getStorageKey());
            if (!stored) return;

            const backup = JSON.parse(stored);

            // Check if backup is for the same recording
            if (backup.recordingTitle !== this.recordingTitle) {
                return;
            }

            // Check if backup is newer than what we have
            // (This is a simple heuristic - in production you'd compare revisions)
            console.info(`Found backup from ${new Date(backup.timestamp).toLocaleString()}`);

            // We could prompt the user here, but for now just load it
            this.workingData.timeline = backup.timeline;
            this._notifyChange();

        } catch (e) {
            console.warn('Failed to restore backup:', e);
        }
    }

    _clearBackup() {
        try {
            localStorage.removeItem(this._getStorageKey());
        } catch (e) {
            console.warn('Failed to clear backup:', e);
        }
    }
}

module.exports = EditableTrackData;
