# Uniform Stock

PHP + SQLite stock inventory for a school uniform vendor. **Separate website** from school-fee-erp and Badminton — deploy on its own Hostinger site / domain.

## Features

- **Schools** — stock is for schools. **Bachpan** and **AHP.S** are seeded on migrate. Select a school before Items / Stock in / Stock out / Reports / Deficit (clear prompt if none selected). Data never mixes across schools. You may add more schools later. **Remove school** requires typing the name and re-entering the admin password; that school’s items and stock history are permanently deleted (other schools untouched). (DB table remains `companies`.)
- **Login** — session auth; default `admin` / `admin123` (change under Password)
- **Items** — **Use existing item name** (dropdown) → type **item code** → type **size**; or **Create new item name** (name + code + size). Same name may use different codes/sizes. Duplicate code+size is rejected with a clear error. Uniqueness is **school + item code + size** only. **Edit** anytime. **Delete** only if unused.
- **Stock in** — enter **bill number** (and date) once at the top. Add **item name** blocks: select a name → all its codes/sizes appear as rows with **On hand** (read-only) before qty → fill qty per row (blank/0 skipped). Add more item blocks under the same bill. **One Save** writes the whole bill. Duplicate SKU lines are summed. School-scoped.
- **Stock out** — issue date + lines: Item/size → **On hand** (read-only, updates on selection) → Qty; deducts on-hand; blocks oversell. School-scoped.
- **Reports** — quantity lookup; item-wise statement; **date-range movements** (Qty In / Qty Out in range, Balance = closing on-hand as of the To date). Every report row is one SKU: **name + item code + size** with its own figures — sizes (and codes) under the same name are never merged. Optional filter by item code. **Current school only** (Bachpan vs AHP.S fully separate).
- **Deficit** — SKUs with quantity &lt; 5; filters: all / by item / by size (current school only)

SKU identity is **school + item code + size** (DB: `company_id` + `item_no` + size). Item **name is not unique**. On-hand = sum(receipts) − sum(issues) per SKU.

### Typical use

1. **Schools** → select **Bachpan** or **AHP.S** (required before anything else)  
2. **Items** → add item name / item code / size for that school  
3. **Stock in** → bill no + date → Add item name → fill qtys for codes/sizes → add more names if needed → **Save bill** once  
4. Stock out / Reports / Deficit as needed (still under the selected school) 

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
