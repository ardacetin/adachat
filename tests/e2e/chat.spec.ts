import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/*
 * The chat, end to end: Ada streams answers from tests/e2e/mock-provider.mjs
 * exactly as it would from OpenAI. The mock answers with Markdown, or with a
 * slow 80-part answer when the prompt contains "long".
 */

async function signIn(page: Page): Promise<void> {
    await page.goto('/login');
    await page
        .getByRole('button', {
            name: /Sign in as Sample User|Sample User olarak giriş yap/,
        })
        .click();
    await page.waitForURL('/');
}

async function setLanguage(page: Page, option: RegExp): Promise<void> {
    await page.goto('/settings/language');
    await page.getByRole('combobox').click();
    await page.getByRole('option', { name: option }).click();
    await page.getByRole('button', { name: /^(Save|Kaydet)$/ }).click();
    await expect(page.getByRole('combobox')).toHaveText(option);
}

test('a new conversation streams a Markdown answer', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await signIn(page);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'How can I help you today?',
    );

    await page.getByLabel('Message').fill('Hello there');
    await page.keyboard.press('Enter');

    await page.waitForURL(/\/c\/[0-9a-z-]+$/);
    await expect(page.getByRole('heading', { name: 'Merhaba!' })).toBeVisible();
    await expect(page.getByText('Birinci madde')).toBeVisible();
    await expect(page.locator('pre')).toContainText("echo 'Ada';");
    await expect(
        page.getByRole('button', { name: 'Regenerate answer' }),
    ).toBeVisible();

    // The conversation is listed in the sidebar under its first message.
    await expect(
        page.locator('[data-sidebar="menu-button"][data-active="true"]'),
    ).toHaveText('Hello there');

    // The answer was stored: it is still there after a reload.
    await page.reload();
    await expect(page.getByText('Birinci madde')).toBeVisible();

    expect(errors).toEqual([]);
});

test('an answer can be stopped and the partial answer is kept', async ({
    page,
}) => {
    await signIn(page);

    await page.getByLabel('Message').fill('please write a long answer');
    await page.keyboard.press('Enter');

    // Streaming is incremental: the first words arrive long before the last.
    await expect(page.getByText(/Kelime 3\./)).toBeVisible();
    await expect(page.getByText(/Kelime 80\./)).toHaveCount(0);

    await page.getByRole('button', { name: 'Stop' }).click();
    await expect(page.getByText('Stopped')).toBeVisible();

    await page.reload();
    await expect(page.getByText('Stopped')).toBeVisible();
    await expect(page.getByText(/Kelime 3\./)).toBeVisible();
    await expect(page.getByText(/Kelime 80\./)).toHaveCount(0);
});

test('the last answer can be regenerated', async ({ page }) => {
    await signIn(page);

    await page.getByLabel('Message').fill('Hi');
    await page.keyboard.press('Enter');
    await expect(page.getByText('İkinci madde')).toBeVisible();

    await page.getByRole('button', { name: 'Regenerate answer' }).click();
    await expect(page.getByRole('button', { name: 'Stop' })).toBeVisible();
    await expect(page.getByText('İkinci madde')).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Regenerate answer' }),
    ).toBeVisible();

    // Only the regenerated answer is part of the conversation.
    await page.reload();
    await expect(page.getByText('İkinci madde')).toHaveCount(1);
});

test('the chat is available in Turkish', async ({ page }) => {
    await signIn(page);
    await setLanguage(page, /Türkçe/);

    try {
        await page.goto('/');
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(
            'Bugün size nasıl yardımcı olabilirim?',
        );
        await expect(
            page.getByRole('combobox', { name: 'Model' }),
        ).toContainText('Akıllı');

        await page.getByLabel('Mesaj').fill('Selam');
        await page.keyboard.press('Enter');
        await expect(page.getByText('Birinci madde')).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Yanıtı yeniden oluştur' }),
        ).toBeVisible();
    } finally {
        await setLanguage(page, /English|İngilizce/);
    }
});
