/**
 * Fails when user-facing text is hard-coded in React components instead of
 * going through the i18n layer (t('namespace.key')).
 *
 * Checks .tsx files under resources/js (shadcn primitives in components/ui
 * are excluded) for:
 *   - JSX text containing letters
 *   - string literals containing letters in user-facing JSX attributes
 *
 * A line can opt out with a trailing comment: // i18n-ignore
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import ts from 'typescript';

const root = new URL('..', import.meta.url).pathname;
const sourceDir = join(root, 'resources/js');
const excluded = ['components/ui/', 'actions/', 'routes/', 'wayfinder/'];
const userFacingAttributes = new Set([
    'title',
    'placeholder',
    'alt',
    'aria-label',
    'aria-description',
    'label',
    'description',
]);
const letters = /\p{L}/u;

function* walk(dir) {
    for (const entry of readdirSync(dir)) {
        const path = join(dir, entry);

        if (statSync(path).isDirectory()) {
            yield* walk(path);
        } else if (path.endsWith('.tsx')) {
            yield path;
        }
    }
}

const problems = [];

for (const file of walk(sourceDir)) {
    const rel = relative(sourceDir, file);

    if (excluded.some((prefix) => rel.startsWith(prefix))) {
        continue;
    }

    const text = readFileSync(file, 'utf8');
    const lines = text.split('\n');
    const source = ts.createSourceFile(
        file,
        text,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TSX,
    );

    const report = (node, value) => {
        const { line } = source.getLineAndCharacterOfPosition(node.getStart());

        if (!lines[line].includes('i18n-ignore')) {
            problems.push(
                `${relative(root, file)}:${line + 1}  "${value.trim()}"`,
            );
        }
    };

    const visit = (node) => {
        if (ts.isJsxText(node) && letters.test(node.text)) {
            report(node, node.text);
        }

        if (
            ts.isJsxAttribute(node) &&
            userFacingAttributes.has(node.name.getText(source)) &&
            node.initializer &&
            ts.isStringLiteral(node.initializer) &&
            letters.test(node.initializer.text)
        ) {
            report(node, node.initializer.text);
        }

        ts.forEachChild(node, visit);
    };

    visit(source);
}

if (problems.length > 0) {
    console.error('Hard-coded UI text found (use t() from react-i18next):\n');
    console.error(problems.map((p) => `  ${p}`).join('\n'));
    process.exit(1);
}

console.log('i18n check passed: no hard-coded UI text.');
