<?php

declare(strict_types=1);

/**
 * The fixed project, driven over a real socket.
 *
 * Everything else in this suite tests a decision as a value. This file tests
 * that the decision reaches the wire: that the router wires validation to the
 * generator, that a refused request really is a 400, and that a fatal error is
 * no longer part of the response body.
 *
 * It reads db/questions.sqlite3 and never writes to it. If that file is missing
 * — a fresh checkout that has not been seeded — the tests that need questions
 * skip and the ones about refusals still run, because a refused request never
 * reaches the database.
 *
 * Two things this file does deliberately, both learned the hard way elsewhere in
 * this archive. It speaks HTTP/1.0 over fsockopen() rather than using
 * file_get_contents() with an http:// context, because that follows redirects
 * and hides the status code the server actually sent. And it gives the dev
 * server a file for both of its streams, never a pipe: php -S logs a line per
 * request, and a pipe nothing reads fills after a few kilobytes and blocks the
 * server mid-response, which looks exactly like a hung test.
 */

final class DevServer
{
    /** @var resource|null */
    private static $process = null;
    private static ?int $port = null;
    private static bool $tried = false;
    private static ?string $log = null;

    public static function port(): ?int
    {
        if (self::$tried) {
            return self::$port;
        }
        self::$tried = true;

        $port = self::freePort();
        if ($port === null) {
            return null;
        }

        self::$log = tempnam(sys_get_temp_dir(), 'fragenesis-server-');

        // bypass_shell is not optional on Windows. Without it proc_open runs the
        // command through cmd.exe, so proc_terminate() kills the shell and
        // leaves php -S running — and an orphan started this way holds the
        // inherited write end of whatever pipe the suite's own output is going
        // to, so `php tests/run.php | tail` never sees EOF and appears to hang
        // for as long as the orphan lives. The suite takes two seconds; it took
        // an hour before this line.
        $process = @proc_open(
            sprintf(
                '%s -S 127.0.0.1:%d -t %s',
                escapeshellarg(PHP_BINARY),
                $port,
                escapeshellarg(dirname(__DIR__))
            ),
            [
                1 => ['file', self::$log, 'a'],
                2 => ['file', self::$log, 'a'],
            ],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            return null;
        }

        self::$process = $process;

        // Wait for it to accept, rather than sleeping a fixed amount.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                self::$port = $port;
                register_shutdown_function([self::class, 'stop']);
                return self::$port;
            }
            usleep(50_000);
        }

        self::stop();
        return null;
    }

    /** @return array{status:int, body:string}|null */
    public static function get(string $path, ?string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) suite'): ?array
    {
        $port = self::port();
        if ($port === null) {
            return null;
        }

        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5);
        if ($socket === false) {
            return null;
        }

        $request = "GET $path HTTP/1.0\r\nHost: 127.0.0.1:$port\r\n";
        if ($userAgent !== null) {
            $request .= "User-Agent: $userAgent\r\n";
        }
        $request .= "Connection: close\r\n\r\n";

        fwrite($socket, $request);
        stream_set_timeout($socket, 30);

        $response = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
        }
        fclose($socket);

        $split  = strpos($response, "\r\n\r\n");
        $head   = $split === false ? $response : substr($response, 0, $split);
        $body   = $split === false ? '' : substr($response, $split + 4);
        $status = 0;
        if (preg_match('#^HTTP/1\.[01] (\d{3})#', $head, $m) === 1) {
            $status = (int) $m[1];
        }

        return ['status' => $status, 'body' => $body];
    }

    public static function stop(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
        if (self::$log !== null && file_exists(self::$log)) {
            unlink(self::$log);
            self::$log = null;
        }
    }

    private static function freePort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            return null;
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
        return $port > 0 ? $port : null;
    }
}

/** True if db/questions.sqlite3 has grade-12 questions in it. */
function bankIsSeeded(): bool
{
    static $seeded = null;
    if ($seeded !== null) {
        return $seeded;
    }

    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'questions.sqlite3';
    if (!file_exists($path)) {
        return $seeded = false;
    }

    try {
        // Read-only, so a suite run can never damage a real question bank.
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $n   = (int) $pdo->query('SELECT COUNT(*) FROM shortAnswerQuestions WHERE grade = 12')->fetchColumn();
        return $seeded = $n > 0;
    } catch (Throwable) {
        return $seeded = false;
    }
}

if (DevServer::port() === null) {
    Harness::test('the built-in server could not be started', function () {
        Harness::skip('no port available — the end-to-end tests did not run');
    });
    return;
}

// ── refusals, which need no question bank ────────────────────────────────────

