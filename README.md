# Bulk Draft Published Posts

Version 1.1.0

A small WordPress utility plugin that safely changes selected **published** content to **Draft**.

## Supports

- Default Posts
- Pages
- Public/admin-visible custom post types (CPTs)
- Native WordPress bulk actions
- Tools → Bulk Draft Published

## Live search

On **Tools → Bulk Draft Published**, search results update automatically as you type. No Enter key or Filter click is required.

- 250ms debounced AJAX search
- Post Type and Per Page changes also refresh automatically
- Pagination updates without reloading the page
- Selected items remain selected while you search for other items in the same post type
- Use **Clear Selection** to reset the selection
- Switching to a different post type clears the current selection

## Safety

The plugin only changes `post_status` from `publish` to `draft`. It does not delete content or modify post type registration, content, custom fields, taxonomies, or URLs.
