<?php
/**
 * Plugin Name: Single Sign-on with Azure Active Directory
 * Plugin URI: http://github.com/psignoret/aad-sso-wordpress
 * Description: Allows you to use your organization's Azure Active Directory user accounts to log in to WordPress. If your organization is using Office 365, your user accounts are already in Azure Active Directory. This plugin uses OAuth 2.0 to authenticate users, and the Azure Active Directory Graph to get group membership and other details.
 * Author: Philippe Signoret
 * Version: 0.6a
 * Author URI: http://psignoret.com/
 * Text Domain: aad-sso-wordpress
 * Domain Path: /languages/
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

define( 'AADSSO', 'aad-sso-wordpress' );
define( 'AADSSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AADSSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Proxy to be used for calls, should be useful for tracing with Fiddler
// BUGBUG: Doesn't actually work, at least not with WP running on WAMP stack
//define( 'WP_PROXY_HOST', '127.0.0.1' );
//define( 'WP_PROXY_PORT', '8888' );

require_once AADSSO_PLUGIN_DIR . '/includes/sessions/AADSSO_Session.php';
require_once AADSSO_PLUGIN_DIR . '/includes/sessions/AADSSO_PHP_Session.php';
require_once AADSSO_PLUGIN_DIR . '/Settings.php';
require_once AADSSO_PLUGIN_DIR . '/SettingsPage.php';
require_once AADSSO_PLUGIN_DIR . '/AuthorizationHelper.php';
require_once AADSSO_PLUGIN_DIR . '/GraphHelper.php';

// TODO: Auto-load the ( the exceptions at least )
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/JWT.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/BeforeValidException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/ExpiredException.php';
require_once AADSSO_PLUGIN_DIR . '/lib/php-jwt/src/SignatureInvalidException.php';

class AADSSO {

	static $instance = FALSE;

	private $settings = null;

	/**
	 * Instance of AADSSO_Session that provides session replacement.
	 */
	private $session = null;

	public function __construct( $settings, $session ) {
		$this->settings = $settings;
		$this->session = $session;

		// Setup the admin settings page
		$this->setup_admin_settings();

		// Some debugging locations
		//add_action( 'admin_notices', array( $this, 'print_debug' ) );
		//add_action( 'login_footer', array( $this, 'print_debug' ) );

		// Add a link to the Settings page in the list of plugins
		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( $this, 'add_settings_link' )
		);

		// Register activation and deactivation hooks
		register_activation_hook( __FILE__, array( 'AADSSO', 'activate' ) );
		register_deactivation_hook( __FILE__, array( 'AADSSO', 'deactivate' ) );

		// If plugin is not configured, we shouldn't proceed.
		if ( ! $this->plugin_is_configured() ) {
			add_action( 'all_admin_notices', array( $this, 'print_plugin_not_configured' ) );
			return;
		}

		// Add the hook that starts the SESSION
		add_action( 'login_init', array( $this, 'register_session' ), 10 );

		// The authenticate filter
		add_filter( 'authenticate', array( $this, 'authenticate' ), 1, 3 );

		// Add the <style> element to the login page
		add_action( 'login_enqueue_scripts', array( $this, 'print_login_css' ) );

		// Add the link to the organization's sign-in page
		add_action( 'login_form', array( $this, 'print_login_link' ) ) ;

		// Clear session variables when logging out
		add_action( 'wp_logout', array( $this, 'clear_session' ) );

		// If configured, bypass the login form and redirect straight to AAD
		add_action( 'login_init', array( $this, 'save_redirect_and_maybe_bypass_login' ), 20 );

		// Redirect user back to original location
		add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 20, 3 );

		// Register the textdomain for localization after WordPress is initialized.
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Run on activation, checks for stored settings, and if none are found, sets defaults.
	 */
	public static function activate() {
		$stored_settings = get_option( 'aadsso_settings', null );
		if ( null === $stored_settings ) {
			update_option( 'aadsso_settings', AADSSO_Settings::get_defaults() );
		}
	}

	/**
	 * Run on deactivation, currently does nothing.
	 */
	public static function deactivate() { }

	/**
	 * Load the textdomain for localization.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'aad-sso-wordpress',
			false, // deprecated
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'
		);
	}

	/**
	 * Determine if required plugin settings are stored.
	 *
	 * @return bool Whether plugin is configured
	 */
	public function plugin_is_configured() {
		return
			   ! empty( $this->settings->client_id )
			&& ! empty( $this->settings->client_secret )
			&& ! empty( $this->settings->redirect_uri )
		;
	}

	/**
	 * Gets the (only) instance of the plugin. Initializes an instance if it hasn't yet.
	 *
	 * @return \AADSSO The (only) instance of the class.
	 */
	public static function get_instance( $settings, $session ) {
		if ( ! self::$instance ) {
			self::$instance = new self( $settings, $session );
		}
		return self::$instance;
	}

	/**
	 * Based on settings and current page, bypasses the login form and forwards straight to AAD.
	 */
	public function save_redirect_and_maybe_bypass_login() {

		$bypass = apply_filters(
			'aad_auto_forward_login',
			$this->settings->enable_auto_forward_to_aad
		);

		/*
		 * If the user is attempting to log out AND the auto-forward to AAD
		 * login is set then we need to ensure we do not auto-forward the user and get
		 * them stuck in an infinite logout loop.
		 */
		if( $this->wants_to_login() ) {

			// Save the redirect_to query param ( if present ) to session
			if ( isset( $_GET['redirect_to'] ) ) {
				$this->session->set( 'aadsso_redirect_to', sanitize_text_field( $_GET['redirect_to'] ) );
			}

			if ( $bypass && ! isset( $_GET['code'] ) ) {
				wp_safe_redirect( $this->get_login_url() );
				exit;
			}
		}
	}

	/**
	 * Restores the session variable that stored the original 'redirect_to' so that after
	 * authenticating with AAD, the user is returned to the right place.
	 *
	 * @param string $redirect_to
	 * @param string $requested_redirect_to
	 * @param WP_User|WP_Error $user
	 *
	 * @return string
	 */
	public function redirect_after_login( $redirect_to, $requested_redirect_to, $user ) {
		$raw_redirect_to = $this->session->get( 'aadsso_redirect_to' );

		if ( is_a( $user, 'WP_User' ) && null !== $raw_redirect_to ) {
			$redirect_to = esc_url( $raw_redirect_to );
		}

		return $redirect_to;
	}

	/**
	* Checks to determine if the user wants to login on wp-login.
	*
	* This function mostly exists to cover the exceptions to login
	* that may exist as other parameters to $_GET[action] as $_GET[action]
	* does not have to exist. By default WordPress assumes login if an action
	* is not set, however this may not be true, as in the case of logout
	* where $_GET[loggedout] is instead set
	*
	* @return boolean Whether or not the user is trying to log in to wp-login.
	*/
	private function wants_to_login() {
		$wants_to_login = false;
		// Cover default WordPress behavior
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( $_REQUEST['action'] ) : 'login';
		// And now the exceptions
		$action = isset( $_GET['loggedout'] ) ? 'loggedout' : $action;
		if( 'login' == $action ) {
			$wants_to_login = true;
		}

		return $wants_to_login;
	}

	/**
	 * Authenticates the user with Azure AD and WordPress.
	 *
	 * This method, invoked as an 'authenticate' filter, implements the OpenID Connect
	 * Authorization Code Flow grant to sign the user in to Azure AD (if they aren't already),
	 * obtain an ID Token to identify the current user, and obtain an Access Token to access
	 * the Azure AD Graph API.
	 *
	 * @param WP_User|WP_Error $user A WP_User, if the user has already authenticated.
	 * @param string $username The username provided during form-based signing. Not used.
	 * @param string $password The password provided during form-based signing. Not used.
	 *
	 * @return WP_User|WP_Error The authenticated WP_User, or a WP_Error if there were errors.
	 */
	function authenticate( $user, $username, $password ) {

		// Don't re-authenticate if already authenticated
		if ( is_a( $user, 'WP_User' ) ) { return $user; }

		/* If 'code' is present, this is the Authorization Response from Azure AD, and 'code' has
		 * the Authorization Code, which will be exchanged for an ID Token and an Access Token.
		 */
		if ( isset( $_GET['code'] ) ) {

			$antiforgery_id = $this->session->get( 'aadsso_antiforgery-id' );
			$state_is_missing = ! isset( $_GET['state'] );
			$state_doesnt_match = $_GET['state'] != $antiforgery_id;

			if ( $state_is_missing || $state_doesnt_match ) {
				return new WP_Error(
					'antiforgery_id_mismatch',
					sprintf( __( 'ANTIFORGERY_ID mismatch. Expecting %s', 'aad-sso-wordpress' ), $antiforgery_id )
				);
			}

			// Looks like we got a valid authorization code, let's try to get an access token with it
			$token = AADSSO_AuthorizationHelper::get_access_token( sanitize_text_field( $_GET['code'] ), $this->settings );

			// Happy path
			if ( isset( $token->access_token ) ) {

				try {
					$jwt = AADSSO_AuthorizationHelper::validate_id_token(
						$token->id_token,
						$this->settings,
						$antiforgery_id
					);

					AADSSO::debug_log( json_encode( $jwt ) );

				} catch ( Exception $e ) {
					return new WP_Error(
						'invalid_id_token',
						sprintf( __( 'ERROR: Invalid id_token. %s', 'aad-sso-wordpress' ), $e->getMessage() )
					);
				}

				/**
				 * Fires once AAD SSO has validated the JWT token.
				 *
				 * @param $jwt JSON Web Token.
				 */
				do_action( 'aadsso_validated_id_token', $jwt );

				// Invoke any configured matching and auto-provisioning strategy and get the user.
				$user = $this->get_wp_user_from_aad_user( $jwt );

				if ( is_a( $user, 'WP_User' ) ) {

					// At this point, we have an authorization code, an access token and the user
					// exists in WordPress (either because it already existed, or we created it
					// on-the-fly). All that's left is to set the roles based on group membership.
					if ( true === $this->settings->enable_aad_group_to_wp_role ) {
						$user = $this->update_wp_user_roles( $user, $jwt->upn, $jwt->tid );
					}
				}
			} elseif ( isset( $token->error ) ) {

				// Unable to get an access token ( although we did get an authorization code )
				return new WP_Error(
					$token->error,
					sprintf(
						__( 'ERROR: Could not get an access token to Azure Active Directory. %s', 'aad-sso-wordpress' ),
						$token->error_description
					)
				);
			} else {

				// None of the above, I have no idea what happened.
				return new WP_Error( 'unknown', __( 'ERROR: An unknown error occurred.', 'aad-sso-wordpress' ) );
			}

		} elseif ( isset( $_GET['error'] ) ) {

			// The attempt to get an authorization code failed.
			return new WP_Error(
				$_GET['error'],
				sprintf(
					__( 'ERROR: Access denied to Azure Active Directory. %s', 'aad-sso-wordpress' ),
					sanitize_text_field( $_GET['error_description'] )
				)
			);
		}

		if ( is_a( $user, 'WP_User' ) ) {
			/**
			 * Fires after a user is authenticated.
			 *
			 * @param WP_User $user User who was authenticated.
			 */
			do_action( 'aadsso_user_authenticated', $user );
		}

		return $user;
	}

	function get_wp_user_from_aad_user( $jwt ) {

		// Try to find an existing user in WP where the upn or unique_name of the current AAD user is
		// (depending on config) the 'login' or 'email' field in WordPress
		$unique_name = isset( $jwt->upn ) ? $jwt->upn : ( isset( $jwt->unique_name ) ? $jwt->unique_name : null );
		if ( null === $unique_name ) {
			return new WP_Error(
					'unique_name_not_found',
					__( 'ERROR: Neither \'upn\' nor \'unique_name\' claims not found in ID Token.',
						'aad-sso-wordpress' )
				);
		}

		$user = get_user_by( $this->settings->field_to_match_to_upn, $unique_name );

		if( true === $this->settings->match_on_upn_alias ) {
			if ( ! is_a( $user, 'WP_User' ) ) {
				$username = explode( sprintf( '@%s', $this->settings->org_domain_hint ), $unique_name );
				$user = get_user_by( $this->settings->field_to_match_to_upn, $username[0] );
			}
		}

		if ( ! is_a( $user, 'WP_User' ) ) {

			// Since the user was authenticated with AAD, but not found in WordPress,
			// need to decide whether to create a new user in WP on-the-fly, or to stop here.
			if( true === $this->settings->enable_auto_provisioning ) {

				// Setup the minimum required user data
				// TODO: Is null better than a random password?
				// TODO: Look for otherMail, or proxyAddresses before UPN for email
				$userdata = array(
					'user_email' => $unique_name,
					'user_login' => $unique_name,
					'first_name' => $jwt->given_name,
					'last_name'	=> $jwt->family_name,
					'user_pass'	=> null
				);

				/**
				 * Fires before a new user is inserted.
				 *
				 * @param array $userdata User Data to be inserted.
				 */
				do_action( 'aadsso_insert_user', $userdata );

				$new_user_id = wp_insert_user( $userdata );
				AADSSO::debug_log( 'Created new user: \'' . $unique_name . '\', user id ' . $new_user_id . '.' );

				$user = new WP_User( $new_user_id );

			} else {

				// The user was authenticated, but not found in WP and auto-provisioning is disabled
				return new WP_Error(
					'user_not_registered',
					sprintf(
						__( 'ERROR: The authenticated user %s is not a registered user in this blog.', 'aad-sso-wordpress' ),
						$jwt->upn
					)
				);
			}
		}

		return $user;
	}

	/**
		* Sets a WordPress user's role based on their AAD group memberships
		*
		* @param WP_User $user
		* @param string $aad_user_id The AAD object id of the user
		* @param string $aad_tenant_id The AAD directory tenant ID
		*
		* @return WP_User|WP_Error Return the WP_User with updated rols, or WP_Error if failed.
		*/
	function update_wp_user_roles( $user, $aad_user_id, $aad_tenant_id ) {

		// Pass the settings to GraphHelper
		AADSSO_GraphHelper::$settings = $this->settings;
		AADSSO_GraphHelper::$tenant_id = $aad_tenant_id;

		// Of the AAD groups defined in the settings, get only those where the user is a member
		$group_ids = array_keys( $this->settings->aad_group_to_wp_role_map );
		$group_memberships = AADSSO_GraphHelper::user_check_member_groups( $aad_user_id, $group_ids );

		// Determine which WordPress role the AAD group corresponds to.
		// TODO: Check for error in the group membership response
		$role_to_set = array();

		if ( ! empty( $group_memberships->value ) ) {
			foreach ( $this->settings->aad_group_to_wp_role_map as $aad_group => $wp_role ) {
				if ( in_array( $aad_group, $group_memberships->value ) ) {
					array_push( $role_to_set, $wp_role );
				}
			}
		}

		if ( ! empty( $role_to_set ) ) {
			$user->set_role("");
			foreach ( $role_to_set as $role ){
				$user->add_role( $role );
			}
		} else if ( null != $this->settings->default_wp_role || "" != $this->settings->default_wp_role ){
			$user->set_role( $this->settings->default_wp_role );
		} else{
			return new WP_Error(
				'user_not_member_of_required_group',
				sprintf(
					__( 'ERROR: AAD user %s is not a member of any group granting a role.', 'aad-sso-wordpress' ),
					$aad_user_id
				)
			);
		}

		return $user;
	}

	/**
	 * Adds a link to the settings page.
	 *
	 * @param array $links The existing list of links
	 *
	 * @return array The new list of links to display
	 */
	function add_settings_link( $links ) {
		$link_to_settings = '<a href="' . esc_url( admin_url( 'options-general.php?page=aadsso_settings' ) ) . '">' .
		                    __( 'Settings', 'aad-sso-wordpress' ) .
		                    '</a>';
		array_push( $links, $link_to_settings );

		return $links;
	}

	/**
	 * Generates the URL used to initiate a sign-in with Azure AD.
	 *
	 * @return string The authorization URL used to initiate a sign-in to Azure AD.
	 */
	function get_login_url() {
		$antiforgery_id = com_create_guid();
		$this->session->set( 'aadsso_antiforgery-id', $antiforgery_id );
		return AADSSO_AuthorizationHelper::get_authorization_url( $this->settings, $antiforgery_id );
	}

	/**
	 * Generates the URL for logging out of Azure AD. (Does not log out of WordPress.)
	 */
	function get_logout_url() {

		// logout_redirect_uri is not a required setting, use default value if none is set
		$logout_redirect_uri = $this->settings->logout_redirect_uri;
		if ( empty( $logout_redirect_uri ) ) {
			$logout_redirect_uri = AADSSO_Settings::get_defaults('logout_redirect_uri');
		}

		return $this->settings->end_session_endpoint
			. '?'
			. http_build_query(
				array( 'post_logout_redirect_uri' => $logout_redirect_uri )
			);
	}

	/**
	 * Get Session Instance.
	 *
	 * @return AADSSO_Session
	 */
	public function get_session() {
		return $this->session;
	}

	/**
	 * Starts a new session.
	 */
	function register_session() {
		$this->session->start();
	}

	/**
	 * Clears the current the session (e.g. as part of logout).
	 */
	function clear_session() {
		$this->session->destroy();
	}

	/*** Settings ***/

	/**
	 * Add filters and actions for admin settings.
	 */
	public function setup_admin_settings() {
		if ( is_admin() ) {
			$azure_active_directory_settings = new AADSSO_Settings_Page();
		}
	}


	/*** View ***/

	/**
	 * Renders the error message shown if this plugin is not correctly configured.
	 */
	function print_plugin_not_configured() {
		echo '<div id="message" class="error"><p>'
		. __( 'Single Sign-on with Azure Active Directory required settings are not defined. '
		      . 'Update them under Settings > Azure AD.', 'aad-sso-wordpress' )
		      .'</p></div>';
	}

	/**
	 * Renders some debugging data.
	 */
	function print_debug() {
		echo '<p>SESSION</p><pre>' . var_export( $this->session, TRUE ) . '</pre>';
		echo '<p>GET</pre><pre>' . var_export( $_GET, TRUE ) . '</pre>';
		echo '<p>Database settings</p><pre>' .var_export( get_option( 'aadsso_settings' ), true ) . '</pre>';
		echo '<p>Plugin settings</p><pre>' . var_export( $this->settings, true ) . '</pre>';
	}

	/**
	 * Renders the CSS used by the HTML injected into the login page.
	 */
	function print_login_css() {
		wp_enqueue_style( AADSSO, AADSSO_PLUGIN_URL . '/login.css' );
	}

	/**
	 * Renders the link used to initiate the login to Azure AD.
	 */
	function print_login_link() {
		$html = '<p class="aadsso-login-form-text">';
		$html .= '<a href="%s">';
		$html .= sprintf( __( 'Sign in with your %s account', 'aad-sso-wordpress' ),
		                  htmlentities( $this->settings->org_display_name ) );
		$html .= '</a><br /><a class="dim" href="%s">'
		         . __( 'Sign out', 'aad-sso-wordpress' ) . '</a></p>';
		printf(
			$html,
			esc_url( $this->get_login_url() ),
			esc_url( $this->get_logout_url() )
		);
	}

	public static function debug_log( $message ) {
		if ( defined('AADSSO_DEBUG') && true === AADSSO_DEBUG ) {
			if ( strpos( $message, "\n" ) === false ) {
				error_log( 'AADSSO: ' . $message );
			} else {
				$lines = explode( "\n", str_replace( "\r\n", "\n", $message ) );
				foreach ( $lines as $line ) {
					AADSSO::debug_log( $line );
				}
			}
		}
	}

	/**
	 * Prints the debug backtrace using this class' debug_log function.
	 */
	public static function debug_print_backtrace() {
		ob_start();
		debug_print_backtrace();
		$trace = ob_get_contents();
		ob_end_clean();
		self::debug_log( $trace );
	}
}

