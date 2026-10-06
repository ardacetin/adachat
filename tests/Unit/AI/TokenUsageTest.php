<?php

use App\Domain\AI\Data\TokenUsage;

test('negative usage counts as zero', function () {
    $usage = new TokenUsage(input: -500, cachedInput: 100, output: -1, reasoning: -2, webSearches: -3);

    expect([$usage->input, $usage->cachedInput, $usage->output, $usage->reasoning, $usage->webSearches])->toBe([0, 100, 0, 0, 0]);
});
