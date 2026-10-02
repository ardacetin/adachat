import { expect, test } from './fixtures';
import type { Page } from '@playwright/test';

/*
 * Answer feedback end to end: a user rates an answer with a reason, the
 * vote is kept, and the satisfaction report counts it without content.
 */

async function signInAs(page: Page, user: string): Promise<void> {
    await page.goto('/login');
    await page
        .getByRole('button', {
            name: new RegExp(`^Sign in as ${user} [a-z_]+$`),
        })
        .click();
    await page.waitForURL('/');
}

test('a user rates an answer and administrators see the totals', async ({
    page,
    browser,
}) => {
    await signInAs(page, 'Sample User');

    await page.getByLabel('Message').fill('Feedback please');
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/c\//);
    await expect(page.getByText('Birinci madde')).toBeVisible();

    const answer = page.getByTestId('feedback-down').last();
    await answer.click();
    await page.getByRole('menuitem', { name: 'Too long' }).click();
    await expect(page.getByTestId('feedback-down').last()).toHaveAttribute(
        'aria-pressed',
        'true',
    );

    await page.reload();
    await expect(page.getByTestId('feedback-down').last()).toHaveAttribute(
        'aria-pressed',
        'true',
    );

    // A separate browser: a late response from the user's page can never
    // put the user's session back into the administrator's cookies.
    const adminContext = await browser.newContext();
    const admin = await adminContext.newPage();

    try {
        await signInAs(admin, 'Sample Super Admin');
        await admin.goto('/admin/feedback');
        await expect(
            admin.getByRole('heading', { name: 'Satisfaction' }),
        ).toBeVisible();
        await expect(admin.getByTestId('feedback-rows')).toContainText('Smart');
        await expect(admin.getByTestId('feedback-votes')).not.toHaveText('0');
    } finally {
        await adminContext.close();
    }
});
