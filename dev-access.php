<?php
/**
 * Plugin Name:       DEV Access & Environment Tools
 * Plugin URI:        https://akkusys.de
 * Description:       DEV-Umgebung Erkennung, ADMIN-Bar Styling, Login-Branding, Zugriffsbegrenzung und Sicherheits-Audit-Log.
 * Version:           1.2.2
 * Author:            Marc Mirschel
 * Author URI:        https://mirschel.biz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev-access
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin-Konstanten definieren
define( 'DAET_VERSION', '1.2.2' );
define( 'DAET_PLUGIN_FILE', __FILE__ );
define( 'DAET_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DAET_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DAET_AUDIT_TABLE', 'daet_audit_log' );
define( 'DAET_DB_VERSION', '1.0' );

/**
 * Plugin-Aktivierung: Erstelle Audit-Log-Tabelle
 */
register_activation_hook( __FILE__, 'daet_activation' );
function daet_activation() {
    daet_create_audit_table();
    
    // Setze Default-Werte für wichtige Optionen
    $options = get_option( 'daet_settings', array() );
    
    // Aktiviere Audit-Log standardmäßig
    if ( ! isset( $options['enable_audit_log'] ) ) {
        $options['enable_audit_log'] = 1;
    }
    
    // Aktiviere Debug-Log standardmäßig für DEV
    if ( ! isset( $options['enable_debug_log'] ) ) {
        $options['enable_debug_log'] = 1;
    }
    
    // Setze Default Retention Days
    if ( ! isset( $options['audit_retention_days'] ) ) {
        $options['audit_retention_days'] = 30;
    }
    
    update_option( 'daet_settings', $options );
}

/**
 * Erstellt oder aktualisiert die Audit-Log-Tabelle
 */
function daet_create_audit_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . DAET_AUDIT_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        event_type varchar(50) NOT NULL,
        event_severity varchar(20) DEFAULT 'info',
        user_id bigint(20) DEFAULT 0,
        username varchar(100) DEFAULT '',
        ip_address varchar(45) NOT NULL,
        user_agent text,
        event_details longtext,
        event_time datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY event_type (event_type),
        KEY event_time (event_time),
        KEY ip_address (ip_address)
    ) $charset_collate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    
    // Versionsnummer speichern für zukünftige Updates
    update_option( 'daet_db_version', DAET_DB_VERSION );
}

/**
 * Prüft bei jedem Admin-Init, ob die Tabelle existiert
 */
add_action( 'admin_init', 'daet_check_database' );
function daet_check_database() {
    $installed_version = get_option( 'daet_db_version' );
    
    if ( $installed_version !== DAET_DB_VERSION ) {
        daet_create_audit_table();
    }
    
    // Prüfe ob Tabelle wirklich existiert
    global $wpdb;
    $table_name = $wpdb->prefix . DAET_AUDIT_TABLE;
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
        daet_create_audit_table();
    }
}

/**
 * Plugin-Deaktivierung: Optional - Tabelle behalten für Sicherheit
 */
register_deactivation_hook( __FILE__, 'daet_deactivation' );
function daet_deactivation() {
    // Clear scheduled events
    wp_clear_scheduled_hook( 'daet_cleanup_audit_logs' );
}

/**
 * Plugin-Deinstallation: Lösche alle Daten
 */
register_uninstall_hook( __FILE__, 'daet_uninstall' );
function daet_uninstall() {
    global $wpdb;
    
    // Lösche Optionen
    delete_option( 'daet_settings' );
    delete_option( 'daet_db_version' );
    
    // Lösche Audit-Tabelle
    $table_name = $wpdb->prefix . DAET_AUDIT_TABLE;
    $wpdb->query( "DROP TABLE IF EXISTS $table_name" );
    
    // Lösche Transients
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_daet_login_attempts_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_daet_login_attempts_%'" );
}

/**
 * Lädt die Textdomain des Plugins für Übersetzungen.
 */
function daet_load_textdomain() {
    load_plugin_textdomain( 'dev-access', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'daet_load_textdomain' );

/**
 * Audit-Log-Funktion mit verbesserter Fehlerbehandlung
 * @param string $event_type Art des Events
 * @param string $severity Schweregrad (info, warning, error, critical)
 * @param array $details Zusätzliche Details
 */
function daet_audit_log( $event_type, $severity = 'info', $details = array() ) {
    global $wpdb;
    
    // Prüfe ob Audit-Log aktiviert ist
    $options = get_option( 'daet_settings', array() );
    
    // Standardmäßig aktiviert, wenn Option nicht gesetzt
    $audit_enabled = isset( $options['enable_audit_log'] ) ? $options['enable_audit_log'] : 1;
    
    if ( ! $audit_enabled ) {
        return false;
    }
    
    $table_name = $wpdb->prefix . DAET_AUDIT_TABLE;
    
    // Prüfe ob Tabelle existiert
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
        // Versuche Tabelle zu erstellen
        daet_create_audit_table();
        
        // Prüfe erneut
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
            // Tabelle konnte nicht erstellt werden, logge in error_log
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( '[DAET] Audit table could not be created' );
            }
            return false;
        }
    }
    
    $current_user = wp_get_current_user();
    
    $data = array(
        'event_type' => sanitize_text_field( $event_type ),
        'event_severity' => sanitize_text_field( $severity ),
        'user_id' => $current_user->ID ?? 0,
        'username' => $current_user->user_login ?? '',
        'ip_address' => daet_get_client_ip(),
        'user_agent' => substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500 ),
        'event_details' => wp_json_encode( $details ),
        'event_time' => current_time( 'mysql' )
    );
    
    $result = $wpdb->insert( $table_name, $data );
    
    // Debug-Ausgabe bei Fehler
    if ( $result === false && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( '[DAET] Audit log insert failed: ' . $wpdb->last_error );
    }
    
    return $result;
}

/**
 * Cleanup alte Audit-Logs (älter als X Tage)
 */
function daet_cleanup_old_audit_logs() {
    global $wpdb;
    $table_name = $wpdb->prefix . DAET_AUDIT_TABLE;
    
    // Prüfe ob Tabelle existiert
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
        return;
    }
    
    $options = get_option( 'daet_settings' );
    $retention_days = isset( $options['audit_retention_days'] ) ? intval( $options['audit_retention_days'] ) : 30;
    
    if ( $retention_days > 0 ) {
        $wpdb->query( $wpdb->prepare( 
            "DELETE FROM $table_name WHERE event_time < DATE_SUB(NOW(), INTERVAL %d DAY)", 
            $retention_days 
        ) );
    }
}

