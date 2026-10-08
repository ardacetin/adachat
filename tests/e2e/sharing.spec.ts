import { expect, test } from './fixtures';
import type { Page } from '@playwright/test';

/*
 * Conversation sharing end to end: the owner creates a read-only link,
 * another signed-in user reads it and copies it into their own
 * conversations, and the link stops working once revoked.
 */

async function signInAs(page: Page, user: string): Promise<void> {
    await page.goto('/');
    await page
        .getByRole('button', {
            name: new RegExp(`^Sign in as ${user} [a-z_]+$`),
        })
        .click();
    await expect(page.getByTestId('dev-login')).toBeHidden();
}

test('a conversation is shared, read, copied and revoked', async ({
    page,
    browser,
}) => {
    await signInAs(page, 'Sample User');

    await page.getByLabel('Message').fill('Share this please');
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/c\//);
    await expect(page.getByText('Birinci madde')).toBeVisible();

    await page.getByTestId('share-open').click();
    await page.getByTestId('share-create').click();
    const link = await page.getByTestId('share-url').inputValue();
    expect(link).toMatch(/\/s\/[A-Za-z0-9_-]{43}$/);
    await expect(page.getByTestId('share-links').locator('li')).toHaveCount(1);

    // Another user, in a separate browser: no cookies shared with the owner.
    const viewerContext = await browser.newContext();
    const viewer = await viewerContext.newPage();

    try {
        await signInAs(viewer, 'Sample Admin');
        await viewer.goto(link);
        await expect(viewer.getByTestId('shared-messages')).toContainText(
            'Share this please',
        );
        await expect(viewer.getByTestId('shared-messages')).toContainText(
            'Birinci madde',
        );
        await expect(viewer.getByText('Shared by Sample User')).toBeVisible();

        await viewer.getByTestId('share-copy').click();
        await viewer.waitForURL(/\/c\//);
        await expect(viewer.getByText('Birinci madde')).toBeVisible();

        // The owner revokes the link: it no longer opens.
        await page.getByRole('button', { name: 'Revoke' }).click();
        await expect(page.getByText('No active links.')).toBeVisible();

        const response = await viewer.goto(link);
        expect(response?.status()).toBe(404);
    } finally {
        await viewerContext.close();
    }
});
