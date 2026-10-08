import { execFileSync } from 'node:child_process';
import { expect, test } from './fixtures';
import type { Page } from '@playwright/test';

/*
 * Personal data rules in the chat: a warned value needs the user's
 * confirmation, a masked one is hidden from the provider and noted under
 * the message.
 */

function rules(tckn: string): void {
    execFileSync(
        'php',
        [
            'artisan',
            'tinker',
            '--execute',
            `$s = app(App\\Domain\\PersonalData\\PersonalDataSettings::class); $s->rules = ['tckn' => '${tckn}', 'iban' => 'off', 'card' => 'off', 'phone' => 'off', 'email' => 'off']; $s->patterns = []; $s->save();`,
        ],
        { stdio: 'ignore' },
    );
}

async function signIn(page: Page): Promise<void> {
    await page.goto('/');
    await page
        .getByRole('button', { name: /^Sign in as Sample User [a-z_]+$/ })
        .click();
    await expect(page.getByTestId('dev-login')).toBeHidden();
}

test.afterAll(() => rules('off'));

test('a warned identity number is sent only after the user confirms', async ({
    page,
}) => {
    rules('warn');
    await signIn(page);

    await page.getByLabel('Message').fill('My number is 10000000146');
    await page.keyboard.press('Enter');

    const warning = page.getByTestId('personal-data-warning');
    await expect(warning).toContainText('Turkish identity number');
    // Nothing was sent: the text is back in the box.
    await expect(page.getByLabel('Message')).toHaveValue(
        'My number is 10000000146',
    );

    await warning.getByRole('button', { name: 'Send anyway' }).click();
    await page.waitForURL(/\/c\/[0-9a-z-]+$/);
    await expect(page.getByRole('heading', { name: 'Merhaba!' })).toBeVisible();
    await expect(warning).toBeHidden();
});

test('a masked identity number is noted under the message', async ({
    page,
}) => {
    rules('mask');
    await signIn(page);

    await page.getByLabel('Message').fill('Check 10000000146 please');
    await page.keyboard.press('Enter');

    await page.waitForURL(/\/c\/[0-9a-z-]+$/);
    await expect(page.getByTestId('personal-data-masked')).toContainText(
        'Hidden from the AI model: Turkish identity number',
    );
    // The user keeps seeing what they wrote.
    await expect(page.getByText('You: Check 10000000146 please')).toBeVisible();
});
