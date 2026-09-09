# CI/CD — GitHub Actions + FTPS deploy to Plesk

This repository is public and runs on **GitHub Free**. Two workflows live in
`.github/workflows/`:

| Workflow | File | Runs when | Does |
| --- | --- | --- | --- |
| **CI** | `ci.yml` | every pull request; every push to `main`; manual | Composer install, `composer check` (PHPStan max + unit tests + initial-data verify), integration tests against a MariaDB 10.11 service container, and the Python legacy-import tests. Fails fast. |
| **Deploy to production** | `deploy.yml` | manually once to **adopt** the live server; then automatically after **CI succeeds on `main`**; manually again for rollback / migration releases | Builds the existing production artifact (`tools/build-release.sh`) and uploads it over **explicit FTPS** to the `spezitest-deploy` account, mirroring only the managed code directories. Never touches the database, `.env`, or `var/`. |

Nothing deploys until the secrets below are set and `main`'s branch protection
is configured.

**Production is already live and initialized** — the database, `.env`, and
`var/` images exist and the application was previously deployed by hand. The
first pipeline deploy is an **adoption** step (§4a): run once manually, it
uploads only application code and creates the `production-deployed` tag. It does
**not** create, seed, migrate, or reset the database and does **not** write
`.env` or `var/`.

> [`DEPLOYMENT.md`](DEPLOYMENT.md) is **fresh-install documentation only** — for
> standing up a brand-new empty server (create DB + user, `.env`, run
> `bin/migrate.php`, import the seed). It is **not** the CI/CD first-deploy path
> for the existing server and must never be run against it.

---

## 1. Branching / release flow

```
feature/*  ──PR──▶  CI (required)  ──merge──▶  main  ──CI green──▶  auto deploy to production
```

- `main` **is production.** Every commit on `main` that passes CI is deployed —
  except one that changes `database/migrations/*.sql`, which stops for a manual
  schema step first (see §5).
- Work happens on `feature/*` (or `fix/*`) branches and reaches `main` only
  through a reviewed pull request with green CI.
- No direct pushes to `main`. No force-pushes to `main`. `main` is never deleted.
- Rollback and migration-bearing releases use the manual "Run workflow" button
  on **Deploy to production** (see sections 5 and 6).

---

## 2. GitHub secrets to add

**Settings → Secrets and variables → Actions → New repository secret** (or, better,
scope them to the `production` *Environment* — see section 4):

| Secret | Value | Notes |
| --- | --- | --- |
| `DEPLOY_HOST` | FTP hostname only, e.g. `web01.st-srv.eu` — no scheme, no port, **no trailing newline** | Used as `ftp://<host>:<port>` with explicit TLS. Prefer a name the TLS certificate actually covers so strict validation passes. The deploy step trims surrounding whitespace and rejects anything outside `[A-Za-z0-9.-]`. |
| `DEPLOY_PORT` | `21` | Explicit FTPS (AUTH TLS) over the normal FTP control port. The deploy step trims whitespace and requires digits only. Use `990` only if the host requires *implicit* FTPS — then also see the note in section 3. |
| `DEPLOY_USERNAME` | `spezitest-deploy` | The dedicated FTP account, already rooted at the `spezitest` folder (the directory that contains `public/`). |
| `DEPLOY_PASSWORD` | the FTP account password | Passed to `lftp` via `LFTP_PASSWORD` (`--env-password`); never written to a file or command line; GitHub masks it in logs. |

Optional:

| Variable (not secret) | Value | Effect |
| --- | --- | --- |
| `DEPLOY_TLS_VERIFY` | `false` | **Discouraged.** Only if the server presents a certificate that cannot be validated (self-signed / name mismatch). TLS is still used; only the certificate check is relaxed. Leave unset for strict validation. |

No credential is ever hard-coded in a workflow or in the repo.

### Does the FTPS host/port still need confirmation?

**Yes.** Confirm before the first deploy:

- the exact **FTP hostname** to put in `DEPLOY_HOST` (domain vs. server host —
  pick the one the certificate covers);
- that the account offers **explicit FTPS on port 21** (Plesk default). If the
  host only does **implicit FTPS on 990**, set `DEPLOY_PORT=990` and change the
  connect line in `deploy.yml` from `ftp://` to `ftps://` (one word);
- that `spezitest-deploy` logs in **at the `spezitest` folder** (the level that
  contains `public/`, `src/`, `.env`, `var/`), not at `public/` and not at the
  subscription root.

---

