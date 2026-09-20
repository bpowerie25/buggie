# Reporting a security problem

**Please do not open a public issue for a security problem.** A public report is a
working exploit handed to everyone running Buggie, including people who cannot patch
today.

Email **security@buggie.eu** instead. If you would rather encrypt it, say so in a first
message with no detail in it and you will get a key back.

Please include enough to reproduce it: the version or commit, whether it is a hosted or
self-hosted install, and what an attacker gets out of it. A proof of concept is welcome
and never required — a clear description of the flaw is worth more than a working
exploit somebody had to write to be believed.

## What happens next

- **Within 3 working days** you get a reply from a person saying whether it is
  reproduced, and what is understood so far.
- **Within 30 days** a fix is released, or you get a written reason why it is taking
  longer and what the plan is.
- You are credited by name in the release notes unless you ask not to be.

This is a project run by one person. Those are honest commitments rather than an
enterprise support promise, and if one slips you will be told rather than left waiting.

## What counts

Buggie is a bug tracker used by agencies to hold their clients' work, so the things
worth reporting are mostly about one tenant reaching another's data:

- Reading, writing or even confirming the existence of anything in a workspace you are
  not a member of.
- A **client** seeing anything marked internal — an issue, a comment, an activity
  event, a custom field, logged time, a chat notification.
- Escalating from client to staff, or from any role to operator.
- Reaching a report, attachment or portal link belonging to somebody else.
- Anything that lets the server be used to reach inside its own network, or a widget
  key be used against a site it was not issued for.
- Authentication: bypassing the second factor, fixing a session, replaying a code.

## What does not

- Missing security headers or a TLS configuration grade, with no exploit attached.
- Rate limits you believe are too generous, without a demonstrated impact.
- Anything requiring an attacker to already hold a workspace owner's session.
- Self-XSS, clickjacking on a page with no state-changing action, or output from an
  automated scanner nobody has read.
- Version disclosure. The source is public; the version is not a secret.

## Self-hosted installs

A self-hosted install is yours to patch. Fixes land on `main` and are described in the
release notes; there is no separate advisory feed yet, so **watch the repository** if
you run one in earnest.

## Scope

This repository, and the hosted service at **buggie.eu**. Please do not test against
buggie.eu in a way that would affect anybody else's data — no automated scanning, no
denial of service, and use your own workspace. Ask first if you are unsure; permission
is usually a short email away.
