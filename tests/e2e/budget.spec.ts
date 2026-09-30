import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/*
 * The user's budget: the sidebar indicator, the usage page and what happens
 * when the budget is used up. The limit is changed with ada:user:budget,
 * as an administrator would until the users screen exists.
 */

const EMAIL = 'user@example.edu';

function setBudget(...args: string[]): void {
    execFileSync('php', ['artisan', 'ada:user:budget', EMAIL, ...args], {
        stdio: 'ignore',
    });
}

async function signIn(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByRole('button', { name: /Sign in as Sample User/ }).click();
    await page.waitForURL('/');
}

test.afterEach(() => setBudget('--clear'));

test('the sidebar shows the budget and the usage page the spending', async ({
    page,
}) => {
    setBudget('--limit=5');
    await signIn(page);

    const indicator = page.getByTestId('budget-indicator');
    await expect(indicator).toContainText('Monthly budget');
    await expect(indicator).toContainText('of $5.00 left');

    await page.getByLabel('Message').fill('Hello budget');
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/c\//);
    await expect(page.getByText('Birinci madde')).toBeVisible();
    // The indicator is refreshed with the conversation after the answer.
    await expect(indicator).toContainText('1% used');

    await indicator.click();
    await page.waitForURL('/usage');
    await expect(page.getByRole('heading', { name: 'Usage' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Smart' })).toBeVisible();
    await expect(page.getByText('Limit')).toBeVisible();
});

test('a used-up budget stops new messages and says when it renews', async ({
    page,
}) => {
    setBudget('--limit=0');
    await signIn(page);

    await expect(page.getByTestId('budget-exhausted')).toContainText(
        'Your monthly budget is used up. It renews on',
    );
    await expect(page.getByLabel('Message')).toBeDisabled();

    await page.getByTestId('budget-exhausted').getByRole('link').click();
    await page.waitForURL('/usage');
    await expect(page.getByText('100% used').first()).toBeVisible();
});
