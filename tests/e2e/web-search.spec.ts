import { expect, test } from './fixtures';

/*
 * Web search end to end: the user turns it on for one message, the mock
 * provider (tests/e2e/mock-provider.mjs) searches and cites a page, and the
 * answer lists it as a source, also after a reload.
 */

test('a message with web search lists its sources', async ({ page }) => {
    await page.goto('/');
    await page
        .getByRole('button', { name: /^Sign in as Sample User [a-z_]+$/ })
        .click();
    await expect(page.getByTestId('dev-login')).toBeHidden();

    const toggle = page.getByTestId('web-search-toggle');
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('web-search-hint')).toContainText(
        'Up to 2 searches, about $0.01 each',
    );

    await page.getByLabel('Message').fill('Ada Lovelace kimdir?');
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/c\/[0-9a-z-]+$/);

    const sources = page.getByTestId('sources');
    const link = sources.getByRole('link', {
        name: /Ada Lovelace – Örnek Ansiklopedi/,
    });
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute(
        'href',
        'https://example.org/ada-lovelace',
    );
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(page.getByText("Web'de buldum.")).toBeVisible();

    // Off again for the next message.
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');

    await page.reload();
    await expect(
        page.getByTestId('sources').getByRole('link', {
            name: /Ada Lovelace – Örnek Ansiklopedi/,
        }),
    ).toBeVisible();
});
