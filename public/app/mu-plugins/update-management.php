<?php
/*
Plugin Name: Update Management
Plugin URI: https://github.com/christinaarntz/wp-composer-stack/
Description: Prevents updates in production and automatically adjusts composer.json when updates are made.
Version: 1.0.0
Author: Felix Arntz
Author URI: https://leaves-and-love.net
License: GNU General Public License v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Leaves_And_Love\Update_Management;

class Update_Management {

	protected $composer_file;

	protected $composer_data;

	protected $composer_changed = false;

	public function run() {
		add_filter( 'automatic_updater_disabled', '__return_true' );

		$environment = defined( 'WP_ENV' ) ? WP_ENV : 'production';

		if ( 'production' === $environment ) {
			$update_types = array(
				'core_major'  => false,
				'core_minor'  => false,
				'core_dev'    => false,
				'plugin'      => false,
				'theme'       => false,
				'translation' => true,
			);

			foreach ( $update_types as $update_type => $enable ) {
				if ( strpos( $update_type, 'core_' ) === 0 ) {
					$filter_name = 'allow_' . str_replace( 'core_', '', $update_type ) . '_auto_core_updates';
				} else {
					$filter_name = 'auto_update_' . $update_type;
				}
				add_filter( $filter_name, $enable ? '__return_true' : '__return_false' );
			}
		} else {
			$this->composer_file = dirname( WP_CONTENT_DIR ) . '/composer.json';
			if ( ! file_exists( $this->composer_file ) ) {
				$this->composer_file = dirname( dirname( WP_CONTENT_DIR ) ) . '/composer.json';
			}

			if ( file_exists( $this->composer_file ) ) {
				$this->composer_data = json_decode( @file_get_contents( $this->composer_file ), true );

				// TODO: Handle theme deletion. Core does not have a hook for that. :(
				add_action( 'upgrader_process_complete', array( $this, 'on_updated' ), 10, 2 );
				add_filter( 'install_plugin_complete_actions', array( $this, 'on_plugin_installed' ), 10, 3 );
				add_filter( 'install_theme_complete_actions', array( $this, 'on_theme_installed' ), 10, 4 );
				add_action( 'deleted_plugin', array( $this, 'on_plugin_deleted' ), 10, 2 );
				add_action( 'shutdown', array( $this, 'maybe_update_composer' ) );
			}
		}
	}

	public function on_updated( $updater, $data ) {
		$changes = array();

		if ( 'core' === $data['type'] ) {
			$changes['johnpbloch/wordpress'] = $GLOBALS['wp_version'];
		} else {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugins = array();
			if ( ! empty( $data['plugins'] ) ) {
				$plugins = array_values( $data['plugins'] );
			}
			if ( ! empty( $data['plugin'] ) ) {
				$plugins[] = $data['plugin'];
			}

			if ( empty( $plugins ) && 'plugin' === $data['type'] && 'install' === $data['action'] && isset( $_POST['slug'] ) ) {
				$slug      = wp_unslash( $_POST['slug'] );
				$plugins[] = $slug . '/' . $slug . '.php';
			}

			foreach ( $plugins as $plugin_file ) {
				$plugin_slug = trim( basename( dirname( $plugin_file ) ), '/' );
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );

				if ( empty( $plugin_data['Version'] ) ) {
					continue;
				}

				$changes[ 'wpackagist-plugin/' . $plugin_slug ] = $plugin_data['Version'];
			}

			$themes = array();
			if ( ! empty( $data['themes'] ) ) {
				$themes = array_values( $data['themes'] );
			}
			if ( ! empty( $data['theme'] ) ) {
				$themes[] = $data['theme'];
			}

			if ( empty( $themes ) && 'theme' === $data['type'] && 'install' === $data['action'] && isset( $_POST['slug'] ) ) {
				$slug     = wp_unslash( $_POST['slug'] );
				$themes[] = $slug;
			}

			foreach ( $themes as $stylesheet ) {
				$theme_slug = trim( $stylesheet, '/' );
				$theme_data = wp_get_theme( $stylesheet );

				if ( ! $theme_data || empty( $theme_data['Version'] ) ) {
					continue;
				}

				$changes[ 'wpackagist-theme/' . $theme_slug ] = $theme_data['Version'];
			}
		}

		if ( ! empty( $changes ) ) {
			foreach ( $changes as $package => $version ) {
				$this->composer_data['require'][ $package ] = $version;
			}

			$this->composer_changed = true;
		}
	}

	public function on_plugin_installed( $actions, $api, $plugin_file ) {
		$plugin_slug = trim( basename( dirname( $plugin_file ) ), '/' );

		if ( empty( $api ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			if ( empty( $plugin_data['Version'] ) ) {
				return $actions;
			}

			$version = $plugin_data['Version'];
		} else {
			if ( empty( $api->version ) ) {
				return $actions;
			}

			$version = $api->version;
		}

		$this->composer_data['require'][ 'wpackagist-plugin/' . $plugin_slug ] = $version;

		$this->composer_changed = true;

		return $actions;
	}

	public function on_theme_installed( $actions, $api, $stylesheet, $theme_data ) {
		$theme_slug = trim( $stylesheet, '/' );

		if ( ! $theme_data || empty( $theme_data['Version'] ) ) {
			return $actions;
		}

		$this->composer_data['require'][ 'wpackagist-theme/' . $theme_slug ] = $theme_data['Version'];

		$this->composer_changed = true;

		return $actions;
	}

	public function on_plugin_deleted( $plugin_file, $deleted ) {
		if ( ! $deleted ) {
			return;
		}

		$plugin_slug = trim( basename( dirname( $plugin_file ) ), '/' );

		if ( isset( $this->composer_data['require'][ 'wpackagist-plugin/' . $plugin_slug ] ) ) {
			unset( $this->composer_data['require'][ 'wpackagist-plugin/' . $plugin_slug ] );

			$this->composer_changed = true;
		}
	}

	public function maybe_update_composer() {
		if ( ! $this->composer_changed ) {
			return;
		}

		@file_put_contents( $this->composer_file, json_encode( $this->composer_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}

( new Update_Management() )->run();