// Schedule cleanup
add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'daet_cleanup_audit_logs' ) ) {
        wp_schedule_event( time(), 'daily', 'daet_cleanup_audit_logs' );
    }
});
add_action( 'daet_cleanup_audit_logs', 'daet_cleanup_old_audit_logs' );

if ( ! function_exists( 'daet_get_client_ip' ) ) {
    /**
     * Ermittelt die IP-Adresse des Clients sicher.
     * @return string Die validierte IP-Adresse.
     */
    function daet_get_client_ip() {
        $ip_keys = array('REMOTE_ADDR');
        
        // Nur vertrauenswürdige Header prüfen, wenn explizit konfiguriert
        $options = get_option( 'daet_settings' );
        $trust_proxy = isset($options['trust_proxy']) ? (bool)$options['trust_proxy'] : false;
        
        if ( $trust_proxy ) {
            // Reihenfolge ist wichtig: vom spezifischsten zum allgemeinsten
            array_unshift($ip_keys, 'HTTP_CF_CONNECTING_IP'); // Cloudflare
            array_unshift($ip_keys, 'HTTP_CLIENT_IP');
            array_unshift($ip_keys, 'HTTP_X_FORWARDED_FOR');
        }
        
        foreach ( $ip_keys as $key ) {
            if ( array_key_exists( $key, $_SERVER ) === true ) {
                $ip_list = explode( ',', $_SERVER[$key] );
                foreach ( $ip_list as $ip ) {
                    $ip = trim( $ip );
                    
                    // Validierung der IP-Adresse
                    if ( filter_var( $ip, FILTER_VALIDATE_IP, 
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
                        return $ip;
                    } elseif ( filter_var( $ip, FILTER_VALIDATE_IP ) !== false ) {
                        // Private/Reserved IPs nur als Fallback
                        return $ip;
                    }
                }
            }
        }
        
        return '0.0.0.0'; // Sicherer Fallback
    }
}

/**
 * Prüft Rate-Limiting für Login-Versuche
 */
function daet_check_login_rate_limit( $username ) {
    $ip = daet_get_client_ip();
    $transient_key = 'daet_login_attempts_' . md5( $ip );
    $attempts = get_transient( $transient_key );
    
    if ( false === $attempts ) {
        $attempts = array();
    }
    
    // Entferne alte Einträge (älter als 1 Stunde)
    $current_time = time();
    $attempts = array_filter( $attempts, function( $timestamp ) use ( $current_time ) {
        return ( $current_time - $timestamp ) < HOUR_IN_SECONDS;
    });
    
    // Prüfe Anzahl der Versuche
    if ( count( $attempts ) >= 5 ) {
        // Log rate limit exceeded
        daet_audit_log( 'rate_limit_exceeded', 'warning', array(
            'username' => $username,
            'attempts' => count( $attempts )
        ));
        return false; // Rate limit erreicht
    }
    
    // Füge neuen Versuch hinzu
    $attempts[] = $current_time;
    set_transient( $transient_key, $attempts, HOUR_IN_SECONDS );
    
    return true;
}

add_action( 'plugins_loaded', 'daet_setup_environment' );
/**
 * Richtet die Umgebungskonstante WP_ENV ein.
 */
function daet_setup_environment() {
    if ( defined( 'WP_ENV' ) ) return;
    $options = get_option( 'daet_settings' );
    $host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );
    $dev_urls  = ! empty( $options['dev_urls'] ) ? array_filter( array_map( 'trim', explode( ",", strtolower($options['dev_urls']) ) ) ) : [];
    $prod_urls = ! empty( $options['prod_urls'] ) ? array_filter( array_map( 'trim', explode( ",", strtolower($options['prod_urls']) ) ) ) : [];
    $is_dev = false; $is_prod = false;
    foreach ( $dev_urls as $url ) { if ( !empty($url) && strpos( $host, $url ) !== false ) { $is_dev = true; break; } }
    if ( ! $is_dev ) { foreach ( $prod_urls as $url ) { if ( !empty($url) && strpos( $host, $url ) !== false ) { $is_prod = true; break; } } }
    if ( $is_dev ) { define( 'WP_ENV', 'development' ); } elseif ( $is_prod ) { define( 'WP_ENV', 'production' ); } elseif ( ! empty( $options['enable_fallback'] ) ) { define( 'WP_ENV', 'development' ); } else { define( 'WP_ENV', 'production' ); }
    if ( WP_ENV === 'development' ) {
        $debug_log_enabled = isset($options['enable_debug_log']) ? (bool) $options['enable_debug_log'] : true;
        if ( ! defined( 'WP_DEBUG' ) ) define( 'WP_DEBUG', true );
        if ( ! defined( 'WP_DEBUG_LOG' ) ) define( 'WP_DEBUG_LOG', $debug_log_enabled );
        if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) define( 'WP_DEBUG_DISPLAY', false );
    } else { 
        if ( ! defined( 'WP_DEBUG' ) ) define( 'WP_DEBUG', false );
        if ( ! defined( 'WP_DEBUG_LOG' ) ) define( 'WP_DEBUG_LOG', false );
    }
}

add_action( 'admin_menu', 'daet_add_admin_menu' );
/**
 * Fügt die Einstellungsseite zum Admin-Menü hinzu.
 */
function daet_add_admin_menu() {
    $options = get_option('daet_settings');
    $capability = !empty($options['plugin_access_capability']) ? $options['plugin_access_capability'] : 'manage_options';
    
    // Hauptseite
    add_options_page( 
        __( 'DEV Access & Tools', 'dev-access' ), 
        __( 'Environment Tools', 'dev-access' ), 
        $capability, 
        'daet_plugin_settings', 
        'daet_options_page_html' 
    );
    
    // Audit-Log-Seite
    add_submenu_page(
        'tools.php',
        __( 'Security Audit Log', 'dev-access' ),
        __( 'Security Audit Log', 'dev-access' ),
        $capability,
        'daet_audit_log',
        'daet_audit_log_page'
    );
}

