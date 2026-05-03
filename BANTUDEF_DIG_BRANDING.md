# Bantu Defend Dignity Mail Branding

Copy these files into the Roundcube root at `/www/wwwroot/mail.bantudefdig.com`:

- `skins/elastic/templates/login.html`
- `skins/elastic/templates/includes/layout.html`
- `skins/elastic/styles/bantudefdig-brand.css`

Keep the product name in `config/config.inc.php`:

```php
$config['product_name'] = 'Bantu Defend Dignity Mail';
```

Do not publish the live `config/config.inc.php`; it contains mail/database secrets.

The login logo currently uses the Elastic skin-relative path:

```html
<img src="/images/logo.svg" alt="Logo">
```

Roundcube rewrites that path correctly to `skins/elastic/images/logo.svg`.
