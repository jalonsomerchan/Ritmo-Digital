import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const mode = process.argv[2];
if (!['local', 'production'].includes(mode)) {
  console.error('Uso: node scripts/sync-environments.mjs <local|production>');
  process.exit(1);
}

const sourcePath = resolve(`.env.${mode}`);
const targetPath = resolve(`src/environments/environment.${mode}.generated.ts`);
const contents = (await readFile(sourcePath, 'utf8')).replace(/^\uFEFF/, '');
const values = {};

for (const line of contents.split(/\r?\n/)) {
  const match = line.match(/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/);
  if (!match) continue;
  let value = match[2];
  if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
    const quote = value[0];
    value = value.slice(1, -1);
    if (quote === '"') value = value.replace(/\\n/g, '\n').replace(/\\r/g, '\r').replace(/\\"/g, '"').replace(/\\\\/g, '\\');
  } else {
    value = value.replace(/\s+#.*$/, '').trim();
  }
  values[match[1]] = value;
}

const required = [
  'VITE_API_BASE',
  'VITE_FIREBASE_API_KEY',
  'VITE_FIREBASE_AUTH_DOMAIN',
  'VITE_FIREBASE_PROJECT_ID',
  'VITE_FIREBASE_APP_ID',
];
const missing = required.filter(key => !values[key]);
if (missing.length) {
  console.error(`Faltan variables en ${sourcePath}: ${missing.join(', ')}`);
  process.exit(1);
}

const firebase = {
  apiKey: values.VITE_FIREBASE_API_KEY,
  authDomain: values.VITE_FIREBASE_AUTH_DOMAIN,
  projectId: values.VITE_FIREBASE_PROJECT_ID,
  appId: values.VITE_FIREBASE_APP_ID,
  ...(values.VITE_FIREBASE_STORAGE_BUCKET ? { storageBucket: values.VITE_FIREBASE_STORAGE_BUCKET } : {}),
  ...(values.VITE_FIREBASE_MESSAGING_SENDER_ID ? { messagingSenderId: values.VITE_FIREBASE_MESSAGING_SENDER_ID } : {}),
  ...(values.VITE_FIREBASE_MEASUREMENT_ID ? { measurementId: values.VITE_FIREBASE_MEASUREMENT_ID } : {}),
};

const generated = `import type { FirebaseOptions } from 'firebase/app';

export const environment = {
  production: ${mode === 'production'},
  apiBaseUrl: ${JSON.stringify(values.VITE_API_BASE)},
  firebase: ${JSON.stringify(firebase, null, 2)} satisfies FirebaseOptions,
};
`;

await writeFile(targetPath, generated, { mode: 0o600 });
console.log(`Configuración ${mode} preparada.`);
