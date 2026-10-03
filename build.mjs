#!/usr/bin/env node
/**
 * Packages the plugin as the zip merchants upload in WordPress:
 * dist/plandalf-memberpress.zip (and a versioned copy), holding one
 * plandalf-memberpress/ folder with only the runtime files. Tests, this
 * script and the README stay out.
 *
 * `npm run build` from the repository root. Fails if the version in the
 * plugin header, the PLANDALF_MEPR_VERSION constant and readme.txt disagree.
 */
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const SLUG = 'plandalf-memberpress';
const RUNTIME = ['plandalf-memberpress.php', 'uninstall.php', 'readme.txt', 'includes', 'gateways', 'assets'];

const here = dirname(fileURLToPath(import.meta.url));
const dist = join(here, 'dist');

const main = readFileSync(join(here, 'plandalf-memberpress.php'), 'utf8');
const readme = readFileSync(join(here, 'readme.txt'), 'utf8');
const versions = {
  header: main.match(/^\s*\*\s*Version:\s*(\S+)/m)?.[1],
  constant: main.match(/define\('PLANDALF_MEPR_VERSION', '([^']+)'\)/)?.[1],
  readme: readme.match(/^Stable tag:\s*(\S+)/m)?.[1],
  package: JSON.parse(readFileSync(join(here, 'package.json'), 'utf8')).version,
};
const version = versions.header;
if (!version || Object.values(versions).some((value) => value !== version)) {
  console.error(`[memberpress] version mismatch: ${JSON.stringify(versions)}`);
  process.exit(1);
}

rmSync(dist, { recursive: true, force: true });
const stage = join(dist, 'stage', SLUG);
mkdirSync(stage, { recursive: true });
for (const entry of RUNTIME) {
  if (!existsSync(join(here, entry))) {
    console.error(`[memberpress] missing ${entry}`);
    process.exit(1);
  }
  cpSync(join(here, entry), join(stage, entry), { recursive: true, filter: (source) => !source.endsWith('.DS_Store') });
}

const zip = join(dist, `${SLUG}.zip`);
execFileSync('zip', ['-rqX', zip, SLUG], { cwd: join(dist, 'stage') });
cpSync(zip, join(dist, `${SLUG}-${version}.zip`));
rmSync(join(dist, 'stage'), { recursive: true, force: true });
const archives = [`${SLUG}.zip`, `${SLUG}-${version}.zip`];
writeFileSync(join(dist, 'SHA256SUMS'), archives.map((name) =>
  `${createHash('sha256').update(readFileSync(join(dist, name))).digest('hex')}  ${name}`
).join('\n') + '\n');

console.log(`[memberpress] built dist/${SLUG}.zip (v${version})`);
