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

    // Render buttons in footer. The core modal is a Lit element (13.4 and
    // 14.3): its footer appears only after Modal.advanced() has returned, and
    // updateComplete resolves once it is there. Modal.renderAsync = true
    // reproduces that; by default the footer renders at once, a simplification
    // the tests that click buttons right after show() rely on.
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    const renderFooter = () => {
        if (Array.isArray(options.buttons)) {
            for (const btnDef of options.buttons) {
                const btn = document.createElement('button');
                btn.className = `btn ${btnDef.btnClass || ''}`.trim();
                btn.textContent = btnDef.text || '';
                // Like the core modal: the name becomes the name attribute.
                if (btnDef.name) btn.setAttribute('name', btnDef.name);
                btn.addEventListener('click', () => {
                    if (typeof btnDef.trigger === 'function') {
                        btnDef.trigger();
                    }
                });
                footer.appendChild(btn);
            }
        }
        el.appendChild(footer);
        return true;
    };
    if (Modal.renderAsync) {
        el.updateComplete = Promise.resolve().then(renderFooter);
    } else {
        renderFooter();
        el.updateComplete = Promise.resolve(true);
    }

    el.hideModal = () => {
        el.dispatchEvent(new Event('typo3-modal-hidden'));
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
    renderAsync: false,
};

export default Modal;
