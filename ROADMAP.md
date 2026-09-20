# Roadmap

What is likely next, what is deliberately not coming, and how that gets decided.

**No dates.** This is built by one person alongside client work, and a date here would
be a guess dressed as a commitment. The ordering is real; the timing is not.

## Next

Roughly in the order they are likely to happen.

- **Issue templates.** A scaffold for "steps to reproduce", and canned replies for
  triage. The smallest of these and the one that pays back fastest.
- **A public read-only tracker**, per project, optional. Bugzilla-style — somewhere to
  point people that is not an account.
- **Realtime updates.** Two people triaging the same inbox currently overwrite each
  other's sense of what is left.
- **`@mention` autocomplete.** Mentions work; finding the name does not.
- **Undo for bulk edits.** Changing forty issues is one click and no way back.
- **A cumulative flow diagram.** Kanban's actual diagnostic — where work piles up. The
  backlog-over-time data already exists on Insights, so this is a chart rather than new
  machinery.
- **Internationalisation.** The widget strings matter more than the application's: an
  agency's client's users are the ones reading them.
- **Email verification on sign-up.**

## Not planned

Saying no is more useful than a long "maybe", so these are decisions rather than
omissions. All of them are reversible if somebody makes the case.

- **Sprints, story points, burndown.** Bugs arrive when they arrive; you cannot
  sprint-plan intake. Continuous flow is the honest model for this work, and Scrum
  ceremony is how a tracker becomes a thing people avoid.
- **Configurable workflow engines.** Statuses are renameable and sit in fixed
  categories. Conditional transitions, approval gates and per-status permissions are
  most of why the older trackers feel the way they do.
- **Per-seat pricing, ever.** Clients are seats, and inviting clients is the point of
  the product. The hosted service meters reports.
- **Paywalled security.** Two-factor, audit trails and the tenancy guarantees are in
  the open-source build and stay there. Charging for security is a way of selling the
  absence of it.
- **A closed-source core.** The published project is AGPL-3.0 and everything in it
  stays that way. The hosted service is operations — backups, mail, uptime,
  somebody else's problem — not features held back.
- **Session replay.** Tempting for intake and a privacy liability nobody asked this
  project to hold.
- **An issue tracker for the whole company.** Buggie is for client work. HR tickets and
  IT support are somebody else's product.

## Undecided

Genuinely open, and worth an issue if you have a view.

- Whether time tracking should carry rates, and so produce money rather than hours.
- Whether a client should ever see a read-only version of Insights for their own
  project.
- Whether custom fields should be collectable by the reporter widget, which means
  shipping their definitions to every visitor.
- Whether "no limit" is the right default for work-in-progress limits, given that a
  limit nobody sets is a feature nobody uses.

## How this gets decided

By what agencies running client work actually need, which is mostly discovered by
running it. The author uses Buggie for Buggie, including for feature work rather than
only bugs, and that is the main source of the list above.

If you want something on it, open an issue describing the problem rather than the
feature. A good description of what is painful is worth more than a specification, and
it survives disagreement about the solution.

See [`CONTRIBUTING.md`](CONTRIBUTING.md) before sending code, and
[`SECURITY.md`](SECURITY.md) instead of an issue for anything exploitable.
