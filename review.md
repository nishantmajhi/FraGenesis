# Review — FraGenesis

First reviewed 2026-09-04. **Remediated 2026-09-19** on branch
`cleanup/2026-09-19`, not pushed. This file is now a record rather than a to-do
list: what was wrong, what was fixed, what was deliberately left, and what the
next session should know.

123 tests, 1616 assertions, 0 failures — `php tests/run.php`, about 40 seconds.

---

## Corrections to the first review

Two of its findings were wrong, and one of them would have sent the next reader
to fix something that was already correct. Both are proved by tests in
`tests/OriginalTest.php`, which runs byte-exact copies of the pre-fix code.

**Finding 4 — "the generator builds HTML by string concatenation … Run question
text through `htmlspecialchars` on output" — was already done.** Every question
and every MCQ option was already escaped in all three generators:
`src/unit.php:45,48`, `src/internal.php:49,52`, `src/terminal.php:49,52` in the
original. There was nothing to fix. The escaping is now asserted in
`tests/PaperTest.php` so nobody has to check again.

**Finding 1's second bullet was wrong in both halves.** It said:

> `?short=-50` or `?mcq=-1` — a negative count reaches the allocation loop and
> then `LIMIT -5`, which SQLite rejects.

Neither happens. `src/unit.php` guarded each question type with
`if ($noOfMCQ > 0)` / `if ($noOfShortQuestions > 0)`, so a negative count made
the whole group disappear from `$questionTypes` and no query was built for it at
all. The observable bug was a 20-mark paper silently missing Group B, which is
worse than an error page and was not what the review described. And had it
reached SQL, SQLite reads a negative `LIMIT` as **no limit** — it would have
returned every matching row rather than complaining.

The finding's conclusion — validate at the entry point — was right, and is the
main thing this pass did.

**Finding 2 was half right in a way that mattered.** It said the interpolated
`LIMIT` was "safe *today*" and would only become a problem after a future
refactor. It was already broken: the counts were never integers to begin with.
See P1.5 below.

---

## What was wrong

Eight defects. The first review found two of them.

### P1.1 — `?short=abc` was an uncaught fatal error *(review finding 1)*

`question/index.php:32` was `echo unitTest($grade, $topics, $MCQ, $shortQuestions / 5)`.
On PHP 8 `'abc' / 5` is a `TypeError`, and a `TypeError` is an `Error`, not an
`Exception` — so the `catch (Exception $e)` inside the generator did not catch
it. The response body was:

```
Fatal error: Uncaught TypeError: Unsupported operand types: string / int in
E:\Backups\code\Web\PHP\FraGenesis\question\index.php:32
Stack trace: …
```

An absolute filesystem path and a stack trace, from a query string.

### P1.2 — `?grade=abc` was the same thing, four calls further down

`fetchAllDistributed(PDO $pdo, int $grade, …)` is typed, so a non-numeric grade
threw at the parameter — inside the `try`, and again not caught, because it is an
`Error`. Not in the first review, which noted `$grade` was unvalidated but
concluded only that "a nonsense grade silently produces an empty paper".

### P1.3 — `?topics[0][]=1` was the same thing again

`utils/groupTopics.php:18` did `explode('.', trim($topic))`. A nested array
arrives from that query string, `trim(array)` is a `TypeError`, fatal, path and
trace in the body. Not in the first review.

### P1.4 — `?grade=1e15` exhausted the memory limit

`intToRoman()` appends one `M` per thousand. `'1e15'` is a numeric string, so it
passed `$num >= 1000` and asked for a string of a million million characters.
About four seconds of CPU and a fatal error per request, on a page with no rate
limit. Not in the first review.

### P1.5 — a fractional count reached a SQL `LIMIT` *(the half of finding 2 that was live)*

`mcq` went into `'count'` unmodified and `short` went in as `short / 5`, so
neither was necessarily an integer. Two consequences:

- `for ($i = 0; $i < 3.2; $i++)` runs **four** times, so the paper carried four
  questions for a request of 3.2.
