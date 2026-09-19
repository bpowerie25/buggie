# Releases

A release groups issues so you can tell a client what changed. "Fixed in 2.4.1", and
a list to hand over rather than a summary written separately and drifting from the
work.

Releases belong to a project. Two projects may each have a 2.4.1, because everybody's
software eventually does.

## Making one

**Project settings → Releases.** A name is all it needs; `2.4.1`, `March`, `Phase 2`
— whatever you actually say out loud.

A new release is **unreleased**: planned, being worked on, not out yet. Marking it
released stamps today rather than asking for a date, because a date typed by hand is
a date somebody gets wrong. Unreleasing clears it again, for when something gets
pulled.

## Putting issues in one

On the issue itself: **Release** in the sidebar. An issue can only go into a release
of its own project — "2.4.1" is a release of one thing, and an issue on the marketing
site has no business in the mobile app's release notes.

The change is recorded in the activity feed, like every other change.

## Finding things

```
version:2.4.1           what is in that release
is:open version:2.4.1   what is left before it can ship
no:version              not planned yet
```

`no:version` is the list you work from when deciding what goes in the next one.

## The changelog

Open a release to see what is in it: shipped first, then still open. Shipped first
because a changelog is read by somebody asking what changed, not by somebody asking
what is left.

A client opening it sees the issues they could already open — their own projects,
client-visible only. A changelog is a listing, and a listing is where a leak goes
unnoticed, so it obeys the same rules as everything else.

## Deleting one

The issues survive and simply stop belonging to a release. Deleting a release never
deletes the work that was in it.
