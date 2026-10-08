import { execFileSync } from 'node:child_process';
import { expect, test } from './fixtures';

/*
 * Google's Search Suggestions under a Gemini answer grounded in Google
 * Search: shown unmodified in a sandboxed frame, within the page's Content
 * Security Policy (the fixture fails the test on any violation).
 */

const SUGGESTIONS =
    '<style>.container{display:flex;gap:8px}.chip{padding:6px 12px;border:1px solid #dadce0;border-radius:16px;color:#1f1f1f;text-decoration:none}</style>' +
    '<div class="container"><a class="chip" href="https://www.google.com/search?q=ada+lovelace">ada lovelace</a></div>';

function seedGroundedConversation(): string {
    const php = `
        $user = App\\Models\\User::where('email', 'user@example.edu')->firstOrFail();
        $conversation = new App\\Models\\Conversation;
        $conversation->forceFill(['user_id' => $user->id, 'title' => 'Grounded answer', 'last_message_at' => now()])->save();
        $question = new App\\Models\\Message;
        $question->forceFill(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Ada Lovelace?', 'status' => 'completed'])->save();
        $answer = new App\\Models\\Message;
        $answer->forceFill(['conversation_id' => $conversation->id, 'parent_message_id' => $question->id, 'role' => 'assistant', 'content' => 'Ada Lovelace was born in 1815.', 'status' => 'completed', 'metadata' => ['search_suggestions' => base64_decode('${Buffer.from(SUGGESTIONS).toString('base64')}')]])->save();
        echo $conversation->id;
    `;

    return execFileSync('php', ['artisan', 'tinker', '--execute', php], {
        encoding: 'utf8',
    }).trim();
}

test("a grounded answer shows Google's Search Suggestions", async ({
    page,
}) => {
    const id = seedGroundedConversation();

    await page.goto('/');
    await page
        .getByRole('button', { name: /^Sign in as Sample User [a-z_]+$/ })
        .click();
    await expect(page.getByTestId('dev-login')).toBeHidden();
    await page.goto(`/c/${id}`);

    await expect(
        page.getByText('Ada Lovelace was born in 1815.'),
    ).toBeVisible();

    const frame = page.getByTestId('search-suggestions');
    await expect(frame).toHaveAttribute(
        'sandbox',
        'allow-popups allow-popups-to-escape-sandbox',
    );

    const chip = page
        .frameLocator('[data-test="search-suggestions"]')
        .getByRole('link', { name: 'ada lovelace' });
    await expect(chip).toHaveAttribute(
        'href',
        'https://www.google.com/search?q=ada+lovelace',
    );
    // The frame's stylesheet applies (inline styles are allowed by the policy).
    await expect(chip).toHaveCSS('border-radius', '16px');
});