## 3. What the deploy uploads (and what it will never touch)

The artifact is built by `tools/build-release.sh` — the **existing** release
script. It contains application code plus production Composer dependencies and
**no** tests, dev tooling, Excel sources, secrets, or `.env`.

The deploy mirrors **only these six directories**, each in isolation, and prunes
stale files **inside that directory only**:

```
public/   src/   config/   bin/   database/   vendor/
```

Plus four individual top-level files, uploaded but never used to delete anything:

```
composer.json   composer.lock   README.md   .env.production.example
```

Because each mirror is scoped to one managed directory, the deploy **cannot see
or delete** anything else in the account. It never enumerates the account root,
so the following are structurally safe — not merely "excluded":

- **`.env`** (production configuration / secrets)
- **`var/`** — `var/admin-images/`, `var/legacy-images/`, `var/tile-cache/`,
  and everything else generated at runtime
- admin-uploaded images, imported legacy images, the map tile cache
- any other file the operator placed in the account

> Note: `public/robots.txt` and `public/.htaccess` are code and *are* part of
> the mirror. If you hand-edit `robots.txt` on the server for a soft launch
> (per `DEPLOYMENT.md` §11), the next deploy restores the committed version.

> Implicit-FTPS hosts: `lftp` with `ftp:ssl-force true` refuses a plaintext
> session, so a misconfigured port fails loudly instead of downgrading.

### One deployment implementation

All FTPS logic is in **`tools/deploy/lftp-deploy.sh`** — the `deploy.yml` step
just runs `bash tools/deploy/lftp-deploy.sh` then writes `UPLOAD_OK=1`. The
static check `tools/ci/check-deploy-workflow.sh` (a step in the single CI job)
renders the same helper with `--print-script` and asserts its shape.

The helper trims and character-whitelists `DEPLOY_HOST` (`[A-Za-z0-9.-]`),
`DEPLOY_PORT` (digits), `DEPLOY_USERNAME` (`[A-Za-z0-9._@-]`) and
`DEPLOY_TLS_VERIFY` (`true`/`false`), generates the lftp script, and runs it as
**`lftp --norc -f "$script"`** — script mode only, nothing on the command line.
Generated script:

```
set cmd:fail-exit yes
set ftp:ssl-force true          # explicit FTPS; a plaintext session is refused
set ftp:ssl-protect-data true
set ftp:ssl-protect-list true
set ssl:verify-certificate true # strict; DEPLOY_TLS_VERIFY=false relaxes only this
open -u "<username>" --env-password "ftp://<host>:<port>"
pwd                             # TLS/auth check; does not list or mirror the root
echo == FTPS session established - starting uploads ==   # plain text: no ';' '&&' '||'
mirror -R --delete --no-perms --exclude-glob .DS_Store "deploy-root/<dir>/" "./<dir>/"   # x6
put -O "./" "deploy-root/<file>"                                                          # x4
echo == all uploads completed ==
bye
```

Every token is a fixed literal or one of the whitelisted values, so no line can
gain a quote, space, newline, or lftp separator. The password is read from
`$LFTP_PASSWORD` via `--env-password` and never appears in the script, on a
command line, or in logs. The helper exits non-zero on the first failed lftp
command (`cmd:fail-exit`); `deploy.yml` writes `UPLOAD_OK=1` only after it
returns 0, and the `production-deployed` tag step + summary step are gated on
`success() && env.UPLOAD_OK == '1'`, so a partial upload can never advance the
tag.

> **Troubleshooting history:**
> - `Unknown command ':<port>'` — a `DEPLOY_HOST` secret with a trailing newline
>   split the lftp URL. The helper trims + validates host/port.
> - `open: invalid option -- 'f'` — `lftp -f <file>` cannot be combined with
>   `-u`/`--env-password`/a site arg. `open` is inside the script; the call is
>   `lftp --norc -f`.
> - `Unknown command 'starting'` — a `;` in the `echo` status line is an lftp
>   command separator. Status text is now separator-free; the static check
>   rejects `;` / `&&` / `||` in any generated line.

---

## 4. Triggers (exact)

**CI (`ci.yml`)**

- `pull_request` — any branch, any base.
- `push` to `main`.
- `workflow_dispatch` — manual, for convenience.
- Concurrency: one run per ref; a newer push cancels the older run.

**Deploy (`deploy.yml`)**

- `workflow_run` — `workflows: ["CI"]`, `types: [completed]`,
  `branches: [main]`. The job runs only if
  `workflow_run.conclusion == 'success'` **and** `head_branch == 'main'`.
