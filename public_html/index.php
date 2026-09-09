<?php
/**
 * Project Odysseus — dashboard entry point.
 *
 * Placeholder until the dashboard is built (milestone M4). Its job right now
 * is to prove the deployment works and to give a 200 at the document root,
 * rather than the 403 an empty directory produces.
 *
 * Deliberately does NOT reveal configuration, database state or version
 * information: it is reachable by anyone.
 */

declare(strict_types=1);

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Project Odysseus</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 15px/1.6 system-ui, -apple-system, Segoe UI, sans-serif;
         max-width: 640px; margin: 80px auto; padding: 0 24px; }
  h1 { font-size: 20px; margin-bottom: 4px; }
  p { opacity: .75; }
</style>
<h1>Project Odysseus</h1>
<p>Retention analytics platform. The dashboard is not built yet.</p>
