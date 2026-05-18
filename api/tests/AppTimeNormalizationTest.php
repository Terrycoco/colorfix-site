<?php
declare(strict_types=1);

test('AppTime normalizes UTC ISO strings into Pacific time', function () {
    assert_equals(
        \App\Lib\AppTime::normalizeDateTimeString('2026-04-30T22:15:00.000Z'),
        '2026-04-30 15:15:00',
        'UTC ISO timestamp should normalize to America/Los_Angeles'
    );
});

test('AppTime preserves naive local timestamps', function () {
    assert_equals(
        \App\Lib\AppTime::normalizeDateTimeString('2026-04-30 15:15:00'),
        '2026-04-30 15:15:00',
        'Naive DATETIME strings should remain in app local time'
    );
});
