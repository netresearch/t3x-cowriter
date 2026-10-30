/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Writing a value into a FormEngine field the way core field controls do,
 * shared by the per-field suggestion list and the overview of all fields.
 * Nothing is saved: the form is only marked as changed.
 */
import FormEngine from '@typo3/backend/form-engine.js';
import FormEngineValidation from '@typo3/backend/form-engine-validation.js';

/**
 * The visible input of a FormEngine field, by the name the field control
 * received (itemFormElName).
 *
 * @param {string} itemName
 * @param {Document} doc
 * @returns {HTMLInputElement|HTMLTextAreaElement|null}
 */
export function findField(itemName, doc = document) {
    if (!itemName) {
        return null;
    }
    return doc.querySelector('[data-formengine-input-name="' + CSS.escape(itemName) + '"]');
}

/**
 * @param {HTMLInputElement|HTMLTextAreaElement} field
 * @param {string} value
 */
export function applyValue(field, value) {
    if (field.classList.contains('t3js-form-field-slug-input')) {
        // TYPO3's slug element: show its editable input, then let its own
        // "input" handler sanitise the value, check uniqueness and update
        // the hidden field that is submitted.
        const wrap = field.closest('.form-control-wrap');
        wrap?.querySelector('.t3js-form-field-slug-readonly')?.classList.add('hidden');
        field.classList.remove('hidden');
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        return;
    }

    // Same sequence as core's password generator field control.
    field.value = value;
    field.dispatchEvent(new Event('change', { bubbles: true }));
    FormEngineValidation.validateField(field);
    FormEngine.markFieldAsChanged(field);
}
