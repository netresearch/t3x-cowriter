import { defineConfig } from 'vitest/config';
import { resolve } from 'path';

export default defineConfig({
    resolve: {
        alias: {
            '@typo3/ckeditor5-bundle.js': resolve(
                __dirname,
                'Tests/JavaScript/__mocks__/ckeditor5-bundle.js'
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
            reporter: ['text', 'json', 'html'],
            include: ['Resources/Public/JavaScript/**/*.js'],
        },
    },
});