- `$stillNeeded = $qt['count'] - count($fetched)` is then fractional, and went
  straight into `LIMIT $stillNeeded`. SQLite answers
  `SQLSTATE[HY000]: General error: 20 datatype mismatch`, which the page printed.

`?mcq=3.5` on a thin topic, or `?short=6`, was enough. Reproduced in
`tests/OriginalTest.php`.

### P1.6 — a 20-mark paper that totalled 24 marks

`?mcq=4&short=16` returned HTTP 200 and a paper headed `4x1=4` and `4x5=20`. The
sliders on the home page cannot produce it — they step by five and are wired so
one makes up what the other leaves — but a URL could, and the paper was wrong
rather than refused. Not in the first review.

### P2.1 — silently empty and silently short papers

- `?topics=` (which is what the front end sends when no topic box is ticked)
  became the single topic `""` via `explode(',', '')`, and produced
  `<article></article>` with a 200 and no indication anything was wrong.
- `?topics=04.2` matched nothing, because the database stores `4.2`. Same silent
  empty paper.

### P2.2 — two PHP diagnostics on any request without a `User-Agent`

`utils/osCheck.php:3` indexed `$_SERVER['HTTP_USER_AGENT']` without checking, so
a request with no such header printed `Warning: Undefined array key
"HTTP_USER_AGENT"` and a `stripos(): Passing null …` deprecation *above* the
page.

---

## What was done

### Validation, in one place, before anything is used

`utils/validateRequest.php` is new, pure, and the fix for P1.1 through P1.6 and
P2.1. It checks every parameter against the set of values the rest of the
project already knows how to handle:

- `grade` against `GRADES` — the two grades there are pages for.
- `fullMarks` against `FULL_MARKS` — the three papers `src/` can generate.
- each topic against the shape the database stores, with leading zeros refused.
- `mcq` and `short` as **marks**: each a whole number, `short` divisible by 5
  because a short-answer question is worth five, and the two summing to the
  paper's full marks. They are returned as question *counts*, so no caller
  divides anything.

Every check is on the raw string before any coercion, which is the point: PHP
turns `"12.9"` into `12` and `"1e3"` into `1000` silently and throws on
`"12abc"`. Anything refused is the existing `Invalid Query` 400 page.

`isPlainNonNegativeInt()` and `isTopicId()` are the two predicates and are
tested directly against about fifty inputs each.

### The allocator extracted and pinned

`utils/allocate.php` is new: phase one of `fetchAllDistributed()` with no
database in it. The algorithm is unchanged — this is the part of the project
worth keeping and the first review was right about that — but it can now be
tested, and `tests/AllocateTest.php` pins:

- every question asked for is allocated to exactly one unit;
- the marks allocated add up to the paper total;
- the exact balance of the papers the site actually makes (30 marks over three
  units is 10/10/10);
- that the largest-mark-first sort is what achieves it — the same greedy
  smallest-first gives 8/15/7;
- that the spread never exceeds the largest question in the paper, over 3,000
  random papers.

Two guards were added. `$numUnits < 1` now throws instead of reaching `min([])`,
which is a `ValueError` and would not have been caught. And a non-integer or
negative count throws, which is the second half of the P1.5 fix.

### The table name and the `LIMIT`, safe by construction *(review finding 2)*

`resolveQuestionTable()` never interpolates a name it was handed. It looks the
name up in the list of tables the database itself reports, checks that table has
the columns the query needs, and interpolates the string that came back from the
listing. No `..` check, no quoting of caller input, no forbidden-character list.

The column check is not decoration: `AUTOINCREMENT` creates `sqlite_sequence`, so
a listing-only allowlist would have resolved that and left the failure to the
`SELECT`.

Both `LIMIT` interpolations are `(int)` cast at the point of use, on values
`allocateByMarks()` has already guaranteed are integers.

### `src/` deduplicated *(not in the first review)*

