# Run doc — ConTracS dev server (Preview tab)

PHP 8 app (no package manager, no build step). DB credentials fall back to
localhost defaults in `config/db.php`, so no `.env` copy step is needed.

## Reproduce artifacts

None required. There are no dependencies to install and no environment files
to copy. `logs/` and `tmp/` are created by the app itself when missing.

## Run the server

The app now works on **any** of these setups without configuration:

- `php -S localhost:8000` (plain, no router) — served URLs carry an explicit
  `.php` suffix. The app auto-detects `PHP_SAPI === 'cli-server'` and sets
  `CTR_PHP_URLS`, which makes `ctr_url()`, the templates and the JS emit
  `/login.php`-style links and `users.php?ajax=1`-style API calls.
- `php -S localhost:8001 router.php` — the router also maps clean URLs
  (`/login` → `login.php`), mirroring `.htaccess`. Both URL styles work.
- Apache with `.htaccess` at `http://localhost/contracs` — clean URLs,
  `/contracs` base path auto-detected from `DOCUMENT_ROOT`.

Detached start (PowerShell; stdout and stderr must go to different files):

```powershell
powershell -NoProfile -Command "(Start-Process -FilePath 'C:\xampp\php\php.exe' -ArgumentList '-S','localhost:8001','router.php' -WorkingDirectory 'C:\xampp\htdocs\contracs' -RedirectStandardOutput 'C:\xampp\htdocs\contracs\.freebuff\preview.log' -RedirectStandardError 'C:\xampp\htdocs\contracs\.freebuff\preview.log.err' -WindowStyle Hidden -PassThru).Id"
```

Notes:
- The `Start-Process ... .Id` command may not return within the shell timeout
  even though the server starts fine — check the port, not the exit code:
  `netstat -ano | findstr :8001`
- Verify: `http://localhost:8001/login` should return 200 and
  `http://localhost:8001/includes/cache_headers.php` should return 403.
- Port 8000 on this machine is held by a user terminal that auto-restarts a
  plain `php -S localhost:8000`; that server is fine for this app now (the
  `.php` URL mode), but don't try to kill it — it respawns.
- Override URL style anywhere with `CONTRACS_PHP_URLS=1|0` (env var).
