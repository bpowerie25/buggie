# Getting started

## Accounts and workspaces

A **user** is a global identity: one name, one email address, one password. A
**workspace** is a tenant — a company or an agency — and everything in Buggie
(projects, issues, labels, reports) belongs to exactly one workspace. One user can
belong to several workspaces, and a switcher at the top of the sidebar moves between
them.

## The subdomain model

Every workspace lives on its own subdomain of the Buggie install:

```
acme.buggie.eu          the Acme workspace
globex.buggie.eu        the Globex workspace
buggie.eu               the central domain
```

The central domain carries the marketing page, sign-in, registration, password
reset, the workspace picker, the widget bundle at `/w/{key}.js` and the reporter
portal. Nothing tenant-owned is reachable there.

This is why workspace addresses are constrained: the slug you choose becomes a DNS
label. It must be lowercase letters, digits and single inner hyphens
(`acme`, `acme-web`, not `Acme_Web`), between 2 and 40 characters, and it cannot be
one of the reserved names (`www`, `api`, `app`, `admin`, `mail`, `support`, `docs`,
`billing`, `login`, `dashboard`, `widget`, `w`, `ingest`, `demo` and a few dozen
others), which exist either because Buggie needs them or because handing them out
invites phishing.

**The address cannot be changed afterwards.** It appears in every widget snippet,
every invitation link and every bookmark your clients hold. The workspace *name* can
be changed at any time in **Settings → Workspace**.

Visiting a subdomain that does not exist gives a 404. So does visiting a workspace
you are not a member of — deliberately, because a 403 would confirm the workspace
exists and let anyone enumerate customer names.

## Signing up

1. Go to the central domain and choose **Register**. You give a name, an email
   address and a password.
2. You are then asked to create a workspace: a name and the address it will live on.
3. You land inside the new workspace as its **owner**.

Registration does not create a workspace for you automatically, and there is no
email verification step — an account works as soon as it is created.

That is how the hosted service works. **A self-hosted install is invitation-only by
default**: you register by following an invitation from a workspace, and only the
people who run the server create workspaces. Some installs let you *Request access*
from the sign-in page instead. See [Self-hosting](self-hosting.md#who-can-join).

## Roles

A role is held per workspace, not globally.

| Role | Can |
|---|---|
| **Owner** | Everything, including billing and deleting the workspace. Cannot be removed. |
| **Admin** | Manage projects, workflows, widget keys, labels, members and invitations. |
| **Member** | Every project in the workspace: file and edit issues, triage the inbox, write internal notes. Cannot invite people or create projects. |
| **Client** | Only the projects they were granted, and only the issues marked visible to them. Can comment in public and file new issues. Never sees internal notes, the triage inbox, the labels screen, or any other client's project. |

Owner, admin and member are collectively *staff*. Everything in Buggie that asks
"can this person see that?" distinguishes staff from clients rather than checking the
three staff roles individually. See [Clients](clients.md) for what a client can reach.

Only the owner can invite another owner, and only the owner can reach billing.

## Being invited

An invitation arrives by email carrying a link on the workspace's own subdomain:

```
https://acme.buggie.eu/invitations/{token}
```

Invitations expire after 14 days. Opening the link while signed out sends you to
registration on the central domain; **open the invitation link again once you have an
account or have signed in**, and the acceptance screen appears. Accepting puts you in
the workspace that issued the token, whichever domain the link was presented on.

The address the invitation was sent to is not re-checked when you accept. The token
is the credential, and people routinely sign in with a different address than the one
they were written to. Being re-invited to a workspace you are already in refreshes
your project access rather than creating a second membership.

## Signing in and out

Sign-in is on the central domain. Afterwards you are sent to the workspace you used
last, or to the picker at `/workspaces` if you belong to more than one and have no
recent one. If you belong to exactly one workspace, the picker sends you straight
there.

Sign out from the button at the bottom of the sidebar. Because signing out crosses
from a workspace subdomain back to the central domain, Buggie issues a special
redirect that tells the browser to make a fresh visit; if sign-out ever appears to do
nothing, that mechanism is what has failed.

## Forgotten passwords

**Forgot your password?** on the sign-in screen mails a reset link, built explicitly
against the central domain. A few deliberate behaviours:

- An unknown address produces exactly the same response as a known one, so the form
  cannot be used to discover who has an account.
- A reset link works once. Using it a second time fails.
- You are not signed in automatically after a reset — you type the new password once
  more, which proves it is the one you meant.
- Requests are rate limited to five per fifteen minutes for a given address, on top
  of the framework's own one-minute gap between links.

There is no "your password was changed" notification.

## Where to go next

- [Projects](projects.md) to make somewhere for issues to live.
- [The reporter widget](widget.md) to start collecting reports.
- [Clients](clients.md) to bring a customer in.
