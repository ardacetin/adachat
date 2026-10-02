import { expect, test } from './fixtures';
import type { Page } from '@playwright/test';

/*
 * Institutional assistants end to end: a super administrator creates one
 * for the default group, a user opens it from the gallery, starts with a
 * suggested prompt, and the answer shows that the provider received the
 * assistant's instructions (tests/e2e/mock-provider.mjs).
 */

const run = Date.now().toString(36);
const name = `Tez ${run}`;

async function signInAs(page: Page, user: string): Promise<void> {
    await page.goto('/login');
    await page
        .getByRole('button', {
            name: new RegExp(`^Sign in as ${user} [a-z_]+$`),
        })
        .click();
    await page.waitForURL('/');
}

test('an assistant is created and used with a starter prompt', async ({
    page,
}) => {
    await signInAs(page, 'Sample Super Admin');

    await page.goto('/admin/assistants/create');
    await page.getByLabel('Slug').fill(`tez-${run}`);
    await page.getByLabel('Name (EN)').fill(name);
    await page.getByLabel('Name (TR)').fill(name);
    await page.getByLabel('Short description (EN)').fill('Thesis help');
    await page
        .getByLabel('Instructions')
        .fill(`Talimat: ${run}\nHelp students plan their thesis.`);
    await page
        .getByLabel('Starter prompt 1')
        .fill('Bölümleri nasıl planlarım?');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(
        page.getByRole('cell', { name: new RegExp(name) }),
    ).toBeVisible();

    // Switch to a regular user.
    await page.context().clearCookies();
    await signInAs(page, 'Sample User');

    await page.getByRole('link', { name: 'Assistants' }).click();
    await page.getByRole('link', { name: new RegExp(name) }).click();
    await expect(page.getByTestId('assistant-intro')).toContainText(name);
    await expect(page.getByRole('combobox', { name: 'Model' })).toBeDisabled();

    await page
        .getByRole('button', { name: 'Bölümleri nasıl planlarım?' })
        .click();
    await page.waitForURL(/\/c\/[0-9a-z-]+$/);
    await expect(page.getByText(`Asistan ${run} burada.`)).toBeVisible();
    await expect(page.getByTestId('conversation-assistant')).toContainText(
        name,
    );

    // Follow-up messages keep the assistant.
    await page.getByLabel('Message').fill('Devam edelim');
    await page.keyboard.press('Enter');
    await expect(page.getByText(`Asistan ${run} burada.`)).toHaveCount(2);
});
