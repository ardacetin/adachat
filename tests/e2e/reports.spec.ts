import { expect, test } from './fixtures';

/*
 * The CSV export on the reports page: a real download with the page's
 * filters, opening cleanly as UTF-8 with the expected columns.
 */

test('reports can be downloaded as CSV', async ({ page }) => {
    await page.goto('/');
    await page
        .getByRole('button', { name: /^Sign in as Sample Admin [a-z_]+$/ })
        .click();
    await expect(page.getByTestId('dev-login')).toBeHidden();
    await page.goto('/admin/reports?by=group');

    await page.getByRole('button', { name: 'Download CSV' }).click();
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('menuitem', { name: 'Breakdown by group' }).click(),
    ]);

    expect(download.suggestedFilename()).toMatch(
        /^ada-usage-group-\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.csv$/,
    );

    const stream = await download.createReadStream();
    const chunks: Buffer[] = [];
    for await (const chunk of stream) {
        chunks.push(chunk as Buffer);
    }
    const csv = Buffer.concat(chunks).toString('utf8');

    expect(csv.split('\n')[0]).toBe(
        '\uFEFFGroup,Requests,"Input tokens","Output tokens","Web searches","Cost (USD)"',
    );
});
