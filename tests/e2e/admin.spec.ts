import { execFileSync } from 'node:child_process';
import { expect, test } from './fixtures';
import type { Page } from '@playwright/test';

/*
 * Administration end to end: a super administrator sets up a budget policy,
 * a group with its models and a new model alias, then manages a user's
 * group and budget; that user (Sample Admin, so the chat specs' user is not
 * touched) sees the result and is put back into the Default group afterwards.
 */

const run = Date.now().toString(36);
const policy = `Research ${run}`;
const group = `Researchers ${run}`;
const alias = `Deep ${run}`;

async function signInAs(page: Page, name: string): Promise<void> {
    await page.goto('/');
    await page
        .getByRole('button', {
            name: new RegExp(`^Sign in as ${name} [a-z_]+$`),
        })
        .click();
    await expect(page.getByTestId('dev-login')).toBeHidden();
}

test.afterAll(() => {
    execFileSync(
        'php',
        [
            'artisan',
            'tinker',
            '--execute',
            "App\\Models\\User::where('email', 'admin@example.edu')->update(['group_id' => App\\Models\\Group::default()->id, 'monthly_limit_override_usd' => null]);",
        ],
        { stdio: 'ignore' },
    );
});

async function logOut(page: Page, name: string): Promise<void> {
    await page.getByRole('button', { name: new RegExp(name) }).click();
    await page.getByTestId('logout-button').click();
    await expect(page.getByTestId('dev-login')).toBeVisible();
}

async function openUser(page: Page, name: string): Promise<void> {
    await page.goto('/admin/users');
    await page.getByRole('searchbox').fill(name);
    await page.keyboard.press('Enter');
    await page.getByRole('link', { name, exact: true }).click();
    await expect(page.getByRole('heading', { name })).toBeVisible();
}

test('a super administrator manages models, groups and a user budget', async ({
    page,
}) => {
    await signInAs(page, 'Sample Super Admin');

    // Budget policy.
    await page.goto('/admin/budget-policies/create');
    await page.getByLabel('Name').fill(policy);
    await page.getByLabel('Monthly limit (USD)').fill('50');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('cell', { name: policy })).toBeVisible();

    // Group with that policy and the existing "Smart" alias.
    await page.goto('/admin/groups/create');
    await page.getByLabel('Name', { exact: true }).fill(group);
    await page.getByLabel('Budget policy').click();
    await page.getByRole('option', { name: new RegExp(policy) }).click();
    await page.getByLabel('Smart').check();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('cell', { name: group })).toBeVisible();

    // A new alias on the mock model, available to the new group only.
    await page.goto('/admin/aliases/create');
    await page.getByLabel('Slug').fill(`deep-${run}`);
    await page.getByLabel('Name (EN)').fill(alias);
    await page.getByLabel('Name (TR)').fill(alias);
    await page.getByLabel('Order').fill('50');
    await page.getByLabel('Default').uncheck();
    await page.getByLabel(group).check();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText(`deep-${run}`)).toBeVisible();

    // The user: new group, individual budget and a manual charge.
    await openUser(page, 'Sample Admin');
    await page.getByLabel('Group').click();
    await page.getByRole('option', { name: group }).click();
    await page.getByRole('button', { name: 'Change group' }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();

    await page.getByLabel('Monthly limit (USD)').fill('75');
    await page.getByRole('button', { name: 'Save budget' }).click();
    await expect(page.getByText('Settings saved.').first()).toBeVisible();

    await page.getByLabel('Amount (USD)').fill('1');
    await page.getByLabel('Reason').fill('Workshop materials');
    await page.getByRole('button', { name: 'Record adjustment' }).click();
    await expect(page.getByText('Workshop materials')).toBeVisible();

    // Everything is in the audit log.
    await page.goto('/admin/audit-log');
    const entries = page.getByRole('listitem');
    await expect(
        entries.filter({ hasText: 'budget.adjusted' }).first(),
    ).toContainText('Workshop materials');
    await expect(
        entries.filter({ hasText: 'user.group_changed' }).first(),
    ).toBeVisible();

    // Dashboard and reports show this month's figures.
    await page.goto('/admin');
    await expect(page.getByTestId('kpi-spend')).toContainText('$');
    await page.goto('/admin/reports?by=user');
    await expect(page.getByTestId('report-spend')).toBeVisible();
    await expect(page.getByText('Token counter deviation')).toBeVisible();

    // The user sees the new model and budget.
    await logOut(page, 'Sample Super Admin');
    await signInAs(page, 'Sample Admin');
    await expect(page.getByTestId('budget-indicator')).toContainText(
        /of \$75\.00 left/,
    );
    await page.getByRole('combobox', { name: 'Model' }).click();
    await expect(
        page.getByRole('option', { name: new RegExp(alias) }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
});

test('an administrator sees users but not system settings', async ({
    page,
}) => {
    await signInAs(page, 'Sample Admin');

    await page.goto('/admin');
    await expect(
        page.getByRole('link', { name: 'Users' }).first(),
    ).toBeVisible();
    await expect(page.getByRole('link', { name: 'Providers' })).toHaveCount(0);

    const response = await page.goto('/admin/providers');
    expect(response?.status()).toBe(403);
});
