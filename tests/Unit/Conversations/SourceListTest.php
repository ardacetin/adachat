<?php

use App\Domain\AI\Data\Events\SourceFound;
use App\Domain\Conversations\Data\SourceList;

test('cited sources are kept once, in order', function () {
    $list = new SourceList;

    expect($list->add(new SourceFound('https://a.example/1', 'A')))->toBe(['url' => 'https://a.example/1', 'title' => 'A'])
        ->and($list->add(new SourceFound('https://a.example/1', 'A again')))->toBeNull()
        ->and($list->add(new SourceFound('https://b.example/', null)))->not->toBeNull();

    expect($list->toArray())->toBe([
        ['url' => 'https://a.example/1', 'title' => 'A'],
        ['url' => 'https://b.example/', 'title' => null],
    ]);
});

test('only http and https links are kept', function (string $url) {
    $list = new SourceList;

    expect($list->add(new SourceFound($url, 'x')))->toBeNull()
        ->and($list->toArray())->toBe([]);
})->with(['javascript:alert(1)', 'data:text/html,hi', 'ftp://files.example/a', '//example.com/a', 'https://']);

test('without citations the first search results are listed', function () {
    $list = new SourceList;

    foreach (range(1, 8) as $i) {
        expect($list->add(new SourceFound("https://r.example/{$i}", "R{$i}", cited: false)))->toBeNull();
    }

    expect(array_column($list->toArray(), 'url'))->toHaveCount(SourceList::MAX_RESULTS)
        ->and($list->toArray()[0]['url'])->toBe('https://r.example/1');

    $list->add(new SourceFound('https://cited.example/', 'C'));

    expect($list->toArray())->toBe([['url' => 'https://cited.example/', 'title' => 'C']]);
});

test('titles are tidied and shortened', function () {
    $list = new SourceList;
    $source = $list->add(new SourceFound('https://a.example/', "  Uzun\n  başlık ".str_repeat('x', 300)));

    expect($source['title'])->toStartWith('Uzun başlık x')
        ->and(mb_strlen((string) $source['title']))->toBeLessThanOrEqual(203);
});