`src/unit.php`, `src/internal.php` and `src/terminal.php` were three copies of
the same 119 lines. The HTML literals were byte-identical; only the question
types and the `<nav>` text differed. They are now 15-line descriptions of their
paper, and `src/paper.php` holds what they share.

The consolidation was proved rather than eyeballed. `tests/original/builder.php`
is a transcription of the original builder with its I/O lifted out, and
`tests/PaperTest.php` puts the same rows through both and compares the output
**byte for byte**, over eleven shapes of paper including every combination of
present and absent groups, plus a case needing escaping. `renderPaper()` is
compared against the original template substitution the same way.

`generatePaper()` also now catches `Throwable`, not `Exception`, so the class of
failure that produced P1.1 to P1.4 cannot reach the client again even if
something slips past validation.

### Smaller things

- `readfile('../pages/grade11.html')` and `readfile('home.html')` were relative
  to the working directory. They happen to work under `php -S` started in the
  project root, which is what the README says to do, but not under a server
  started anywhere else. Both are absolute now.
- `$_SERVER['HTTP_USER_AGENT'] ?? ''` fixes P2.2. `$_SERVER['REQUEST_METHOD']`
  got the same treatment.
- `$questionNumber` was incremented in all three generators and never read. Gone.
- `test/` renamed to `seed/`, which is what it is — and `.gitignore` followed it.
- `LICENSE` added (MIT), which the first review asked for.

---

## Deliberately not done

**The paper does not say when it is short.** Ask for a 20-mark unit test on topic
`3.11`, which has one short-answer question in the bank, and you get a 5-mark
paper with no note that 15 marks are missing. Twenty-odd grade-12 syllabus
topics have no questions at all. Telling the user would mean new text on the
page, and the standing constraint for this archive is business logic only — so it
is recorded here instead. It is the most likely next complaint about this
project.

**MCQ options are double-lettered.** The importer stores options already prefixed
(`"a. Create"`, `"b. Select"`) and the generator renders them inside
`<ol type="a">`, so the reader sees `a. a. Create`. Fixing it is either a
presentation change or a re-import, and both are the author's call. Worth ten
minutes with `seed/QuestionImporter.php` if the author wants it.

**Question numbering restarts in each group.** Group A runs 1–9, then Group B
restarts at 1. The template `question/question.html` shows the author's intent —
its Group C is `<ol start="6">`, continuing from Group B — and the dead
`$questionNumber` counter was the beginning of an implementation. Presentation,
so left alone, but the counter's existence is the evidence that it was meant.

**`ORDER BY RANDOM()`** *(review finding 3)*. A full scan and sort of the
matching rows. It is 136 to 544 rows per table, so it costs nothing today, and
replacing it is a real change to how papers are selected. Left, and noted in the
README.

**Grade 11 has no questions.** `db/syllabus.js` has the whole grade-11 syllabus
and the question bank has nothing for it, so grade 11 answers with the
coming-soon page. That is content, not code.

**`intToRoman(0)` returns `''`.** Nothing calls it with zero now that `grade` is
checked against `GRADES`, so inventing a return value would be a change with no
caller asking for it. Asserted as-is.

---

## Notes for the next session

**Run the suite before touching anything:** `php tests/run.php`. It needs
nothing but PHP with PDO SQLite. Nothing in it writes to
`db/questions.sqlite3` — the tests that need questions build their own in
memory, and `tests/ServerTest.php` opens the real one read-only and asserts at
the end that it still has rows. A seeded bank is not required; the paper-content
tests skip without one.

**Never run `seed/index.php --fresh` to "check the seeder".** It drops every
table. `db/questions.sqlite3` is not committed, so a bank the author has added to
by hand is not recoverable from git. This pass backed it up and compared
checksums before and after; it is byte-identical.

Three traps from this pass, all of which cost time:

