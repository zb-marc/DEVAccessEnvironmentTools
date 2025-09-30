<?php
/**
 * Plugin Name:       AS DEV Access & Environment Tools
 * Plugin URI:        https://akkusys.de
 * Description:       DEV-Umgebung Erkennung, ADMIN-Bar Styling, Login-Branding und Zugriffsbegrenzung mit Admin-Oberfläche.
 * Version:           1.1.1
 * Author:            Marc Mirschel
 * Author URI:        https://mirschel.biz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev-access
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lädt die Textdomain des Plugins für Übersetzungen.
 */
function daet_load_textdomain() {
    load_plugin_textdomain( 'dev-access', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'daet_load_textdomain' );

if ( ! function_exists( 'daet_get_client_ip' ) ) {
    /**
     * Ermittelt die IP-Adresse des Clients.
     * @return string Die IP-Adresse.
     */
    function daet_get_client_ip() {
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            return trim( $parts[0] );
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
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
    add_options_page( __( 'DEV Access & Tools', 'dev-access' ), __( 'Environment Tools', 'dev-access' ), $capability, 'daet_plugin_settings', 'daet_options_page_html' );
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
}

add_action( 'admin_enqueue_scripts', 'daet_admin_assets' );
/**
 * Lädt Admin-Assets (CSS & JS).
 * @param string $hook_suffix Der Suffix der aktuellen Admin-Seite.
 */
function daet_admin_assets( $hook_suffix ) {
    if ( strpos( $hook_suffix, 'daet_plugin_settings' ) === false ) return;
    if ( ! wp_style_is( 'wp-color-picker', 'registered' ) ) {
        wp_enqueue_style( 'wp-color-picker' );
    }
    if ( ! wp_script_is( 'wp-color-picker', 'registered' ) ) {
        wp_enqueue_script( 'wp-color-picker' );
    }
    wp_enqueue_style( 'daet-admin-styles', plugin_dir_url( __FILE__ ) . 'admin-styles.css', [], '1.1.1' );
    wp_enqueue_script( 'daet-admin-scripts', plugin_dir_url( __FILE__ ) . 'admin-scripts.js', ['jquery', 'wp-color-picker'], '1.1.1', true );
}

/**
 * Rendert die HTML-Struktur der Einstellungsseite.
 */
function daet_options_page_html() {
    $options = get_option('daet_settings');
    $capability = !empty($options['plugin_access_capability']) ? $options['plugin_access_capability'] : 'manage_options';
    if ( ! current_user_can( $capability ) ) return;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'DEV Access & Environment Tools', 'dev-access' ); ?></h1>
        <form action="options.php" method="post">
            <?php settings_fields( 'daet_options_group' ); do_settings_sections( 'daet_plugin_settings' ); submit_button( __( 'Save Changes', 'dev-access' ) ); ?>
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
    $roles = [
        'administrator' => translate_user_role('Administrator'),
        'editor'        => translate_user_role('Editor'),
        'author'        => translate_user_role('Author'),
        'contributor'   => translate_user_role('Contributor'),
        'subscriber'    => translate_user_role('Subscriber'),
    ];
    ?>
    <select id="<?php echo esc_attr($id); ?>" name="daet_settings[<?php echo esc_attr($id); ?>]">
        <?php foreach ( $roles as $role_slug => $role_name ) : ?>
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
    $default_value = ($id === 'enable_debug_log') ? 1 : 0;
    $value = $options[$id] ?? $default_value;
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
        <input type="text" class="daet-tag-input">
        <?php if ( $id === 'allowed_ips' ) : $current_ip = daet_get_client_ip(); ?>
            <p style="margin-top: 5px; margin-bottom: 10px;">
                <a href="#" id="daet-add-current-ip" data-ip-address="<?php echo esc_attr($current_ip); ?>" class="button button-small">
                    <?php printf( esc_html__( 'Add my current IP address (%s)', 'dev-access' ), esc_html($current_ip) ); ?>
                </a>
            </p>
        <?php endif; ?>
        <div class="daet-tags-display" <?php echo $style_attribute; ?>>
            <?php foreach ( $tags as $tag ): if(empty($tag)) continue; ?>
                <span class="daet-tag-item button-primary"><?php echo esc_html( $tag ); ?><span class="daet-remove-tag">×</span></span>
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
function daet_render_field_text( array $args ) { $options = get_option( 'daet_settings' ); $id = $args['id']; $value = $options[$id] ?? $args['default']; echo '<input type="text" id="'.esc_attr($id).'" name="daet_settings['.esc_attr($id).']" value="'.esc_attr($value).'" class="regular-text">'; }

/**
 * Rendert einen Color-Picker.
 * @param array $args Argumente für das Feld.
 */
function daet_render_field_color( array $args ) { $options = get_option( 'daet_settings' ); $id = $args['id']; $value = $options[$id] ?? $args['default']; echo '<input type="text" id="'.esc_attr($id).'" name="daet_settings['.esc_attr($id).']" value="'.esc_attr($value).'" class="wp-color-picker-field">'; }

/**
 * Bereinigt die Plugin-Einstellungen vor dem Speichern.
 * @param array $input Die rohen Eingabedaten.
 * @return array Die bereinigten Daten.
 */
function daet_sanitize_settings( $input ) {
    $sanitized_input = [];
    $tag_fields = ['dev_urls', 'prod_urls', 'allowed_ips'];
    foreach($tag_fields as $field) {
        if (!isset($input[$field])) continue;
        $tags = explode(',', $input[$field]);
        $clean_tags = [];
        if ($field === 'dev_urls' || $field === 'prod_urls') {
            foreach($tags as $url) {
                $trimmed_url = trim($url); if (empty($trimmed_url)) continue;
                $schemed_url = preg_match('#^https?://#', $trimmed_url) ? $trimmed_url : 'http://' . $trimmed_url;
                $host = parse_url($schemed_url, PHP_URL_HOST);
                if ($host) { $clean_tags[] = $host; }
            }
        } else { $clean_tags = array_map('sanitize_text_field', $tags); }
        $sanitized_input[$field] = implode(',', array_unique(array_filter($clean_tags)));
    }
    $checkboxes = ['enable_fallback', 'enable_admin_bar_styling', 'enable_login_page_styling', 'enable_access_control', 'enable_debug_log'];
    foreach($checkboxes as $field){ $sanitized_input[$field] = !empty($input[$field]) ? 1 : 0; }
    $text_fields = ['dev_badge_text', 'login_warning_text', 'login_main_heading_text'];
    foreach($text_fields as $field){ $sanitized_input[$field] = isset($input[$field]) ? sanitize_text_field($input[$field]) : ''; }
    $color_fields = ['admin_bar_color', 'dev_text_color'];
    foreach($color_fields as $field){ if (isset($input[$field]) && preg_match('/^#([a-f0-9]{6}|[a-f0-9]{3})$/i', $input[$field])) { $sanitized_input[$field] = $input[$field]; } else { $sanitized_input[$field] = '#000000'; } }
    if (isset($input['plugin_access_capability'])) {
        $allowed_caps = ['manage_options', 'edit_theme_options', 'publish_pages', 'edit_posts'];
        if (in_array($input['plugin_access_capability'], $allowed_caps, true)) {
            $sanitized_input['plugin_access_capability'] = $input['plugin_access_capability'];
        }
    }
    if (isset($input['allowed_user_agents'])) {
        $lines = explode("\n", $input['allowed_user_agents']);
        $clean_lines = array_unique(array_filter(array_map('trim', $lines)));
        $sanitized_input['allowed_user_agents'] = implode("\n", $clean_lines);
    }
    $role_fields = ['login_restriction_role_dev', 'login_restriction_role_prod'];
    $existing_roles = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
    foreach ($role_fields as $field) {
        if (isset($input[$field]) && in_array($input[$field], $existing_roles, true)) {
            $sanitized_input[$field] = $input[$field];
        }
    }
    return $sanitized_input;
}

add_action('init', function() {
    if ( !defined( 'WP_ENV' ) || WP_ENV !== 'development' ) return;
    $options = get_option( 'daet_settings' );
    $admin_bar_color = !empty($options['admin_bar_color']) ? $options['admin_bar_color'] : '#d63638';
    $dev_text_color  = !empty($options['dev_text_color']) ? $options['dev_text_color'] : '#d63638';
    $dev_badge_text  = !empty($options['dev_badge_text']) ? $options['dev_badge_text'] : '(DEV Stage)';
    $login_warning_text = !empty($options['login_warning_text']) ? $options['login_warning_text'] : 'DEV-Umgebung – nicht produktiv';
    $login_main_heading_text = !empty($options['login_main_heading_text']) ? $options['login_main_heading_text'] : 'DEV ENVIRONMENT';
    if ( ! empty( $options['enable_admin_bar_styling'] ) ) {
        add_action( 'wp_head', function() use ($admin_bar_color) { echo "<style>#wpadminbar { background: ".esc_attr($admin_bar_color)." !important; }</style>"; } );
        add_action( 'admin_head', function() use ($admin_bar_color) { echo "<style>#wpadminbar { background: ".esc_attr($admin_bar_color)." !important; }</style>"; } );
        add_filter( 'admin_footer_text', function( $text ) use ($dev_text_color, $dev_badge_text) { return $text . ' <span style="color:'.esc_attr($dev_text_color).';font-weight:bold;">'.esc_html($dev_badge_text).'</span>'; } );
    }
    if ( ! empty( $options['enable_login_page_styling'] ) ) {
        add_action( 'login_form', function() use ($login_warning_text, $dev_text_color) { echo '<p style="text-align:center; color:'.esc_attr($dev_text_color).'; font-weight:bold;">'.esc_html($login_warning_text).'</p>'; } );
        add_action( 'login_enqueue_scripts', function() use ($login_main_heading_text, $dev_text_color) { echo "<style>body.login { background: #f5f5f5 !important; }.login h1 a { display: none !important; }.login h1 { text-align: center !important; }.login h1:before { content: '".esc_js($login_main_heading_text)."'; display: block; font-size: 28px; color: ".esc_js($dev_text_color)."; margin-bottom: 20px; }</style>"; } );
        add_filter( 'the_privacy_policy_link', '__return_false' );
        add_action( 'login_init', function() { ob_start(); } );
        add_action( 'login_footer', function() { $html = ob_get_clean(); echo preg_replace('/<p id="backtoblog">.*?<\/p>/is', '', $html); } );
    }
    if ( ! empty( $options['enable_access_control'] ) ) {
        add_action( 'template_redirect', function() use ( $options ) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if ( !empty($user_agent) && !empty($options['allowed_user_agents']) ) {
                $allowed_agents = array_filter(array_map('trim', explode("\n", $options['allowed_user_agents'])));
                foreach($allowed_agents as $agent) {
                    if (stripos($user_agent, $agent) !== false) { return; }
                }
            }
            if ( is_admin() || strpos( $_SERVER['REQUEST_URI'], 'wp-login.php' ) !== false || ( defined('DOING_AJAX') && DOING_AJAX ) || ( defined('XMLRPC_REQUEST') && XMLRPC_REQUEST ) ) return;
            $allowed_ips_string = $options['allowed_ips'] ?? '';
            $allowed_ips = !empty($allowed_ips_string) ? explode(',', $allowed_ips_string) : [];
            $ip = daet_get_client_ip();
            if ( ! is_user_logged_in() && ! in_array( $ip, $allowed_ips, true ) ) auth_redirect();
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
    $settings_link = '<a href="admin.php?page=daet_plugin_settings">' . esc_html__( 'Settings', 'dev-access' ) . '</a>';
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
    $role_levels = [ 'subscriber' => 1, 'contributor' => 2, 'author' => 3, 'editor' => 4, 'administrator' => 5 ];
    $required_level = $role_levels[$min_role_slug] ?? 99;
    $user_level = 0;
    foreach ( (array) $user_obj->roles as $role ) {
        if ( isset($role_levels[$role]) && $role_levels[$role] > $user_level ) {
            $user_level = $role_levels[$role];
        }
    }
    if ( is_multisite() && is_super_admin( $user_obj->ID ) ) {
        return $user;
    }
    if ( $user_level < $required_level ) {
        return new WP_Error(
            'daet_role_denied',
            '<strong>' . esc_html__( 'ERROR', 'dev-access' ) . '</strong>: ' . esc_html__( 'You do not have the required permission to log in to this site.', 'dev-access' )
        );
    }
    return $user;
}