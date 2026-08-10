import fs from 'node:fs';
import path from 'node:path';
const root = process.cwd();
const targets = [
  ['public/js', 'public/dist/js'],
  ['public/css', 'public/dist/css'],
];
for (const [srcDir, dstDir] of targets) {
  const absSrc = path.join(root, srcDir);
  const absDst = path.join(root, dstDir);
  if (!fs.existsSync(absSrc)) continue;
  fs.mkdirSync(absDst, { recursive: true });
  for (const file of fs.readdirSync(absSrc)) {
    const src = path.join(absSrc, file);
    if (!fs.statSync(src).isFile()) continue;
    const out = path.join(absDst, file.replace(/\.(js|css)$/i, '.min.$1'));
    const text = fs.readFileSync(src, 'utf8')
      .replace(/\/\*[\s\S]*?\*\//g, '')
      .replace(/\/\/.*$/gm, '')
      .replace(/\s+/g, ' ')
      .trim();
    fs.writeFileSync(out, text);
  }
}
