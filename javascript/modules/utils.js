
/**
 * Formats a number of bytes into a human-readable string with units.
 *
 * @param {number} bytes - The number of bytes to format.
 * @param {number} [precision=2] - The number of decimal places to include.
 * @returns {string} The formatted string (e.g., "1.5 GB").
 */
export function formatBytes(bytes, precision = 2) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    bytes = max(bytes, 0);
    const pow = Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024));
    const p = Math.min(pow, units.length - 1);
    bytes /= Math.pow(1024, p);
    return Math.round(bytes, precision) + ' ' + units[p];
}

/**
 * Returns the maximum of two values.
 *
 * @param {number} a - First value.
 * @param {number} b - Second value.
 * @returns {number} The greater of the two values.
 * @private
 */
function max(a, b) { return a > b ? a : b; }

/**
 * Formats a megabyte value into a gigabyte string.
 *
 * @param {number|string} mb - The megabyte value to format.
 * @returns {string} The formatted string (e.g., "4.5 GB").
 */
export function formatGbFromMb(mb) {
    const n = Number(mb) || 0;
    if (n <= 0) return '0 GB';
    return (Math.round((n / 1024) * 10) / 10) + ' GB';
}

/**
 * Toggles the open state of a custom dropdown select element.
 * Closes other open selects before toggling.
 *
 * @param {string} id - The ID of the select element.
 */
export function toggleSelect(id) {
    const select = document.getElementById(id);
    if (!select) return;
    document.querySelectorAll('.mcmm-select').forEach(s => {
        if (s.id !== id) s.classList.remove('open');
    });
    select.classList.toggle('open');
}

/**
 * Selects an option from a custom dropdown and updates the trigger text 
 * and hidden target value.
 *
 * @param {string} selectId - The ID of the select element.
 * @param {string} value - The value of the selected option.
 * @param {string} text - The display text of the selected option.
 */
export function selectOption(selectId, value, text) {
    const select = document.getElementById(selectId);
    if (!select) return;
    const trigger = select.querySelector('.mcmm-select-trigger');
    const targetId = select.dataset.target;
    const hidden = targetId ? document.getElementById(targetId) : (select.parentElement.querySelector('input[type="hidden"]') || select.querySelector('input[type="hidden"]'));

    if (trigger) trigger.textContent = text;
    if (hidden) hidden.value = value;

    select.querySelectorAll('.mcmm-option').forEach(o => o.classList.remove('selected'));
    if (typeof event !== 'undefined' && event.target) event.target.classList.add('selected');
    select.classList.remove('open');
}

// Global click listener for select closing
document.addEventListener('click', (e) => {
    if (!e.target.closest('.mcmm-select')) {
        document.querySelectorAll('.mcmm-select').forEach(s => s.classList.remove('open'));
    }
});
