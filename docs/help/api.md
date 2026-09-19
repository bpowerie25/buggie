# The API

A token-authenticated HTTP API for scripting against a workspace: listing and filing
issues, reading projects, and updating what a script has found.

Everything here obeys the same rules as the screens. A client's token sees exactly
what that client sees in the application, and not one issue more — the API reuses the
same policies rather than implementing its own idea of what is allowed.

## Getting a token

**Settings → Workspace → API tokens.** Give it a name and choose what it may do:

| Ability | Allows |
| --- | --- |
| `read` | Listing and reading issues and projects |
| `write` | Creating and updating issues |

The token is shown **once**, on the screen that creates it. It is stored hashed, so
nobody — including you — can read it back afterwards. Lose it and you make another.

Tokens are staff-only. They belong to the person who made them; nobody else can see
or revoke them.

### A token belongs to one workspace

A token made in `acme` works in `acme` and nowhere else, even in another workspace you
are a member of. Presented elsewhere it returns 404, not 403 — it should not be able
to find out which other workspaces exist.

It also stops working the moment its owner leaves the workspace. Revoking somebody's
access should not leave a key of theirs in the door.

## Calling it

The API lives on the workspace's own subdomain:

```sh
curl -H "Authorization: Bearer <token>" \
     -H "Accept: application/json" \
     https://acme.buggie.eu/api/v1/issues
```

### Endpoints

| Method | Path | Ability |
| --- | --- | --- |
| `GET` | `/api/v1/issues` | `read` |
| `GET` | `/api/v1/issues/{key}` | `read` |
| `POST` | `/api/v1/issues` | `write` |
| `PATCH` | `/api/v1/issues/{key}` | `write` |
| `GET` | `/api/v1/projects` | `read` |

### Filtering

`GET /api/v1/issues` takes `q`, the same [query language](query-language.md) the
filter bar uses:

```sh
curl -G https://acme.buggie.eu/api/v1/issues \
     -H "Authorization: Bearer <token>" \
     --data-urlencode "q=is:open project:web -label:wontfix"
```

The response echoes the query back canonicalised under `meta.query`, so a script can
see how its filter was understood rather than guessing.

Paginate with `page` and `per_page` (50 by default, 100 at most).

### Filing an issue

```sh
curl -X POST https://acme.buggie.eu/api/v1/issues \
     -H "Authorization: Bearer <token>" \
     -H "Content-Type: application/json" \
     -d '{"project_id": 1, "title": "Checkout fails on Safari", "type": "bug"}'
```

Returns `201` and the created issue, key included.

## Limits and errors

Requests are limited to 120 a minute **per token**, so one noisy script does not
throttle a colleague working from the same office.

| Status | Means |
| --- | --- |
| `401` | No token, an expired token, or one that has been revoked |
| `403` | The token lacks the ability for this call |
| `404` | Wrong workspace, or something you cannot see |
| `422` | The request was understood and refused; see `errors` |
| `429` | Too many requests |

A `404` on something you believe exists usually means the token is for a different
workspace.
