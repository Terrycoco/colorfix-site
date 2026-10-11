export function sasSpaFallback() {
  const install = (server) => {
    server.middlewares.use((req, _res, next) => {
      const pathname = new URL(req.url || '/', 'http://localhost').pathname;
      if (
        ['GET', 'HEAD'].includes(req.method || 'GET') &&
        String(req.headers.accept || '').includes('text/html') &&
        /^\/sas(?:\/[^.]*)?\/?$/.test(pathname)
      ) {
        req.url = '/sas/index.html';
      }
      next();
    });
  };
  return {
    name: 'sas-spa-fallback',
    configureServer: install,
    configurePreviewServer: install,
  };
}
