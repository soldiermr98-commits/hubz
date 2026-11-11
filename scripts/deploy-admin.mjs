import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { resolve } from 'node:path';
const target = resolve('public_html/admin/assets');
mkdirSync(target, { recursive: true });
let index = readFileSync('public_html/admin/index.html', 'utf8');
for (const file of ['admin.js', 'admin.css']) {
  const source = resolve('dist/assets', file);
  copyFileSync(source, resolve(target, file));
  const hash = createHash('sha256').update(readFileSync(source)).digest('hex').slice(0, 12);
  const pattern = new RegExp(`(/admin/assets/${file.replace('.', '\\.')})(?:\\?[^"']*)?`, 'g');
  if (!pattern.test(index)) throw new Error(`Missing asset reference: ${file}`);
  index = index.replace(pattern, `$1?v=${hash}`);
}
writeFileSync('public_html/admin/index.html', index);
console.log('Admin assets and content-hashed URLs deployed.');
