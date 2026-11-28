import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { isolateLoopback } from '../e2e/isolated-loopback.mjs';

test('loopback proxy forwards HTTP bodies with an isolated real source address', { skip: process.platform !== 'linux' }, async () => {
  const upstream = http.createServer((request, response) => {
    const parts = [];
    request.on('data', chunk => parts.push(chunk));
    request.on('end', () => {
      response.writeHead(201, { 'Content-Type': 'application/json', 'X-Fixture': 'forwarded' });
      response.end(JSON.stringify({ ip: request.socket.remoteAddress, method: request.method, path: request.url, body: Buffer.concat(parts).toString() }));
    });
  });
  await new Promise((resolve, reject) => upstream.once('error', reject).listen(0, '127.0.0.1', resolve));
  let proxy;
  try {
    proxy = await isolateLoopback(`http://127.0.0.1:${upstream.address().port}`);
    assert.equal(proxy.isolated, true);
    assert.notEqual(proxy.source, '127.0.0.1');
    const response = await fetch(new URL('/test?one=2', proxy.baseURL), { method: 'POST', body: 'multipart-shaped body' });
    assert.equal(response.status, 201);
    assert.equal(response.headers.get('x-fixture'), 'forwarded');
    assert.deepEqual(await response.json(), { ip: proxy.source, method: 'POST', path: '/test?one=2', body: 'multipart-shaped body' });
  } finally {
    if (proxy) await proxy.close();
    await new Promise((resolve, reject) => {
      upstream.close(error => error ? reject(error) : resolve());
      upstream.closeAllConnections();
    });
  }
});

test('loopback isolation leaves remote and HTTPS origins untouched', async () => {
  for (const origin of ['https://127.0.0.1:8080', 'http://example.test:8080']) {
    const result = await isolateLoopback(origin);
    assert.equal(result.baseURL, origin);
    assert.equal(result.isolated, false);
    await result.close();
  }
});
