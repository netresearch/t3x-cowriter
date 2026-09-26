import { defineConfig } from 'vitest/config';
import { resolve } from 'path';

export default defineConfig({
    resolve: {
        alias: {
            '@ckeditor/ckeditor5-core': resolve(
                __dirname,
                'Tests/JavaScript/__mocks__/ckeditor5-core.js'
            ),
            '@ckeditor/ckeditor5-ui': resolve(
                __dirname,
                'Tests/JavaScript/__mocks__/ckeditor5-ui.js'
            ),
            '@ckeditor/ckeditor5-utils': resolve(
                __dirname,
                'Tests/JavaScript/__mocks__/ckeditor5-utils.js'
            ),
            '@typo3/backend/modal.js': resolve(
                __dirname,
                'Tests/JavaScript/__mocks__/typo3-backend-modal.js'
            ),
            '@typo3/backend/notification.js': resolve(
                __dirname,
                'Tests/JavaScript/__mocks__/typo3-backend-notification.js'
            ),
            '@typo3/backend/form-engine.js': resolve(
                __dirname,
                'Tests/JavaScript/__mocks__/typo3-backend-form-engine.js'
            ),
            '@typo3/backend/form-engine-validation.js': resolve(
                __dirname,
                'Tests/JavaScript/__mocks__/typo3-backend-form-engine-validation.js'
            ),
            '@typo3/core/ajax/ajax-request.js': resolve(
                __dirname,
                'Tests/JavaScript/__mocks__/typo3-core-ajax-request.js'
            ),
            '@typo3/core/document-service.js': resolve(
                __dirname,
                'Tests/JavaScript/__mocks__/typo3-core-document-service.js'
            ),
            '@netresearch/t3_cowriter/AIService': resolve(
                __dirname,
                'Resources/Public/JavaScript/Ckeditor/AIService.js'
            ),
            '@netresearch/t3_cowriter/CowriterDialog': resolve(
                __dirname,
                'Resources/Public/JavaScript/Ckeditor/CowriterDialog.js'
            ),
            '@netresearch/t3_cowriter/Labels': resolve(
                __dirname,
                'Resources/Public/JavaScript/Ckeditor/Labels.js'
            ),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        root: '.',
        include: ['Tests/JavaScript/**/*.test.js'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'json', 'html', 'lcov'],
            reportsDirectory: 'coverage',
            include: ['Resources/Public/JavaScript/**/*.js'],
        },
    },
});
