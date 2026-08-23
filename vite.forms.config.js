import { defineConfig } from 'vite'
import { resolve } from 'path'

/**
 * The FORMS bundle, built separately from `vite.config.js`.
 *
 * That one carries Vue for the table island; this one is plain Alpine glue for
 * Filament's own server-rendered form markup, and a form page has no reason to
 * download a renderer it never uses. Vite's library mode takes a single entry
 * per IIFE build, so the two are separate configs rather than two entries.
 */
export default defineConfig({
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    build: {
        outDir: 'dist',
        emptyOutDir: false,
        lib: {
            entry: resolve(__dirname, 'resources/js/forms/index.js'),
            name: 'VillamentForms',
            formats: ['iife'],
            fileName: () => 'villament-forms.js',
        },
    },
})
