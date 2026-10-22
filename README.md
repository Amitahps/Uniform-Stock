# Uniform Stock

PHP + SQLite stock inventory for a school uniform vendor. **Separate website** from school-fee-erp and Badminton — deploy on its own Hostinger site / domain.

## Features

- **Companies** — create a company name, then select it. Items, stock in/out, on-hand, reports, and deficit are **scoped to the selected company** (stock is never mixed across companies). **Remove company** requires typing the name and re-entering the admin password; that company’s items and stock history are permanently deleted (other companies untouched).
- **Login** — session auth; default `admin` / `admin123` (change under Password)
- **Items** — **Use existing item name** (dropdown) → type **item code** → type **size**; or **Create new item name** (name + code + size). Same name may use different codes/sizes. Duplicate code+size is rejected with a clear error. Uniqueness is **company + item code + size** only. **Edit** anytime. **Delete** only if unused.
- **Stock in** — bill number + multiple lines in one save. Each line: item name (read-only) → **select item code** → size auto-fills (or choose if several) → enter **quantity only**. Names may repeat across codes in the list.
- **Stock out** — issue date + quantities; deducts on-hand; blocks oversell
- **Reports** — quantity lookup; item-wise statement; **date-range movements** (Qty In / Qty Out in range, Balance = closing on-hand as of the To date). Every report row is one SKU: **name + item code + size** with its own figures — sizes (and codes) under the same name are never merged. Optional filter by item code. Current company only.
- **Deficit** — SKUs with quantity &lt; 5; filters: all / by item / by size (current company)

SKU identity is **company + item code + size** (DB column `item_no`). Item **name is not unique**. On-hand = sum(receipts) − sum(issues) per SKU.

### Typical use

1. **Companies** → create company → select it  
2. **Items** → add item name / item code / size (edit or delete unused SKUs later)  
3. **Stock in** → bill no → select name → code → size → qty → save (multi-line OK)  
4. Stock out / Reports / Deficit as needed  

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
