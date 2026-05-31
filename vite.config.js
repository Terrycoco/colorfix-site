import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'
import fs from 'node:fs/promises'
import svgr from 'vite-plugin-svgr'

export default defineConfig({
  plugins: [
    adminDevSpaFallback(),
    nonBlockingPlayerCss(),
    react(),
    tailwindcss(),
    svgr(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
      '@styles': path.resolve(__dirname, './src/styles'),
      '@config': path.resolve(__dirname, './src/config'),
      '@helpers': path.resolve(__dirname, './src/helpers'),
      '@components': path.resolve(__dirname, './src/components'),
      '@context': path.resolve(__dirname, './src/context'),
      '@data': path.resolve(__dirname, './src/data'),
      '@layout': path.resolve(__dirname, './src/layout'),
      '@pages': path.resolve(__dirname, './src/pages'),
      '@hooks': path.resolve(__dirname, './src/hooks'),
      '@test': path.resolve(__dirname, './src/test'),
      '@lib': path.resolve(__dirname, './src/lib'),
    },
  },
  server: {
    port: 5173,
    strictPort: true,
    watch: {
      ignored: ['**/tmp_partial.jsx'],
    },
    proxy: {
      // Proxy ALL /api requests to your PHP host in dev
      '/api': {
        target: 'https://colorfix.terrymarr.com',
        changeOrigin: true,
        secure: false,              // ignore TLS quirks on shared hosts
        rewrite: (p) => p,          // keep /api/v2/... path as-is
      },
    },
  },
  build: {
    rollupOptions: {
      input: {
        main: path.resolve(__dirname, 'index.html'),
        admin: path.resolve(__dirname, 'admin/index.html'),
        player: path.resolve(__dirname, 'player/index.html'),
      },
    },
  },
})

function adminDevSpaFallback() {
  return {
    name: 'admin-dev-spa-fallback',
    configureServer(server) {
      server.middlewares.use(async (req, res, next) => {
        const method = req.method || 'GET';
        if (method !== 'GET' && method !== 'HEAD') {
          next();
          return;
        }

        const url = new URL(req.url || '/', 'http://localhost');
        const pathname = url.pathname;
        const acceptsHtml = String(req.headers.accept || '').includes('text/html');
        if (!acceptsHtml || (pathname !== '/admin' && !pathname.startsWith('/admin/'))) {
          next();
          return;
        }

        if (path.extname(pathname)) {
          next();
          return;
        }

        try {
          const htmlPath = path.resolve(process.cwd(), 'admin/index.html');
          const rawHtml = await fs.readFile(htmlPath, 'utf8');
          const html = await server.transformIndexHtml(pathname, rawHtml);
          res.statusCode = 200;
          res.setHeader('Content-Type', 'text/html');
          res.end(html);
        } catch (error) {
          next(error);
        }
      });
    },
  };
}

function nonBlockingPlayerCss() {
  return {
    name: 'non-blocking-player-css',
    enforce: 'post',
    transformIndexHtml: {
      order: 'post',
      handler(html, context) {
        const filename = context?.filename ? path.normalize(context.filename) : '';
        if (!filename.endsWith(path.normalize('player/index.html'))) return html;
        return html.replace(
          /<link rel="stylesheet" crossorigin href="([^"]+)">/g,
          (_, href) => (
            `<link rel="preload" as="style" crossorigin href="${href}" onload="this.onload=null;this.rel='stylesheet'">`
            + `<noscript><link rel="stylesheet" crossorigin href="${href}"></noscript>`
          ),
        );
      },
    },
  };
}
