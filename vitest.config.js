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
            '@typo3/backend/modal.js': resolve(
                __dirname,
                'Tests/JavaScript/__mocks__/typo3-backend-modal.js'
            ),
            '@netresearch/t3_cowriter/AIService': resolve(
                __dirname,
                'Resources/Public/JavaScript/Ckeditor/AIService.js'
            ),
            '@netresearch/t3_cowriter/CowriterDialog': resolve(
                __dirname,
                'Resources/Public/JavaScript/Ckeditor/CowriterDialog.js'
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
