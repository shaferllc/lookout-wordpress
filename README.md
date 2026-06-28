# Lookout — WordPress

WordPress plugin that reports **uncaught exceptions**, **shutdown fatals** (E_ERROR, E_PARSE, etc.), and optional **test events** to Lookout via `POST /api/ingest` with your **project API key**.

**Free dashboard:** [Create a free Starter account](https://uselookout.app/register) (no credit card) — one project, thousands of events/month. Copy your project API key from Settings and view grouped errors in the web UI.

**Monorepo path:** `packages/lookout-wordpress/`. Optional **git subtree** mirror when `SPLIT_LOOKOUT_WORDPRESS_REPO` is set (see `.github/workflows/package-split.yml`).

## Requirements

- WordPress **6.0+**
- PHP **8.0+**
- A Lookout project **API key** and your Lookout app **base URL** (no trailing slash)

## Install

1. Copy this folder into `wp-content/plugins/lookout/` (the directory should contain `lookout.php`).
2. In wp-admin go to **Plugins** → enable **Lookout**.
3. Go to **Settings → Lookout**:
   - **Lookout base URL** — e.g. `https://your-lookout-host.example`
   - **Project API key** — from the project in Lookout
   - Enable **Send errors to Lookout**
   - Save, then use **Send test event** to verify.

## Same host as Lookout?

If WordPress and Lookout share the **same hostname**, the plugin **does not send** events (to avoid ingest calling back into the same app). Run WordPress on another domain/subdomain, or use a separate Lookout deployment for testing.

## What gets sent

- **Uncaught `Throwable`:** message, class, file, line, trace string, structured `stack_frames`, `language: php`, request URL, WordPress/PHP context.
- **Fatal errors** (via shutdown): message, type, file, line, URL, context.
- **Test event:** info-level marker with `context.test: true`.

See your Lookout instance’s **Ingest API** documentation (e.g. `https://your-host/docs/ingest`) for the full payload reference.

## Filters

- `lookout_remote_post_args` — adjust `wp_remote_post` arguments (`$args`, `$payload`, `$url`).

## License

MIT
