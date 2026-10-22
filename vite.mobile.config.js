import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// Applications Android et iOS : npm run mobile:livreur ou npm run mobile:ecommercant
// (le mode désigne l'application ; écrans compilés dans mobile/<application>/www)
const APPS = ['livreur', 'ecommercant']

export default defineConfig(({ mode }) => {
    if (!APPS.includes(mode)) {
        throw new Error(`Application inconnue « ${mode} » : ${APPS.join(' ou ')}.`)
    }

    return {
        root: 'mobile',
        publicDir: false,
        plugins: [vue(), tailwindcss()],
        define: {
            'import.meta.env.VITE_NATIVE_APP': JSON.stringify(mode),
        },
        build: {
            outDir: `${mode}/www`,
            emptyOutDir: true,
        },
    }
})
