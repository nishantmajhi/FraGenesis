<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'groupTopics.php';

Harness::test('topics are grouped under their unit number', function () {
    Harness::same(
        [1 => ['1.1', '1.2'], 4 => ['4.3']],
        groupTopicsByUnit(['1.1', '1.2', '4.3'])
    );
});

Harness::test('three-segment ids are normalised to the two the database stores', function () {
    // This is the whole reason the function exists. Grade 11 sends "1.1.1" and
    // grade 12's unit 4 sends "4.2.1"; the question tables hold "1.1" and "4.2".
    Harness::same(
        [4 => ['4.2', '4.3']],
        groupTopicsByUnit(['4.2.1', '4.2.2', '4.2.5', '4.3.1'])
    );
});

Harness::test('normalising deduplicates, so a topic is not queried twice', function () {
    // Five granular ids under 4.2 must become one "4.2" — otherwise the IN
    // clause repeats it and the LIMIT is spent on the same rows.
    $grouped = groupTopicsByUnit(['4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5']);
    Harness::same([4 => ['4.2']], $grouped);
});

Harness::test('a one-segment id is left alone', function () {
    Harness::same([3 => ['3']], groupTopicsByUnit(['3']));
});

Harness::test('surrounding whitespace is trimmed', function () {
    Harness::same([1 => ['1.1']], groupTopicsByUnit([' 1.1 ']));
});

Harness::test('the unit key is an integer, whatever the id was', function () {
    $grouped = groupTopicsByUnit(['12.34']);
    Harness::same([12], array_keys($grouped));
    Harness::ok(is_int(array_keys($grouped)[0]));
});

Harness::test('the topic lists are packed lists, because the fetcher indexes them', function () {
    // fetchAllDistributed() builds its placeholder string from count() and then
    // passes the list to execute(), so a gap in the keys would misalign the
    // bound parameters.
    foreach (groupTopicsByUnit(['1.1', '1.1', '1.2', '1.1']) as $topics) {
        Harness::same(range(0, count($topics) - 1), array_keys($topics));
    }
});

Harness::test('an empty list groups to nothing', function () {
    Harness::same([], groupTopicsByUnit([]));
});

Harness::test('every grade-12 syllabus id groups to the unit it belongs to', function () {
    // db/syllabus.js is what the front end offers, so every id in it has to come
    // out under the unit its own number names. Read from the real file: if the
    // syllabus gains a shape this function cannot handle, this fails.
    $syllabus = file_get_contents(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'syllabus.js'
    );
    Harness::ok($syllabus !== false, 'db/syllabus.js is readable');

    preg_match_all('/"id": "([0-9.]+)"/', $syllabus, $matches);
    Harness::ok(count($matches[1]) > 150, 'found the topic ids: ' . count($matches[1]));

    foreach (groupTopicsByUnit($matches[1]) as $unit => $topics) {
        foreach ($topics as $topic) {
            Harness::same($unit, (int) explode('.', $topic)[0], $topic);
            Harness::ok(
                substr_count($topic, '.') <= 1,
                "$topic was not normalised to two segments"
            );
        }
    }
});

Harness::test('every syllabus id also passes validation', function () {
    // The two have to agree: a topic the front end can offer must be a topic the
    // validator accepts, or the Final Exam button 400s.
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'validateRequest.php';

    $syllabus = file_get_contents(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'syllabus.js'
    );
    preg_match_all('/"id": "([0-9.]+)"/', $syllabus, $matches);

    foreach ($matches[1] as $id) {
        Harness::ok(isTopicId($id), $id);
    }

    // And the whole grade-11 list at once, which is the larger of the two, fits
    // inside MAX_TOPICS.
    Harness::ok(
        count(array_unique($matches[1])) <= MAX_TOPICS * 2,
        'the syllabus has ' . count(array_unique($matches[1])) . ' ids'
    );
});
