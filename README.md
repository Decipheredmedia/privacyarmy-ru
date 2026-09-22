# PrivacyArmy

PrivacyArmy is a scaffold for a privacy-focused smartphone and hardening-services storefront. The project uses a **Python static site generator** for the public catalog, **PHP endpoints** for NOWPayments checkout + webhooks, and **JSON-backed order storage** to keep the initial implementation simple and easy to deploy.

## Project structure

```text
/site/                      generated static output (committed for review, rebuild with generate_site.py)
/templates/                 Jinja2 templates and static assets copied into /site
/data/products.json         single source of truth for products
/data/site_config.json      site-wide brand, contact, domain, and SEO settings
/data/orders.json           scaffold order storage (swap to SQLite/MySQL/Postgres later)
/api/create_payment.php     validates order input and creates a NOWPayments invoice
/api/ipn_callback.php       verifies NOWPayments IPN signatures and updates orders
/api/lib/nowpayments.php    NOWPayments API helper
/api/lib/mailer.php         SMTP/mail() transactional email helper
/admin/index.php            password-protected order dashboard
/support/faq.json           FAQ content for the support page and FAQPage schema
/support/ticket.php         support form mail handler
generate_site.py            builds the static storefront into /site
```

## Why NOWPayments invoices

The checkout scaffold uses the **NOWPayments `/v1/invoice` endpoint** rather than building a custom on-site crypto payment UI. That keeps the storefront static, reduces the amount of payment-state UI we maintain ourselves, and returns a hosted `invoice_url` that the frontend can redirect customers to immediately.

## Local setup

1. Install Python dependencies:
   ```bash
   python -m venv .venv
   . .venv/bin/activate
   pip install -r requirements.txt
   ```
2. Copy the environment template and fill in real values:
   ```bash
   cp .env.example .env
   ```
   Export those variables through your shell, web server, or PHP-FPM pool configuration. This scaffold reads secrets from environment variables and never hardcodes them.
3. Generate the static site:
   ```bash
   python generate_site.py
   ```
4. Serve the generated `/site` directory as your document root, while keeping `/api`, `/admin`, `/support`, and `/data` available to PHP outside or alongside the public web root depending on your server layout.

The generated `/site` output is committed in this scaffold so you can review or deploy a known-good build immediately, but the source of truth remains the JSON data and Jinja templates.

## Required environment variables

- `SITE_BASE_URL` — public base URL used for checkout return URLs and IPN callback URLs.
- `NOWPAYMENTS_API_KEY` — API key for creating hosted NOWPayments invoices.
- `NOWPAYMENTS_IPN_SECRET` — secret used to verify the `x-nowpayments-sig` webhook signature.
- `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` — SMTP delivery settings for transactional email.
- `SMTP_FROM` / `SMTP_FROM_NAME` — sender address and display name.
- `SUPPORT_EMAIL` — support inbox and admin notification recipient.
- `ADMIN_PASSWORD_HASH` — output from PHP `password_hash()` for the admin dashboard login.

## Deployment notes (Apache/Nginx + PHP-FPM)

- Point the public web root at the generated `/site` directory for the static storefront.
- Route `/api/*.php`, `/admin/index.php`, and `/support/ticket.php` through PHP-FPM.
- Keep `/data/orders.json` writable by the PHP user for this scaffold.
- For production persistence, replace the JSON order storage in `api/lib/orders.php` with SQLite first, then move to MySQL/Postgres if multi-user concurrency grows.

### Example Nginx shape

```nginx
root /var/www/privacyarmy/site;

location /api/ {
    alias /var/www/privacyarmy/api/;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/privacyarmy$uri;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}

location = /admin/index.php {
    root /var/www/privacyarmy;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/privacyarmy/admin/index.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}

location = /support/ticket.php {
    root /var/www/privacyarmy;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/privacyarmy/support/ticket.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

Adjust paths for Apache or your deployment layout. The important part is that the generated static site stays the public frontend while PHP handles checkout, webhooks, support, and admin tasks.

## How to add a new product

1. Add one JSON object to `/data/products.json` with:
   - `id`
   - `slug`
   - `title`
   - `short_description`
   - `description`
   - `price_usd`
   - `images[]`
   - `category`
   - `condition_options[]`
   - `stock_status`
   - `brand`
2. Run:
   ```bash
   python generate_site.py
   ```
3. Deploy the updated `/site` output.

Because the catalog is data-driven, you do **not** hand-write product pages.

## NOWPayments dashboard configuration

In the NOWPayments dashboard, configure the following to match your deployment:

- **IPN callback URL:** `https://your-domain.example/api/ipn_callback.php`
- **IPN secret:** the same value you set in `NOWPAYMENTS_IPN_SECRET`
- **Success URL / Cancel URL:** these are passed dynamically by `api/create_payment.php`, so keep your dashboard defaults permissive if NOWPayments asks for them.

When a customer starts checkout, `api/create_payment.php` validates the product against `/data/products.json`, stores a pending order in `/data/orders.json`, creates a hosted invoice, and emails the customer a pending-order notice. When NOWPayments sends a signed IPN callback, `api/ipn_callback.php` verifies the HMAC-SHA512 signature, updates the order status, and triggers the customer/admin follow-up emails on `confirmed` or `finished` status.

## Support automation

- `/support/faq.json` powers both the generated FAQ page widget and the `FAQPage` JSON-LD schema.
- `/support/ticket.php` is the starter customer-support automation layer. It validates a basic contact form and emails the support inbox.
- A future task can add AI-assisted auto-response logic without changing the public FAQ content model.

## Verification commands

```bash
python generate_site.py
python -m py_compile generate_site.py
find api admin support -name '*.php' -print0 | xargs -0 -n1 php -l
```
