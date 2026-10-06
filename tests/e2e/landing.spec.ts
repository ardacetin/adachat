import { expect, test } from './fixtures';

/*
 * Guests land on a short public page and reach sign-in from it.
 */

test('the landing page leads to sign-in', async ({ page }) => {
    await page.goto('/');

    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        "Your institution's AI assistant",
    );
    for (const title of [
        'Many models, one place',
        'A monthly budget for everyone',
        'Private by design',
    ]) {
        await expect(page.getByText(title)).toBeVisible();
    }

    await page.getByTestId('landing-sign-in').click();
    await page.waitForURL('/login');
    await expect(
        page.getByRole('button', { name: /^Sign in as Sample User/ }),
    ).toBeVisible();
});
