/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { defineConfig } from 'vitest/config';
import { resolve } from 'path';

/**
 * Aliases both projects share: the TYPO3 backend modules are mocked, the
 * extension's own modules resolve to their sources.
 */
const sharedAliases = {
    '@typo3/backend/modal.js': resolve(__dirname, 'Tests/JavaScript/__mocks__/typo3-backend-modal.js'),
    '@typo3/backend/viewport.js': resolve(__dirname, 'Tests/JavaScript/__mocks__/typo3-backend-viewport.js'),
    '@typo3/backend/notification.js': resolve(__dirname, 'Tests/JavaScript/__mocks__/typo3-backend-notification.js'),
    '@typo3/backend/form-engine.js': resolve(__dirname, 'Tests/JavaScript/__mocks__/typo3-backend-form-engine.js'),
    '@typo3/backend/form-engine-validation.js': resolve(
        __dirname,
        'Tests/JavaScript/__mocks__/typo3-backend-form-engine-validation.js'
    ),
    '@typo3/backend/utility/message-utility.js': resolve(
        __dirname,
        'Tests/JavaScript/__mocks__/typo3-backend-message-utility.js'
    ),
    '@typo3/core/ajax/ajax-request.js': resolve(__dirname, 'Tests/JavaScript/__mocks__/typo3-core-ajax-request.js'),
    '@typo3/core/document-service.js': resolve(__dirname, 'Tests/JavaScript/__mocks__/typo3-core-document-service.js'),
    '@netresearch/t3_cowriter/AIService': resolve(__dirname, 'Resources/Public/JavaScript/Ckeditor/AIService.js'),
    '@netresearch/t3_cowriter/CowriterDialog': resolve(__dirname, 'Resources/Public/JavaScript/Ckeditor/CowriterDialog.js'),
    '@netresearch/t3_cowriter/Labels': resolve(__dirname, 'Resources/Public/JavaScript/Ckeditor/Labels.js'),
    '@netresearch/t3_cowriter/AboutSection': resolve(__dirname, 'Resources/Public/JavaScript/Ckeditor/AboutSection.js'),
    '@netresearch/t3_cowriter/HtmlSanitizer': resolve(__dirname, 'Resources/Public/JavaScript/Ckeditor/HtmlSanitizer.js'),
    '@netresearch/t3_cowriter/ImageDialog': resolve(__dirname, 'Resources/Public/JavaScript/Ckeditor/ImageDialog.js'),
    '@netresearch/t3_cowriter/InlineSuggestions': resolve(
        __dirname,
        'Resources/Public/JavaScript/Ckeditor/InlineSuggestions.js'
    ),
    '@netresearch/t3_cowriter/FieldValue': resolve(__dirname, 'Resources/Public/JavaScript/FormEngine/FieldValue.js'),
    '@netresearch/t3_cowriter/FieldOverview': resolve(__dirname, 'Resources/Public/JavaScript/FormEngine/FieldOverview.js'),
};

export default defineConfig({
    test: {
        coverage: {
            provider: 'v8',
            reporter: ['text', 'json', 'html', 'lcov'],
            reportsDirectory: 'coverage',
            include: ['Resources/Public/JavaScript/**/*.js'],
        },
        projects: [
            {
                // Unit tests against mocked CKEditor modules.
                resolve: {
                    alias: {
                        '@ckeditor/ckeditor5-core': resolve(__dirname, 'Tests/JavaScript/__mocks__/ckeditor5-core.js'),
                        '@ckeditor/ckeditor5-ui': resolve(__dirname, 'Tests/JavaScript/__mocks__/ckeditor5-ui.js'),
                        '@ckeditor/ckeditor5-utils': resolve(__dirname, 'Tests/JavaScript/__mocks__/ckeditor5-utils.js'),
                        ...sharedAliases,
                    },
                },
                test: {
                    name: 'unit',
                    environment: 'jsdom',
                    globals: true,
                    root: '.',
                    include: ['Tests/JavaScript/**/*.test.js'],
                    exclude: ['Tests/JavaScript/Integration/**', '**/node_modules/**'],
                },
            },
            {
                // The real CKEditor 5 build TYPO3 ships (EXT:rte_ckeditor) and the
                // real image plugin of netresearch/rte-ckeditor-image, both from
                // the Composer vendor directory. Without `composer install` the
                // tests in here are skipped and say so.
                resolve: {
                    alias: [
                        {
                            find: /^@ckeditor\/(ckeditor5-[a-z0-9-]+)$/,
                            replacement: resolve(
                                __dirname,
                                '.Build/vendor/typo3/cms-rte-ckeditor/Resources/Public/Contrib/@ckeditor/$1.js'
                            ),
                        },
                        ...Object.entries(sharedAliases).map(([find, replacement]) => ({ find, replacement })),
                    ],
                },
                test: {
                    name: 'ckeditor',
                    environment: 'jsdom',
                    globals: true,
                    root: '.',
                    include: ['Tests/JavaScript/Integration/**/*.test.js'],
                },
            },
        ],
    },
});