/**
 * Audit-Log-Seite rendern
 */
function daet_audit_log_page() {
    $options = get_option('daet_settings');
    $capability = !empty($options['plugin_access_capability']) ? $options['plugin_access_capability'] : 'manage_options';
    
    if ( ! current_user_can( $capability ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'dev-access' ) );
    }
    
    // Lade die Table-Klasse
    require_once DAET_PLUGIN_DIR . 'audit-log-table.php';
    
    $list_table = new DAET_Audit_Log_Table();
    $list_table->prepare_items();
    
    // Handle Clear Log Action
    if ( isset( $_POST['clear_log'] ) && wp_verify_nonce( $_POST['daet_clear_log_nonce'], 'daet_clear_log' ) ) {
        global $wpdb;
        $table_name = $wpdb->prefix . DAET_AUDIT_TABLE;
        $wpdb->query( "TRUNCATE TABLE $table_name" );
        daet_audit_log( 'audit_log_cleared', 'info', array( 'cleared_by' => wp_get_current_user()->user_login ) );
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Audit log has been cleared.', 'dev-access' ) . '</p></div>';
    }
    
    // Status-Anzeige
    $audit_enabled = isset( $options['enable_audit_log'] ) ? $options['enable_audit_log'] : 1;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Security Audit Log', 'dev-access' ); ?></h1>
        
        <?php if ( ! $audit_enabled ) : ?>
            <div class="notice notice-warning">
                <p><?php 
                printf( 
                    esc_html__( 'Audit logging is currently disabled. %s to enable it.', 'dev-access' ),
                    '<a href="' . admin_url( 'options-general.php?page=daet_plugin_settings#enable_audit_log' ) . '">' . esc_html__( 'Go to settings', 'dev-access' ) . '</a>'
                );
                ?></p>
            </div>
        <?php endif; ?>
        
        <p><?php esc_html_e( 'Monitor all security-related events on your site.', 'dev-access' ); ?></p>
        
        <form method="post" style="display: inline;">
            <?php wp_nonce_field( 'daet_clear_log', 'daet_clear_log_nonce' ); ?>
            <button type="submit" name="clear_log" class="button" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to clear all audit logs?', 'dev-access' ); ?>');">
                <?php esc_html_e( 'Clear All Logs', 'dev-access' ); ?>
            </button>
        </form>
        
        <form method="post">
            <?php $list_table->display(); ?>
        </form>
    </div>
    <?php
}

add_action( 'admin_init', 'daet_settings_init' );
/**
 * Initialisiert die Plugin-Einstellungen, Sektionen und Felder.
 */
