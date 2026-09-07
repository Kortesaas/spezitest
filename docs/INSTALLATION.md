# Complete installation manual

This repository contains everything non-secret required to recreate the full
Spezitest beta on another computer or a fresh server:

- application source and locked Composer dependencies;
- all tracked database migrations;
- the portable reviewed refresh plan and its approved source hashes;
- the reviewed data-only seed for 196 drinks, 125 completed tests, 375 raw
  ratings, and 5 Testabende;
- all 195 referenced private images: 186 optimized WebPs and nine retained
  JPEG/PNG fallbacks; and
- safe environment templates without credentials.

The source Excel workbooks are not required for installation. They remain
migration/provenance inputs and contain no runtime authority. Real database and
admin credentials are deliberately absent and must be created per environment.

## Verify a checkout

Requirements: PHP 8.3 with PDO MySQL and Fileinfo, Composer 2, and MariaDB
10.11. No Node.js is required.

```sh
composer install
composer initial-data:verify
composer check
```

`initial-data:verify` checks the exact reviewed refresh-plan hash, SQL seed hash,
and every image's hash, MIME type, and dimensions. Expected totals are 195
images and the documented 196/125/375 data counts. `.gitattributes` forces LF
line endings for these integrity-checked files, including on Windows; do not
copy or save the generated SQL through a text-mode converter.

## Install the full dataset on another development computer

1. Clone or copy the repository and run `composer install`.
2. Create an empty MariaDB database using `utf8mb4` and
   `utf8mb4_unicode_ci`. Use a dedicated local database user.
3. Copy `.env.example` to `.env`, set `APP_ENV=local`, and fill the local
   `DB_*` values. Never commit `.env`.
4. Create a password hash with PHP's secure API and set `ADMIN_USERNAME` and
   `ADMIN_PASSWORD_HASH` in `.env`:

   ```sh
   php -r "echo password_hash('CHOOSE-A-LOCAL-PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
   ```

5. Create the schema, then import the reviewed data into that empty schema:

   ```sh
   composer migrate
   mysql --default-character-set=utf8mb4 -h 127.0.0.1 -u YOUR_USER -p YOUR_DATABASE \
     < resources/initial-data/spezitest-data.sql
   ```

   A GUI such as phpMyAdmin can import the same SQL file. The seed refuses a
   non-empty application dataset and does not drop tables.

6. Install the private image files at the portable paths stored in the seed:

   ```sh
   mkdir -p var/admin-images/admin var/legacy-images
   cp -R resources/primary-images/640x1024 var/admin-images/admin/
   cp -R resources/primary-images/legacy var/legacy-images/
   ```

7. Keep these `.env` paths unless you intentionally selected equivalent private
   absolute paths:

   ```dotenv
   ADMIN_IMAGE_STORAGE_ROOT=var/admin-images
   LEGACY_IMAGE_STORAGE_ROOT=var/legacy-images
   ```

8. Start the local application:

   ```sh
   composer dev
   ```

   Verify `/` reports 125 tested Spezis, `/spezis` reports 196 results, a
   product image responds as WebP, and `/admin` redirects to the login form.

## Build portable deployment archives

From a verified checkout, run:

```sh
composer build:full-release
```

This produces two ignored artifacts under `dist/`:

- `spezitest-<version>.tar.gz`: application plus production dependencies; and
- `spezitest-initial-data-<version>.tar.gz`: data-only SQL, both installation
  manuals, reviewed refresh plan, integrity manifests, and all private image
  files already arranged beneath `var/`.

The data archive can be built without a database because its reviewed inputs
are tracked. `composer initial-data:export` is only for deliberately refreshing
the tracked seed from the verified non-production database; it refuses a
production environment.

The build prints a SHA-256 for each archive. After extracting the data archive,
run `shasum -a 256 -c MANIFEST.txt` from its top-level directory when shell
access is available; on Plesk without shell access, compare both archive hashes
before upload and after download using a local computer.

## Install on Plesk production

Use the complete reviewed procedure in [DEPLOYMENT.md](DEPLOYMENT.md). In short:

1. create the dedicated empty database and least-privilege user;
2. deploy the application archive with `public/` as document root;
3. create the production `.env` from `.env.production.example`;
4. take a database and filesystem backup;
5. run the seven currently tracked migrations once via a Plesk Scheduled Task;
6. import `spezitest-data.sql` through phpMyAdmin;
7. copy the data archive's `var/` contents into the application `var/`;
8. verify HTTPS, admin authentication, generic errors, counts, ratings, and
   WebP image responses; and
9. remove uploaded archives and disable the one-off migration task.

Never import this seed into a populated database, overwrite production `.env`
or `var/` during an update, expose the app root as the document root, or run
the legacy/refresh tooling against production.
