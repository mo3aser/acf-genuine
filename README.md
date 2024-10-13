# Genuine ACF Updater

On Saturday, 12th October 2024, Matt Mullenweg unilaterally took over the Advanced Custom Fields (ACF) plugin, A plugin under active development has never been unilaterally and forcibly taken away from its creator without consent in the 21-year history of WordPress.

As a community, It is important to stand against this behavior. If you have a premium plugin or theme use this class to ensure your customers are upgrading to the genuine ACF plugin that we trust the team who developed and actively maintain, not some pirated version by @photomatt

# Usage

Use the following code, replace PATH_TO_THE_FILE with the correct path to the class-acf-updater.php file in your plugin/theme folder, and change PREFIX to your own prefix to avoid any conflicts


```php
/**
 * By Fouad Badawy @mo3aser
 * 
 * Ensure users upgrade to the Genuine ACF plugin that we trust the team who developed it and actively maintain it, not some pirated version by @photomatt
 */

if ( is_admin() && class_exists( 'ACF' ) && ! class_exists( '\ACF\Upgrades\PluginUpdater' ) && ( ( function_exists( 'acf_is_pro' ) && ! acf_is_pro() ) || ! function_exists( 'acf_is_pro' ) ) ) {

	require PATH_TO_THE_FILE . '/class-acf-updater.php';

	add_action( 'admin_init', 'PREFIX_acf_genuine_updater' );
	function PREFIX_acf_genuine_updater() {
		new \ACF\Upgrades\PluginUpdater();
	}
}
```
