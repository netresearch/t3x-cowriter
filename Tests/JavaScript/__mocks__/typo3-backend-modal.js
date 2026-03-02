/**
 * Mock for @typo3/backend/modal.js
 *
 * Provides a minimal TYPO3 Backend Modal stub for testing.
 * The modal instance is a real DOM element to support querySelector.
 */

/**
 * Create a mock modal element that behaves like a TYPO3 Modal.
 *
 * @param {object} options
 * @returns {HTMLElement}
 */
function createMockModal(options) {
    const el = document.createElement('div');
    el.className = 'modal';
    el.dataset.modalTitle = options.title || '';

    // Render content
    const body = document.createElement('div');
    body.className = 'modal-body';
    if (options.content instanceof HTMLElement) {
        body.appendChild(options.content);
    }
    el.appendChild(body);

    // Render buttons in footer
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    if (Array.isArray(options.buttons)) {
        for (const btnDef of options.buttons) {
            const btn = document.createElement('button');
            btn.className = `btn ${btnDef.btnClass || ''}`.trim();
            btn.textContent = btnDef.text || '';
            if (btnDef.name) btn.dataset.name = btnDef.name;
            btn.addEventListener('click', () => {
                if (typeof btnDef.trigger === 'function') {
                    btnDef.trigger();
                }
            });
            footer.appendChild(btn);
        }
    }
    el.appendChild(footer);

    el.hideModal = () => {
        el.remove();
    };

    document.body.appendChild(el);
    return el;
}

const Modal = {
    sizes: {
        small: 'small',
        medium: 'medium',
        large: 'large',
        full: 'full',
    },
    advanced: (options) => createMockModal(options),
};

export default Modal;
