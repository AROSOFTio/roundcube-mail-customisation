# Roundcube Mail Customisation

Custom Roundcube skin, branding, and password-recovery integration for the AROSOFT-hosted mail stack.

## What This Repo Contains

- `skins/elastic/templates/` - customized Roundcube Elastic templates.
- `skins/elastic/styles/` - AROSOFT and hosted-domain CSS overrides.
- `skins/elastic/scripts/` - custom login/UI JavaScript.
- `skins/elastic/images/` - logo and favicon assets.
- `password-recovery/public/` - self-service password recovery entrypoint.
- `password-recovery/roundcube/` - Roundcube password driver for Stalwart JMAP.
- `password-recovery/config/config.example.json` - safe example config.

## What Must Not Be Committed

Do not commit the live config file:

```text
password-recovery/config/config.json
```

It contains the Stalwart recovery API key and SMTP app password. It is intentionally ignored by `.gitignore`.

## Current Production Mounts

The production Roundcube container uses bind mounts from `/data/roundcube-custom`, including:

```text
/data/roundcube-custom/skins/elastic/templates/login.html
/data/roundcube-custom/skins/elastic/templates/includes/layout.html
/data/roundcube-custom/skins/elastic/styles/arosoft-mail.css
/data/roundcube-custom/skins/elastic/styles/bantudefdig-brand.css
/data/roundcube-custom/skins/elastic/scripts/arosoft-mail.js
/data/roundcube-custom/password-recovery/public
/data/roundcube-custom/password-recovery/config/config.json
/data/roundcube-custom/password-recovery/roundcube/stalwart_jmap.php
```

## Deployment Notes

This repo can be used as the source of truth for deployment, but the live secret config should still be injected separately as a server file or secret, not stored in GitHub.

Recommended approach:

1. Deploy this repo to `/data/roundcube-custom`.
2. Keep `password-recovery/config/config.json` on the server, copied from `config.example.json` and filled with real secrets.
3. Mount the repo files into the Roundcube container as read-only where possible.
4. Restart/recreate only the Roundcube container after template, CSS, JS, or image changes.
5. Do not prune Docker volumes during deployment; Roundcube and Stalwart data live in named volumes.

## Production Domains

- `mail.arofi.net` - Roundcube webmail.
- `webmail.arofi.net` - Stalwart admin portal.
- `mail.bantudefdig.com` - Roundcube webmail.
- `mail.owltechsolutionsltd.com` - Roundcube webmail.

## Safety Checklist

Before pushing or deploying:

- Confirm `password-recovery/config/config.json` is not staged.
- Check for secrets with a search for API keys, app passwords, tokens, and private credentials.
- Confirm the Roundcube login page loads CSS through `static.php`.
- Confirm `webmail.arofi.net` still routes to Stalwart admin, not Roundcube.
