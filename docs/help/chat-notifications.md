# Slack and Teams

Post a readable message into a channel when something happens, so the team finds out
where they already are rather than by opening Buggie.

**Settings → Workspace → Slack and Teams.**

This is the same event vocabulary as [Webhooks](webhooks.md), formatted for people
instead of for a program. If you want the JSON, you want a webhook. If you want
somebody to glance at their phone and know a customer's checkout is broken, you want
this.

## Getting an address

Both services work the same way: they give you a URL, and anything that posts to that
URL appears in the channel.

**Slack.** Create an app at api.slack.com, turn on *Incoming Webhooks*, then *Add New
Webhook to Workspace* and pick the channel. Copy the `https://hooks.slack.com/services/…`
address.

**Teams.** In the channel, *Workflows* → *Post to a channel when a webhook request is
received*. Copy the address it gives you when the flow is saved. If you have an older
*Incoming Webhook* connector, that works too — Buggie sends whichever card format the
address expects, because Microsoft is retiring the connectors and the two formats are
not interchangeable.

There is no "Sign in with Slack" button, and there will not be one soon. An OAuth
Slack app has to be registered and reviewed before anybody outside the developer's own
workspace can install it, and that is a process rather than an afternoon. Pasting a
URL needs neither.

## The address is a password

Anyone holding that URL can post into your channel as you. There is nothing else to
present — no key, no account, no second step.

So Buggie treats it as a credential: it is **encrypted in the database**, and it is
**never sent back to the browser**, the same as the SMTP password on the instance
settings page. The channel's row shows its name and nothing else. To change the
address, paste a new one; leave the field blank and the stored one is kept.

If a URL leaks, revoke it at Slack or Teams. Deleting the channel here stops Buggie
using it, but does not stop anybody else who has a copy.

## What you can be told about

The same five events as webhooks: an issue is created, an issue changes, an issue is
closed, somebody comments, a report arrives from the widget.

A channel watches **one project or all of them**. One client, one channel is the
arrangement worth setting up, and the reason is in the next section.

## What is in a message, and what is not

A message carries the issue key and title, the project, the status, the priority, the
assignee and a link.

It never carries the **description of an issue** or the **text of a comment**. Not for
internal ones, not for public ones, not ever.

That is a deliberate decision and it is worth explaining, because Buggie could send
them and other trackers do.

An issue marked internal is hidden from clients inside Buggie by three separate gates:
workspace membership, a grant on the project, and the issue's own visibility. A chat
channel has none of those. Buggie is handed a URL and has no way to see who is in the
room on the other end — whether the channel is private, whether a client was invited
into it last week, whether it is mirrored into somebody else's Slack by a connector we
know nothing about. Sending a body into that is a guess, and the thing being guessed
about is a customer's confidential note about another customer.

Titles are sent, because a notification with no subject is not a notification. Treat
the title of an issue as something the channel may see, and put anything that must not
be read by the wrong person in the body, where it stays.

**Internal notes** are announced only where you have ticked *This channel is the
team's own*. Left unticked — the default — a private note produces no message at all,
which is how webhooks behave. Ticked, the channel is told that somebody left an
internal note on an issue. Still not what they wrote.

## Test

The **Test** button posts a real message immediately and, if the service refuses it,
shows you what the service said rather than "could not send".

This matters more than it sounds. `invalid_token`, `no_service` and
`channel_not_found` are three different problems with three different fixes, and
without them the only symptom is a channel that is quiet — which looks exactly like a
week with no bugs.

## When it fails

Messages are queued, so a slow or broken channel never slows down the person who just
closed an issue. A failed delivery is retried after about a minute, ten minutes and an
hour: briefly down, down for lunch, down.

The last few attempts are listed under the channel with the status code and whatever
the service said, so "it stopped working" has something to look at.

## Addresses that are refused

Only public `http` and `https` addresses can be posted to. Anything resolving to a
private or internal network is refused — `127.0.0.1`, `10.x`, `192.168.x`,
`169.254.169.254` and the rest — and the address is resolved and checked again at the
moment of sending, not only when it was saved.

The reasoning is the same as for [webhooks](webhooks.md#addresses-that-are-refused):
the address is supplied by you and fetched by *our* server from inside our network, so
without the check it could be pointed at a cloud metadata service or used to map what
we can reach. A name that resolved somewhere public yesterday can resolve somewhere
else today.

## Known rough edges

- Messages are one-way. You cannot close an issue from Slack, and there are no
  buttons on the card.
- There is no threading. Ten comments on one issue are ten messages, not ten replies
  under the first.
- Nothing is batched. A bulk edit that touches forty issues posts forty messages, so
  put a bulk-heavy project on `issue.closed` rather than `issue.updated`.
- No Slack OAuth app, so no channel picker and no `/buggie` command — you paste a URL
  per channel.
- Teams renders whichever card format your address expects, chosen from the host name.
  An address from somewhere unexpected gets the newer Adaptive Card, which a very old
  Teams client may show as an empty message.
- The event list is shared with webhooks. There is no "only urgent issues" filter; use
  a per-project channel instead.

## Related pages

- [Webhooks](webhooks.md) — the same events as JSON, signed, for a program to read.
- [Notifications](notifications.md) — what gets emailed, to whom, and when.
- [Clients](clients.md) — what a client can and cannot see inside Buggie.
