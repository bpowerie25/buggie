# Clients

Buggie is built for an agency that runs several customers out of one workspace. The
`client` role is not "a member with fewer buttons" — it is a separate visibility
plane, and everything a client can reach passes through it.

## The three gates

A client sees an issue only when **all three** of these are true:

| Gate | |
|---|---|
| 1. Workspace membership | They hold the `client` role in this workspace. |
| 2. Project grant | They have been granted that specific project. |
| 3. Issue visibility | That individual issue is marked **visible to client**. |

The third is the one people do not expect. **Holding the project is not enough.** A
project contains plenty that is not the customer's business — internal rewrites,
estimates, opinions about their legacy code — so issues are shared one at a time.

A client may hold several projects and sees the union of them. Nothing restricts a
client to one project.

## Why gate 2 matters even for names

An agency running several customers in one workspace means **one client learning
another's project name is a leak**, even when they can read none of the work. A
project a client has not been granted does not exist as far as they are concerned: not
a filtered list, not a greyed-out row, nothing.

The same reasoning applies to everything else a client can see. The dashboard, the
project list, the filter bar's project and label choices, and the saved views in the
sidebar are all filtered per role. A client is not offered the workspace's staff list
either — they cannot assign anything, so it would only be a list of names they have
not been introduced to.

## Inviting a client

**Settings → Members** (`/settings/members`), owners and admins only.

1. Enter the email address.
2. Choose the role **Client**.
3. Tick the projects they should see.

A client invitation with **no projects is refused**. It would produce someone who can
see nothing, which is a confusing way to arrive.

The invitation is emailed and expires after 14 days. Pending invitations are listed on
the same screen with their link, and can be revoked. Re-inviting the same address
refreshes the existing invitation rather than creating a second live token.

When they accept, the named projects are granted. See
[Being invited](getting-started.md#being-invited) for what that looks like from their
side.

### Changing a client's projects afterwards

There is no control for this yet. The project grants are set when the invitation is
accepted. In practice: invite the same address again with the fuller list of projects
ticked, and ask them to open the new link — accepting adds the extra projects without
disturbing their membership. Removing a project again needs database access.

Removing someone from the workspace (**Members → remove**) also removes all their
project grants. The workspace owner cannot be removed.

## Deciding what a client sees

An issue is internal by default. Make one visible with the **Visibility** control in
the right-hand column of the issue page. There is no bulk control for visibility — the
bulk bar on the issue list offers status, assignee and priority only — so sharing is
one issue at a time, which is the intended shape rather than an oversight.

Two cases where Buggie decides for you:

- An issue a **client files** is forced to client-visible and left unassigned, so they
  do not lose sight of it the moment it is created.
- An issue accepted from a report that carried an **email address** is created
  client-visible; an anonymous report becomes internal. See [Triage](triage.md).
- An issue a **reporter replies to** through the portal becomes client-visible, since
  somebody replying to their own report expects to be kept informed.

## Two kinds of client

Not every client is the same person. Usually it is whoever reported the bug.
Occasionally, at a larger organisation, it is a project manager whose job is to see
all of it.

So a grant carries a **tier**, chosen per project in **Settings → Members → Change**:

| Tier | Sees |
|---|---|
| **Their own issues** (the default) | Only what they reported, or were brought into by commenting or being mentioned |
| **All client issues** | Every issue on that project marked visible to the client |

**The default is the narrow one**, and that is deliberate. A client of an agency
usually has no business seeing what another department at their company reported, and
treating "client" as one undifferentiated role quietly assumed otherwise.

**The tier is per project, not per person.** The same account can be a manager on one
client's project and an ordinary reporter on another. The grant is the unit.

**It changes nothing about the internal half.** Gate 3 still decides whether an issue
is shared with clients at all; the tier only decides how much of the shared half one
person sees. A client manager sees no more internal work than anybody else — which is
to say, none.

Anything unrecognised in that column is read as the narrowest tier. A permission that
cannot be parsed should never fail open.

## Internal notes

Comments and activity events default to **internal**. Something a client can see is a
decision someone makes, never an accident.

On the issue page the composer starts as an *Internal note* — amber, with a lock —
and switching it to *Visible to client* puts a warning line under the button before
you post. Internal comments are visually distinct in the stream, and internal activity
events are withheld from clients entirely.

A client's own comments are always public, and there is no code path by which a client
can produce an internal note, whatever the form posts.

Staff replying to a notification **by email** also write an internal note, matching
what the in-app composer defaults to for them. Everybody else writing by email writes
in public. See [Email](email.md).

## What a client can do

- See their granted projects and the client-visible issues in them.
- Open an issue, read its public comments and public activity, and comment.
- File a new issue (it will be visible to them).
- Attach files to an issue they can see.
- Use the issue list, the board, the query language and their own saved views.

## What a client cannot do

- See the triage inbox or any raw report.
- See the labels screen, the members screen, workspace settings or billing.
- Change a status, assignee, priority, type or visibility — reading is not triaging.
- See internal comments, internal activity, or the workspace's staff list.
- See another client's projects, or even learn their names.
- See shared saved views.

A client reaching a staff-only URL directly gets an error rather than a filtered page.

## The reporter portal

Someone who filed a bug through the widget is not a user of a bug tracker. They
followed a link from an email to find out what happened. They get a page of their own
rather than an account:

```
https://buggie.eu/portal/{token}
```

It shows one issue: the title, the description, the public comments, and a reply box.
Nothing else — no navigation, no other issues, no jargon.

Two deliberate choices:

- It shows the **status category**, not the team's status name. "Closed" is honest,
  where "Won't Fix" needs explaining and reads as rude.
- The reply box can only ever produce a **public** comment. There is no path from
  there to an internal note.

The token is the whole credential, so it is long, bound to one issue, expires after 90
days, and replies are rate limited to ten per ten minutes. A link is issued when a
named report is accepted in triage, and one live link exists per reporter per issue —
re-issuing reuses it rather than creating another. Expired links are deleted 30 days
after they stop working.

The portal cannot show attachments and the reporter cannot add one.

## Related pages

- [Issues](issues.md)
- [Triage](triage.md)
- [Privacy and security](privacy-and-security.md)
