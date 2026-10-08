import path from 'node:path';
import { expect, test } from './fixtures';
import type { Page } from '@playwright/test';

/*
 * Attachments end to end: an image and a text file are uploaded from the
 * composer and sent with a message. tests/e2e/mock-provider.mjs answers
 * "Görsel sayısı: N." when it receives N inline images, so the answer proves
 * the image reached the provider.
 */

const files = path.join(import.meta.dirname, 'files');

async function signIn(page: Page): Promise<void> {
    await page.goto('/');
    await page.getByRole('button', { name: /Sign in as Sample User/ }).click();
    await expect(page.getByTestId('dev-login')).toBeHidden();
}

test('an image and a text file are sent with a message', async ({ page }) => {
    await signIn(page);

    await page
        .getByTestId('attachment-input')
        .setInputFiles([
            path.join(files, 'diagram.png'),
            path.join(files, 'notes.md'),
        ]);

    const chips = page.getByTestId('attachment-chip');
    await expect(chips).toHaveCount(2);
    await expect(chips.filter({ hasText: 'diagram.png' })).toContainText(
        'about 1,600 tokens',
    );
    await expect(chips.filter({ hasText: 'notes.md' })).toContainText(
        /about \d+ tokens/,
    );

    await page.getByLabel('Message').fill('What is in these files?');
    await page.keyboard.press('Enter');

    await page.waitForURL(/\/c\/[0-9a-z-]+$/);
    await expect(page.getByText('Görsel sayısı: 1.')).toBeVisible();
    await expect(chips).toHaveCount(0);

    // After a reload the message still shows its files.
    await page.reload();
    const image = page.getByRole('img', { name: 'diagram.png' });
    await expect(image).toBeVisible();
    expect(
        await image.evaluate(
            (element: HTMLImageElement) => element.naturalWidth,
        ),
    ).toBe(32);
    await expect(page.getByRole('link', { name: 'notes.md' })).toBeVisible();
});

test('a rejected file is explained and not sent', async ({ page }) => {
    await signIn(page);

    await page.getByTestId('attachment-input').setInputFiles({
        name: 'logo.svg',
        mimeType: 'image/svg+xml',
        buffer: Buffer.from(
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        ),
    });

    const chip = page.getByTestId('attachment-chip');
    await expect(chip).toContainText('This file type is not supported.');

    await page.getByRole('button', { name: 'Remove logo.svg' }).click();
    await expect(chip).toHaveCount(0);
});

test('a PDF goes as a file, a Word document as text', async ({ page }) => {
    await signIn(page);

    await page
        .getByTestId('attachment-input')
        .setInputFiles([path.join(files, 'sample.pdf')]);
    await expect(page.getByTestId('attachment-chip')).toContainText(
        '2 pages · about 3,000 tokens',
    );
    await page.getByLabel('Message').fill('Summarise the report');
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/c\/[0-9a-z-]+$/);
    await expect(page.getByText('PDF alındı: 1.')).toBeVisible();

    await page
        .getByTestId('attachment-input')
        .setInputFiles([path.join(files, 'sample.docx')]);
    await expect(page.getByTestId('attachment-chip')).toContainText(
        /about \d+ tokens/,
    );
    await page.getByLabel('Message').fill('And these notes?');
    await page.keyboard.press('Enter');
    await expect(page.getByText('Dosyayı okudum.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'sample.docx' })).toBeVisible();
});
