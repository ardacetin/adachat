import { execFileSync } from 'node:child_process';
import { expect, test } from './fixtures';

/*
 * Easy pricing: a model is added by choosing it from Ada's price catalog,
 * without typing a price. The prices are stored from the catalog.
 */

const run = Date.now().toString(36);
const providerName = `Catalog ${run}`;
const modelName = `Haiku ${run}`;

function tinker(code: string): void {
    execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        stdio: 'ignore',
    });
}

test('a model is added from the price catalog in easy mode', async ({
    page,
}) => {
    tinker(
        `$p = new App\\Models\\Provider; $p->forceFill(['slug' => 'catalog-${run}', 'driver' => 'anthropic', 'name' => '${providerName}', 'enabled' => false])->save();`,
    );

    await page.goto('/');
    await page
        .getByRole('button', {
            name: /^Sign in as Sample Super Admin [a-z_]+$/,
        })
        .click();
    await expect(page.getByTestId('dev-login')).toBeHidden();
    await page.goto('/admin/models/create');

    await page.getByRole('combobox', { name: 'Provider' }).click();
    await page.getByRole('option', { name: providerName }).click();
    await expect(page.getByTestId('mode-easy')).toHaveAttribute(
        'data-state',
        'on',
    );
    await expect(page.locator('#input_price_per_million')).toHaveCount(0);

    await page.getByRole('combobox', { name: 'Model' }).click();
    await page.getByRole('option', { name: /^Claude Haiku 4\.5/ }).click();
    await expect(page.getByTestId('catalog-summary')).toContainText(
        'Input $1.00 · output $5.00 per million tokens',
    );

    await page.getByLabel('Display name').fill(modelName);
    await page.getByRole('button', { name: 'Save' }).click();

    await page.waitForURL(/\/admin\/models$/);
    const row = page.getByRole('row', { name: new RegExp(modelName) });
    await expect(row).toContainText('Catalog');
    await expect(row).toContainText('claude-haiku-4-5-20251001');
});
