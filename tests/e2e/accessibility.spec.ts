import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from './fixtures';

/*
 * Automated accessibility checks (axe-core, WCAG 2.1 A and AA) on the main
 * screens, in light and dark mode. Automated checks find only part of the
 * problems; docs/frontend-architecture.md §10 lists the manual checks.
 */

const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function signInAs(page: Page, name: string): Promise<void> {
    await page.goto('/login');
    await page
        .getByRole('button', {
            name: new RegExp(`^Sign in as ${name} [a-z_]+$`),
        })
        .click();
    await page.waitForURL('/');
}

async function expectNoViolations(page: Page, name: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(WCAG).analyze();

    const serious = results.violations
        .filter((violation) =>
            ['serious', 'critical'].includes(violation.impact ?? ''),
        )
        .map(
            (violation) =>
                `${name}: ${violation.id} (${violation.impact}) — ${violation.help}: ${violation.nodes
                    .slice(0, 3)
                    .map((node) => node.target.join(' '))
                    .join(', ')}`,
        );

    expect(serious).toEqual([]);
}

test('the landing page is accessible', async ({ page }) => {
    await page.goto('/');
    await expectNoViolations(page, 'landing');
});

test('the sign-in page is accessible', async ({ page }) => {
    await page.goto('/login');
    await expectNoViolations(page, 'login');
});

for (const scheme of ['light', 'dark'] as const) {
    test(`user screens are accessible (${scheme})`, async ({ page }) => {
        await page.emulateMedia({ colorScheme: scheme });
        await signInAs(page, 'Sample User');

        await expectNoViolations(page, 'chat');

        await page.goto('/usage');
        await expectNoViolations(page, 'usage');

        await page.goto('/search?q=merhaba');
        await expectNoViolations(page, 'search');

        await page.goto('/assistants');
        await expectNoViolations(page, 'assistants');

        await page.goto('/settings/language');
        await expectNoViolations(page, 'settings');
    });
}

test('administration screens are accessible', async ({ page }) => {
    await signInAs(page, 'Sample Super Admin');

    for (const path of [
        '/admin',
        '/admin/reports',
        '/admin/users',
        '/admin/groups',
        '/admin/audit-log',
        '/admin/privacy',
        '/admin/institution',
        '/admin/texts',
        '/admin/assistants',
        '/admin/assistants/create',
        '/admin/models/create',
    ]) {
        await page.goto(path);
        await expectNoViolations(page, path);
    }
});