function daet_settings_init() {
    register_setting( 'daet_options_group', 'daet_settings', 'daet_sanitize_settings' );
    add_settings_section( 'daet_env_section', __( '1. Environment Detection', 'dev-access' ), null, 'daet_plugin_settings' );
    add_settings_field( 'dev_urls', __( 'Development URLs', 'dev-access' ), 'daet_render_field_tagging_ui', 'daet_plugin_settings', 'daet_env_section', [ 'id' => 'dev_urls', 'description' => __( 'Enter URL and press Enter. Protocol (https://) and paths are removed automatically.', 'dev-access' ) ] );
    add_settings_field( 'prod_urls', __( 'Production URLs', 'dev-access' ), 'daet_render_field_tagging_ui', 'daet_plugin_settings', 'daet_env_section', [ 'id' => 'prod_urls', 'description' => __( 'Enter URL and press Enter. For the detection of the production environment.', 'dev-access' ) ] );
    add_settings_field( 'enable_fallback', __( 'Fallback Mode', 'dev-access' ), 'daet_render_field_toggle', 'daet_plugin_settings', 'daet_env_section', [ 'id' => 'enable_fallback', 'label' => __( 'If no URL matches, treat this site as a DEV environment.', 'dev-access' ) ] );
    add_settings_section( 'daet_features_section', __( '2. Features & Styling', 'dev-access' ), null, 'daet_plugin_settings' );
    add_settings_field( 'enable_admin_bar_styling', __( 'Admin Bar Styling', 'dev-access' ), 'daet_render_field_toggle', 'daet_plugin_settings', 'daet_features_section', [ 'id' => 'enable_admin_bar_styling', 'label' => __( 'Enables the styling of the admin bar.', 'dev-access' ) ] );
    add_settings_field( 'enable_login_page_styling', __( 'Customize Login Page', 'dev-access' ), 'daet_render_field_toggle', 'daet_plugin_settings', 'daet_features_section', [ 'id' => 'enable_login_page_styling', 'label' => __( 'Enables customizations on the login page.', 'dev-access' ) ] );
    add_settings_field( 'admin_bar_color', __( 'Admin Bar Color', 'dev-access' ), 'daet_render_field_color', 'daet_plugin_settings', 'daet_features_section', [ 'id' => 'admin_bar_color', 'default' => '#d63638' ] );
    add_settings_field( 'dev_text_color', __( 'DEV Text Color', 'dev-access' ), 'daet_render_field_color', 'daet_plugin_settings', 'daet_features_section', [ 'id' => 'dev_text_color', 'default' => '#d63638' ] );
    add_settings_section( 'daet_texts_section', __( '3. Custom Texts', 'dev-access' ), null, 'daet_plugin_settings' );
    add_settings_field( 'dev_badge_text', __( 'Text for Admin Footer', 'dev-access' ), 'daet_render_field_text', 'daet_plugin_settings', 'daet_texts_section', [ 'id' => 'dev_badge_text', 'default' => '(DEV Stage)' ] );
    add_settings_field( 'login_warning_text', __( 'Text for Login Warning', 'dev-access' ), 'daet_render_field_text', 'daet_plugin_settings', 'daet_texts_section', [ 'id' => 'login_warning_text', 'default' => 'DEV Environment – not for production' ] );
    add_settings_field( 'login_main_heading_text', __( 'Text for Login Title', 'dev-access' ), 'daet_render_field_text', 'daet_plugin_settings', 'daet_texts_section', [ 'id' => 'login_main_heading_text', 'default' => 'DEV ENVIRONMENT' ] );
    add_settings_section( 'daet_access_section', __( '4. Access Restriction', 'dev-access' ), null, 'daet_plugin_settings' );
    add_settings_field( 'enable_access_control', __( 'Access Restriction', 'dev-access' ), 'daet_render_field_toggle', 'daet_plugin_settings', 'daet_access_section', [ 'id' => 'enable_access_control', 'label' => __( 'Restrict frontend access to logged-in users or allowed IPs.', 'dev-access' ) ] );
    add_settings_field( 'allowed_ips', __( 'Allowed IP Addresses', 'dev-access' ), 'daet_render_field_tagging_ui', 'daet_plugin_settings', 'daet_access_section', [ 'id' => 'allowed_ips', 'description' => __( 'Enter IP address and press Enter.', 'dev-access' ) ] );
    add_settings_field( 'allowed_user_agents', __( 'Allowed User Agents', 'dev-access' ), 'daet_render_user_agent_field', 'daet_plugin_settings', 'daet_access_section' );
    add_settings_section( 'daet_plugin_access_section', __( '5. Plugin Access', 'dev-access' ), null, 'daet_plugin_settings' );
    add_settings_field( 'plugin_access_capability', __( 'Required Capability:', 'dev-access' ), 'daet_render_field_select', 'daet_plugin_settings', 'daet_plugin_access_section', [
        'id' => 'plugin_access_capability', 'description' => __( 'Select the minimum capability a user needs to see this settings page.', 'dev-access' ),
        'options' => [ 'manage_options' => __( 'Administrator' ), 'edit_theme_options' => __( 'Editor' ), 'publish_pages' => __( 'Author' ), 'edit_posts' => __( 'Contributor' ) ]
    ] );
    add_settings_section( 'daet_debugging_section', __( '6. Debugging', 'dev-access' ), null, 'daet_plugin_settings' );
    add_settings_field( 'enable_debug_log', __( 'Debug Log', 'dev-access' ), 'daet_render_field_toggle', 'daet_plugin_settings', 'daet_debugging_section', [ 'id' => 'enable_debug_log', 'label' => __( 'Write errors to the `debug.log` file (in the `wp-content` folder).', 'dev-access' ) ] );
    add_settings_section( 'daet_login_restriction_section', __( '7. Login Restriction', 'dev-access' ), null, 'daet_plugin_settings' );
    add_settings_field( 'login_restriction_role_dev', __( 'Minimum Role for DEV Login', 'dev-access' ), 'daet_render_field_role_select', 'daet_plugin_settings', 'daet_login_restriction_section', ['id' => 'login_restriction_role_dev'] );
    add_settings_field( 'login_restriction_role_prod', __( 'Minimum Role for LIVE Login', 'dev-access' ), 'daet_render_field_role_select', 'daet_plugin_settings', 'daet_login_restriction_section', ['id' => 'login_restriction_role_prod'] );
    add_settings_section( 'daet_security_section', __( '8. Security Settings', 'dev-access' ), null, 'daet_plugin_settings' );
    add_settings_field( 'trust_proxy', __( 'Trust Proxy Headers', 'dev-access' ), 'daet_render_field_toggle', 'daet_plugin_settings', 'daet_security_section', [ 'id' => 'trust_proxy', 'label' => __( 'Enable only if behind a trusted proxy (Cloudflare, Load Balancer).', 'dev-access' ) ] );
    add_settings_field( 'enable_rate_limiting', __( 'Login Rate Limiting', 'dev-access' ), 'daet_render_field_toggle', 'daet_plugin_settings', 'daet_security_section', [ 'id' => 'enable_rate_limiting', 'label' => __( 'Limit login attempts to 5 per hour per IP.', 'dev-access' ) ] );
    add_settings_field( 'enable_honeypot', __( 'Honeypot Protection', 'dev-access' ), 'daet_render_field_toggle', 'daet_plugin_settings', 'daet_security_section', [ 'id' => 'enable_honeypot', 'label' => __( 'Add invisible field to catch bots on login page.', 'dev-access' ) ] );
    add_settings_section( 'daet_audit_section', __( '9. Audit Logging', 'dev-access' ), null, 'daet_plugin_settings' );
    add_settings_field( 'enable_audit_log', __( 'Enable Audit Log', 'dev-access' ), 'daet_render_field_toggle', 'daet_plugin_settings', 'daet_audit_section', [ 'id' => 'enable_audit_log', 'label' => __( 'Log all security-related events for monitoring.', 'dev-access' ) ] );
    add_settings_field( 'audit_retention_days', __( 'Log Retention (Days)', 'dev-access' ), 'daet_render_field_number', 'daet_plugin_settings', 'daet_audit_section', [ 'id' => 'audit_retention_days', 'default' => 30, 'min' => 7, 'max' => 365, 'description' => __( 'How many days to keep audit logs. Older logs will be automatically deleted.', 'dev-access' ) ] );
}

/**
 * Rendert ein Zahlenfeld.
 * @param array $args Argumente für das Feld.
 */
function daet_render_field_number( array $args ) {
    $options = get_option( 'daet_settings' );
    $id = $args['id'];
    $value = $options[$id] ?? $args['default'];
    $min = $args['min'] ?? 0;
    $max = $args['max'] ?? 999999;
    ?>
    <input type="number" 
           id="<?php echo esc_attr($id); ?>" 
           name="daet_settings[<?php echo esc_attr($id); ?>]" 
           value="<?php echo esc_attr($value); ?>" 
           min="<?php echo esc_attr($min); ?>" 
           max="<?php echo esc_attr($max); ?>"
           class="small-text">
    <?php if ( ! empty( $args['description'] ) ) : ?>
        <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
    <?php endif;
}

add_action( 'admin_enqueue_scripts', 'daet_admin_assets' );
/**
 * Lädt Admin-Assets (CSS & JS) nur auf Plugin-Seiten.
 * @param string $hook_suffix Der Suffix der aktuellen Admin-Seite.
 */
