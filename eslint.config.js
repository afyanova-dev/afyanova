import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';

// Rule 01-placement: Inertia is the server-state transport; no HTTP client
// or client-state library for domain state.
const transportPaths = [
    { name: 'axios', message: 'IC §16: Inertia is the server-state transport; no HTTP client for domain state.' },
    { name: 'swr', message: 'IC §16: use Inertia, not SWR, for authoritative domain state.' },
    { name: '@tanstack/react-query', message: 'IC §16: use Inertia, not React Query, for authoritative domain state.' },
    { name: 'redux', message: 'IC §16: no global client-state library without an ADR.' },
    { name: '@reduxjs/toolkit', message: 'IC §16: no global client-state library without an ADR.' },
    { name: 'zustand', message: 'IC §16: no global client-state library without an ADR.' },
    { name: 'jotai', message: 'IC §16: no global client-state library without an ADR.' },
    { name: 'mobx', message: 'IC §16: no global client-state library without an ADR.' },
];

// Rule 04: only lib/http/ may fetch (the getJson funnel); Rule 02: never
// branch on roles. Shared so the lib/http carve-out restates the same bans.
const baseSyntaxBans = [
    {
        selector: "Literal[value=/^(Visit|FlowEpisode|InpatientDomain|EMERGENCY_OVERRIDE|EmergencyFinancialOverride)$/]",
        message: 'IC §25: retired / non-canonical term.',
    },
    {
        selector: 'BinaryExpression[operator=/^(===|!==)$/] > MemberExpression[property.name="role"]',
        message: 'Rule 02: never branch on roles — ask can(permission) (Rule 01) instead.',
    },
    {
        selector: 'CallExpression > MemberExpression[property.name="includes"][object.property.name="roles"]',
        message: 'Rule 02: never branch on roles — ask can(permission) (Rule 01) instead.',
    },
];
const noDomainPatterns = [
    {
        group: ['@/features', '@/features/**', '@/pages', '@/pages/**'],
        message: 'Rule 01-placement: reusable layers must not import from features/ or pages/.',
    },
];
const noUiPatterns = [
    {
        group: ['@/components', '@/components/**', '@/layouts', '@/layouts/**', '@/features', '@/features/**', '@/pages', '@/pages/**'],
        message: 'Rule 01-placement: foundation layers (app/lib/hooks/types/icons) must not import UI layers.',
    },
];
const noPagesPatterns = [
    {
        group: ['@/pages', '@/pages/**'],
        message: 'Rule 01-placement: features/ must not import from pages/ (pages compose features, not vice versa).',
    },
];

export default tseslint.config(
    { ignores: ['resources/js/shared/types/generated.ts', 'public/build/**'] },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        languageOptions: { globals: { ...globals.browser } },
        plugins: { 'react-hooks': reactHooks },
        rules: {
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
            '@typescript-eslint/no-explicit-any': 'error',
            'no-restricted-imports': ['error', {
                paths: transportPaths,
            }],
            'no-restricted-syntax': ['error',
                ...baseSyntaxBans,
                {
                    selector: 'CallExpression[callee.name="fetch"]',
                    message: 'Rule 04: domain fetching goes through lib/http/json.ts (getJson) — no direct fetch().',
                },
            ],
        },
    },
    {
        files: ['resources/js/{components,layouts}/**/*.{ts,tsx}'],
        rules: {
            'no-restricted-imports': ['error', {
                paths: transportPaths,
                patterns: noDomainPatterns,
            }],
        },
    },
    {
        files: ['resources/js/{app,lib,hooks,types,icons,shared}/**/*.{ts,tsx}'],
        rules: {
            'no-restricted-imports': ['error', {
                paths: transportPaths,
                patterns: noUiPatterns,
            }],
        },
    },
    {
        files: ['resources/js/features/**/*.{ts,tsx}'],
        rules: {
            'no-restricted-imports': ['error', {
                paths: transportPaths,
                patterns: noPagesPatterns,
            }],
        },
    },
    {
        // Rule 04 carve-out: the getJson funnel itself may fetch; all other
        // syntax bans still apply here.
        files: ['resources/js/lib/http/**/*.{ts,tsx}'],
        rules: {
            'no-restricted-syntax': ['error', ...baseSyntaxBans],
        },
    },
);
