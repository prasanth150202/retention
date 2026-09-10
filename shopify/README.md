# Shopify app configuration

**This is not an application.** The app is the PHP project in the parent
directory, running on `retention.digifyce.com`. Shopify CLI is used here as a
deployment tool and nothing else.

```
shopify.app.toml              scopes, URLs, webhooks, compliance topics
extensions/retention-pixel/   the web pixel Shopify installs on each store
```

There is no `web/`, no server, no database. Node exists on a developer's
laptop so `shopify app deploy` can run, and nowhere else — the PHP host has
no Node and needs none.

## Commands

```bash
npm install                # once
npx shopify app config link   # attach to the Partner Dashboard app
npx shopify app deploy        # push config + extension
```

`shopify app config link` fills in `client_id`. It is intentionally left blank
in git so a copy of this repository cannot deploy over someone else's app.

## Do not run `shopify app dev`

`automatically_update_urls_on_dev` is set to `false` for a reason. With it on,
`shopify app dev` rewrites `application_url` to a developer's temporary tunnel
— on the live app, for every merchant who has it installed.

## The extension is half of a contract

`extensions/retention-pixel/src/index.js` decides what each event looks like.
`../app/cron/import.php` decides how to read it. If one changes a field name
and the other does not, data is silently lost.

They live in one repository so a single commit changes both. Do not split them.
