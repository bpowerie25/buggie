# Email

Buggie sends mail (invitations, notification digests, reporter portal links) and, if
you configure it, accepts mail: a project can be written to directly, and a reply to a
notification becomes a comment.

Incoming mail uses **Mailgun inbound routes** — a webhook at `/api/mail/inbound` —
rather than polling a mailbox. A webhook beats a cron poll on both latency and failure
modes.

## Filing an issue by email

Each project has its own inbound address:

```
bugs+{project token}@{your inbound domain}
```

Writing to it creates an issue in that project. The subject becomes the title
(trimmed to 200 characters, or "Emailed report" if there is none) and the body becomes
the description, with a line recording the address it came from.

The project token is random rather than derived from the project slug, so guessing one
project's address does not reveal another's.

> **Worth knowing:** the address is not yet shown anywhere in the interface. The token
> exists on every project from the moment it is created, but reading it currently needs
> database access. Until that is surfaced, email-in is practical only for an operator
> who can look the token up.

Who wrote the message decides what the issue looks like:

- From somebody with an account **in that workspace**: the issue is attributed to them
  and created **visible to clients**.
- From a stranger: the issue is created **internal**, so a person triages it before a
  client can see it.

## Replying to a notification

Notification digests set `Reply-To` to an address of the form:

```
reply+{ISSUE-KEY}.{project token}@{your inbound domain}
```

Replying adds a comment to that issue, and the loop closes. The reply token pairs the
issue key *with* the project token, so a valid key cannot be aimed at a different
project.

**Who wrote it decides where it lands.** A staff member replying by email writes an
**internal note**, matching what the in-app composer defaults to for them. Everybody
else writes in **public**, attributed to their address. See
[Clients](clients.md#internal-notes).

### What is stored

An email reply is mostly not the reply: it is the reply, then the quoted thread, then
a signature, then a legal footer. Buggie keeps only the top. It stops at the first
quote marker — `On … wrote:`, `-----Original Message-----`, a `From:` header line, a
long underscore rule, `Sent from my iPhone`, and the French and German equivalents —
and at a `--` signature delimiter, and it drops any line beginning with `>` wherever
it appears. Storing all of it makes a thread unreadable within about three messages.

An email whose body is empty after that treatment creates nothing.

## Setting it up

1. Point a Mailgun inbound route at `https://your-buggie/api/mail/inbound`.
2. Set `MAIL_INBOUND_DOMAIN` and `MAILGUN_SIGNING_KEY`.

Every request is verified against Mailgun's signature (timestamp plus token, HMAC-SHA256)
before anything in it is read, and a signature more than five minutes old is rejected
as a replay.

**With no signing key configured the endpoint accepts nothing.** Failing open here
would let anyone file issues in any workspace.

Malformed addresses, unknown project tokens and unknown issue keys are answered with a
`200`, not an error. Mailgun retries failures, and these will never succeed however
many times they are tried.

Inbound attachments are discarded: Mailgun sends them, and Buggie does not yet turn
them into issue attachments.

## Outgoing mail

Three things are sent:

- **Invitations** — see [Getting started](getting-started.md#being-invited).
- **Notification digests** — see [Notifications](notifications.md).
- **Reporter portal links**, when a named report is accepted in triage — see
  [Clients](clients.md#the-reporter-portal).

Without outgoing mail configured, nobody can be invited and no reporter can follow
their own bug. Password reset also depends on it.

There is no unsubscribe link in digest mail, and bounces are not handled.

## Related pages

- [Notifications](notifications.md)
- [Self-hosting](self-hosting.md)
