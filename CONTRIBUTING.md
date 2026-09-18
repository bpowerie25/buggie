# Contributing

## Getting set up

There is no local PHP: everything runs in Docker.

```sh
docker compose up -d
npm install && npm run dev
./bin/art migrate --seed
./bin/test
```

`./bin/art`, `./bin/composer` and `./bin/test` wrap the containerised toolchain.

## Before opening a pull request

```sh
./bin/test        # must pass
npm run types     # must be clean
npm run build     # must succeed
```

## What gets a quick yes

- A bug fix with a test that fails without it.
- Widget compatibility fixes — it runs in browsers we cannot all test in, and reports
  from the field are genuinely useful.
- Documentation that would have saved you an hour.

## What to discuss first

- New dependencies, especially in the widget. It is ~6KB gzipped and every visitor to
  every site running Buggy downloads it.
- Anything that changes the tenancy scope, the client visibility rules, or the widget's
  redaction. These have tests written adversarially on purpose; read them first.
- New configuration. If it can be inferred, infer it.

## Things worth knowing

`AGENTS.md` is the working guide to the codebase — the invariants and the traps. A few
that catch people:

- `workspace_id` is never mass-assignable. The `BelongsToWorkspace` trait stamps it.
- Statuses are renameable per project but map to a fixed `StatusCategory`. Never test a
  status by its name.
- Comments and activity events default to **internal**. Something a client can see is a
  decision, never an accident.
- The widget's redaction happens in the DOM before rasterising, not by painting over the
  canvas afterwards. The earlier approach put the masks on the wrong elements.

`docs/DESIGN.md` records why things are the way they are, including the mistakes.

## Licence

Contributions are accepted under the AGPL-3.0, the same licence as the project.
