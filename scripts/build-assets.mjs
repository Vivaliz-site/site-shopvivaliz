import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const root = process.cwd();
const targets = [
  ['js', 'public/dist/js', ['.js']],
  ['css', 'public/dist/css', ['.css']],
  ['public/assets/liz-assistant', 'public/dist/assets/liz-assistant', ['.js', '.css']],
];
const manifest = {};

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function minify(text, ext) {
  if (ext === '.css') {
    return text
      .replace(/\/\*[\s\S]*?\*\//g, '')
      .replace(/\s+/g, ' ')
      .replace(/\s*([{}:;,>+~])\s*/g, '$1')
      .trim();
  }
  return text
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '')
    .replace(/\s+/g, ' ')
    .trim();
}

function versionedName(file, content) {
  const ext = path.extname(file);
  const base = path.basename(file, ext);
  const hash = crypto.createHash('sha256').update(content).digest('hex').slice(0, 10);
  return `${base}.${hash}.min${ext}`;
}

function walk(dir) {
  if (!fs.existsSync(dir)) return [];
  const out = [];
  for (const name of fs.readdirSync(dir)) {
    const file = path.join(dir, name);
    const stat = fs.statSync(file);
    if (stat.isDirectory()) {
      out.push(...walk(file));
    } else if (stat.isFile()) {
      out.push(file);
    }
  }
  return out;
}

for (const [srcDir, dstDir, extensions] of targets) {
  const absSrc = path.join(root, srcDir);
  const absDst = path.join(root, dstDir);
  if (!fs.existsSync(absSrc)) continue;
  ensureDir(absDst);

  for (const src of walk(absSrc)) {
    const ext = path.extname(src).toLowerCase();
    if (!extensions.includes(ext)) continue;
    if (/\.min\.(js|css)$/i.test(src)) continue;

    const relative = path.relative(absSrc, src);
    const originalPublicPath = '/' + path.posix.join(srcDir.replaceAll(path.sep, '/'), relative.replaceAll(path.sep, '/'));
    const text = fs.readFileSync(src, 'utf8');
    const minified = minify(text, ext);
    const outName = versionedName(path.basename(src), minified);
    const outDir = path.join(absDst, path.dirname(relative));
    ensureDir(outDir);
    const outFile = path.join(outDir, outName);
    fs.writeFileSync(outFile, minified);

    const publicOutput = '/' + path.posix.join(dstDir.replaceAll(path.sep, '/'), path.dirname(relative).replaceAll(path.sep, '/'), outName).replace(/\/\.\//g, '/');
    manifest[originalPublicPath] = {
      file: publicOutput.replace('/./', '/'),
      bytes: Buffer.byteLength(minified),
      source_bytes: Buffer.byteLength(text),
    };
  }
}

const manifestPath = path.join(root, 'public/dist/asset-manifest.json');
ensureDir(path.dirname(manifestPath));
fs.writeFileSync(manifestPath, JSON.stringify({
  generated_at: new Date().toISOString(),
  assets: manifest,
}, null, 2));

console.log(`Built ${Object.keys(manifest).length} assets into public/dist`);