function daet_admin_assets( $hook_suffix ) {
    // Nur auf unseren Settings-Seiten laden
    if ( ! in_array( $hook_suffix, array( 'settings_page_daet_plugin_settings', 'tools_page_daet_audit_log' ), true ) ) {
        return;
    }
    
    // Prüfen ob Color Picker bereits registriert ist (nur für Settings-Seite)
    if ( $hook_suffix === 'settings_page_daet_plugin_settings' ) {
        if ( ! wp_script_is( 'wp-color-picker', 'registered' ) ) {
            return; // WordPress Core Script sollte immer vorhanden sein
        }
        
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
    }
    
    // Plugin-eigene Assets mit Versionierung aus Plugin-Header
    wp_enqueue_style( 
        'daet-admin-styles', 
        DAET_PLUGIN_URL . 'admin-styles.css', 
        array(), 
        DAET_VERSION 
    );
    
    wp_enqueue_script( 
        'daet-admin-scripts', 
        DAET_PLUGIN_URL . 'admin-scripts.js', 
        array('jquery'), 
        DAET_VERSION, 
        true 
    );
    
    // Nonce für AJAX-Requests
    wp_localize_script( 'daet-admin-scripts', 'daet_ajax', array(
        'nonce' => wp_create_nonce( 'daet_ajax_nonce' ),
        'ajax_url' => admin_url( 'admin-ajax.php' )
    ));
}

/**
 * Rendert die HTML-Struktur der Einstellungsseite.
 */
function daet_options_page_html() {
    $options = get_option('daet_settings');
    $capability = !empty($options['plugin_access_capability']) ? $options['plugin_access_capability'] : 'manage_options';
    if ( ! current_user_can( $capability ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'dev-access' ) );
    }
    
    // Status des Audit-Logs
    $audit_enabled = isset( $options['enable_audit_log'] ) ? $options['enable_audit_log'] : 1;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'DEV Access & Environment Tools', 'dev-access' ); ?></h1>
        <?php settings_errors(); ?>
        
        <?php if ( $audit_enabled ) : ?>
            <div class="notice notice-info">
                <p><?php 
                printf( 
                    esc_html__( 'Audit logging is active. %s', 'dev-access' ),
                    '<a href="' . admin_url( 'tools.php?page=daet_audit_log' ) . '">' . esc_html__( 'View Security Audit Log', 'dev-access' ) . '</a>'
                );
                ?></p>
            </div>
        <?php else : ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e( 'Audit logging is currently disabled. Enable it in the settings below to track security events.', 'dev-access' ); ?></p>
            </div>
        <?php endif; ?>
        
        <div style="margin: 20px 0;">
            <a href="<?php echo admin_url( 'tools.php?page=daet_audit_log' ); ?>" class="button button-secondary">
                <?php esc_html_e( 'View Security Audit Log', 'dev-access' ); ?>
            </a>
        </div>
        <form action="options.php" method="post">
            <?php 
            settings_fields( 'daet_options_group' ); 
            do_settings_sections( 'daet_plugin_settings' ); 
            submit_button( __( 'Save Changes', 'dev-access' ) ); 
            ?>
        </form>
    </div>
    <?php
}

/**
 * Rendert das Auswahlfeld für die Benutzerrollen.
 * @param array $args Argumente für das Feld.
 */
function daet_render_field_role_select( array $args ) {
    $options = get_option('daet_settings');
    $id = $args['id'];
    $current_value = $options[$id] ?? 'subscriber';
    
    // WordPress-native Rollen-Funktion nutzen
    $wp_roles = wp_roles();
    $role_names = $wp_roles->get_names();
    
    // Sortierung nach Capabilities-Level
    $sorted_roles = array(
        'administrator' => $role_names['administrator'] ?? __('Administrator'),
        'editor' => $role_names['editor'] ?? __('Editor'),
        'author' => $role_names['author'] ?? __('Author'),
        'contributor' => $role_names['contributor'] ?? __('Contributor'),
        'subscriber' => $role_names['subscriber'] ?? __('Subscriber'),
    );
    ?>
    <select id="<?php echo esc_attr($id); ?>" name="daet_settings[<?php echo esc_attr($id); ?>]">
        <?php foreach ( $sorted_roles as $role_slug => $role_name ) : ?>
            <option value="<?php echo esc_attr($role_slug); ?>" <?php selected($current_value, $role_slug); ?>>
                <?php echo esc_html($role_name); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="description"><?php esc_html_e( 'Users with this role and higher are allowed to log in. All others will be blocked.', 'dev-access' ); ?></p>
    <?php
}

/**
 * Rendert einen Toggle-Schalter.
 * @param array $args Argumente für das Feld.
 */
function daet_render_field_toggle( array $args ) {
    $options = get_option('daet_settings');
    $id = $args['id'];
    
    // Spezielle Default-Behandlung für wichtige Features
    if ( $id === 'enable_audit_log' ) {
        $default_value = ! isset( $options[$id] ) ? 1 : $options[$id];
    } elseif ( $id === 'enable_debug_log' ) {
        $default_value = ! isset( $options[$id] ) ? 1 : $options[$id];
    } else {
        $default_value = $options[$id] ?? 0;
    }
    
    $value = $default_value;
    ?>
    <div style="display: flex; align-items: center; gap: 10px;">
        <label class="daet-toggle-switch">
            <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="daet_settings[<?php echo esc_attr($id); ?>]" value="1" <?php checked($value, 1); ?>>
            <span class="daet-toggle-slider"></span>
        </label>
        <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($args['label']); ?></label>
    </div>
    <?php
}

/**
 * Rendert das Feld für User-Agents.
 */
function daet_render_user_agent_field() {
    $options = get_option( 'daet_settings' );
    $id = 'allowed_user_agents';
    $default_value = "Googlebot\nLighthouse\nGTmetrix\nScreaming Frog\nGemini-Deep-Research";
    $value = $options[$id] ?? $default_value;
    $services = [ 
        'Google PageSpeed' => 'Googlebot,Lighthouse', 
        'GTmetrix' => 'GTmetrix', 
        'Screaming Frog' => 'Screaming Frog', 
        'Google (General)' => 'Googlebot', 
        'Gemini Research' => 'Gemini-Deep-Research' 
    ];
    ?>
    <fieldset>
        <legend class="screen-reader-text"><span><?php esc_html_e( 'Common Services', 'dev-access' ); ?></span></legend>
        <p style="margin-top:0;"><b><?php esc_html_e( 'Select common services:', 'dev-access' ); ?></b></p>
        <?php foreach ( $services as $label => $agents ) : ?>
            <label style="margin-right: 20px;">
                <input type="checkbox" class="daet-ua-checkbox" data-agents="<?php echo esc_attr($agents); ?>">
                <?php echo esc_html($label); ?>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <p style="margin-top: 15px;"><b><?php esc_html_e( 'Custom / More User Agents:', 'dev-access' ); ?></b></p>
    <textarea id="<?php echo esc_attr($id); ?>" name="daet_settings[<?php echo esc_attr($id); ?>]" rows="4" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
    <p class="description"><?php esc_html_e( 'Enter identifiers of tools here (one per line) that are allowed to bypass the access restriction.', 'dev-access' ); ?></p>
    <?php
}