/**
 * Initialize the AADSSO Plugin main class and return the single instance of AADSSO.
 */
function aadsso() {
	global $aadsso;

	if ( ! isset( $aadsso ) ) {

		// Load settings JSON contents from DB and initialize the plugin
		$aadsso_settings_instance = AADSSO_Settings::init();

		/**
		 * Filter the AADSSO Session instance.
		 * By default `AADSSO_PHP_Session` is used. You can replace it with your implementation of `AADSSO_Session`.
		 */
		$aadsso_session_instance = apply_filters( 'aadsso_session_init', new AADSSO_PHP_Session() );

		$aadsso = AADSSO::get_instance( $aadsso_settings_instance, $aadsso_session_instance );
	}

	return $aadsso;
}
add_action( 'plugins_loaded', 'aadsso' );

/*** Utility functions ***/

if ( ! function_exists( 'com_create_guid' ) ) {
	/**
	 * Generates a globally unique identifier ( Guid ).
	 *
	 * @return string A new random globally unique identifier.
	 */
	function com_create_guid() {
		mt_srand( ( double )microtime() * 10000 );
		$charid = strtoupper( md5( uniqid( rand(), true ) ) );
		$hyphen = chr( 45 ); // "-"
		$uuid = chr( 123 ) // "{"
			.substr( $charid, 0, 8 ) . $hyphen
			.substr( $charid, 8, 4 ) . $hyphen
			.substr( $charid, 12, 4 ) . $hyphen
			.substr( $charid, 16, 4 ) . $hyphen
			.substr( $charid, 20, 12 )
			.chr( 125 ); // "}"
		return $uuid;
	}
}
