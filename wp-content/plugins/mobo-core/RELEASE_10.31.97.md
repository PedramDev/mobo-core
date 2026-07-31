# Mobo Core 10.31.97

## Shared-media registered-size alias completion

This release completes the WordPress/WooCommerce size mapping for virtual shared-media attachments.

### Fixed

- Registered image sizes are no longer limited to exact `width x height` matches.
- Sizes with one unconstrained dimension, such as `600x0`, are resolved with WordPress image-resize rules.
- Bounding-box sizes, such as `1024x1024`, are resolved to the actual aspect-ratio-preserving worker output.
- For a `960x1280` source image, the central cuts are now used as follows:
  - `medium_large` -> `768x1024`
  - `large` -> `768x1024`
  - `woocommerce_single` -> `600x800`
- Existing shared attachments are repaired at runtime through the attachment-metadata filter, so no database migration or image re-download is required.
- Newly imported or refreshed shared attachments persist the completed aliases directly in `_wp_attachment_metadata`.

### Safety

- No credentials, webhook security codes, API tokens, server constants, or private configuration files are changed.
- Public/shared-hosting sites with shared media disabled retain the previous image-download behavior.
- Existing exact aliases are preserved and are not overwritten.