/**
 * Rendert ein Select-Feld.
 * @param array $args Argumente für das Feld.
 */
function daet_render_field_select( array $args ) {
    $options = get_option('daet_settings');
    $id = $args['id'];
    $current_value = $options[$id] ?? 'manage_options';
    echo '<select id="'.esc_attr($id).'" name="daet_settings['.esc_attr($id).']">';
    foreach ($args['options'] as $value => $label) {
        echo '<option value="' . esc_attr($value) . '" ' . selected($current_value, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . esc_html($args['description']) . '</p>';
}

/**
 * Rendert die Tagging-UI für URLs und IPs.
 * @param array $args Argumente für das Feld.
 */
function daet_render_field_tagging_ui( array $args ) {
    $options = get_option( 'daet_settings' );
    $id = $args['id'];
    $value_csv = $options[$id] ?? '';
    $tags = !empty($value_csv) ? explode(',', $value_csv) : [];
    $style_attribute = empty($tags) ? 'style="display: none;"' : '';
    ?>
    <div class="daet-tag-container">
        <input type="text" class="daet-tag-input" data-field-id="<?php echo esc_attr($id); ?>">
        <?php if ( $id === 'allowed_ips' ) : $current_ip = daet_get_client_ip(); ?>
            <p style="margin-top: 5px; margin-bottom: 10px;">
                <a href="#" id="daet-add-current-ip" data-ip-address="<?php echo esc_attr($current_ip); ?>" class="button button-small">
                    <?php printf( esc_html__( 'Add my current IP address (%s)', 'dev-access' ), esc_html($current_ip) ); ?>
                </a>
            </p>
        <?php endif; ?>
        <div class="daet-tags-display" <?php echo $style_attribute; ?>>
            <?php foreach ( $tags as $tag ): if(empty($tag)) continue; ?>
                <span class="daet-tag-item button-primary"><?php echo esc_html( $tag ); ?><span class="daet-remove-tag" data-tag="<?php echo esc_attr($tag); ?>">×</span></span>
            <?php endforeach; ?>
        </div>
        <input type="hidden" class="daet-tag-hidden-input" id="<?php echo esc_attr($id); ?>" name="daet_settings[<?php echo esc_attr($id); ?>]" value="<?php echo esc_attr($value_csv); ?>" />
        <div class="daet-feedback"></div>
        <p class="description"><?php echo esc_html($args['description']); ?></p>
    </div>
    <?php
}

/**
 * Rendert ein Textfeld.
 * @param array $args Argumente für das Feld.
 */
function daet_render_field_text( array $args ) { 
    $options = get_option( 'daet_settings' ); 
    $id = $args['id']; 
    $value = $options[$id] ?? $args['default']; 
    echo '<input type="text" id="'.esc_attr($id).'" name="daet_settings['.esc_attr($id).']" value="'.esc_attr($value).'" class="regular-text">'; 
}

/**
 * Rendert einen Color-Picker.
 * @param array $args Argumente für das Feld.
 */
function daet_render_field_color( array $args ) { 
    $options = get_option( 'daet_settings' ); 
    $id = $args['id']; 
    $value = $options[$id] ?? $args['default']; 
    echo '<input type="text" id="'.esc_attr($id).'" name="daet_settings['.esc_attr($id).']" value="'.esc_attr($value).'" class="wp-color-picker-field" data-default-color="'.esc_attr($args['default']).'">'; 
}

/**
 * Bereinigt die Plugin-Einstellungen vor dem Speichern.
 * @param array $input Die rohen Eingabedaten.
 * @return array Die bereinigten Daten.
 */
function daet_sanitize_settings( $input ) {
    $sanitized_input = [];
    
    // Alte Einstellungen für Vergleich
    $old_settings = get_option( 'daet_settings' );
    
    $tag_fields = ['dev_urls', 'prod_urls', 'allowed_ips'];
    foreach($tag_fields as $field) {
        if (!isset($input[$field])) continue;
        $tags = explode(',', $input[$field]);
        $clean_tags = [];
        if ($field === 'dev_urls' || $field === 'prod_urls') {
            foreach($tags as $url) {
                $trimmed_url = trim($url); 
                if (empty($trimmed_url)) continue;
                $schemed_url = preg_match('#^https?://#', $trimmed_url) ? $trimmed_url : 'http://' . $trimmed_url;
                $host = parse_url($schemed_url, PHP_URL_HOST);
                if ($host) { 
                    $clean_tags[] = sanitize_text_field($host); 
                }
            }
        } elseif ($field === 'allowed_ips') {
            // IP-Adressen validieren
            foreach($tags as $ip) {
                $clean_ip = trim($ip);
                if (filter_var($clean_ip, FILTER_VALIDATE_IP)) {
                    $clean_tags[] = $clean_ip;
                }
            }
        } else { 
            $clean_tags = array_map('sanitize_text_field', $tags); 
        }
        $sanitized_input[$field] = implode(',', array_unique(array_filter($clean_tags)));
    }
    $checkboxes = ['enable_fallback', 'enable_admin_bar_styling', 'enable_login_page_styling', 'enable_access_control', 'enable_debug_log', 'trust_proxy', 'enable_rate_limiting', 'enable_honeypot', 'enable_audit_log'];
    foreach($checkboxes as $field){ 
        $sanitized_input[$field] = !empty($input[$field]) ? 1 : 0; 
    }
    $text_fields = ['dev_badge_text', 'login_warning_text', 'login_main_heading_text'];
    foreach($text_fields as $field){ 
        $sanitized_input[$field] = isset($input[$field]) ? sanitize_text_field($input[$field]) : ''; 
    }
    $color_fields = ['admin_bar_color', 'dev_text_color'];
    foreach($color_fields as $field){ 
        if (isset($input[$field]) && preg_match('/^#([a-f0-9]{6}|[a-f0-9]{3})$/i', $input[$field])) { 
            $sanitized_input[$field] = $input[$field]; 
        } else { 
            $sanitized_input[$field] = '#000000'; 
        } 
    }
    if (isset($input['plugin_access_capability'])) {
        $allowed_caps = ['manage_options', 'edit_theme_options', 'publish_pages', 'edit_posts'];
        if (in_array($input['plugin_access_capability'], $allowed_caps, true)) {
            $sanitized_input['plugin_access_capability'] = $input['plugin_access_capability'];
        }
    }
    if (isset($input['allowed_user_agents'])) {
        $lines = explode("\n", $input['allowed_user_agents']);
        $clean_lines = array_unique(array_filter(array_map('sanitize_text_field', $lines)));
        $sanitized_input['allowed_user_agents'] = implode("\n", $clean_lines);
    }
    $role_fields = ['login_restriction_role_dev', 'login_restriction_role_prod'];
    $existing_roles = array_keys(wp_roles()->roles);
    foreach ($role_fields as $field) {
        if (isset($input[$field]) && in_array($input[$field], $existing_roles, true)) {
            $sanitized_input[$field] = $input[$field];
        }
    }
    
    // Audit Retention Days
    if (isset($input['audit_retention_days'])) {
        $retention = intval($input['audit_retention_days']);
        $sanitized_input['audit_retention_days'] = max(7, min(365, $retention));
    }
    
    // Log Settings-Änderungen - aber nur wenn Audit aktiviert ist oder wird
    $audit_will_be_enabled = isset( $sanitized_input['enable_audit_log'] ) ? $sanitized_input['enable_audit_log'] : 
                             (isset( $old_settings['enable_audit_log'] ) ? $old_settings['enable_audit_log'] : 1);
    
    if ( $audit_will_be_enabled ) {
        $changes = array();
        foreach ( $sanitized_input as $key => $value ) {
            $old_value = isset( $old_settings[$key] ) ? $old_settings[$key] : null;
            if ( $old_value != $value ) {
                $changes[$key] = array(
                    'old' => $old_value,
                    'new' => $value
                );
            }
        }
        if ( ! empty( $changes ) ) {
            daet_audit_log( 'settings_changed', 'info', $changes );
        }
    }
    
    return $sanitized_input;
}

/**
 * Honeypot-Feld zur Login-Seite hinzufügen
 */
add_action( 'login_form', 'daet_add_honeypot_field' );
function daet_add_honeypot_field() {
    $options = get_option( 'daet_settings' );
    if ( empty( $options['enable_honeypot'] ) ) {
        return;
    }
    ?>
    <p style="position: absolute; left: -9999px; height: 0; width: 0; overflow: hidden;">
        <label for="daet_email_confirm"><?php esc_html_e( 'Confirm Email', 'dev-access' ); ?></label>
        <input type="text" name="daet_email_confirm" id="daet_email_confirm" class="input" value="" size="20" autocomplete="off" />
    </p>
    <?php
}

/**
 * Honeypot-Validierung
 */
add_filter( 'authenticate', 'daet_validate_honeypot', 10, 2 );
function daet_validate_honeypot( $user, $username ) {
    $options = get_option( 'daet_settings' );
    if ( empty( $options['enable_honeypot'] ) ) {
        return $user;
    }
    
    // Honeypot-Feld sollte leer sein
    if ( isset( $_POST['daet_email_confirm'] ) && ! empty( $_POST['daet_email_confirm'] ) ) {
        // Log verdächtige Aktivität
        daet_audit_log( 'honeypot_triggered', 'warning', array(
            'username' => $username,
            'honeypot_value' => substr( $_POST['daet_email_confirm'], 0, 50 )
        ));
        
        return new WP_Error( 'daet_honeypot_triggered', __( 'Security check failed.', 'dev-access' ) );
    }
    
    return $user;
}

// Log failed login attempts - mit korrekter Priority
add_action( 'wp_login_failed', 'daet_log_failed_login', 10 );
function daet_log_failed_login( $username ) {
    if ( ! empty( $username ) ) {
        daet_audit_log( 'login_failed', 'warning', array(
            'username' => sanitize_text_field( $username )
        ));
    }
}

// Log successful logins
add_action( 'wp_login', 'daet_log_successful_login', 10, 2 );
function daet_log_successful_login( $user_login, $user ) {
    daet_audit_log( 'login_success', 'info', array(
        'username' => $user_login,
        'user_id' => $user->ID
    ));
}

add_action('init', function() {
    if ( !defined( 'WP_ENV' ) || WP_ENV !== 'development' ) return;
    $options = get_option( 'daet_settings' );
    $admin_bar_color = !empty($options['admin_bar_color']) ? $options['admin_bar_color'] : '#d63638';
    $dev_text_color  = !empty($options['dev_text_color']) ? $options['dev_text_color'] : '#d63638';
    $dev_badge_text  = !empty($options['dev_badge_text']) ? $options['dev_badge_text'] : '(DEV Stage)';
    $login_warning_text = !empty($options['login_warning_text']) ? $options['login_warning_text'] : 'DEV-Umgebung – nicht produktiv';
    $login_main_heading_text = !empty($options['login_main_heading_text']) ? $options['login_main_heading_text'] : 'DEV ENVIRONMENT';
    
    // Security Headers hinzufügen
    if ( ! is_admin() ) {
        add_action( 'send_headers', function() {
            header( 'X-Content-Type-Options: nosniff' );
            header( 'X-Frame-Options: SAMEORIGIN' );
            header( 'X-XSS-Protection: 1; mode=block' );
            header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        });
    }
    
    if ( ! empty( $options['enable_admin_bar_styling'] ) ) {
        add_action( 'wp_head', function() use ($admin_bar_color) { 
            echo "<style>#wpadminbar { background: ".esc_attr($admin_bar_color)." !important; }</style>"; 
        } );
        add_action( 'admin_head', function() use ($admin_bar_color) { 
            echo "<style>#wpadminbar { background: ".esc_attr($admin_bar_color)." !important; }</style>"; 
        } );
        add_filter( 'admin_footer_text', function( $text ) use ($dev_text_color, $dev_badge_text) { 
            return $text . ' <span style="color:'.esc_attr($dev_text_color).';font-weight:bold;">'.esc_html($dev_badge_text).'</span>'; 
        } );
    }
    if ( ! empty( $options['enable_login_page_styling'] ) ) {
        // DEV-Warnung nur NACH dem Honeypot (falls aktiviert)
        add_action( 'login_form', function() use ($login_warning_text, $dev_text_color) { 
            echo '<p style="text-align:center; color:'.esc_attr($dev_text_color).'; font-weight:bold;">'.esc_html($login_warning_text).'</p>'; 
        }, 20 );
        add_action( 'login_enqueue_scripts', function() use ($login_main_heading_text, $dev_text_color) { 
            echo "<style>body.login { background: #f5f5f5 !important; }.login h1 a { display: none !important; }.login h1 { text-align: center !important; }.login h1:before { content: '".esc_js($login_main_heading_text)."'; display: block; font-size: 28px; color: ".esc_js($dev_text_color)."; margin-bottom: 20px; }</style>"; 
        } );
        add_filter( 'the_privacy_policy_link', '__return_false' );
        // Entfernung von "Back to Blog" Link ohne Output Buffering
        add_action( 'login_footer', function() {
            echo '<style>#backtoblog { display: none !important; }</style>';
        } );
    }
    if ( ! empty( $options['enable_access_control'] ) ) {
        add_action( 'template_redirect', function() use ( $options ) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if ( !empty($user_agent) && !empty($options['allowed_user_agents']) ) {
                $allowed_agents = array_filter(array_map('trim', explode("\n", $options['allowed_user_agents'])));
                foreach($allowed_agents as $agent) {
                    if (stripos($user_agent, $agent) !== false) { 
                        return; 
                    }
                }
            }
            if ( is_admin() || strpos( $_SERVER['REQUEST_URI'], 'wp-login.php' ) !== false || ( defined('DOING_AJAX') && DOING_AJAX ) || ( defined('XMLRPC_REQUEST') && XMLRPC_REQUEST ) ) return;
            $allowed_ips_string = $options['allowed_ips'] ?? '';
            $allowed_ips = !empty($allowed_ips_string) ? explode(',', $allowed_ips_string) : [];
            $ip = daet_get_client_ip();
            if ( ! is_user_logged_in() && ! in_array( $ip, $allowed_ips, true ) ) {
                // Log access attempt
                daet_audit_log( 'access_denied', 'warning', array(
                    'requested_url' => $_SERVER['REQUEST_URI']
                ));
                auth_redirect();
            }
        } );
    }
});

$plugin_basename = plugin_basename( __FILE__ );
add_filter( "plugin_action_links_{$plugin_basename}", 'daet_add_settings_link' );
/**
 * Fügt einen direkten Link zu den Einstellungen auf der Plugin-Seite hinzu.
 * @param array $links Die bestehenden Links.
 * @return array Die modifizierten Links.
 */
function daet_add_settings_link( $links ) {
    $settings_link = '<a href="options-general.php?page=daet_plugin_settings">' . esc_html__( 'Settings', 'dev-access' ) . '</a>';
    $audit_link = '<a href="tools.php?page=daet_audit_log">' . esc_html__( 'Audit Log', 'dev-access' ) . '</a>';
    array_unshift( $links, $audit_link );
    array_unshift( $links, $settings_link );
    return $links;
}

add_filter( 'authenticate', 'daet_enforce_login_restriction', 30, 2 );
/**
 * Setzt die Login-Beschränkung basierend auf der Benutzerrolle durch.
 * @param WP_User|WP_Error|null $user     WP_User object if the user is authenticated, WP_Error or null otherwise.
 * @param string                $username Submitted username.
 * @return WP_User|WP_Error|null
 */
function daet_enforce_login_restriction( $user, $username ) {
    if ( is_wp_error( $user ) || ! $username ) {
        return $user;
    }
    
    $options = get_option('daet_settings');
    
    // Rate-Limiting prüfen
    if ( ! empty( $options['enable_rate_limiting'] ) ) {
        if ( ! daet_check_login_rate_limit( $username ) ) {
            return new WP_Error( 
                'daet_rate_limit_exceeded', 
                __( 'Too many login attempts. Please try again later.', 'dev-access' ) 
            );
        }
    }
    
    $current_env = defined('WP_ENV') ? WP_ENV : 'production';
    $min_role_slug = '';
    if ($current_env === 'production') {
        $min_role_slug = $options['login_restriction_role_prod'] ?? 'subscriber';
    } else {
        $min_role_slug = $options['login_restriction_role_dev'] ?? 'subscriber';
    }
    if ($min_role_slug === 'subscriber') {
        return $user;
    }
    if ( ! $user instanceof WP_User ) {
        $user_obj = get_user_by( 'login', $username );
    } else {
        $user_obj = $user;
    }
    if ( ! $user_obj ) {
        return new WP_Error( 'daet_login_denied', __( 'Login failed.', 'dev-access' ) );
    }
    
    // WordPress-native Capabilities-Prüfung
    $role_capabilities = array(
        'administrator' => 'manage_options',
        'editor' => 'edit_others_posts',
        'author' => 'publish_posts',
        'contributor' => 'edit_posts',
        'subscriber' => 'read'
    );
    
    $required_cap = $role_capabilities[$min_role_slug] ?? 'read';
    
    if ( is_multisite() && is_super_admin( $user_obj->ID ) ) {
        return $user;
    }
    
    if ( ! user_can( $user_obj, $required_cap ) ) {
        daet_audit_log( 'login_role_denied', 'warning', array(
            'username' => $username,
            'required_role' => $min_role_slug
        ));
        
        return new WP_Error(
            'daet_role_denied',
            '<strong>' . esc_html__( 'ERROR', 'dev-access' ) . '</strong>: ' . esc_html__( 'You do not have the required permission to log in to this site.', 'dev-access' )
        );
    }
    return $user;
}

// Test-Funktion für Debugging
add_action( 'init', function() {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && isset( $_GET['daet_test_audit'] ) ) {
        daet_audit_log( 'test_event', 'info', array( 'test' => 'Debug test at ' . current_time( 'mysql' ) ) );
        wp_die( 'Audit log test completed. Check the audit log.' );
    }
});