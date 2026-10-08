import { execFileSync } from 'node:child_process';
import { expect, test } from './fixtures';

/*
 * The usage notice: a user who has not acknowledged it sees it first, and
 * after "I understand" can chat.
 */

function resetAcknowledgment(): void {
    execFileSync(
        'php',
        [
            'artisan',
            'tinker',
            '--execute',
            "App\\Models\\User::where('email', 'user@example.edu')->update(['acknowledged_version' => null, 'acknowledged_at' => null]);",
        ],
        { stdio: 'ignore' },
    );
}

test('the usage notice comes before the first chat', async ({ page }) => {
    resetAcknowledgment();

    await page.goto('/');
    await page.getByRole('button', { name: /Sign in as Sample User/ }).click();
    await page.waitForURL('/acknowledgment');

    await expect(
        page.getByRole('heading', { name: 'Before you start' }),
    ).toBeVisible();
    await expect(page.getByText('Do not enter personal data')).toBeVisible();

    await page.getByRole('button', { name: 'I understand' }).click();
    await page.waitForURL('/');
    await expect(page.getByLabel('Message')).toBeVisible();
});
