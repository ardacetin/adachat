<?php

use App\Domain\Conversations\Data\ChatStreamEvent;

test('events are encoded as server-sent events', function () {
    $event = new ChatStreamEvent('delta', ['text' => "Merhaba\n/dünya"]);

    expect($event->encode())->toBe("event: delta\ndata: {\"text\":\"Merhaba\\n/dünya\"}\n\n");
});
