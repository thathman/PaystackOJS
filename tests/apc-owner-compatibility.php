<?php

require_once dirname(__DIR__) . '/classes/ApcOwnerCompatibility.php';

use APP\plugins\paymethod\paystack\classes\ApcOwnerCompatibility;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$publicationPaymentType = 7;
$subscriptionPaymentType = 3;

assertSameValue(
    true,
    ApcOwnerCompatibility::mayClaim(
        $publicationPaymentType,
        6,
        6,
        'author@example.com',
        [6],
        'author@example.com'
    ),
    'A correctly owned APC remains authorized'
);

assertSameValue(
    true,
    ApcOwnerCompatibility::mayClaim(
        $publicationPaymentType,
        1,
        6,
        'primary@example.com',
        [6, 7],
        'PRIMARY@example.com'
    ),
    'The primary assigned author may claim an editor-owned APC'
);

assertSameValue(
    true,
    ApcOwnerCompatibility::mayClaim(
        $publicationPaymentType,
        1,
        6,
        'sole@example.com',
        [6],
        null
    ),
    'The sole assigned author may claim when primary author data is unavailable'
);

assertSameValue(
    false,
    ApcOwnerCompatibility::mayClaim(
        $publicationPaymentType,
        1,
        9,
        'outsider@example.com',
        [6],
        'author@example.com'
    ),
    'An unassigned user may not claim an APC'
);

assertSameValue(
    false,
    ApcOwnerCompatibility::mayClaim(
        $publicationPaymentType,
        1,
        7,
        'coauthor@example.com',
        [6, 7],
        'primary@example.com'
    ),
    'A non-primary author may not claim a multi-author APC'
);

assertSameValue(
    false,
    ApcOwnerCompatibility::mayClaim(
        $subscriptionPaymentType,
        1,
        6,
        'author@example.com',
        [6],
        'author@example.com'
    ),
    'Non-APC payments may never be reassigned'
);

assertSameValue(
    false,
    ApcOwnerCompatibility::mayClaim(
        $publicationPaymentType,
        1,
        6,
        '',
        [6],
        null
    ),
    'Missing author identity data fails closed'
);

assertSameValue(
    false,
    ApcOwnerCompatibility::mayClaim(
        $publicationPaymentType,
        1,
        6,
        'author@example.com',
        [],
        null
    ),
    'Missing author assignment data fails closed'
);

assertSameValue(
    false,
    ApcOwnerCompatibility::mayClaim(
        $publicationPaymentType,
        1,
        6,
        'author@example.com',
        [6, 7],
        null
    ),
    'Missing primary author data does not authorize one of several assigned authors'
);

echo "APC ownership compatibility tests passed\n";
