# Uniform Stock

PHP + SQLite stock inventory for a school uniform vendor. **Separate website** from school-fee-erp and Badminton — deploy on its own Hostinger site / domain.

## Features

- **Companies** — create a company name, then select it. Items, stock in/out, on-hand, reports, and deficit are **scoped to the selected company** (stock is never mixed across companies).
- **Login** — session auth; default `admin` / `admin123` (change under Password)
- **Items** — register item name, item no, and size once (item master). Add another size under the same item no when needed. **Edit** name / item no / size anytime. **Delete** only if the SKU has no stock-in or stock-out history (otherwise shown as Locked).
- **Stock in** — bill number + multiple lines in one save. Each line: **select item no** → item name shown read-only → size auto-fills (or choose size if several) → enter **quantity only**. Replenish under a new bill the same way.
- **Stock out** — issue date + quantities; deducts on-hand; blocks oversell
- **Reports** — quantity lookup for one SKU; full item-wise stock statement (current company)
- **Deficit** — SKUs with quantity &lt; 5; filters: all / by item / by size (current company)

SKU identity is **company + item_no + size** (with item name). On-hand = sum(receipts) − sum(issues) per SKU.

### Typical use

1. **Companies** → create company → select it  
2. **Items** → add item name / item no / size (edit or delete unused SKUs later)  
3. **Stock in** → bill no → select item no → size auto → qty → save (multi-line OK)  
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
2. Connect GitHub → repository **`Amitahps/Uniform-Stock`** → deploy into that site’s `public_html` (document root).
3. Ensure PHP 8+ and **pdo_sqlite** are enabled for the site.
4. Make `data/` writable by the web user (chmod 775 or Hostinger file manager permissions). The app creates `data/stock.db` on first request.
5. Open `https://YOUR-DOMAIN/check.php` — all checks should pass.
6. Sign in at `/login.php` with `admin` / `admin123`, then change the password.

No Composer or Node build step. Upload / Git sync of these PHP files is enough.

## Security notes

- Change the default password immediately after first login.
- Do not commit `data/stock.db` (ignored via `.gitignore`).
- Keep this app on its own domain/subdomain; it does not share databases with sibling apps.
