# Database Environments

AfyaNova uses **one codebase with four isolated PostgreSQL databases** on the local
server. Each environment has its own database and matching `.env` file, so dev,
tests, demo and prod data never mix.

All four databases are **completely empty** — schema only, no tenants, users,
facilities or reference rows. Seed or register data on demand.

---

## Databases

| Database | Purpose | Env file | Used by |
|---|---|---|---|
| `afyanova_dev` | Day-to-day local development | `.env` | `php artisan serve` |
| `afyanova_test` | Automated test suite | `.env.testing` | `php artisan test` / `phpunit` |
| `afyanova_demo` | Disposable data for demos | `.env.demo` | demo runs / stakeholder previews |
| `afyanova_prod` | Clean production baseline (not touched locally) | `.env.prod` | future real deployment |

`DB_RAW_DATABASE` mirrors `DB_DATABASE` in each env file — it is the connection
the RLS tests (`pgsql_raw`) use and must point to the same database.

---

## `afy-db` — the easy way

`afy-db` at the repo root wraps the common cases so you don't have to remember
env overrides or which `.env` to copy:

```bash
./afy-db status            # which DB the active .env points to
./afy-db use demo          # point the active .env at afyanova_demo
./afy-db use dev           # back to development
./afy-db dev migrate       # run artisan against afyanova_dev
./afy-db demo migrate:fresh --seed
./afy-db test test         # run the test suite on afyanova_test
./afy-db prod migrate      # prod always asks before running; add --yes to skip
```

Anything written after the environment name is passed straight to `php artisan`.

---

## Switching environments manually

### Development (default)

`.env` already points to `afyanova_dev`, so normal work needs no action.

```bash
php artisan serve
```

If a server is already running, restart it after any `.env` change.

### Tests

Tests read `.env.testing` automatically; no switch is needed.

```bash
php artisan test
```

### Demo

Copy the demo env into the active config, then clear the cache:

```bash
cp .env.demo .env
php artisan config:clear
php artisan serve
```

Switch back to dev afterwards:

```bash
sed -i 's/^DB_DATABASE=.*/DB_DATABASE=afyanova_dev/; s/^DB_RAW_DATABASE=.*/DB_RAW_DATABASE=afyanova_dev/' .env
php artisan config:clear
php artisan serve
```

### Production

Keep `afyanova_prod` pristine — on a real deployment this env file lives on the
prod server, not in your working copy. Do **not** run `migrate:fresh` or manual
experiments against it locally.

---

## Migrating / seeding

Migrations create the empty schema. Seeders fill it with reference data and a
default tenant/facility/user set. Because the databases ship **empty**, nothing
inside them can be used until you migrate at least:

```bash
php artisan migrate
```

And, when you want the built-in demo dataset:

```bash
DB_DATABASE=afyanova_demo DB_RAW_DATABASE=afyanova_demo php artisan seed
```

Target one database explicitly without touching the others:

```bash
DB_DATABASE=afyanova_dev DB_RAW_DATABASE=afyanova_dev php artisan migrate:fresh --seed
DB_DATABASE=afyanova_demo DB_RAW_DATABASE=afyanova_demo php artisan migrate:fresh --seed
DB_DATABASE=afyanova_test DB_RAW_DATABASE=afyanova_test php artisan migrate:fresh --seed
```

Production is intentionally excluded from this list.

---

## Inspecting data (RLS note)

Most tables are protected by row-level security, so a plain query returns zero
rows unless the tenant context is set. Set `app.tenant_id` first:

```sql
select set_config('app.tenant_id', '<tenant-uuid>', false);
select count(*) from org_facilities;
```

Get the tenant UUID with:

```sql
select id from shared_tenants limit 1;
```

The app sets this automatically inside `Tenancy::run()`; the manual snippet is
only for psql / debugging.

---

## Files touched

- `.env` — active local config → `afyanova_dev`
- `.env.testing` — → `afyanova_test`
- `.env.demo` — → `afyanova_demo`
- `.env.prod` — → `afyanova_prod`

Exactly four environment files. The legacy `afyanovav4` database and the old
`*_test_*`/`afyanova_test_1..8` copies are unused and can be dropped manually
later if you want to reclaim disk space.


TO REMOVE RLS
sudo -u postgres psql -d afyanova_dev -f /tmp/opencode/disable_rls_dev.sql

Sorry — let me strip it back to the essential mental model. No new info, just the same thing in plain terms.

You have 4 separate databases. At any moment exactly one is active — the one named in .env. Right now that's afyanova_dev.

Whatever php artisan command you run goes to the active DB only. Not all four. The others are untouched.

So the only thing you ever need to think about is: which DB do I want to work on right now?

Want to work on dev? Do nothing — .env already points to afyanova_dev.
Want to work on demo? Run ./afy-db use demo, then work as usual. Want to go back? ./afy-db use dev.
Prefer to edit .env by hand? That's fine too — just change DB_DATABASE= and DB_RAW_DATABASE= to the DB you want and run php artisan config:clear.
The afy-db dev migrate style is just a shortcut for "run this one command against that DB without changing .env" — you never have to use it if you don't want to.

Rule of thumb: .env = the DB you're currently using. afy-db use <name> = change which one that is.

If it helps, forget the rest and start with just ./afy-db status to see where you are.

php artisan afyanova:platform-admin:bootstrap --name "Ops Admin" --email "you@example.com" --change-ref "your-ticket-or-ref"


php artisan db:seed --class=GlobalReferenceDataSeeder