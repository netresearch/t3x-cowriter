/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the "Generate image" control of FAL file fields.
 *
 * The fixture copies the markup TYPO3's FilesControlContainer renders around
 * the custom controls (the element with the field's DOM id, the hidden
 * "inlineRecord" input holding the reference uids) plus the button
 * AddImageGenerationFileControlListener renders. The dialog is mocked at its
 * module boundary; MessageUtility, Notification and the TYPO3 modules are
 * mocked via vitest.config.js aliases.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import Notification from '@typo3/backend/notification.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';
import { ImageDialog } from '@netresearch/t3_cowriter/ImageDialog';
import {
    CONTROL_SELECTOR,
    fileExtension,
    generateFor,
    isFull,
    referenceUids,
} from '../../Resources/Public/JavaScript/FormEngine/ImageFileControl.js';

vi.mock('@netresearch/t3_cowriter/ImageDialog', () => ({
    ImageDialog: vi.fn(),
}));

const FIELD_ID = 'data-12-tt_content-34-image';
const FIELD_NAME = 'data[tt_content][34][image]';

const generated = {
    success: true,
    image: {
        fileUid: 77,
        fileTable: 'sys_file',
        url: '/fileadmin/user_upload/a-lighthouse-1a2b3c4d.png',
        width: 1024,
        height: 1024,
        mimeType: 'image/png',
        file: '1:/user_upload/a-lighthouse-1a2b3c4d.png',
    },
    alt: 'A lighthouse',
};

function setBody(html) {
    document.body.replaceChildren(document.createRange().createContextualFragment(html));
}

const RECORD = 'data-table="tt_content" data-uid="34" data-field="image" data-pid="12"';

function fixture({ uids = '', max = 0, allowed = 'png', record = RECORD } = {}) {
    setBody(`
<typo3-formengine-container-inline id="${FIELD_ID}" data-object-group="${FIELD_ID}-sys_file_reference">
  <div class="form-group t3js-formengine-validation-marker t3js-inline-controls">
    <button type="button" class="btn btn-default t3js-element-browser">Create new relation</button>
  </div>
  <div class="panel-group" id="${FIELD_ID}_records"></div>
  <div id="${FIELD_ID}_customControls">
    <button type="button" class="btn btn-default t3js-cowriter-generate-image"
      data-file-irre-object="${FIELD_ID}-sys_file_reference" data-form-field-id="${FIELD_ID}"
      data-form-field="${FIELD_NAME}" data-max-items="${max}" data-allowed-extensions="${allowed}" ${record}
      data-label-prompt-help="Help text for the field" data-label-generating="Generating for the field"
      data-label-full-title="Full" data-label-full-message="Remove a file first."
      data-label-not-accepted="Stored as %s, .%s is not accepted." data-label-not-added="Stored as %s, now full."
      data-label-done-title="Added" data-label-done-message="Stored as %s and added.">Generate image</button>
  </div>
  <input type="hidden" class="inlineRecord" name="${FIELD_NAME}" value="${uids}" />
</typo3-formengine-container-inline>`);

    return /** @type {HTMLElement} */ (document.querySelector(CONTROL_SELECTOR));
}

/** The dialog the next call opens settles with what this returns. */
function dialogResult(settle) {
    ImageDialog.mockImplementation(function () {
        this.show = vi.fn(settle);
    });
}

/** Let pending promise callbacks run. */
function flush() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

