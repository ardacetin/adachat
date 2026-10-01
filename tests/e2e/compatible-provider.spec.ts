import { execFileSync } from 'node:child_process';
import { expect, test } from './fixtures';
import type { Page } from '@playwright/test';

/*
 * An OpenAI-compatible server (Ollama, vLLM…) end to end: a super
 * administrator adds it without an API key, tests the connection, and a user
 * chats with a model on it. tests/e2e/mock-provider.mjs answers
 * /v1/chat/completions and refuses any request that carries credentials.
 */

const run = Date.now().toString(36);
const providerName = `Local ${run}`;
const aliasSlug = `local-${run}`;
const aliasName = `Local model ${run}`;

function tinker(code: string): void {
    execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        stdio: 'ignore',
    });
}

async function signInAs(page: Page, name: string): Promise<void> {
    await page.goto('/login');
    await page
        .getByRole('button', {
            name: new RegExp(`^Sign in as ${name} [a-z_]+$`),
        })
        .click();
    await page.waitForURL('/');
}

// The alias stays in the database (usage rows refer to it) but leaves the menu.
test.afterAll(() => {
    tinker(
        `$a = App\\Models\\ModelAlias::where('slug', '${aliasSlug}')->first(); if ($a) { $a->groups()->detach(); $a->forceFill(['enabled' => false])->save(); }`,
    );
});

test('a keyless OpenAI-compatible server is added and answers in the chat', async ({
    page,
}) => {
    const port = process.env.MOCK_PROVIDER_PORT ?? '8765';

    await signInAs(page, 'Sample Super Admin');

    await page.goto('/admin/providers/create');
    await page.getByLabel('Name').fill(providerName);
    await page.getByLabel('Slug').fill(`local-${run}`);
    await page.getByLabel('Type').click();
    await page.getByRole('option', { name: /OpenAI-compatible/ }).click();
    await expect(page.getByLabel('Base URL')).toHaveAttribute('required', '');
    await page.getByLabel('Base URL').fill(`http://127.0.0.1:${port}/v1`);
    await page.getByRole('button', { name: 'Save' }).click();

    await page
        .getByRole('row', { name: new RegExp(providerName) })
        .getByRole('link', { name: 'Edit' })
        .click();
    await page.getByRole('button', { name: 'Test connection' }).click();
    await expect(
        page.getByText('Connection successful: the API key works.'),
    ).toBeVisible();

    // Model and alias as in tests/e2e/seed.php; the admin screens for them
    // are covered by admin.spec.ts.
    tinker(
        [
            `$p = App\\Models\\Provider::where('slug', 'local-${run}')->sole();`,
            `$m = new App\\Models\\AiModel; $m->forceFill(['provider_id' => $p->id, 'provider_model_id' => 'llama-mock', 'display_name' => 'Llama Mock', 'input_price_per_million' => '0', 'output_price_per_million' => '0', 'context_window' => 32000, 'max_output_tokens' => 2048])->save();`,
            `$a = new App\\Models\\ModelAlias; $a->forceFill(['slug' => '${aliasSlug}', 'name' => ['en' => '${aliasName}', 'tr' => '${aliasName}'], 'ai_model_id' => $m->id, 'sort_order' => 90])->save();`,
            `$a->groups()->attach(App\\Models\\Group::default());`,
        ].join(' '),
    );

    await page.goto('/');
    await page.getByRole('combobox', { name: 'Model' }).click();
    await page.getByRole('option', { name: new RegExp(aliasName) }).click();

    await page.getByLabel('Message').fill('Hello local model');
    await page.keyboard.press('Enter');

    await page.waitForURL(/\/c\/[0-9a-z-]+$/);
    await expect(page.getByText('Yerel modelden')).toBeVisible();
    await expect(page.locator('strong', { hasText: 'merhaba' })).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Regenerate answer' }),
    ).toBeVisible();
});
