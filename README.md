# Bulk Draft Published Posts

A WordPress utility plugin for safely changing selected **published** content to **Draft**.

## Available Versions

This repository may contain multiple versions of the plugin. Each version is maintained in its own folder.

- `wp-bulk-draft-published-v1.1.0/`
- `wp-bulk-draft-published-v1.3.0/`
- Add future versions using the same naming pattern: `wp-bulk-draft-published-vX.Y.Z/`

Replace `X.Y.Z` with the version number for each release. Each version folder should contain that release's plugin files. This README applies generally to the versions in this repository; check the relevant version folder for its specific implementation.

## Features

The plugin supports:

- Default WordPress Posts
- Pages
- Public/admin-visible custom post types (CPTs)
- Native WordPress bulk actions
- **Tools → Bulk Draft Published**

## Live Search

On **Tools → Bulk Draft Published**, search results update automatically as you type. No Enter key or Filter click is required.

- Search uses a 250 ms debounced AJAX request.
- Changing the post type or items per page refreshes results automatically.
- Pagination updates without reloading the page.
- Selected items remain selected while searching within the same post type.
- Use **Clear Selection** to reset the selection.
- Switching to a different post type clears the current selection.

## Safety

The plugin changes the post status of selected content from `publish` to `draft`. It does not delete content or intentionally modify post type registration, post content, custom fields, taxonomies, or URLs.

## Versioning

When adding a new release:

1. Create a new folder named `wp-bulk-draft-published-vX.Y.Z/`.
2. Place the plugin files for that release inside the folder.
3. Keep existing version folders if you want to preserve previous releases.
4. Update this README only if the repository-wide features, organization, or usage instructions change.

**Note:** Features can vary between versions. Refer to the files and documentation inside the relevant version folder to confirm what that release supports.
