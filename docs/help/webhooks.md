# Webhooks

Call a URL when something happens, so bugs reach wherever your team already is —
Slack, Teams, or something of your own.

**Settings → Workspace → Webhooks.**

## What you can be told about

| Event | Fires when |
| --- | --- |
| `issue.created` | An issue is created |
| `issue.updated` | An issue changes |
| `issue.closed` | An issue is closed |
| `comment.created` | Somebody comments |
| `report.received` | A report arrives from the widget |

A webhook watches one project or all of them. One client, one channel is the common
arrangement.

**Internal notes are never sent.** A webhook is an outside audience, and the whole
point of an internal note is that it has none.

## What arrives

A `POST` with JSON:

```json
{
  "event": "issue.created",
  "delivered_at": "2026-09-19T14:02:11+00:00",
  "data": {
    "key": "WEB-42",
    "title": "Checkout fails on Safari",
    "status": "Todo",
    "open": true,
    "url": "https://acme.buggie.eu/issues/WEB-42"
  }
}
```

## Checking it came from us

Every delivery carries `X-Buggie-Signature`, an HMAC-SHA256 of the exact body using
the webhook's signing secret, shown when you create it:

```
X-Buggie-Signature: sha256=<hex>
```

Compute the same over the raw body and compare. Compare the raw bytes, not a
re-encoded copy — re-serialising the JSON changes it and the signatures will not
match.

## When it fails

Deliveries are retried after about a minute, ten minutes and an hour: briefly down,
down for lunch, down. The last few attempts are listed under the webhook with the
status code and any error, so "it isn't working" has something to look at.

**Test** sends a real delivery immediately, which is the fastest way to find out
whether a URL is right.

## Addresses that are refused

Only public `http` and `https` addresses can be called. Anything resolving to a
private or internal network is refused — `127.0.0.1`, `10.x`, `192.168.x`,
`169.254.169.254` and the rest.

This is not fussiness. A webhook address is supplied by you and fetched by *our*
server from inside our network, so without the check a webhook could be pointed at
the cloud metadata service or used to map what we can reach. The address is resolved
and checked again at delivery, not only when saved, because a name that pointed
somewhere public yesterday can point somewhere else today.

A host that cannot be resolved at all is refused for the same reason: from here,
there is no way to tell a typo from a name that only answers inside somebody else's
network.
