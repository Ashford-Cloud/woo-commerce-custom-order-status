# Ashford Woo Custom Statuses

A lightweight WooCommerce plugin by **Jim Saunders** / **Ashford.cloud** for creating and managing custom WooCommerce order statuses from the WordPress admin area.

## Features

- Adds default custom statuses:
  - Awaiting Parts
  - Pending Refund
- Add new custom order statuses from the admin screen.
- Edit custom status labels, descriptions, ordering and enabled state.
- Shows WooCommerce core statuses as locked so users can see what cannot be edited.
- Adds bulk order actions for enabled custom statuses.
- Discreet top-right donation button using PayPal.me.
- Built-in GitHub release updater for this repository.

## GitHub updater

The plugin is hard-coded to check releases from:

`Ashford-Cloud/woo-commerce-custom-order-status`

To publish an update:

1. Update the version number in `ashford-woo-custom-statuses.php`:
   - Plugin header `Version:`
   - `const VERSION`
2. Create a WordPress install zip named:

   `ashford-woo-custom-statuses.zip`

3. The install zip should contain this folder structure:

   `ashford-woo-custom-statuses/ashford-woo-custom-statuses.php`

4. Create a GitHub Release using a tag such as:

   `v1.2.1`

5. Attach `ashford-woo-custom-statuses.zip` to the release.

WordPress will then show the standard **update now** link on the Plugins screen when the release version is newer than the installed plugin version.

## Donate link

The donation popup uses:

`https://paypal.me/jimmistiles`

## Requirements

- WordPress 5.8+
- WooCommerce
- PHP 7.4+

## Author

Created by **Jim Saunders**  
https://ashford.cloud
