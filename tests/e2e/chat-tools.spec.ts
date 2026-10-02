import { expect, test } from './fixtures';
import type { Page } from '@playwright/test';

/*
 * Pinning, searching and exporting a conversation.
 */

const run = Date.now().toString(36);

async function signIn(page: Page): Promise<void> {
    await page.goto('/login');
    await page
        .getByRole('button', { name: /^Sign in as Sample User [a-z_]+$/ })
        .click();
    await page.waitForURL('/');
}

test('a conversation is pinned, found by search and exported', async ({
    page,
}) => {
    await signIn(page);

    await page.getByLabel('Message').fill(`Pelikan ${run} hakkında yaz`);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/c\/[0-9a-z-]+$/);
    await expect(
        page
            .getByText('Yerel modelden')
            .or(page.getByText('sahte sağlayıcıdan')),
    ).toBeVisible();

    // Pin from the conversation toolbar: it moves to the "Pinned" section.
    await page.getByRole('button', { name: 'Pin' }).click();
    await expect(page.getByTestId('pinned-conversations')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Unpin' })).toBeVisible();

    // The Markdown export downloads a file with the message.
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('link', { name: 'Download as Markdown' }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/^ada-.*\.md$/);

    // Search finds it by a word in the message and highlights the match.
    await page.keyboard.press('Control+k');
    await page.waitForURL(/\/search/);
    await page.getByLabel('Search your conversations').fill(`pelikan ${run}`);
    await page.keyboard.press('Enter');
    await expect(
        page.locator('mark', { hasText: `Pelikan ${run}` }),
    ).toBeVisible();
    await page.locator('mark', { hasText: `Pelikan ${run}` }).click();
    await page.waitForURL(/\/c\/[0-9a-z-]+$/);

    // Unpin again so other tests see a normal list.
    await page.getByRole('button', { name: 'Unpin' }).click();
    await expect(page.getByRole('button', { name: 'Pin' })).toBeVisible();
});
