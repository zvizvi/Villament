import { defineConfig } from 'vite'
import { resolve } from 'path'

// `ClientRepeater`'s Alpine component: an ES module that `x-load` imports
// on demand, like Filament's own form components. Built next to the island
// bundle, without emptying `dist/`.
export default defineConfig({
    build: {
        outDir: 'dist/components',
        emptyOutDir: false,
        target: 'es2022',
        minify: true,
        lib: {
            entry: resolve(__dirname, 'resources/js/forms/client-repeater/index.js'),
            formats: ['es'],
            fileName: () => 'client-repeater.js',
        },
    },
})
