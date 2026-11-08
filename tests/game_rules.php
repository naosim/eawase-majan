<?php
declare(strict_types=1);

require_once __DIR__ . '/../game.php';

$checks = [
    'three natural triplets' => isWinningHand([
        'A1', 'A1', 'A1',
        'B2', 'B2', 'B2',
        'C3', 'C3', 'C3',
    ]),
    'wildcard completes triplet' => isWinningHand([
        'A1', 'A1', 'B2', 'B2', 'C3', 'C3', 'J1', 'J2', 'A1',
    ]),
    'meld contributes to nine tiles' => isWinningHand(
        ['B2', 'B2', 'B2', 'C3', 'C3', 'C3'],
        [['A1', 'A1', 'A1']]
    ),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Failed: {$name}\n");
        exit(1);
    }
}

$notWinning = [
    'A1', 'A1', 'A2', 'A2', 'B1', 'B1', 'C1', 'C1', 'J1',
];
if (isWinningHand($notWinning)) {
    fwrite(STDERR, "Failed: invalid wildcard shape accepted\n");
    exit(1);
}

$tenpai = ['A1', 'A1', 'A1', 'B1', 'B1', 'B1', 'C1', 'C1'];
if (!isTenpai($tenpai, [])) {
    fwrite(STDERR, "Failed: tenpai hand rejected\n");
    exit(1);
}

if (isTenpai(['A1', 'B1', 'C1'], [['A2', 'A2', 'A2']])) {
    fwrite(STDERR, "Failed: incomplete hand accepted as tenpai\n");
    exit(1);
}

if (!canClaimPong(['A1', 'A1'], 'A1')
    || !canClaimPong(['A1', 'J1'], 'A1')
    || canClaimPong(['A1', 'B1'], 'A1')) {
    fwrite(STDERR, "Failed: pong availability check\n");
    exit(1);
}

echo "Game-rule checks passed.\n";
