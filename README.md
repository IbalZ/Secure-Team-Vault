# SecureTeam Vault

SecureTeam Vault is a Laravel-based team credential manager created for the FYP 2 project. It stores credential secrets with AES-256-GCM authenticated encryption, limits access by role and per-credential grants, and records security-sensitive activity in a tamper-evident audit chain.

This repository contains **source code only** for academic review. It excludes the original `.env`, application and vault keys, database contents, local accounts, logs, runtime caches, installed dependencies, reports, slides and videos. Install dependencies from the included lockfiles and create a new local demonstration database; do not use any original company or personal database. The source snapshot does not change the author's working application.

## Main features

- Five roles: Administrator, Manager / Executive, Staff, Intern and Freelance
- Administrator-managed user accounts with activation and expiry controls
- Administrator-controlled password resets through the user edit screen
- Encrypted credential create, view, update, archive, reveal, and copy workflows
- Per-user credential access with optional expiry
- Password re-confirmation before secrets are revealed or copied
- Automatic browser-side hiding of revealed values after 20 seconds
- Application-enforced append-only audit records protected by an HMAC hash chain
- Responsive Bootstrap dashboard

## Role permissions

| Function | Administrator | Manager / Executive | Staff / Intern / Freelance |
| --- | --- | --- | --- |
| Manage users and audit logs | Yes | No | No |
| Create credentials | Yes | Yes | No |
| View every credential | Yes | No | No |
| View owned or assigned credentials | Yes | Yes | Assigned only |
| Edit and share credentials | Yes | Owned or explicitly granted | No |
| Reveal or copy a secret | Yes | Owned or explicitly granted | Explicitly granted |
| Archive a record | Yes | Owned records only | No |

Account status, account expiry, ownership and active grant permissions are checked on the server.

## First-time setup

Run these commands from the project directory in the VS Code terminal:

```powershell
git clone https://github.com/IbalZ/Secure-Team-Vault.git
cd Secure-Team-Vault
composer install
npm.cmd ci
if (!(Test-Path -LiteralPath '.env')) { Copy-Item -LiteralPath '.env.example' -Destination '.env' }
php artisan key:generate
php artisan vault:keys
php artisan migrate
npm.cmd run build
```

Before `php artisan migrate`, create a new empty MySQL database such as `secureteam_vault_demo` and a dedicated local application user. Edit the private `.env` file to set its database name, host, port, username and password. Grant access only to that demonstration database. The runtime requires PHP 8.2 or a compatible later version, Laravel's required extensions and OpenSSL, Composer, and Node.js/npm compatible with the included Vite 6 lockfile. Tests also require PHP's SQLite driver. Never commit or share `.env`. On non-Windows systems, use `npm` and the equivalent file-copy command.

The public repository can be cloned without signing in. Never place an access token in a command, URL or committed file. The setup above is for a fresh local installation only.

If an existing `.env` and database are already configured, do not copy `.env.example` over them and do not regenerate the keys. Run only:

```powershell
php artisan migrate
npm.cmd run build
```

### Create the first local administrator

Public registration is disabled, and the default seeder does not create an administrator. Do not assume that `db:seed` provisions an admin account. For a fresh local demonstration database, run:

```powershell
php artisan tinker
```

Then enter these PHP statements. The password prompt hides the chosen password, and the User model hashes it before storage. Choose a new password, not one used on the original application.

```php
$adminPassword = \Laravel\Prompts\password(label: 'Choose a unique local admin password', required: true, validate: fn (string $value) => strlen($value) < 12 ? 'Use at least 12 characters.' : null);
\App\Models\User::create(['name' => 'Demo Administrator', 'email' => 'admin@example.test', 'password' => $adminPassword, 'role' => \App\Enums\UserRole::Admin, 'is_active' => true]);
unset($adminPassword);
exit
```

The example email is a fictional local login identity, not an original project account. Create the remaining roles through **User management** after signing in.

### Start the local application

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Open <http://127.0.0.1:8000> and sign in with the new administrator account. Use local loopback only for development; remote use requires HTTPS and production configuration.

## Normal demonstration flow

1. Sign in as Administrator.
2. Open **User management** and create fictional Manager / Executive, Staff, Intern and Freelance accounts.
3. Open **Add credential**, enter a test login, and save it.
4. On the credential page, grant the intended restricted user reveal access.
5. Use **Reveal for 20 seconds**. The application asks for the current password before returning plaintext.
6. Open **Audit logs** as Administrator and confirm the recorded events.
7. Run `php artisan vault:audit-verify` to verify the complete audit hash chain.

Use fictional demonstration credentials only. Do not put real personal, university, or production passwords into a development system.

## Development checks

```powershell
php artisan test
php artisan view:cache
npm.cmd run build
php artisan route:list --except-vendor
php artisan vault:audit-verify
```

Tests use an in-memory SQLite database and do not erase the configured MySQL database.

## Security operations

- Keep `.env` outside source control and back it up in a protected location.
- Losing `VAULT_KEY_V1` makes stored credential data unrecoverable.
- Losing or changing `AUDIT_HMAC_KEY` prevents existing audit events from verifying.
- `php artisan vault:keys --force` is intentionally blocked after any credential or audit event exists.
- Use HTTPS and set `APP_DEBUG=false` before any non-local deployment.
- Give the MySQL application account access only to the `secureteam_vault` database.

The audit model blocks ordinary application update/delete operations, and the HMAC chain detects direct database changes. The migration also installs MySQL blocking triggers when the server permits it. Some binary-logged MySQL installations require a server administrator to enable trigger creation; the verifier remains active when those optional triggers cannot be installed. Production deployments still require restricted database accounts, backups, monitoring, and HTTPS.

The audit chain is tamper-evident, not an immutable blockchain. A sufficiently privileged database/key operator remains a threat. Twenty-second hiding clears visible content, not clipboard contents, screenshots or previously learned passwords. Revoking a grant blocks future vault retrieval, so offboarding may also require rotating the underlying service password. Titles, categories and website URLs remain searchable metadata rather than encrypted secrets.

This is a prototype, not a production-security certification. Before using real secrets remotely, configure a stable host, enforce HTTPS and HTTP redirection, verify database controls, protect keys and test recovery. The project report retains limitations in transport enforcement, sustained concurrent loads and end-to-end backup recovery. Cloudflare Quick Tunnels were temporary web-access tools, not permanent application or database hosting. No live tunnel credentials or demonstration database are included here.

## Useful troubleshooting

- Clear cached settings after changing `.env`: `php artisan config:clear`
- Check the database connection: `php artisan db:show`
- Apply pending schema changes: `php artisan migrate`
- Rebuild styling and JavaScript: `npm.cmd run build`
- Stop the local server: press `Ctrl+C` in its terminal
- Start it again from this folder: `php artisan serve`
