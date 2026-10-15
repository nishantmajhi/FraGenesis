<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'intToRoman.php';

Harness::test('the two grades this project has render correctly', function () {
    Harness::same('XI', intToRoman(11));
    Harness::same('XII', intToRoman(12));
});

Harness::test('the subtractive pairs are used', function () {
    $expected = [
        1 => 'I', 4 => 'IV', 5 => 'V', 9 => 'IX', 10 => 'X', 14 => 'XIV',
        40 => 'XL', 49 => 'XLIX', 50 => 'L', 90 => 'XC', 100 => 'C',
        400 => 'CD', 500 => 'D', 900 => 'CM', 1000 => 'M',
        1987 => 'MCMLXXXVII', 2026 => 'MMXXVI', 3999 => 'MMMCMXCIX',
    ];
    foreach ($expected as $n => $roman) {
        Harness::same($roman, intToRoman($n), (string) $n);
    }
});

Harness::test('zero is the empty string, which is what it has always been', function () {
    // Left as it was. Nothing calls it with zero now that grade is validated
    // against GRADES, and inventing a return value would be a change with no
    // caller asking for it.
    Harness::same('', intToRoman(0));
});

Harness::test('the runaway input that exhausted memory can no longer reach it', function () {
    // intToRoman(1e15) appends one 'M' per thousand — a string of a million
    // million characters — and the request died on the memory limit after about
    // four seconds of CPU. The fix is upstream: the grade is checked against
    // GRADES before anything calls this. So the guarantee to assert is that
    // validation refuses the input, not that this function survives it.
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'validateRequest.php';

    foreach (['1e15', '1000000', '9999999999'] as $runaway) {
        $r = validatePaperRequest(['grade' => $runaway, 'fullMarks' => '30', 'topics' => '1.1']);
        Harness::same(false, $r['ok'], "grade=$runaway");
    }

    // And every grade that *is* allowed is small enough to be instant.
    foreach (GRADES as $grade) {
        $started = microtime(true);
        $roman   = intToRoman($grade);
        Harness::ok(strlen($roman) < 20, "grade $grade gives $roman");
        Harness::ok(microtime(true) - $started < 0.1, "grade $grade was quick");
    }
});
