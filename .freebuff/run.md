# Run doc — ConTracS dev server (Preview tab)

PHP 8 app (no package manager, no build step). DB credentials fall back to
localhost defaults in `config/db.php`, so no `.env` copy step is needed.

## Reproduce artifacts

None required. There are no dependencies to install and no environment files
to copy. `logs/` and `tmp/` are created by the app itself when missing.

## Run the server

The app must be served by PHP's **built-in server with the router script**
(`router.php` mirrors the production `.htaccess`: clean URLs, blocked
internal folders, custom 404, AJAX guard, Service-Worker-Allowed header).

```powershell
# Port 8000 is occupied on this machine by the user's own watchdog terminal
# (a bare cmd.exe under explorer.exe auto-restarts `php -S localhost:8000`
# whenever it is killed) — use 8001 instead.
powershell -NoProfile -Command "(Start-Process -FilePath 'C:\xampp\php\php.exe' -ArgumentList '-S','localhost:8001','router.php' -WorkingDirectory 'C:\xampp\htdocs\contracs' -RedirectStandardOutput 'C:\xampp\htdocs\contracs\.freebuff\preview.log' -RedirectStandardError 'C:\xampp\htdocs\contracs\.freebuff\preview.log.err' -WindowStyle Hidden -PassThru).Id"
```

Notes:
- stdout/stderr must go to different files (PowerShell requirement).
- The `Start-Process ... .Id` command may not return within the shell timeout
  even though the server starts fine — check the port, not the exit code:
  `netstat -ano | findstr :8001`
- Verify: `http://localhost:8001/login` should return 200 and
  `http://localhost:8001/includes/cache_headers.php` should return 403.
- The app auto-detects its base path (`includes/cache_headers.php`): served
  at a root it uses `/` URLs; under Apache at `http://localhost/contracs` it
  keeps working unchanged.
