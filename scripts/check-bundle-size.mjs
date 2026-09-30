/**
 * Bundle size budget (roadmap M10). Reads the Vite manifest of the last
 * build and fails when the JavaScript a page needs grows past its budget,
 * measured gzipped as it is sent over the wire:
 *
 *   - the entry: resources/js/app.tsx and everything it imports statically
 *     (loaded on every page);
 *   - each page: its own chunk plus the static imports the entry does not
 *     already bring.
 *
 * Lazily loaded code (code highlighting and its language grammars,
 * translation bundles) is not counted: it is fetched only when needed.
 *
 * Usage: npm run build && npm run bundle:check
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { gzipSync } from 'node:zlib';

const KB = 1024;

/**
 * Budgets in KiB, gzipped: the sizes at the time they were set plus about
 * 15 % headroom. Raise them deliberately, in the PR that needs it.
 */
const BUDGETS = {
    entry: 230,
    page: 40,
    // The chat renders Markdown (react-markdown + remark-gfm).
    pages: { 'chat/index.tsx': 75, 'chat/show.tsx': 75 },
};

const root = new URL('..', import.meta.url).pathname;
const buildDir = join(root, 'public/build');
const manifest = JSON.parse(
    readFileSync(join(buildDir, 'manifest.json'), 'utf8'),
);

const sizes = new Map();

function gzipSize(file) {
    if (!sizes.has(file)) {
        sizes.set(file, gzipSync(readFileSync(join(buildDir, file))).length);
    }

    return sizes.get(file);
}

/** The chunk and everything it imports statically, as manifest keys. */
function closure(key, seen = new Set()) {
    if (seen.has(key) || !manifest[key]) {
        return seen;
    }

    seen.add(key);

    for (const imported of manifest[key].imports ?? []) {
        closure(imported, seen);
    }

    return seen;
}

const total = (keys) =>
    [...keys].reduce((sum, key) => sum + gzipSize(manifest[key].file), 0);

const entryKey = 'resources/js/app.tsx';

if (!manifest[entryKey]) {
    console.error(
        `No ${entryKey} in the Vite manifest: run npm run build first.`,
    );
    process.exit(1);
}

const entry = closure(entryKey);
const failures = [];
const rows = [];

const entrySize = total(entry);
rows.push(['entry (app.tsx + static imports)', entrySize, BUDGETS.entry]);

for (const [key, chunk] of Object.entries(manifest)) {
    if (!key.startsWith('resources/js/pages/') || !chunk.file.endsWith('.js')) {
        continue;
    }

    const page = key.replace('resources/js/pages/', '');
    const own = [...closure(key)].filter((k) => !entry.has(k));
    rows.push([
        `page: ${page}`,
        total(own),
        BUDGETS.pages[page] ?? BUDGETS.page,
    ]);
}

rows.sort((a, b) => b[1] - a[1]);

// The largest bundles and every one over budget.
rows.forEach(([name, size, budget], index) => {
    const over = size > budget * KB;
    const line = `${over ? 'OVER' : 'ok  '}  ${(size / KB).toFixed(1).padStart(7)} KiB / ${budget} KiB  ${name}`;

    if (over) {
        failures.push(line);
    }

    if (over || index < 8) {
        console.log(line);
    }
});

if (failures.length > 0) {
    console.error(
        `\n${failures.length} bundle(s) over budget. Split the code (lazy imports) or raise the budget deliberately in scripts/check-bundle-size.mjs.`,
    );
    process.exit(1);
}

console.log(`\nBundle sizes within budget (${rows.length} bundles checked).`);
