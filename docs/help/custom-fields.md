# Custom fields

Extra fields on every issue in a project — a client reference, an environment, a
browser version, an internal estimate. Defined **per project**, not per workspace: an
agency's clients do not share a vocabulary, and the field that matters on a shop is
not the one that matters on an internal tool.

## Defining them

**Project → Settings → Custom fields.** Anyone who can edit the project can define
them; filling them in is an issue job and needs no special permission.

| Type | What it is |
|---|---|
| Text | A single line, up to 255 characters |
| Text area | Several lines, up to 5,000 |
| Number | Anything numeric; prose is rejected |
| Choice | A fixed list you write, one per line |
| Checkbox | Yes or no |
| Link | An `http` or `https` URL, shown as a link |
| Date | A calendar date |

Deliberately short. MantisBT offers a dozen types and most installs use three; every
extra type is another validation path and another way for a value to be a string that
lies about what it is.

## The name and the key

Each field has a **name**, which people read, and a **key**, derived from it, which
the query language, the CSV header and the API use.

**The key never changes.** Renaming "Browser" to "Browser version" leaves the key as
`browser`, because a saved view filtering on it and a spreadsheet built around the
export would otherwise break silently. If you genuinely need a different key, delete
the field and make a new one — and read the next section first.

Two fields named the same thing get distinct keys (`browser`, `browser_2`) rather
than an error.

## Who can see them

**A field is internal unless you say otherwise.** This matches the rule everywhere
else in Buggie: something a client can see is a decision, never an accident. "Internal
estimate" and "Client reference" are both plausible fields and only one of them should
ever be shown.

Tick **Clients can see it** to change that, per field.

An internal field is not merely hidden from a client's screen — it never reaches their
browser, is absent from their CSV export, is absent from their API responses, and
**cannot be filtered on**. That last one matters: filtering leaks a value as surely as
printing it, because anyone can try values and watch the result count.

## Filtering

In the issue list, the query language and any saved view:

```
field:environment=Production
field:environment=Production field:browser=Safari
-field:environment=Staging
field:client_ref
```

The last form matches issues where the field has any value at all. Values are matched
case-insensitively.

The `field:` prefix is not decoration. A project is free to name a field "type" or
"label", and a bare key would then mean different things in different workspaces, or
silently shadow the built-in filter — which is worse than being verbose.

## In the export and the API

CSV gains one column per visible field, headed `field:<key>`. Where an export spans
projects that both define `browser`, they share a column rather than producing two
half-empty ones.

The API reads and writes the same shape on an issue:

```json
{ "title": "Checkout fails", "custom_fields": { "environment": "Production" } }
```

A `PATCH` changes only the fields it names. Sending `null` clears one; leaving one out
leaves it alone.

A key matching no field is **ignored, not rejected**, so an import or an older client
sending a field somebody has since deleted does not fail outright.

## Deleting a field

Deleting a field deletes **every value recorded against it**, on every issue in the
project, permanently. There is no undo and the values are not in the export you took
last week unless you took one. The confirmation says so.

## Known rough edges

- Custom fields are not yet collected by the reporter widget, so a field cannot be
  filled in by the person reporting the bug — only by somebody working on it
  afterwards.
- A field's type can be changed after values exist, and existing values are not
  re-validated against the new type.
- There is no ordering control beyond the order fields were created in.

## Related pages

- [Projects](projects.md) — the rest of project settings.
- [The issue query language](query-language.md) — everything else you can filter on.
- [Clients](clients.md) — what a client can see, and why it defaults to nothing.
