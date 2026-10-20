# Bulk Draft Published Posts — v1.3.0

A WordPress admin utility for safely changing published Posts, Pages, and supported Custom Post Types to Draft.

## New: Bulk Update From File

You can now upload a CSV or XLSX list and let the plugin find the WordPress entries automatically.

This is designed to work with reports such as the WP CPT Content Auditor export containing columns like:

- Content Status
- ID
- Title
- Slug
- CPT
- Post Status
- Preview URL

### Workflow

1. Go to **Tools → Bulk Draft Published**.
2. Open **Bulk Update From File**.
3. Select the target post type/CPT.
4. Upload a CSV or XLSX file.
5. Choose a matching method:
   - Auto: ID → Slug → Preview URL → Exact Title
   - Post ID
   - Slug
   - Preview URL
   - Exact Title
6. Choose which file rows to use:
   - Auto — prefers `No Content` when a Content Status column exists
   - No Content only
   - Has Content only
   - All rows
7. Click **Preview File Matches**.
8. Review:
   - READY
   - NOT PUBLISHED
   - NOT FOUND
   - WRONG CPT
   - AMBIGUOUS
9. Select the READY matches.
10. Click **Move Selected Matches to Draft** and confirm.

Nothing is changed during preview.

## Matching

Auto mode tries the safest matching fields in this order:

1. WordPress Post ID
2. Exact slug
3. Preview URL / permalink
4. Exact post title

The selected target CPT is always validated.

## XLSX support

XLSX import reads the first worksheet and requires PHP:

- ZipArchive (`php-zip`)
- SimpleXML

If these are unavailable, export/upload the report as CSV.

## Existing features retained

- Manual search/filter screen.
- Bulk drafting from the Tools screen.
- Native WordPress list-table bulk action.
- Posts, Pages, and supported CPTs.
- Capability checks.
- Nonce protection.
- Only currently published entries are moved to Draft.
- No post content, taxonomies, metadata, or URLs are deleted.

## Safety

The plugin uses `wp_update_post()` and does not directly modify database rows.
