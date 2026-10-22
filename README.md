# Uniform Stock

PHP + SQLite stock inventory for a school uniform vendor. **Separate website** from school-fee-erp and Badminton — deploy on its own Hostinger site / domain.

## Features

- **Companies** — create/select your vendor company. **Remove company** (password + name confirm) deletes that company’s stock for **both** schools.
- **Schools** — after a company is selected, choose **Bachpan** or **AHP.S** (fixed). Items, stock in/out, reports, and deficit are scoped to **company + school**. Switching school under the same company shows only that school’s stock. Bachpan and AHP.S never mix.
- **Login** — session auth; default `admin` / `admin123` (change under Password)
- **Items** — **Use existing item name** (dropdown) → type **item code** → type **size**; or **Create new item name** (name + code + size). Uniqueness is **company + school + item code + size**. **Edit** anytime. **Delete** only if unused.
- **Stock in** — bill number + date once; item-name blocks with **On hand** before qty; **One Save** for the whole bill. Company+school scoped.
- **Stock out** — Item/size → **On hand** → Qty; blocks oversell. Company+school scoped.
- **Reports** / **Deficit** — current company + school only.
- **Backup** (login required) — **Download source code** (server-built ZIP of PHP/assets, excludes `data/*.db`) and **Download data backup** (timestamped `uniform-stock-backup-YYYYMMDD-HHMM.db`). Optional GitHub `main.zip` link. `data/` stays blocked from public URLs; downloads only via authenticated PHP.

SKU identity is **company + school + item code + size** (DB: `company_id`, `school`, `item_no`, `size`). On-hand = sum(receipts) − sum(issues) per SKU.

### Typical use

1. **Companies** → create/select your company  
2. Choose school **Bachpan** or **AHP.S**  
3. **Items** → add item name / code / size for that company+school  
4. **Stock in** → bill → item blocks → **Save bill** once  
5. Stock out / Reports / Deficit (same company+school); switch school anytime under Companies 

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