**`proc_open` on Windows must be given `['bypass_shell' => true]`.** Without it
the command goes through `cmd.exe`, so `proc_terminate()` kills the shell and
leaves `php -S` running — and an orphan started that way holds the inherited
write end of whatever pipe the suite's own output is going to. `php
tests/run.php | tail` then never sees EOF and looks like a hung test for as long
as the orphan lives. `php tests/run.php > file` finished in two seconds while the
piped form of the same run took **fifty-nine minutes**, with one second of CPU.
`tests/ServerTest.php` has the fix and the explanation. This is worth checking in
any other suite in this archive that spawns a server — `Web/PHP/cscli-api`
showed the same symptom on 2026-09-19 and is the first place to look.

**PHP's `exec()` strips trailing whitespace from each line of its output array,**
so it cannot be used to compare file contents. `tests/OriginalTest.php`'s first
test — which asserts the copies in `tests/original/` still match commit
`d70b64f` — reported five bogus differences until it switched to `shell_exec()`.
Several of this project's files have trailing spaces.

**Windows `escapeshellarg()` replaces a double quote with a space** rather than
escaping it, so a JSON argument arrives with its quotes gone.
`tests/original/driver.php` takes its arguments base64-encoded for that reason.

**The pattern worth stealing from this project** is `tests/PaperTest.php`: when
you consolidate duplicated code that builds a string, transcribe the original
builder with its I/O lifted out and compare the two outputs byte for byte over
every shape of input. It turns "I read both copies and they looked the same" into
a test, and it is about thirty lines. The sibling pattern, running byte-exact
copies of the original in a subprocess because the fixed code wants the same
function names, is `Web/PHP/cscli-api`'s and is used here in
`tests/OriginalTest.php`.

**If you change the allocator,** `tests/AllocateTest.php` asserts exact mark
splits, not tolerances. That is on purpose: a change to the balance should be
looked at rather than absorbed. The 3,000-case property check is seeded
(`mt_srand(20260919)`) so a failure reproduces.

**If you change the schema,** `tests/Fixture.php` holds a copy of the seeder's
`CREATE TABLE` statements and `tests/SchemaTest.php` compares the two column for
column, so the copy cannot drift silently.

## Licence, 2026-10-02

The author chose one licence for all of their projects: free for personal,
non-commercial use, modifiable for the user's own use, and not to be
distributed, original or modified. No standard licence says that, so `LICENSE`
is a short custom text, the same in every project apart from the year and what
it excludes. The README's licence section says the same. Anything above that
calls the licence an open question, or calls the project MIT, is superseded.

## CI, 2026-10-02

`.github/workflows/ci.yml` builds `db/questions.sqlite3` from `seed/data` in
the throwaway checkout, then runs `tests/run.php` on PHP 8.4, on
`ubuntu-latest`, with full history for `OriginalTest`. Nothing has been
pushed, so it has not run on GitHub yet. Every job was run before committing,
from a fresh clone of this branch, the way a runner would run it: Linux jobs
in a throwaway Ubuntu 24.04 (the image `ubuntu-latest` uses) with the same
tool versions. 123 tests, 1616 assertions. Without the seed step a fresh clone
runs 117: the tests that use real generated papers skip.

**Fixed on the way, test code only:** "both ends of the unit-test slider
produce a 20-mark paper" asked topics 1.1–1.4 for up to 20 multiple-choice
questions. They hold 10, so the generator rightly returned 15- and 10-mark
papers, as the next test ("a topic with almost no questions gives a short
paper") expects. It passed here only because this machine's bank holds every
question twice. It now uses every topic of units 1 and 2 (24 MCQs) and passes
against both banks.

**For the author:** this machine's `db/questions.sqlite3` (dated March) was
seeded twice: 214 long-answer rows where one seeding gives 107, and 68
multiple-choice questions appear twice, so a paper generated here can print
the same question twice. The importer does plain `INSERT`s, so running `cd
seed && php index.php` on an existing bank doubles it; `--fresh` rebuilds.
Whether the importer should refuse or skip duplicates is your call, and the
local bank was left as it is.
