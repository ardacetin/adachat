import { expect, test } from './fixtures';

/*
 * Adding users by e-mail address: an administrator enters addresses, the
 * accounts appear as invited and an unused one can be removed again.
 */

const run = Date.now().toString(36);
const email = `invite-${run}@partner.example`;

test('an administrator adds a user by e-mail address', async ({ page }) => {
    await page.goto('/login');
    await page
        .getByRole('button', {
            name: /^Sign in as Sample Super Admin [a-z_]+$/,
        })
        .click();
    await page.waitForURL('/');

    await page.goto('/admin/users');
    await expect(page.getByTestId('sign-in-access')).toContainText(
        'Who can sign in',
    );

    await page.getByRole('button', { name: 'Add users' }).click();
    await page
        .getByLabel('E-mail addresses')
        .fill(`Invited Person ${run} <${email}>`);
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.getByText('Added 1 user(s)')).toBeVisible();

    await page.goto(`/admin/users?q=${encodeURIComponent(email)}`);
    const row = page.getByRole('row', { name: new RegExp(email) });
    await expect(row.getByText('Invited', { exact: true })).toBeVisible();

    await row.getByRole('link', { name: `Invited Person ${run}` }).click();
    await expect(page.getByTestId('invitation-pending')).toBeVisible();
    await page.getByRole('button', { name: 'Delete' }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Delete' })
        .click();

    await page.waitForURL(/\/admin\/users$/);
    await expect(page.getByText('The user was removed.')).toBeVisible();
});
