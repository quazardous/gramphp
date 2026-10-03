# How the journal works

The README says what gramphp does for you. This page says how: the journal's
own vocabulary, the rule it applies, and how it stays beside your data
rather than replacing it.

- [Subjects, nodes, steps](#subjects-nodes-steps)
- [The step statuses](#the-step-statuses)
- [The claim rule](#the-claim-rule)
- [Your states, not the journal's](#your-states-not-the-journals)
- [Revisions and tokens](#revisions-and-tokens)
- [The history](#the-history)

## Subjects, nodes, steps

A **subject** is your id — an `int` or a `string`, returned exactly as given.
A **node** is a step of the graph. The journal holds at most one row per
pair (subject, node): a **step**. A step that has no row has not started.

## The step statuses

A closed set: the journal decides on it, so it is gramphp's, not yours.
`Quazardous\GramPHP\Status`.

| status | the step… |
|---|---|
| `running` | is held by a worker, under the token its claim issued |
| `scheduled` | failed and will be retried: it becomes claimable again at its due time |
| `done` | concluded: the work was done |
| `skipped` | concluded: an optional step was given up (by `skip`, or past its grace) |
| `omitted` | concluded: a choice took another branch, so this one will never run |
| `failed` | concluded: the work did not produce — past its retries, if it had any |

`done`, `skipped` and `omitted` **satisfy** a child: what follows may start.
`failed` satisfies no one, unless a child's edge accepts it (`on`): that is
how a compensation runs on a failure.

A wait (`Node::$wait`) and a lane (`Node::$lane`) are steps no worker claims:
`settle` concludes them.

## The claim rule

A step is claimable for a subject when:

1. **its parents are concluded** in a way it accepts — all of them, or `need`
   of them, each with a status its edge accepts;
2. **nobody holds it** — no row, or a `scheduled` row now due;
3. **no descendant has started** — the journal never goes backwards; going
   back is `forget`, on purpose.

The rule lives in `Dag::claimable()` and is evaluated in PHP, on the rows a
driver read. Drivers never re-express it in SQL; the shared contract confronts
every driver with it.

## Your states, not the journal's

Your subjects keep their own states — an order is `paid`, `shipped`,
`refunded` in your own column, in your own words. The journal sits beside
that column; it does not replace it.

A node may say which of your states it stands for, as **projections**:

```php
new Node('ship', parents: ['pay'], working: 'shipping', state: 'shipped');
```

- `working`: the state your subject carries while the step runs;
- `state`: the state it reaches once the step concludes.

Nothing in gramphp reads them to decide anything: they let your application
mirror the journal into its own column, and read back which node a state
belongs to (`NodeJournal::nodeForState()`). The graph refuses two nodes
posting the same state — the label would become ambiguous.

## Revisions and tokens

- **The revision.** Every subject carries a revision that `forget` (and
  anything else taking rows away) raises. A claim writes only if the revision
  is still the one it read: a step forgotten in between is never claimed on
  a stale reading.
- **The token.** Every claim issues a token stored on the rows it took.
  `conclude` and `fail` touch only the rows holding the token they bring: a
  worker whose lease went to another writes nothing. `token: null` is the
  operator's override, written on purpose.

## The history

Nothing is lost: every row taken away is archived, with when and why
(`Quazardous\GramPHP\Reason`).

| reason | the row was taken away by… |
|---|---|
| `forget` | `forget` — the step back, by hand |
| `release` | `release` or `expire` — a worker held it too long |
| `retry` | a failure retried: the failed attempt is archived |
| `loop` | a declared loop sending the subject back |
| `migrate` | a migration dropping the node |
| `arrival` | a lane letting a new version in: the previous pass is archived |

Two more kinds of rows are **notes**, not archived steps: `signal` (an event
received, which a wait settles on) and `lane` (what became of an arrival:
merged into the one waiting, skipped while a pass ran, dropped from a batch,
entered).

`retry` and `loop` rows are **counted**: a retry limit and a loop bound read
them, so `pruneHistory` never deletes them — a clean-up cannot reset a limit.
