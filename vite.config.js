import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * When the Laravel app is reached via a public URL (e.g. Expose) but Vite runs
 * locally, set VITE_DEV_SERVER_URL to the tunneled origin for the Vite port
 * so public/hot and HMR use that host (second tunnel/share for :5173).
 */
function tunnelDevServerFromEnv(devOrigin) {
    const trimmed = devOrigin.replace(/\/$/, '');
    const url = new URL(trimmed);
    const isHttps = url.protocol === 'https:';
    const port = url.port ? parseInt(url.port, 10) : (isHttps ? 443 : 80);

    return {
        origin: trimmed,
        host: true,
        strictPort: true,
        hmr: {
            host: url.hostname,
            protocol: isHttps ? 'wss' : 'ws',
            clientPort: port,
        },
    };
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const viteDevServerUrl = env.VITE_DEV_SERVER_URL?.trim();
    const server = viteDevServerUrl ? tunnelDevServerFromEnv(viteDevServerUrl) : undefined;

    return {
        plugins: [
            tailwindcss(),
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/css/deploy-pipeline.css',
                    'resources/css/docs.css',
                    'resources/js/app.js',
                    'resources/js/docs.js',
                    'resources/js/dply-passkeys-lazy.js',
                    'resources/js/file-browser-editor-lazy.js',
                    'resources/js/roadmap-admin-dnd.js',
                ],
                refresh: false,
            }),
        ],
        ...(server ? { server } : {}),
    };
});
