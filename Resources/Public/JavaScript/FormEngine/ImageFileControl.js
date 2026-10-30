/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

/**
 * "Generate image" control of FAL file fields in FormEngine
 * (AddImageGenerationFileControlListener renders the button).
 *
 * The editor describes the image in the same dialog as in the rich text
 * editor, the route tx_cowriter_image_generate generates and stores it, and
 * the stored file is handed to the field with the message core's "Add media
 * by URL" sends: typo3:foreignRelation:insert with the field's object group
 * and the sys_file uid. The field's container then creates the file
 * reference exactly as for a file the editor selected; nothing is saved until
 * the record is saved.
 *
 * Core hides its own add controls while the field is full, but not this one,
 * which renders below the references: the module does that itself on the
 * "change" event core dispatches after a reference was added or removed, and
 * checks again before the dialog opens and before the hand-over, because a
 * full field silently drops a new reference.
 *
 * Clicks are delegated from the document, so a control inside an inline
 * record loaded later works as well.
 */
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';
import Notification from '@typo3/backend/notification.js';
import { AIService } from '@netresearch/t3_cowriter/AIService';
import { ImageDialog } from '@netresearch/t3_cowriter/ImageDialog';
import { withModel } from '@netresearch/t3_cowriter/AboutSection';
import { t } from '@netresearch/t3_cowriter/Labels';

export const CONTROL_SELECTOR = '.t3js-cowriter-generate-image';

/**
 * A text of the control: the data-label-* attribute the listener filled from
 * locallang_be.xlf in the editor's language, else the English fallback, with
 * %s filled in order.
 *
 * @param {HTMLElement} control
 * @param {string} name - e.g. "promptHelp" for data-label-prompt-help
 * @param {string} fallback
 * @param {...string} args
 * @returns {string}
 */
function text(control, name, fallback, ...args) {
    const key = 'label' + name.charAt(0).toUpperCase() + name.slice(1);
    let index = 0;

    // One pass, as in Labels.t(): an argument containing "%s" is not filled again.
    return (control.dataset[key] || fallback)
        .replace(/%[sd]/g, (placeholder) => (index < args.length ? String(args[index++]) : placeholder));
}

/**
 * The uids of the references the field holds, from the hidden field core
 * keeps them in (the same list core's isBelowMax() counts).
 *
 * @param {HTMLElement} control
 * @returns {string[]}
 */
export function referenceUids(control) {
    const container = document.getElementById(control.dataset.formFieldId || '');
    const name = control.dataset.formField || '';
    if (!container || name === '') {
        return [];
    }

    const field = Array.from(document.getElementsByName(name)).find((element) => container.contains(element));
    if (!(field instanceof HTMLInputElement)) {
        return [];
    }

    return field.value.split(',').map((uid) => uid.trim()).filter((uid) => uid !== '');
}

/**
 * @param {HTMLElement} control
 * @returns {boolean}
 */
export function isFull(control) {
    const max = parseInt(control.dataset.maxItems || '0', 10);

    return max > 0 && referenceUids(control).length >= max;
}

/**
 * Hides every control whose field is full and shows the others again.
 */
export function syncVisibility() {
    document.querySelectorAll(CONTROL_SELECTOR).forEach((control) => {
        if (control instanceof HTMLElement) {
            control.hidden = isFull(control);
        }
    });
}

/**
 * The lower-case extension of a FAL identifier such as "1:/user_upload/a.png".
 *
 * @param {string} identifier
 * @returns {string}
 */
export function fileExtension(identifier) {
    const name = identifier.split('/').pop() || '';
    const dot = name.lastIndexOf('.');

    return dot > 0 ? name.slice(dot + 1).toLowerCase() : '';
}

/**
 * @param {HTMLElement} control
 * @returns {Promise<void>}
 */
export async function generateFor(control) {
    if (isFull(control)) {
        control.hidden = true;
        Notification.warning(
            text(control, 'fullTitle', 'The field is full'),
            text(control, 'fullMessage', 'Remove a file from the field before you generate an image for it.'),
        );
        return;
    }

    const dialog = new ImageDialog(new AIService(), {
        returnFocus: () => control.focus(),
        promptHelp: text(
            control,
            'promptHelp',
            'The image is stored in your upload folder and added to this field. Save the record to keep it.',
        ),
        generating: text(
            control,
            'generating',
            'Generating the image. This can take one or two minutes. '
            + 'If you close this dialog, the image is still added to the field when it is ready, '
            + 'as long as this record stays open; otherwise it is in your upload folder.',
        ),
        // The record and field the image is for: the dialog proposes a description from them.
        target: {
            table: control.dataset.table || '',
            uid: control.dataset.uid || '0',
            field: control.dataset.field || '',
            pid: control.dataset.pid || '0',
        },
        showField: true,
    });

    let result;
    try {
        // Also resolves when the dialog was closed while the image was
        // generated: the image is paid for and stored.
        result = await dialog.show();
    } catch (error) {
        if (error?.message && error.message !== 'User cancelled') {
            Notification.error(t('ckeditor.image.failed', 'Image generation failed'), error.message, 0);
        }
        return;
    }

    const file = String(result?.image?.file || '');
    const extension = fileExtension(file);
    const accepted = (control.dataset.allowedExtensions || '').split(',').filter((value) => value !== '');

    if (!accepted.includes(extension)) {
        Notification.warning(
            t('ckeditor.image.stored.title', 'Image stored'),
            text(
                control,
                'notAccepted',
                'Stored as %s. This field does not accept .%s files, so the image was not added to it.',
                file,
                extension,
            ),
            0,
        );
        return;
    }

    if (isFull(control)) {
        control.hidden = true;
        Notification.warning(
            t('ckeditor.image.stored.title', 'Image stored'),
            text(
                control,
                'notAdded',
                'Stored as %s. The field is full by now, so the image was not added to it.',
                file,
            ),
            0,
        );
        return;
    }

    MessageUtility.send({
        actionName: 'typo3:foreignRelation:insert',
        objectGroup: control.dataset.fileIrreObject,
        table: 'sys_file',
        uid: result.image.fileUid,
    });

    Notification.success(
        text(control, 'doneTitle', 'Image added'),
        withModel(text(control, 'doneMessage', 'Stored as %s and added to the field. Save the record to keep it.', file), result),
        8,
    );
}

/**
 * @param {MouseEvent} event
 */
function onClick(event) {
    const target = event.target instanceof Element ? event.target.closest(CONTROL_SELECTOR) : null;
    if (!(target instanceof HTMLElement)) {
        return;
    }

    event.preventDefault();
    void generateFor(target);
}

document.addEventListener('click', onClick);
document.addEventListener('change', syncVisibility);
