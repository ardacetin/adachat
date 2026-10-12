import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import type { Plugin } from 'vite-plus';

/*
 * Writes public/build/ada-source.json: a hash of the sources the front end
 * is built from. `ada:doctor` computes the same hash (App\Support\
 * FrontendBuild) and reports a build that is missing or older than the
 * code, the usual cause of a page that behaves like the previous version
 * after an upgrade. Keep the file list in step with FrontendBuild.
 */

const DIRECTORIES = ['resources/js', 'resources/css'];
const FILES = ['package-lock.json', 'vite.config.ts'];
// Route helpers generated from the PHP routes at build time.
const GENERATED = [
    'resources/js/actions',
    'resources/js/routes',
    'resources/js/wayfinder',
];

function walk(base: string, directory: string, found: string[]): void {
    for (const entry of readdirSync(path.join(base, directory), {
        withFileTypes: true,
    })) {
        const relative = `${directory}/${entry.name}`;

        if (entry.isDirectory()) {
            if (!GENERATED.includes(relative)) {
                walk(base, relative, found);
            }
        } else if (entry.isFile()) {
            found.push(relative);
        }
    }
}

export function sourceHash(base: string): string {
    const files: string[] = [];

    for (const directory of DIRECTORIES) {
        if (existsSync(path.join(base, directory))) {
            walk(base, directory, files);
        }
    }

    files.push(...FILES.filter((file) => existsSync(path.join(base, file))));
    files.sort();

    const hash = createHash('sha256');

    for (const file of files) {
        const content = createHash('sha256')
            .update(readFileSync(path.join(base, file)))
            .digest('hex');
        hash.update(`${file}\n${content}\n`);
    }

    return hash.digest('hex');
}

export function sourceStamp(): Plugin {
    let root = process.cwd();
    let outDir = 'public/build';

    return {
        name: 'ada-source-stamp',
        apply: 'build',
        configResolved(config) {
            root = config.root;
            outDir = path.resolve(config.root, config.build.outDir);
        },
        writeBundle() {
            writeFileSync(
                path.join(outDir, 'ada-source.json'),
                `${JSON.stringify({ hash: sourceHash(root) })}\n`,
            );
        },
    };
}
