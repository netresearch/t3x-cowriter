// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

/**
 * Labels - Texts of the CKEditor plugin and the Cowriter dialog.
 *
 * The backend resolves them from locallang_be.xlf in the editor's backend
 * language and injects them as a JSON data element (InjectAjaxUrlsListener,
 * id "cowriter-labels-data"), the same way the AJAX URLs arrive. Each call
 * site passes its English text as fallback, used when the element or the key
 * is missing.
 *
 * @module Labels
 */

/** @type {Record<string, string>|null} */
let labels = null;

/**
 * @returns {Record<string, string>}
 */
function load() {
    if (labels !== null) {
        return labels;
    }

    const element = typeof document === 'undefined' ? null : document.getElementById('cowriter-labels-data');
    if (!element) {
        // Not cached: the element may still be added later.
        return {};
    }

    /** @type {Record<string, string>} */
    const loaded = Object.create(null);
    try {
        const data = JSON.parse(element.textContent || '{}');
        if (data && typeof data === 'object') {
            for (const [key, value] of Object.entries(data)) {
                if (typeof value === 'string' && value !== '') {
                    loaded[key] = value;
                }
            }
        }
    } catch (error) {
        console.error('Cowriter: Failed to parse label data', error);
    }
    labels = loaded;

    return labels;
}

/**
 * The label for a locallang_be.xlf id, with %s / %d filled from the arguments in order.
 *
 * @param {string} key - Trans-unit id, e.g. "ckeditor.button.translate"
 * @param {string} fallback - English text used when no label was injected
 * @param {...(string|number)} args - Values for the placeholders
 * @returns {string}
 */
export function t(key, fallback, ...args) {
    const text = load()[key] ?? fallback;
    let index = 0;
    // One pass: an argument that itself contains "%s" is not filled again.
    return text.replace(/%[sd]/g, (placeholder) => (index < args.length ? String(args[index++]) : placeholder));
}

/**
 * Forget the parsed labels (tests).
 */
export function resetLabels() {
    labels = null;
}
