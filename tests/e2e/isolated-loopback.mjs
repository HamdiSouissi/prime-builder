import { randomBytes } from 'node:crypto';
import http from 'node:http';

// Forward a disposable local CMS through a fresh Linux loopback source address.
// Unlike clearing counters or spoofing forwarding headers, this exercises the
// real per-IP limit while avoiding interference from preceding browser tests.
// On other platforms/remote origins, retain the original URL and caller must
// ensure the fixture's form quota is available.
export async function isolateLoopback(baseURL) {
  const upstream = new URL(baseURL);
  if (process.platform !== 'linux' || upstream.protocol !== 'http:' || upstream.hostname !== '127.0.0.1') {
    return { baseURL, isolated: false, close: async () => {} };
  }

  const [a, b, c] = randomBytes(3);
  const source = `127.${1 + a % 254}.${b}.${c}`;
  const proxy = http.createServer((request, response) => {
    const forwarded = http.request({
      host: upstream.hostname,
      port: upstream.port || 80,
      localAddress: source,
      method: request.method,
      path: request.url,
      headers: request.headers,
      agent: false,
    }, result => {
      response.writeHead(result.statusCode || 502, result.headers);
      result.on('error', () => response.destroy());
      result.pipe(response);
    });
    forwarded.on('error', error => {
      if (!response.headersSent) response.writeHead(502, { 'Content-Type': 'text/plain' });
      response.end(`Isolated test proxy: ${error.message}`);
    });
    request.on('aborted', () => forwarded.destroy());
    request.on('error', () => forwarded.destroy());
    response.on('close', () => { if (!response.writableFinished) forwarded.destroy(); });
    request.pipe(forwarded);
  });
  await new Promise((resolve, reject) => proxy.once('error', reject).listen(0, '127.0.0.1', resolve));
  const address = proxy.address();
  if (!address || typeof address === 'string') {
    proxy.close();
    throw new Error('Cannot allocate an isolated test proxy port');
  }
  const local = new URL(upstream);
  local.port = String(address.port);
  return {
    baseURL: local.href,
    source,
    isolated: true,
    close: () => new Promise((resolve, reject) => {
      proxy.close(error => error ? reject(error) : resolve());
      proxy.closeAllConnections();
    }),
  };
}
