import js from '@eslint/js';
import globals from 'globals';

export default [
    js.configs.recommended,
    {
        files: ['Resources/Public/JavaScript/**/*.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                TYPO3: 'readonly',
            },
        },
    },
    {
        ignores: [
            'node_modules/',
            'vendor/',
            '.Build/',
            'Build/',
            'Tests/',
            'coverage/',
            'vitest.config.js',
        ],
    },
];
