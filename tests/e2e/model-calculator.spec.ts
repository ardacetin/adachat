import { expect, test } from './fixtures';

/*
 * The cost calculator on the model form: results follow every keystroke,
 * and typing in it does not submit the form.
 */

test('the model cost calculator updates as numbers are typed', async ({
    page,
}) => {
    await page.goto('/');
    await page
        .getByRole('button', {
            name: /^Sign in as Sample Super Admin [a-z_]+$/,
        })
        .click();
    await expect(page.getByTestId('dev-login')).toBeHidden();
    await page.goto('/admin/models/create');
    // Prices are typed in the advanced mode (easy mode takes them from the
    // catalog, see model-catalog.spec.ts).
    await page.getByTestId('mode-advanced').click();

    await page.locator('#input_price_per_million').fill('2');
    await page.locator('#output_price_per_million').fill('8');

    await page.getByTestId('calculator-input').fill('1000');
    await page.getByTestId('calculator-output').fill('500');
    await page.getByTestId('calculator-messages').fill('10');
    await page.getByTestId('calculator-budget').fill('10');

    // 1,000 × $2/M + 500 × $8/M = $0.006 per message.
    await expect(page.getByTestId('calculator-per-message')).toHaveText(
        '$0.006',
    );
    await expect(page.getByTestId('calculator-total')).toHaveText('$0.06');
    await expect(page.getByTestId('calculator-fits')).toHaveText('1,666');

    await page.getByTestId('calculator-messages').fill('250');
    await expect(page.getByTestId('calculator-total')).toHaveText('$1.50');

    // A price change is reflected at once too.
    await page.locator('#output_price_per_million').fill('16');
    await expect(page.getByTestId('calculator-per-message')).toHaveText(
        '$0.01',
    );

    await page.getByTestId('calculator-budget').press('Enter');
    await expect(page).toHaveURL(/\/admin\/models\/create$/);
});
