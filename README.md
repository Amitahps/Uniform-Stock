# Uniform Stock

PHP + SQLite stock inventory for a school uniform vendor. **Separate website** from school-fee-erp and Badminton — deploy on its own Hostinger site / domain.

## Features

- **Companies** (admin) — create/select/remove vendor company. **Remove company** (password + name confirm) deletes that company’s stock for **both** schools.
- **Schools** — with a company selected, **Bachpan** and **AHP.S** tabs stay at the top. One click switches the active school; Items / Stock / Reports / Deficit follow that school. Stocks never mix.
- **Users** (admin) — create **staff** usernames/passwords, assign one or more companies, reset passwords, delete staff. Staff see only assigned companies.
- **Login** — admin `admin` / `admin123` (change under Password); staff use credentials created by admin.
- **Staff access** — Items, Stock in/out, Reports, Deficit, school tabs, company picker, Logout. **No** Companies / Users / Backup / Password (direct URLs blocked).
- **Items** — uniqueness **company + school + item name + item code + size** (different names may share code/size).
- **Stock in / out / Reports / Deficit** — company+school scoped; SKUs by name+code+size.
- **Backup** (admin) — download source ZIP and timestamped SQLite backup; `data/` not publicly exposed.

SKU identity is **company + school + item name + item code + size**. On-hand = sum(receipts) − sum(issues) per SKU.

### Typical use

1. **Admin:** Companies → create/select company → click **Bachpan** or **AHP.S** tab  
2. **Admin:** Users → create staff → assign companies → give them username/password  
3. **Items** → add name / code / size for that company+school  
4. **Stock in** → bill → item blocks → **Save bill** once  
5. Switch school with the top tabs anytime; staff switch company via **Company** 

## Requirements

- PHP 8.0+ with `pdo_sqlite`
- Writable `data/` directory (SQLite file `data/stock.db` is created automatically)

## Local run

```bash
php -S 127.0.0.1:8080
# open http://127.0.0.1:8080/login.php
# admin / admin123
```

Health check: `http://127.0.0.1:8080/check.php`

## Hostinger deploy (new separate website)

1. In Hostinger, create a **new website** (do not reuse school-fee-erp or Badminton `public_html`).
2. Connect GitHub → repository **`Amitahps/Uniform-Stock`** → deploy into that site’s `public_html` (document root). Use **Redeploy** after each push to `main` if auto-deploy lags.
3. Ensure PHP 8+ and **pdo_sqlite** are enabled for the site.
4. Make `data/` writable by the web user (chmod 775 or Hostinger file manager permissions). The app creates `data/stock.db` on first request.
5. Open `https://YOUR-DOMAIN/check.php` — all checks should pass.
6. Sign in at `/login.php` with `admin` / `admin123`, then change the password.

No Composer or Node build step. Upload / Git sync of these PHP files is enough.

### If `/check.php` shows SQLite / FOREIGN KEY errors

The app migrates with foreign keys off and tries to recover half-broken DBs (leftover `*_new` tables from a failed mid-DROP). If it still fails after Redeploy:

1. In Hostinger File Manager, open this site’s `data/` folder.
2. **Delete only** `stock.db` (and `stock.db-wal` / `stock.db-shm` if present). Keep the `data/` folder.
3. Reload `/check.php` — a fresh database is created automatically.

Do **not** delete or change school-fee-erp or Badminton files.

### If the site shows **403 Forbidden**

1. **Redeploy** Uniform-Stock `main` in Hostinger Git (confirm files appear in this site’s `public_html`: `index.php`, `login.php`, `.htaccess`).
2. If `public_html` is empty or only has a nested folder, the Git deploy path is wrong — point deploy at `public_html` (repo root), not a subfolder and not another website.
3. Root `.htaccess` sets `DirectoryIndex index.php login.php` and blocks web access to `data/` only (not the site root).

## Security notes

- Change the default password immediately after first login.
- Do not commit `data/stock.db` (ignored via `.gitignore`).
- Keep this app on its own domain/subdomain; it does not share databases with sibling apps.