describe('ImageFileControl', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        dialogResult(() => Promise.resolve(generated));
    });

    it('hands the stored file to the field as core does for "Add media by URL"', async () => {
        const control = fixture();

        await generateFor(control);

        expect(MessageUtility.send).toHaveBeenCalledTimes(1);
        expect(MessageUtility.send).toHaveBeenCalledWith({
            actionName: 'typo3:foreignRelation:insert',
            objectGroup: `${FIELD_ID}-sys_file_reference`,
            table: 'sys_file',
            uid: 77,
        });
        expect(Notification.success).toHaveBeenCalledWith(
            'Added',
            'Stored as 1:/user_upload/a-lighthouse-1a2b3c4d.png and added.',
            8,
        );
    });

    it('opens the dialog with the texts of the field, not of the rich text editor', async () => {
        const control = fixture();

        await generateFor(control);

        expect(ImageDialog).toHaveBeenCalledTimes(1);
        const options = ImageDialog.mock.calls[0][1];
        expect(options.promptHelp).toBe('Help text for the field');
        expect(options.generating).toBe('Generating for the field');
    });

    it('tells the dialog the record and field the image is for, and to name the field', async () => {
        const control = fixture();

        await generateFor(control);

        const options = ImageDialog.mock.calls[0][1];
        expect(options.target).toEqual({ table: 'tt_content', uid: '34', field: 'image', pid: '12' });
        expect(options.showField).toBe(true);
    });

    it('passes an empty target for a control without record data', async () => {
        const control = fixture({ record: '' });

        await generateFor(control);

        expect(ImageDialog.mock.calls[0][1].target).toEqual({ table: '', uid: '0', field: '', pid: '0' });
    });

    it('names the model that generated the image in the success message', async () => {
        const control = fixture();
        dialogResult(() => Promise.resolve({ ...generated, about: { model: 'gpt-image-2' } }));

        await generateFor(control);

        expect(Notification.success).toHaveBeenCalledWith(
            'Added',
            'Stored as 1:/user_upload/a-lighthouse-1a2b3c4d.png and added. Generated with gpt-image-2.',
            8,
        );
    });

    it('returns the focus to the control when the dialog closes', async () => {
        const control = fixture();
        await generateFor(control);

        ImageDialog.mock.calls[0][1].returnFocus();

        expect(document.activeElement).toBe(control);
    });

    it('is started by a click on the control, also on its icon', async () => {
        const control = fixture();
        control.prepend(document.createRange().createContextualFragment('<span class="t3js-icon"><svg></svg></span>'));

        control.querySelector('svg').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flush();

        expect(MessageUtility.send).toHaveBeenCalledTimes(1);
    });

    it('ignores clicks elsewhere', async () => {
        fixture();

        document.querySelector('.t3js-element-browser').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flush();

        expect(ImageDialog).not.toHaveBeenCalled();
    });

    it('does not open the dialog when the field is full', async () => {
        const control = fixture({ uids: '5,6', max: 2 });

        await generateFor(control);

        expect(ImageDialog).not.toHaveBeenCalled();
        expect(MessageUtility.send).not.toHaveBeenCalled();
        expect(Notification.warning).toHaveBeenCalledWith('Full', 'Remove a file first.');
        expect(control.hidden).toBe(true);
    });

    it('does not hand the file over when the field became full while the image was generated', async () => {
        const control = fixture({ uids: '5', max: 2 });
        dialogResult(() => {
            document.querySelector('input.inlineRecord').value = '5,NEW123';
            return Promise.resolve(generated);
        });

        await generateFor(control);

        expect(MessageUtility.send).not.toHaveBeenCalled();
        expect(Notification.warning).toHaveBeenCalledWith(
            'Image stored',
            'Stored as 1:/user_upload/a-lighthouse-1a2b3c4d.png, now full.',
            0,
        );
    });

    it('does not hand over a file type the field does not accept', async () => {
        const control = fixture({ allowed: 'jpg,jpeg' });

        await generateFor(control);

        expect(MessageUtility.send).not.toHaveBeenCalled();
        expect(Notification.warning).toHaveBeenCalledWith(
            'Image stored',
            'Stored as 1:/user_upload/a-lighthouse-1a2b3c4d.png, .png is not accepted.',
            0,
        );
    });

    it('says nothing when the editor cancels', async () => {
        const control = fixture();
        dialogResult(() => Promise.reject(new Error('User cancelled')));

        await generateFor(control);

        expect(MessageUtility.send).not.toHaveBeenCalled();
        expect(Notification.error).not.toHaveBeenCalled();
        expect(Notification.success).not.toHaveBeenCalled();
    });

    it('reports a failure that settles after the dialog was closed', async () => {
        const control = fixture();
        dialogResult(() => Promise.reject(new Error('The image could not be generated.')));

        await generateFor(control);

        expect(MessageUtility.send).not.toHaveBeenCalled();
        expect(Notification.error).toHaveBeenCalledWith(
            'Image generation failed',
            'The image could not be generated.',
            0,
        );
    });

    it('hides itself when core adds the last reference the field may hold, and shows itself when one is removed', () => {
        const control = fixture({ uids: '5', max: 2 });
        const field = document.querySelector('input.inlineRecord');

        field.value = '5,NEW1';
        document.dispatchEvent(new Event('change'));
        expect(control.hidden).toBe(true);

        field.value = '5';
        document.dispatchEvent(new Event('change'));
        expect(control.hidden).toBe(false);
    });

    it('counts the references of its own field only', () => {
        const control = fixture({ uids: '5', max: 1 });
        // Same name earlier in the document, e.g. the field of another record
        // in an inline relation.
        document.body.prepend(document.createRange().createContextualFragment(
            `<input type="hidden" name="${FIELD_NAME}" value="" />`,
        ));

        expect(referenceUids(control)).toEqual(['5']);
        expect(isFull(control)).toBe(true);
    });

    it('treats maxitems 0 as no limit', () => {
        const control = fixture({ uids: '1,2,3,4,5', max: 0 });

        expect(isFull(control)).toBe(false);
    });

    it('reads the extension of a FAL identifier', () => {
        expect(fileExtension('1:/user_upload/a.PNG')).toBe('png');
        expect(fileExtension('1:/user.upload/noext')).toBe('');
        expect(fileExtension('')).toBe('');
    });
});
