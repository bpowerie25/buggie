# Keyboard shortcuts and the command palette

Buggie is keyboard-first, not keyboard-only: everything reachable by a key is also
reachable by mouse.

Bare keys are ignored while the caret is in a text field, so typing `c` in a comment
types a `c`. Modifier combinations work everywhere, including inside the editor.

Two-key sequences such as `g i` wait up to one second for the second key, so a stray
`g` does not lie in wait.

Press `?` anywhere for the in-app shortcut sheet, or use the keyboard icon at the
bottom of the sidebar.

## Anywhere

| Key | |
|---|---|
| `⌘K` / `Ctrl+K` | Command palette |
| `c` | New issue |
| `?` | Shortcut sheet |
| `Escape` | Close the palette or the sheet |
| `g` `i` | Issues |
| `g` `p` | Projects |
| `g` `t` | Triage inbox |
| `g` `d` | Dashboard |

## On the issue list

| Key | |
|---|---|
| `j` / `↓` | Next issue |
| `k` / `↑` | Previous issue |
| `Enter` | Open the highlighted issue |
| `/` | Focus the filter bar |
| `e` | Status |
| `a` | Assignee |
| `p` | Priority |
| `x` | Select or deselect the highlighted row |
| `Escape` | Close a popover and clear the selection |
| `g` `b` | Switch to the board |
| `g` `l` | Switch to the list |
| `g` `a` | Filter to issues assigned to me |

`e`, `a` and `p` open an inline popover on the highlighted row; they do nothing for a
client, who cannot change an issue's state.

## In the triage inbox

| Key | |
|---|---|
| `j` / `↓`, `k` / `↑` | Move between reports |
| `Enter` | Expand the console and network panel |
| `a` | Accept as an issue |
| `m` | Merge into an existing issue |
| `s` | Spam |
| `x` | Discard |
| `Escape` | Close the merge field or the expanded panel |

See [Triage](triage.md).

## Writing

| Key | |
|---|---|
| `⌘Enter` / `Ctrl+Enter` | Post the comment |
| `Escape` | Cancel an inline title edit |

## The command palette

`⌘K` opens a single search field over everything the current page knows about:

- **Jump to** — type an issue key such as `WEB-142` and open it directly.
- **Issues** — the issues currently loaded by the page, matched on key and title.
- **Views** — your [saved views](query-language.md#saved-views), opening the list with
  the query, layout and grouping restored.
- **Projects** — filters the issue list to that project.
- **Commands** — new issue, issues assigned to me, unassigned issues, switch to list,
  switch to board, triage inbox, manage labels, and toggle the light or dark theme.

The palette searches the issues the page already has, plus a direct key match. There
is no server-side search-as-you-type, so an issue that is not in the current list is
found by typing its key, or by searching from the filter bar.

## Two mismatches in the shortcut sheet

The sheet groups `g b`, `g l` and `g a` under "Go to" and `/` under "Anywhere". All
four are registered on the issue list only, and do nothing elsewhere.
