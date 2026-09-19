# Billing and plans

**Limits apply on the hosted service and nowhere else.** A self-hosted install runs
the `self_hosted` plan, whose every limit is unset: no plans, no seat counts, no
metering, no billing screens, no upgrade prompts and no telemetry. Not reduced
features — none. If you are running Buggie on your own server, this page does not
apply to you. See [Self-hosting](self-hosting.md).

The rest of this page describes the hosted service.

## Plans

| | Free | Studio | Agency |
|---|---|---|---|
| Projects | 3 | unlimited | unlimited |
| Members | 3 | unlimited | unlimited |
| Reports per calendar month | 100 | 2,000 | 20,000 |

Paid plans meter reports and nothing else. Projects and people cost nothing to host;
screenshots and payloads do. Capping projects would punish an agency with twenty quiet
client sites, and charging per seat would charge for inviting clients — which is the
point of the product.

A new workspace gets a **14-day trial on the Studio plan**. When the trial ends, and
with no active subscription, the workspace falls back to Free.

## Prices and terms

Every price is quoted **excluding VAT**. These are sold to businesses, which expect
ex-VAT pricing, and the tax due depends on where the customer is. Stripe Tax works it
out at checkout: Irish VAT domestically, the customer's own rate for EU consumers,
nothing for EU businesses supplying a valid VAT number under the reverse charge, and
UK or US rules for those.

| | Monthly | Annual |
|---|---|---|
| Studio | €19 | €190 |
| Agency | €49 | €490 |

Annual is **ten months' money for twelve months' service**. The saving shown on the
pricing page is worked out from the two amounts rather than written down beside them,
so changing a price cannot leave a stale "save €38" on the page.

Prices are also quoted in **GBP** and **USD**. These are local prices rather than a
conversion of the euro one — a price ending in 47 looks like arithmetic rather than a
decision. The currency and the term are both chosen by the visitor and remembered for
the session, and whichever pair they were quoted in is what Stripe charges.

Prices shown in the application are read from configuration; Stripe is the source of
truth for what is actually charged. Each plan needs its own Stripe price per currency
per term — twelve in all — and each must be created with tax behaviour **exclusive**.

## What is metered

**Reports per calendar month** is the metered quantity, because that is what scales
with usage: every report costs storage, a screenshot and a worker. Projects and
members are counted too, because they are what people compare on.

The count resets at the start of each calendar month. Reports from last month do not
count against this one.

### Members include outstanding invitations

A promised seat is a taken seat. Otherwise a workspace could invite its way past its
limit and only find out when people tried to accept, which blames the wrong person.
Re-inviting an address that already has a pending invitation does not consume a second
seat.

### What happens at a limit

- **Projects** — creating one past the limit is refused with a message naming what was
  exceeded.
- **Members** — inviting past the limit is refused the same way.
- **Reports** — the ingest endpoint answers `402` with a message **the widget shows to
  the reporter**: that this site has reached its monthly report limit and they should
  tell the team directly. The person who hit the bug did nothing wrong and should be
  told something true rather than "could not send".

A usage banner appears in-app once a workspace passes 80% of an allowance.

## Managing a subscription

**Settings → Billing** (`/settings/billing`), **owner only**. Money is the owner's
business alone — admins cannot reach this screen, and self-hosted installs answer 404
for it.

The screen shows the current plan, usage against each limit, the trial end date, the
card on file, and the other plans. From it you can:

- **Subscribe or change plan** — hands off to Stripe Checkout rather than handling card
  details in Buggie.
- **Manage payment details and invoices** — opens Stripe's own billing portal.
- **Cancel** — takes effect at the end of the current billing period, not immediately.
- **Resume** — available while a cancelled subscription is still in its grace period.

A plan with no Stripe price configured cannot be subscribed to, and the screen says so
rather than showing a checkout button that cannot work. The price and its period are
still shown — what something costs is a separate question from whether it can be
bought this minute.

Switching the screen between monthly and annual reloads it, because the prices are
rendered from configuration on the server. The term is sent with the button you press
rather than read from the session, so what you are charged is what the card you
clicked said.

## Known rough edges

- There is no dunning: a failed payment downgrades entitlements at the next webhook
  with no warning email.
- There is no usage export or invoice history beyond Stripe's own portal.

## Related pages

- [Self-hosting](self-hosting.md)
- [The reporter widget](widget.md) — where the `402` is displayed

## What counts as a report

One submission into the triage inbox: someone using the reporter widget, a native
SDK call, or an email to a project's address. Issues you or a client create inside
Buggie are not reports and are never metered.

The count runs from the 1st of the calendar month and resets on the 1st.

### Duplicates are mostly free

Reports of a bug already seen this month stop being metered after the first few.
Past that point the report keeps its error and its page — enough to count as another
occurrence — and drops the console, the network table and the screenshot. Nobody
needs the sixth screenshot of the same broken button, so it is not stored, and what
is not stored is not charged for.

Two consequences worth knowing:

- **Forty people hitting one broken checkout costs a handful of reports, not forty.**
  That is the same promise triage makes, kept in the billing as well.
- **One bug going round cannot switch reporting off.** Running out of allowance stops
  new bugs being accepted, not further reports of a bug already known.

A report with no error attached always counts. There is nothing to group it by, so a
person reads it individually, and that is real work however many arrive.
