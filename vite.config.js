import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
    plugins: [vue()],
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        lib: {
            entry: resolve(__dirname, 'resources/js/index.js'),
            name: 'FilamentVueTable',
            formats: ['iife'],
            fileName: () => 'filament-vue-table.js',
        },
        rollupOptions: {
            output: {
                assetFileNames: 'filament-vue-table.[ext]',
            },
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        passWithNoTests: true,
    },
})