Harness::test('every input that used to be a fatal error is now a 400', function () {
    $cases = [
        '/question/?grade=12&fullMarks=20&topics=1.1&short=abc'  => 'non-numeric short',
        '/question/?grade=abc&fullMarks=30&topics=1.1'           => 'non-numeric grade',
        '/question/?grade=12&fullMarks=30&topics%5B0%5D%5B%5D=1' => 'nested topics array',
        '/question/?grade=1e15&fullMarks=30&topics=1.1'          => 'runaway grade',
        '/question/?grade=12&fullMarks=20&topics=1.1&mcq=3.5'    => 'fractional mcq',
        '/question/?grade=12&fullMarks=20&topics=1.1&mcq=0&short=6' => 'short not a multiple of 5',
        '/question/?grade=12&fullMarks=20&topics=1.1&mcq=4&short=16' => 'a 24-mark 20-mark paper',
        '/question/?grade=12&fullMarks=30&topics='               => 'empty topics',
        '/question/?grade=12&fullMarks=25&topics=1.1'            => 'a paper that does not exist',
        '/question/?grade=13&fullMarks=30&topics=1.1'            => 'a grade that does not exist',
        '/question/?grade=12&fullMarks=30&topics=04.2'           => 'a leading zero in a topic',
        '/question/?grade=12&fullMarks=20&topics=1.1&mcq=-1&short=20' => 'negative mcq',
        '/question/?grade=12&fullMarks=20&topics=1.1&mcq=1000000' => 'a million questions',
        '/question/?fullMarks=30&topics=1.1'                     => 'no grade',
        '/question/?grade=12&topics=1.1'                         => 'no fullMarks',
        '/question/?grade=12&fullMarks=30'                       => 'no topics',
    ];

    foreach ($cases as $path => $label) {
        $r = DevServer::get($path);
        Harness::same(400, $r['status'], $label);
        Harness::contains('Invalid Query', $r['body'], $label);
    }
});

Harness::test('a refusal leaks no path, no stack trace and no SQL', function () {
    foreach ([
        '/question/?grade=12&fullMarks=20&topics=1.1&short=abc',
        '/question/?grade=abc&fullMarks=30&topics=1.1',
        '/question/?grade=12&fullMarks=30&topics%5B0%5D%5B%5D=1',
        '/question/?grade=1e15&fullMarks=30&topics=1.1',
        '/question/?grade=12&fullMarks=20&topics=1.1&mcq=0&short=6',
    ] as $path) {
        $r = DevServer::get($path);
        foreach (['Fatal error', 'Stack trace', 'TypeError', 'Warning', 'Deprecated',
                  'FraGenesis' . DIRECTORY_SEPARATOR, 'SQLSTATE', 'datatype mismatch',
                  'Allowed memory size', '.php on line'] as $leak) {
            Harness::notContains($leak, $r['body'], "$path leaked $leak");
        }
    }
});

Harness::test('a request with no query string at all is a 400', function () {
    $r = DevServer::get('/question/');
    Harness::same(400, $r['status']);
});

Harness::test('the runaway grade is refused quickly instead of burning four seconds', function () {
    $started = microtime(true);
    $r       = DevServer::get('/question/?grade=1e15&fullMarks=30&topics=1.1');
    $elapsed = microtime(true) - $started;

    Harness::same(400, $r['status']);
    Harness::ok($elapsed < 2.0, sprintf('took %.2fs', $elapsed));
});

Harness::test('the home page answers a request with no User-Agent without complaining', function () {
    $r = DevServer::get('/', null);
    Harness::same(200, $r['status']);
    Harness::notContains('Undefined array key', $r['body']);
    Harness::notContains('Deprecated', $r['body']);
    Harness::notContains('Warning', $r['body']);
});

