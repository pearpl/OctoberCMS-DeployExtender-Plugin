# Deploy Extender

Extends [RainLab.Deploy](https://octobercms.com/plugin/rainlab-deploy) with bidirectional database synchronization, `storage/app/uploads` deployment, and automatic backup management.

## Features

- **Deploy Uploads** — deploy `storage/app/uploads` directory to a remote server alongside standard RainLab Deploy operations.
- **Sync Push (Local → Remote)** — push your local database and storage files to a remote server.
- **Sync Pull (Remote → Local)** — pull the remote database and storage files to your local environment.
- **Automatic Backups** — a full database backup is created before every destructive sync operation (both local and remote).
- **Selective Table Sync** — optionally skip backend user tables during sync to preserve login credentials on either side.
- **Safety Tables** — deploy-related tables, event logs, sessions, cache, and job queues are never synced.
- **Sync Logging** — every sync operation is logged with direction, status, table/file counts, timestamps, and backup paths.
- **Confirmation Prompts** — every destructive operation requires explicit confirmation before proceeding.

## Requirements

- October CMS v3.x
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- [RainLab.Deploy](https://octobercms.com/plugin/rainlab-deploy) v2.x (with beacon installed on the remote server)

## Installation

Install via the October CMS Marketplace or Composer:

```bash
composer require pear/deployextender-plugin
```

Then run migrations:

```bash
php artisan october:migrate
```

## Usage

### Deploy Uploads

Deploy the `storage/app/uploads` directory to a remote server:

```bash
php artisan deployextender:deploy-uploads <server>
```

This builds a ZIP archive of your local `storage/app/uploads` directory, uploads it to the remote server via the RainLab Deploy beacon, and extracts it there.

### Sync Push (Local → Remote)

Push your local database and storage files to the remote server:

```bash
php artisan deployextender:sync-push <server>
```

**Options:**

| Flag | Description |
|------|-------------|
| `--skip-users` | Skip backend user tables (preserves remote admin accounts) |
| `--no-db` | Skip database sync |
| `--no-media` | Skip `storage/app/media` sync |
| `--no-uploads` | Skip `storage/app/uploads` sync |
| `--force` | Skip confirmation prompt |

**Examples:**

```bash
# Full sync - database + media + uploads
php artisan deployextender:sync-push production

# Database only, skip users
php artisan deployextender:sync-push production --skip-users --no-media --no-uploads

# Files only (media + uploads), no database
php artisan deployextender:sync-push staging --no-db
```

### Sync Pull (Remote → Local)

Pull the remote database and storage files to your local environment:

```bash
php artisan deployextender:sync-pull <server>
```

**Options:** Same as sync-push.

**Examples:**

```bash
# Pull everything from production
php artisan deployextender:sync-pull production

# Pull database only, keep local users
php artisan deployextender:sync-pull production --skip-users --no-media --no-uploads
```

### Server Argument

The `<server>` argument accepts either the server **name** or **ID** as configured in RainLab Deploy.

## Safety & Backups

### Automatic Backups

Before every destructive sync operation, a full database backup is created:

- **Push:** Remote database is backed up on the remote server at `storage/app/deploy-backups/`
- **Pull:** Local database is backed up locally at `storage/app/deploy-backups/`

Backup files are timestamped SQL dumps that can be manually imported to restore the previous state.

### Confirmation Prompts

Every sync operation displays a summary of what will happen and requires you to confirm with `yes` before proceeding. Use `--force` only in automated/CI environments where you understand the implications.

### Tables Never Synced

The following tables are always excluded from sync operations to prevent data corruption:

| Category | Tables |
|----------|--------|
| **Deploy** | `rainlab_deploy_servers`, `rainlab_deploy_server_keys` |
| **Plugin** | `pear_deployextender_sync_logs` |
| **Logs** | `system_event_logs`, `system_request_logs` |
| **Transient** | `sessions`, `cache`, `cache_locks`, `jobs`, `failed_jobs` |

### Backend User Tables (Optional Skip)

When using `--skip-users`, the following tables are also excluded:

- `backend_users`
- `backend_user_groups`
- `backend_user_roles`
- `backend_user_preferences`
- `backend_user_throttle`
- `backend_users_groups` (pivot)
- `backend_users_roles` (pivot)

## Sync Logs

All sync operations are logged in the database and viewable in the backend under **Deploy Extender > Sync Logs**. Each log entry records:

- Server name
- Direction (Push / Pull)
- Sync type (database, uploads, media, full)
- Status (running, success, error)
- Number of tables and files synced
- Whether users were skipped
- Backup file path
- Start and completion timestamps
- Error message (if failed)

## How It Works

### Architecture

Deploy Extender uses the existing RainLab Deploy beacon infrastructure for remote server communication. All data transfer is secured with RSA-SHA256 signing and nonce-based replay protection.

### Push Flow

1. Backup remote database (beacon eval script)
2. Export local database to SQL dump
3. Upload SQL dump to remote server (signed file upload)
4. Import SQL on remote server (beacon eval script)
5. Build ZIP archives of storage directories
6. Upload and extract archives on remote server
7. Clean up temporary files on both sides
8. Log the operation

### Pull Flow

1. Backup local database
2. Export remote database (beacon eval script)
3. Download SQL dump in chunks (beacon eval script)
4. Import SQL locally
5. Build ZIP archives on remote server (beacon eval script)
6. Download archives in chunks
7. Extract archives locally
8. Clean up temporary files on both sides
9. Log the operation

### File Transfer

Large files (database dumps, storage archives) are transferred using a chunked download mechanism with 2MB chunks, allowing transfer of files larger than PHP's memory limit.

## Limitations

- **MySQL only** — database sync currently supports MySQL/MariaDB. PostgreSQL and SQLite are not supported.
- **Beacon required** — the remote server must have the RainLab Deploy beacon installed and accessible.
- **Transfer size** — while chunking handles large files, very large databases (>1GB) may experience timeouts. Adjust `max_execution_time` on the remote server if needed.
- **No incremental sync** — each sync is a full replacement, not an incremental/differential sync.

## License

This plugin is proprietary software by Pear Interactive.

## Changelog

### 1.0.3

- Fixed composer dependency: support RainLab Deploy v2 and v3 (`^2.0 || ^3.0`).

### 1.0.2

- Fixed batch upload exceeding server `upload_max_filesize` — batches now capped at 50MB, auto-split into sub-batches when needed.
- Fixed progress bar showing "undefined" stats on completion.
- Session-resilient sync counters — accurate table/file totals in finalize step even with session loss.
- Added remote extraction result verification on push — reports clear error if extraction fails on server.

### 1.0.1

- Batched storage sync — media and uploads are now synced in 50-file batches, preventing timeouts on large sites (1GB+).
- Live progress bar with transferred/total bytes and transfer speed.
- Auto-retry (2x) on timeout, 502, and server errors.
- Fixed `ZipArchive::extractTo` permission errors on existing files.
- Beacon scripts now use `set_time_limit(300)` and `CM_STORE` (no compression) for faster archive creation.

### 1.0.0

- Initial release.
- Bidirectional database sync (push/pull) with automatic backups.
- Storage sync for `app/media` and `app/uploads` directories.
- Deploy uploads integration with RainLab Deploy workflow.
- Selective table sync with user table skip option.
- Sync logging with full operation history.
- Console commands: `sync-push`, `sync-pull`, `deploy-uploads`.