- `workflow_dispatch` — manual, with inputs:
  - `ref` — commit SHA or tag to deploy. Empty = current `main`. An older
    known-good SHA/tag here performs a **rollback**.
  - `migrations_ack` (boolean) — set **true** only *after* you have already
    applied and verified the production schema change by hand (see §5). It
    authorises the **code** deployment; the workflow itself runs no migration.
- Concurrency: `group: production-deploy`, `cancel-in-progress: false` — deploys
  **never overlap**; a second one queues.
- `environment: production` — attach required reviewers here for a manual
  approval gate, and scope the `DEPLOY_*` secrets to this environment.

### 4a. First deploy — adopting the existing production server

Production is already running. The first pipeline deploy just points the
pipeline at it. It **does not** touch the database, run migrations, import
seed/legacy data, or recreate `.env` / `var/`.

Until the `production-deployed` tag exists, an automatic (`workflow_run`) deploy
stops with an instruction summary instead of uploading. Do this once:

1. **Confirm the FTP root.** The `spezitest-deploy` account must log in **at the
   existing `spezitest/` application directory** — the one that already contains
   `public/`, `src/`, `.env`, and `var/` (not at `public/`, not at the
   subscription root).
2. **Add the four secrets** — `DEPLOY_HOST`, `DEPLOY_PORT`, `DEPLOY_USERNAME`,
   `DEPLOY_PASSWORD` (§2), ideally on the `production` environment.
3. **Actions → Deploy to production → Run workflow**, branch `main`, inputs left
   at defaults (`ref` empty, `migrations_ack` unchecked).
4. The run builds the artifact, uploads **only** the managed code directories
   (`public src config bin database vendor`) plus `composer.json`,
   `composer.lock`, `README.md`, `.env.production.example`, and then **creates**
   `production-deployed` on the deployed commit.
5. From then on, a successful CI run on `main` deploys automatically (subject to
   the migration check in §5).

If the deployed FTP account root or the live layout is ever wrong, fix the FTP
account — never repoint it by editing server files.

---

## 5. Database migrations

**The production database is manually managed.** GitHub Actions:

- holds **no** production database credentials;
- runs **no** SQL, and **no** `bin/migrate.php` — ever;
- exposes **no** migration endpoint.

The production schema is changed only by the owner, by hand, through the
approved Plesk process in [`DEPLOYMENT.md`](DEPLOYMENT.md) §12, **before** the
matching code is deployed.

### How the pipeline enforces that

1. After each successful deploy the workflow moves a lightweight git tag
   **`production-deployed`** to the deployed commit. (Do **not** add a tag
   protection rule for this tag — the workflow force-updates it.)
2. On the next deploy it runs
   `git diff --name-only production-deployed HEAD -- 'database/migrations/*.sql'`.
3. **No migration file changed** → deploy proceeds automatically. Normal case.
4. **A migration file was added or changed** and `migrations_ack` is not `true`
   → **the deploy stops before uploading anything** and the run summary reports
   that a manual production DB/schema operation is required. The owner then, in
   this order:

   1. **Creates a production DB backup** — Plesk Backup Manager (Databases)
      *and* a phpMyAdmin export.
   2. **Reviews and runs the migration** through the approved manual process —
      Plesk → Scheduled Tasks → Run now, one-off:
      `/opt/plesk/php/8.3/bin/php <app-root>/bin/migrate.php`; then disables the
      task.
   3. **Verifies the production database** — the expected `Applied migration:`
      line(s), plus the `DEPLOYMENT.md` §10 checks covering the new schema.
   4. **Only after that**, re-runs **Deploy to production** manually with
      `ref` = that commit and `migrations_ack = true`.

5. **`migrations_ack = true` means one thing only:** *"I have already applied
   and verified the production schema change manually — allow CODE deployment."*
   It triggers no database action in the workflow.
6. First deploy (no `production-deployed` tag yet): there is no baseline to diff
   against, so **no migration or seed requirement is implied**. The auto path
   stops and asks for the one-time manual adoption deploy (§4a); the existing
   production database, `.env`, and `var/` are left untouched. The diff check in
   step 2 applies from the second deploy onward.

This ordering is deliberate: the schema is always migrated and verified on a
fresh backup *before* the code that depends on it goes live. Any future
automation must preserve that order and the "owner backs up first, workflow
holds no DB credentials" rule.

---

## 6. Rollback

