import js from '@eslint/js'
import vue from 'eslint-plugin-vue'
import tseslint from 'typescript-eslint'

export default [
    {
        ignores: [
            'public/**',
            'test-results/**',
            'playwright-report/**',
            'vendor/**',
        ],
    },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    ...vue.configs['flat/essential'],
    {
        files: ['**/*.vue'],
        languageOptions: { parserOptions: { parser: tseslint.parser } },
    },
]
