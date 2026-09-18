# The private platform repository

buggie.eu is deployed from a **private** repository that carries this one as an
upstream remote. This repository stays AGPL and complete; the private one adds
anything that is not being given away.

The split exists so hosted-only work has somewhere to land instead of drifting into
the public tree. Today that is almost nothing — billing, plans and limits are here,
behind `BUGGIE_HOSTED`, and are useless to anyone without the Stripe keys.

## The rule that makes it cheap

**Private changes add files. They never edit them.**

Merging from upstream is close to free while the private tree only contains new
directories, new service providers, new routes and new migrations. The first time a
private commit edits `IssueController.php`, every upstream change to that file becomes
a conflict, forever.

Where the core has to behave differently, add the hook **here**, in public — an event,
a config key, a middleware slot — and consume it privately. The extension point being
public is also just better design.

## Setting it up

```sh
# 1. Create it, private, empty.
gh repo create bpowerie25/buggie-platform --private

# 2. Clone it somewhere separate from this checkout.
git clone git@github.com:bpowerie25/buggie-platform.git ~/Sites/buggie-platform
cd ~/Sites/buggie-platform

# 3. Point it at the open-source repository.
git remote add upstream https://github.com/bpowerie25/buggie.git
git fetch upstream

# 4. Take the application. The first merge brings the whole tree across.
#    --allow-unrelated-histories is needed exactly once, because the private
#    repository starts with no commits in common.
git merge upstream/main --allow-unrelated-histories
git push -u origin main
```

## Keeping it current

```sh
cd ~/Sites/buggie-platform
git fetch upstream
git merge upstream/main
git push
```

Do this before every deploy, so the hosted service is never behind the public one.

## Deploying it

`deploy/deploy.sh` takes its remote and branch from the environment, so the same
script serves both repositories:

```sh
# On the server, /srv/buggie is a clone of the PRIVATE repository.
bash deploy/deploy.sh
```

`origin` there is `buggie-platform`, so nothing extra is needed. A self-hoster cloning
the public repository gets the identical script and the identical default.

To deploy a branch other than `main`:

```sh
BUGGIE_BRANCH=staging bash deploy/deploy.sh
```

## What does not move

- **`deploy/` stays here.** The scripts are generic and they are part of the
  self-hosting story. Everything buggie.eu-specific is in `.env`, which is never
  committed.
- **Billing stays here.** Already written, already flagged, and worth nothing without
  the Stripe keys. Moving it would buy nothing and cost a permanent conflict in
  `Workspace.php`.

## The licensing condition

This arrangement is legal because one person holds the copyright on every line here,
and a copyright holder is not bound by the licence they grant everyone else.

That ends the first time somebody else contributes without assigning copyright: part
of the core would then belong to them under AGPL, and a private derivative offered
over a network would have to be published under AGPL §13. It cannot be fixed
afterwards except by finding the contributor or deleting their work.

See the contributor terms in [`CONTRIBUTING.md`](../CONTRIBUTING.md). This is
reasoning about the licence text, not legal advice; it is worth an hour of a
solicitor's time before any money depends on it.
