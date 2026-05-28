# Ashford Woo Custom Statuses

A lightweight WooCommerce plugin by **Jim Saunders** / **Ashford.cloud** for creating and managing custom WooCommerce order statuses directly from the WordPress admin area.

By Jim Saunders — *The Wizard of Eastleigh*

---

## Installation

This repository contains the plugin source code and development files.

To install the plugin in WordPress, use the installable plugin zip located in:

```text
/dist/ashford-woo-custom-statuses.zip
```

Do **not** use GitHub’s automatically generated **Source code** zip for WordPress installation.

### WordPress Installation

1. Go to **Plugins → Add New**
2. Click **Upload Plugin**
3. Select the zip file from the `/dist` folder
4. Activate the plugin

---

## Features

* Adds default custom statuses:

  * Awaiting Parts
  * Pending Refund
* Create unlimited custom WooCommerce order statuses
* Edit status labels, descriptions, ordering, and enabled state
* Shows WooCommerce core statuses as locked for clarity
* Adds bulk order actions for enabled custom statuses
* Discreet top-right donation button using PayPal.me
* Built-in GitHub release updater support

---

## GitHub Updater

The plugin is hard-coded to check releases from:

```text
Ashford-Cloud/woo-commerce-custom-order-status
```

### Publishing Updates

1. Update the version number in:

```text
ashford-woo-custom-statuses.php
```

Update both:

* Plugin header `Version:`
* `const VERSION`

2. Create a WordPress install zip named:

```text
ashford-woo-custom-statuses.zip
```

3. The install zip should contain this structure:

```text
ashford-woo-custom-statuses/
└── ashford-woo-custom-statuses.php
```

4. Create a GitHub Release using a tag such as:

```text
v1.2.1
```

5. Attach:

```text
ashford-woo-custom-statuses.zip
```

to the release assets.

WordPress will then show the standard **Update now** link when a newer release version is available.

---

## Donate

Support development via PayPal:

```text
https://paypal.me/jimmistiles
```

---

## Requirements

* WordPress 5.8+
* WooCommerce
* PHP 7.4+

---

## Author

Created by **Jim Saunders**
https://ashford.cloud