Harness::test('the home page is served to a desktop browser and not to a phone', function () {
    $desktop = DevServer::get('/', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120');
    Harness::same(200, $desktop['status']);
    Harness::contains('home.js', $desktop['body']);

    $phone = DevServer::get('/', 'Mozilla/5.0 (Linux; Android 14; Pixel) Chrome/120 Mobile');
    Harness::same(200, $phone['status']);
    Harness::notContains('home.js', $phone['body']);
});

Harness::test('grade 11 gets the coming-soon page, from wherever the server was started', function () {
    // The original read it as '../pages/grade11.html', relative to the working
    // directory rather than to the file. It is an absolute path now.
    $r = DevServer::get('/question/?grade=11&fullMarks=50&topics=1.1.1');
    Harness::same(200, $r['status']);
    Harness::contains('Coming Soon', $r['body']);
    Harness::notContains('Warning', $r['body']);
});

// ── generated papers, which need the question bank ───────────────────────────

if (!bankIsSeeded()) {
    Harness::test('db/questions.sqlite3 has no grade-12 questions', function () {
        Harness::skip('run `cd seed && php index.php` to exercise the generated papers');
    });
    return;
}

Harness::test('the three papers are generated and add up to their full marks', function () {
    $topics = '1.1,1.2,2.5,2.6,3.1,3.2,4.1,4.2,5.1,6.1,7.1';

    foreach ([20 => 'Unit Test', 30 => 'Internal Exam', 50 => 'Terminal Exam'] as $marks => $name) {
        $r = DevServer::get("/question/?grade=12&fullMarks=$marks&topics=$topics");

        Harness::same(200, $r['status'], $name);
        Harness::contains("<span>$name</span>", $r['body'], $name);
        Harness::contains('Grade XII', $r['body'], $name);

        preg_match_all('/class="marks">\d+x\d+=(\d+)</', $r['body'], $m);
        Harness::same($marks, array_sum(array_map('intval', $m[1])), "$name totals");
    }
});

Harness::test('a generated paper contains no PHP diagnostics', function () {
    $r = DevServer::get('/question/?grade=12&fullMarks=50&topics=1.1,2.5,3.1,4.1,5.1,6.1,7.1');
    foreach (['Warning', 'Notice', 'Deprecated', 'Fatal error', 'Stack trace', 'SQLSTATE'] as $leak) {
        Harness::notContains($leak, $r['body'], $leak);
    }
});

Harness::test('both ends of the unit-test slider produce a 20-mark paper', function () {
    // Every topic of units 1 and 2, because the 20-MCQ end needs 20 questions
    // to exist. Topics 1.1 to 1.4 hold 10, so with those a short paper is the
    // right answer; this passed only against a bank seeded twice, which has
    // every question in it twice.
    $topics = '1.1,1.2,1.3,1.4,1.5,1.6,1.7,1.8,2.1,2.2,2.5,2.6,2.7,2.9,2.10,2.12,2.13,2.14,2.15';
    foreach ([[0, 20], [5, 15], [10, 10], [15, 5], [20, 0]] as [$mcq, $short]) {
        $r = DevServer::get("/question/?grade=12&fullMarks=20&topics=$topics&mcq=$mcq&short=$short");

        Harness::same(200, $r['status'], "mcq=$mcq&short=$short");
        preg_match_all('/class="marks">\d+x\d+=(\d+)</', $r['body'], $m);
        Harness::same(20, array_sum(array_map('intval', $m[1])), "mcq=$mcq&short=$short totals");
    }
});

Harness::test('the whole grade-12 syllabus produces a terminal paper', function () {
    // What the Final Exam button on the home page sends: every topic id in the
    // grade, three-segment ones included.
    $syllabus = file_get_contents(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'syllabus.js'
    );
    $twelve = substr($syllabus, (int) strpos($syllabus, '12: {'));
    preg_match_all('/"id": "([0-9.]+)"/', $twelve, $m);

    $r = DevServer::get('/question/?grade=12&fullMarks=50&topics=' . implode(',', $m[1]));

    Harness::same(200, $r['status']);
    preg_match_all('/class="marks">\d+x\d+=(\d+)</', $r['body'], $marks);
    Harness::same(50, array_sum(array_map('intval', $marks[1])), 'full syllabus terminal paper');
});

Harness::test('the example paper from the template never reaches a generated page', function () {
    $r = DevServer::get('/question/?grade=12&fullMarks=30&topics=1.1,2.5,3.1');
    Harness::notContains('Which one of the following is an input device?', $r['body']);
    Harness::notContains('Suggest the prevention methods of cybercrime.', $r['body']);
});

Harness::test('a topic with almost no questions gives a short paper, not an error', function () {
    // 3.11 has one short-answer question in the real bank. The original answered
    // this with "Database Error: datatype mismatch" as soon as the count did not
    // divide by five; it is a 400 for that now, and a short paper otherwise.
    $r = DevServer::get('/question/?grade=12&fullMarks=20&topics=3.11&mcq=0&short=20');
    Harness::same(200, $r['status']);
    Harness::notContains('Database Error', $r['body']);
    Harness::notContains('SQLSTATE', $r['body']);
});

Harness::test('the question bank was not modified by this suite', function () {
    // Nothing above opens it for writing, and this is the assertion that says so.
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'questions.sqlite3';
    $pdo  = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    foreach (['multipleChoice', 'shortAnswerQuestions', 'longAnswerQuestions'] as $table) {
        Harness::ok(
            (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() > 0,
            "$table still has rows"
        );
    }
});
