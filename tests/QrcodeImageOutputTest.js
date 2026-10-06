import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../Qrcodeextend/resources/assets/qrcodeextend.js', import.meta.url), 'utf8');

assert.match(source, /class="qrcodeextend-image"/);
assert.match(source, /canvas\.toDataURL\('image\/png'\)/);
assert.match(source, /-webkit-touch-callout:default/);
assert.match(source, /renderId !== qrRenderId/);
assert.match(source, /class="qrcodeextend-canvas"[^>]+style="display:none"/);

console.log('QR image output checks passed');
