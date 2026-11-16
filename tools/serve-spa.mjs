// Local replacement for the Docker nginx frontend containers.
// Serves web/<module>/dist and proxies /api/* to the Laravel backend.
// Usage: node tools/serve-spa.mjs <crm|member> <port> [backendUrl]
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const [, , moduleName = 'crm', portArg = '18081', backendArg = 'http://127.0.0.1:8000'] = process.argv;
const here = path.dirname(fileURLToPath(import.meta.url));
const distDir = path.resolve(here, '..', 'web', moduleName, 'dist');
const backend = new URL(backendArg);
const port = Number(portArg);

if (!fs.existsSync(path.join(distDir, 'index.html'))) {
  console.error(`Missing ${distDir}\\index.html - run "npm run build:${moduleName}" first`);
  process.exit(1);
}

const types = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.mjs': 'text/javascript',
  '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png',
  '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.ico': 'image/x-icon',
  '.woff': 'font/woff', '.woff2': 'font/woff2', '.map': 'application/json',
};

function proxy(req, res) {
  const upstream = http.request({
    hostname: backend.hostname, port: backend.port || 80, path: req.url, method: req.method,
    headers: {
      ...req.headers,
      host: req.headers.host,
      'x-forwarded-for': req.socket.remoteAddress,
      'x-real-ip': req.socket.remoteAddress,
    },
  }, (up) => { res.writeHead(up.statusCode, up.headers); up.pipe(res); });
  upstream.on('error', () => { res.writeHead(502); res.end('Backend not reachable at ' + backend.href); });
  req.pipe(upstream);
}

function serveFile(res, file) {
  res.writeHead(200, { 'content-type': types[path.extname(file).toLowerCase()] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
}

http.createServer((req, res) => {
  const urlPath = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (urlPath.startsWith('/api/')) return proxy(req, res);
  const file = path.resolve(distDir, '.' + urlPath);
  if (file.startsWith(distDir) && fs.existsSync(file) && fs.statSync(file).isFile()) return serveFile(res, file);
  serveFile(res, path.join(distDir, 'index.html')); // SPA fallback (try_files ... /index.html)
}).listen(port, '127.0.0.1', () => {
  console.log(`${moduleName} -> http://127.0.0.1:${port}  (api -> ${backend.href})`);
});
