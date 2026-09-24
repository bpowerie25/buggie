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
- **Joining by email domain.** A workspace could name a domain — anybody with an
  `@kennco.ie` address joins the Kennco workspace without waiting for approval. It
  needs email verification first, since without it the domain is only a claim, which is
  why it waits behind the item above. Until then, *Request access* covers it with a
  person in the loop.

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
- **Where the line between the open project and the hosted service sits.** Today there
  is no line: buggie.eu runs the same image you would install, and the difference is
  that somebody else runs Postgres, mail and backups. Whether some future
  enterprise-shaped feature — single sign-on, audit logging, retention policies —
  arrives only in the hosted service is genuinely open. What is not open is taking
  anything away: the published project is AGPL-3.0 and what is in it stays in it.
- **Whether anything about security could ever be a paid feature.** The instinct here
  is firmly no, on the grounds that charging for security sells the absence of it, and
  two-factor is in the free plan and the self-hosted build because of it. It is listed
  as undecided rather than settled because it is entangled with the question above.

## How this gets decided

By what agencies running client work actually need, which is mostly discovered by
running it. The author uses Buggie for Buggie, including for feature work rather than
only bugs, and that is the main source of the list above.

If you want something on it, open an issue describing the problem rather than the
feature. A good description of what is painful is worth more than a specification, and
it survives disagreement about the solution.

See [`CONTRIBUTING.md`](CONTRIBUTING.md) before sending code, and
[`SECURITY.md`](SECURITY.md) instead of an issue for anything exploitable.
