<?php
/**
 * By Fouad @mo3aser
 * Tielabs.com
 * 
 * Based on the ACF Updater class
 * The PluginUpdater class which can be used to pull plugin updates from a new location.
 */

namespace ACF\Upgrades;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use stdClass;

/**
 * The PluginUpdater class which can be used to pull plugin updates from a new location.
 */
class PluginUpdater {
	/**
	 * The URL where the api is located.
	 * @var ApiUrl
	 */
	private $api_url;

	/**
	 * The amount of time to wait before checking for new updates.
	 * @var CacheTime
	 */
	private $cache_time;

	/**
	 * These properties are passed in when instantiating to identify the plugin and it's update location.
	 * @var Properties
	 */
	private $properties;

	/**
	 * Get the class constructed.
	 *
	 */
	public function __construct() {

		$properties = array(
			'plugin_slug'     => 'advanced-custom-fields',
			'plugin_basename' => ACF_BASENAME,
		);

		$this->api_url     = 'https://wpe-plugin-updates.wpengine.com/';
		$this->cache_time  = time() + HOUR_IN_SECONDS * 5;

		$this->update_installed_plugin_version();

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'wporg_api_reponse' ), 1 ) ;

		$this->properties = $this->get_full_plugin_properties( $properties, $this->api_url );

		if ( ! $this->properties ) {
			return;
		}

		$this->register();
	}


	/**
	 * Ensure that the installed version of the pirated version that hosted on wp.org is always lower than the genuine plugin latest version.
	 * 
	 * by: Fouad @mo3aser
	 * @return void
	 */
	public function update_installed_plugin_version(){
		$cache_plugins = wp_cache_get( 'plugins', 'plugins' );

		if( ! empty( $cache_plugins ) ){

			$key = array_key_first( $cache_plugins );
			if( ! empty( $cache_plugins[ $key ][ ACF_BASENAME ]['AuthorName'] ) && $cache_plugins[ $key ][ ACF_BASENAME ]['AuthorName'] != 'WP Engine' ){
				$cache_plugins[ $key ][ ACF_BASENAME ]['Version'] = '6.0.0';
				wp_cache_replace( 'plugins', $cache_plugins, 'plugins' );
			}
		}
	}


	/**
	 * Remove the plugin from the wp.org API reponse, in case Matt did something dump like pumping the latest version number to a very high number
	 * 
	 * by: Fouad @mo3aser
	 */
	function wporg_api_reponse( $transient ){

		if( ! empty( $transient->response[ ACF_BASENAME ]->id ) && strpos( $transient->response[ ACF_BASENAME ]->package, 'wordpress.org' ) !== false ){
			$transient->response[ ACF_BASENAME ] = false;
		}

		return $transient;
	}


	/**
	 * Get the full plugin properties, including the directory name, version, basename, and add a transient name.
	 *
	 * @param Properties $properties These properties are passed in when instantiating to identify the plugin and it's update location.
	 * @param ApiUrl     $api_url    The URL where the api is located.
	 */
	public function get_full_plugin_properties( $properties, $api_url ) {

		$plugins = \get_plugins();

		// Scan through all plugins installed and find the one which matches this one in question.
		foreach ( $plugins as $plugin_basename => $plugin_data ) {
			// Match using the passed-in plugin's basename.
			if ( $plugin_basename === $properties['plugin_basename'] ) {
				// Add the values we need to the properties.
				$properties['plugin_dirname']                   = dirname( $plugin_basename );
				$properties['plugin_version']                   = $plugin_data['Version'];
				$properties['plugin_update_transient_name']     = 'wpesu-plugin-' . sanitize_title( $properties['plugin_dirname'] );
				$properties['plugin_update_transient_exp_name'] = 'wpesu-plugin-' . sanitize_title( $properties['plugin_dirname'] ) . '-expiry';
				$properties['plugin_manifest_url']              = trailingslashit( $api_url ) . trailingslashit( $properties['plugin_slug'] ) . 'info.json';

				return $properties;
			}
		}

		// No matching plugin was found installed.
		return null;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'plugins_api', array( $this, 'filter_plugin_update_info' ), 20, 3 );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_plugin_update_transient' ), 100 );
	}

	/**
	 * Filter the plugin update transient to take over update notifications.
	 *
	 * @param object $transient The site_transient_update_plugins transient.
	 *
	 * @handles site_transient_update_plugins
	 * @return object
	 */
	public function filter_plugin_update_transient( $transient ) {
		// No update object exists. Return early.
		if ( empty( $transient ) ) {
			return $transient;
		}

		$result = $this->fetch_plugin_info();

		if ( false === $result ) {
			return $transient;
		}

		$res = $this->parse_plugin_info( $result );

		if ( version_compare( $this->properties['plugin_version'], $result->version, '<' ) ) {
			$transient->response[ $res->plugin ] = $res;
		} else {
			$transient->no_update[ $res->plugin ] = $res;
		}
		
		return $transient;
	}

	/**
	 * Filters the plugin update information.
	 *
	 * @param object $res    The response to be modified for the plugin in question.
	 * @param string $action The action in question.
	 * @param object $args   The arguments for the plugin in question.
	 *
	 * @handles plugins_api
	 * @return object
	 */
	public function filter_plugin_update_info( $res, $action, $args ) {

		// Do nothing if this is not about getting plugin information.
		if ( 'plugin_information' !== $action ) {
			return $res;
		}

		// Do nothing if it is not our plugin.
		if ( $this->properties['plugin_dirname'] !== $args->slug ) {
			return $res;
		}

		$result = $this->fetch_plugin_info();

		// Do nothing if we don't get the correct response from the server.
		if ( false === $result ) {
			return $res;
		}

		return $this->parse_plugin_info( $result );
	}

	/**
	 * Fetches the plugin update object from the WP Product Info API.
	 *
	 * @return object|false
	 */
	private function fetch_plugin_info() {

		// Fetch cache first.
		$expiry   = get_option( $this->properties['plugin_update_transient_exp_name'], 0 );
		$response = get_option( $this->properties['plugin_update_transient_name'] );
		
		if ( empty( $expiry ) || time() > $expiry || empty( $response ) ) {
			$response = wp_remote_get(
				$this->properties['plugin_manifest_url'],
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			if (
				is_wp_error( $response ) ||
				200 !== wp_remote_retrieve_response_code( $response ) ||
				empty( wp_remote_retrieve_body( $response ) )
			) {
				return false;
			}

			$response = wp_remote_retrieve_body( $response );
			
			// Cache the response.
			update_option( $this->properties['plugin_update_transient_exp_name'], $this->cache_time, false );
			update_option( $this->properties['plugin_update_transient_name'], $response, false );
		}

		$decoded_response = json_decode( $response );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return false;
		}

		return $decoded_response;
	}

	/**
	 * Parses the product info response into an object that WordPress would be able to understand.
	 *
	 * @param object $response The response object.
	 *
	 * @return stdClass
	 */
	private function parse_plugin_info( $response ) {

		global $wp_version;

		$res                = new stdClass();
		$res->name          = $response->name;
		$res->slug          = $response->slug;
		$res->version       = $response->version;
		$res->requires      = $response->requires;
		$res->download_link = $response->download_link;
		$res->trunk         = $response->download_link;
		$res->new_version   = $response->version;
		$res->plugin        = $this->properties['plugin_basename'];
		$res->package       = $response->download_link;

		// Plugin information modal and core update table use a strict version comparison, which is weird.
		// If we're genuinely not compatible with the point release, use our WP tested up to version.
		// otherwise use exact same version as WP to avoid false positive.
		$res->tested = 1 === version_compare( substr( $wp_version, 0, 3 ), $response->tested )
			? $response->tested
			: $wp_version;

		$res->sections = array(
			'description' => $response->sections->description,
			'changelog'   => $response->sections->changelog,
		);

		return $res;
	}
}
