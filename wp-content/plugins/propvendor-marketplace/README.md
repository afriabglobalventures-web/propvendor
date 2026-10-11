# PropVendor Marketplace

## Listing image storage

Vendor-submitted listing images are stored in
`wp-content/uploads/propvendor-private/` and delivered through the plugin's
WordPress image endpoint. The endpoint exposes images publicly only for
published listings approved for public viewing. The listing owner and users
with the administrator capability may also view non-public listing images.
Other WordPress uploads are not moved or blocked.

The plugin creates `.htaccess`, `web.config`, and `index.php` protection files
in the private directory. Direct-file denial was verified with Apache on the
local XAMPP installation. The `web.config` rules have not been tested on IIS.

### Production hosting requirements

- Configure the web server to deny every direct request beneath
  `/wp-content/uploads/propvendor-private/`. Do not rely on `.htaccess` unless
  the production Apache host permits and honors overrides. For Nginx, add and
  verify a deny rule for this exact path; for other servers, use their
  equivalent access-control configuration.
- Prefer storage outside the public document root where the host permits it.
  If storage remains beneath `uploads`, the server-level direct-access denial
  is mandatory; a PHP endpoint alone does not prevent direct file requests.
- Do not publicly cache the private directory or cache the authenticated
  WordPress delivery endpoint. Retain HTTPS and verify that requests for both
  original images and every generated image size are denied directly.
- Before deploying existing listing media, move it only with a tested
  migration and rollback plan, then verify the server denies the old and new
  paths. No listing-associated attachments existed in the local database
  before the security test, so no existing images were migrated.

The local Apache/XAMPP test does not establish that a production server,
reverse proxy, CDN, or object-storage configuration enforces these controls.