FTPS hosting has no server-side release history, so rollback = **re-deploy an
older known-good commit** through the same safe path:

1. **Actions → Deploy to production → Run workflow.**
2. `ref` = the last good commit SHA or a tag (e.g. a previous
   `production-deployed` value — check the tag's history, or the deploy run
   summaries, which record every deployed ref).
3. Leave `migrations_ack` unset.
4. Run it. The workflow rebuilds that commit's artifact and mirrors it; stale
   files added by the bad release are pruned from the managed directories.
   `.env` and `var/` are untouched.

Notes:

- Migrations are **forward-only**. If the bad release added a migration that was
  already applied to production, rolling the *code* back is fine only if the old
  code tolerates the new column/table (usually it does — additive changes). If
  it does not, restore the database from the pre-migration backup as well
  (`DEPLOYMENT.md` §7).
- Never hand-edit files on the server to roll back — always go through the
  workflow so the tree stays consistent.
- For a fast emergency freeze, disable the **Deploy to production** workflow
  (Actions tab → workflow → ⋯ → Disable) so a new `main` push cannot deploy
  while you investigate.

---

## 7. GitHub settings to configure manually

No authenticated GitHub tooling is available from this environment, so configure
these in the web UI.

### 7a. Branch ruleset for `main`

**Settings → Rules → Rulesets → New branch ruleset** (or **Settings → Branches →
Add branch protection rule**):

- **Target branches:** `main` (default branch).
- **Restrict deletions:** on.
- **Block force pushes:** on.
- **Require a pull request before merging:** on.
  - Required approvals: `1` if someone else can review; a solo maintainer can
    set `0` but keep "require a pull request" on so nothing lands on `main`
    without a PR and CI.
  - Dismiss stale approvals on new commits: on.
- **Require status checks to pass:** on.
  - Add the check named **`build-and-test`** (from the **CI** workflow).
  - Require branches to be up to date before merging: on.
- **Require linear history:** optional (recommended; keep merges fast-forward /
  squash).
- Do **not** enable "Require deployments to succeed" for `main`.
- Leave the bypass list empty (or include only yourself for emergencies).

### 7b. Tags

- Do **not** create a ruleset that protects the `production-deployed` tag; the
  deploy workflow updates it on every release.

### 7c. Actions permissions

**Settings → Actions → General:**

- Fork pull request workflows from outside collaborators: **Require approval for
  all external contributors** (default for public repos — keep it). This stops a
  fork PR from running CI, and — more importantly — from ever reaching the
  `production` environment or its secrets.
- Workflow permissions: set the repo default to **Read repository contents and
  packages permissions** (the restricted default). The workflows declare exactly
  what they need:
  - `ci.yml` — `contents: read` only.
  - `deploy.yml` — `contents: read` by default; the `deploy` job alone elevates
    to `contents: write`, used only to force-update the `production-deployed`
    marker tag. No other scope, and no database or deployment scope.

### 7d. Production environment

**Settings → Environments → New environment → `production`:**

- Add the four `DEPLOY_*` secrets here (environment-scoped rather than repo-wide).
- Optional but recommended: **Required reviewers** = you. Every production
  deploy then waits for a one-click approval in the Actions run.
- Optional: **Deployment branches and tags** → "Selected branches and tags" →
  allow `main` and the tag pattern `production-deployed` (rollback targets are
  passed as raw SHAs via `ref`, which are always allowed).

---

## 8. Local checks mirror CI

| CI step | Local equivalent |
| --- | --- |
| Deploy-workflow static checks | `sh tools/ci/check-deploy-workflow.sh` |
| `composer check` | `composer check` |
| Integration tests | `composer test:integration` (needs `.env.testing` + a `*_test` MariaDB 10.11 DB) |
| Legacy-import tests | `composer test:legacy-import` |
| Artifact build | `sh tools/build-release.sh` |

`check-deploy-workflow.sh` renders the script via
`tools/deploy/lftp-deploy.sh --print-script` and asserts: one valid `open`
command, no password in the script, no `;` / `&&` / `||` / `` ` `` / `$(` in any
generated line, mirror/put targets exactly the allowlist (no `.env` / `var/`),
explicit FTPS + strict verification, `deploy.yml` calls the shared helper (no
inline lftp), and the `production-deployed` tag + summary steps gated on
`UPLOAD_OK` (set only after a successful upload). It is a fast, network-free
step in the single CI job.

The production runtime still needs **no Node.js, Docker, or SSH** — those appear
only on the GitHub-hosted runner while building the artifact.
