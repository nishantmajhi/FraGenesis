# FraGenesis

Generates printable question papers for NEB grade 11 and 12 Computer Science.

You pick a grade, a paper, and which topics are in scope. It draws questions
from a local SQLite bank and spreads them across the syllabus units so that no
one unit dominates the marks, then renders a paper you can print from the
browser.

The interesting part is that last step. Balancing a paper is not the same as
dealing out an equal number of questions per unit — a unit that supplies one
eight-mark question has carried more of the paper than a unit that supplies
five one-mark ones. What the generator balances is **marks**, and it does it
with an indivisible-lump constraint, which is why the algorithm is written down
rather than improvised. See [The allocator](#the-allocator).

## What it generates

Three papers, each with a fixed total:

| Paper | Full marks | Composition |
| --- | --- | --- |
| Unit test | 20 | MCQ and short answer, split by two sliders |
| Internal exam | 30 | 7×1 MCQ + 3×5 short + 1×8 long |
| Terminal exam | 50 | 9×1 MCQ + 5×5 short + 2×8 long |

Grade 11 has a syllabus but no questions imported yet, so it answers with a
"coming soon" page.

## Setup

Needs PHP 8.1 or newer with PDO SQLite, which is standard. Nothing else — no
composer, no build step, no node.

```sh
git clone https://github.com/nishantmajhi/FraGenesis.git
cd FraGenesis
```

**Seed the question bank.** The database is not in the repository; the seeder
builds it from the raw papers in `seed/data/raw/`.

```sh
cd seed
php index.php
```

That writes `db/questions.sqlite3`. `php index.php --fresh` drops every table
and re-imports from scratch, and asks for confirmation first — it will destroy
a bank you have added to by hand.

**Serve the project root.**

```sh
php -S localhost:1211
```

Any port will do. Then open <http://localhost:1211>.

## Using it

Pick a grade and a paper on the home page, tick the topics you want, and press
the button. The paper opens in a new tab with a print button on it. The
**Final Exam** button is a shortcut: the whole syllabus, as a terminal paper.

For the 20-mark unit test, two linked sliders set how the marks are divided
between MCQs and short-answer questions. They move in steps of five and always
add up to twenty, because a short-answer question is worth five marks and a
paper has to total what its heading claims.

## How a request works

Everything goes through `question/index.php` as a GET query string:

```
/question/?grade=12&fullMarks=30&topics=1.1,1.2,2.5
/question/?grade=12&fullMarks=20&topics=1.1&mcq=5&short=15
```

| Parameter | Meaning |
| --- | --- |
| `grade` | `11` or `12` |
| `fullMarks` | `20`, `30` or `50` — which paper |
| `topics` | Comma-separated topic ids, or repeated `topics[]` |
| `mcq`, `short` | 20-mark paper only: **marks**, not question counts. Must each be a whole number, `short` must divide by 5, and the two must add up to 20. Default 5 and 15. |

Anything else is a 400 with the `Invalid Query` page. That includes a grade
that is not 11 or 12, a paper size that does not exist, a topic id that is not
the shape the database stores, an empty topic list, and a marks split that does
not total the paper. The parameters are checked before any of them is used, not
as they are needed — see [Validation](#validation).

### Topic ids

The syllabus in `db/syllabus.js` is more granular than the question bank. Grade
11 offers three-segment ids like `1.1.1`; grade 12's later units do too
(`4.2.1`, `4.2.5`). The question tables store two segments, so
`groupTopicsByUnit()` normalises and deduplicates: five `4.2.x` ids become one
`4.2`, filed under unit 4.

Not every syllabus topic has questions. About twenty grade-12 topics currently
have none. Asking for one is not an error — it contributes nothing, and the
paper is filled from the other topics you selected.

## Layout

```
index.php                  Home page; sends phones to pages/premium.html
question/index.php         Validates the query, picks a paper, prints it
question/question.html     Page template, with a worked example paper in it
src/unit.php               20-mark paper: what it is made of
src/internal.php           30-mark paper
src/terminal.php           50-mark paper
src/paper.php              Everything the three have in common
utils/validateRequest.php  The query string, checked. Pure.
utils/allocate.php         The allocation algorithm. Pure.
utils/fetchQuestions.php   The allocation turned into SELECTs
utils/groupTopics.php      Topic ids to units, normalised
utils/intToRoman.php       "XII"
utils/invalidQuery.php     The 400 page
utils/osCheck.php          Desktop or not, from the User-Agent
db/syllabus.js             The syllabus the front end offers
db/questions.sqlite3       The question bank (built by seed/, not committed)
seed/                      Builds and populates the database
tests/                     The test suite
```

`src/` describes the three papers; `utils/` holds the decisions, each in a file
with no database and no request in it; `seed/` is separate because it is a
one-off command, not part of serving.

## The allocator

`utils/allocate.php` decides how many questions of each type come from each
unit. It is greedy: take the question types **largest-mark-first**, and give
each individual question to whichever unit has the lowest running mark total.

The sort order is the part that is not obvious, and it is load-bearing. Marks
come in indivisible lumps — 8, 5 and 1. Going largest-first, the eight-mark
questions land on empty units and the one-mark questions fill the gaps: a
30-mark internal paper over three units comes out **10/10/10**. Going
smallest-first, the one-mark questions spread evenly and there is nothing small
enough left to offset the eights: the same paper comes out **8/15/7**.

What the greedy can promise is bounded by the largest question, not by the
algorithm. A 30-mark paper containing one eight-mark question cannot be split
evenly over seven units; it comes out 8/5/5/5/3/2/2. Both of those numbers are
pinned in `tests/AllocateTest.php`, along with a property check over three
thousand random papers.

`utils/fetchQuestions.php` then turns the allocation into one query per unit per
type, and makes up any shortfall with a single unrestricted query over all the
selected topics. That backfill trades the balance for a paper of the requested
length, which is the right way round: a short paper is the more visible failure.

Question selection within a unit is `ORDER BY RANDOM()`, so two papers from the
same topics are different papers. That is a full scan and sort of the matching
rows — fine at a few hundred questions per table, worth revisiting if the bank
grows by an order of magnitude.

## Validation

`utils/validateRequest.php` checks every parameter against the set of values the
rest of the project already knows how to handle, rather than against a
description of the values it cannot. `fullMarks` is compared with the three
papers that exist. A topic id is matched against the shape the database stores.
`grade` is compared with the two grades there are pages for.

Every check is on the raw string, before any coercion, because PHP's coercions
are the trap: it turns `"12.9"` into `12` and `"1e3"` into `1000` without a
word, and throws a `TypeError` on `"12abc"` — and a `TypeError` is an `Error`,
not an `Exception`, so a `try`/`catch (Exception)` does not catch it and the
request dies with a stack trace and an absolute filesystem path in the response
body.

The same logic covers the table names that go into SQL.
`resolveQuestionTable()` never interpolates a name it was handed: it looks the
name up in the list of tables the database itself reports, checks the table has
the columns the query needs, and interpolates the string that came back from
that listing. There is no `..` check, no quoting and no forbidden-character
list, because none is needed.

## Tests

```sh
php tests/run.php            # everything
php tests/run.php Allocate   # one file
```

No framework and no dependencies. 109 checks over the allocator, the
validator, the topic grouping, the paper markup, the fetcher against in-memory
question banks, and the whole project over a real socket.

Two parts are worth knowing about:

- **`tests/OriginalTest.php`** runs byte-exact copies of the pre-fix code, kept
  in `tests/original/`, against a throwaway question bank in a subprocess — the
  fixed code wants the original's function names, so the two cannot share a
  process. Every bug this project has had is reproduced there first and then
  shown to be refused. Its first test looks the baseline commit up in git and
  asserts the copies still match it, so a copy that drifts fails loudly instead
  of quietly asserting nothing.
- **`tests/PaperTest.php`** compares the paper markup byte-for-byte against a
  transcription of the original three-way-duplicated builder, which is how
  consolidating those three files into `src/paper.php` was shown to change
  nothing a reader sees.

Nothing in the suite writes to `db/questions.sqlite3`. The tests that need
questions build their own bank in memory; the end-to-end file reads the real one
and skips if it has not been seeded.

## Licence

Free for personal, non-commercial use. You may use, study and modify this
software for yourself, but you may not use it commercially, and you may not
share or publish it, in its original form or modified. See [LICENSE](LICENSE)
for the full terms.
