<?php
/**
 * Audit Log Table Class
 * 
 * @package DEV Access & Environment Tools
 * @since 1.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class DAET_Audit_Log_Table
 * 
 * Erweitert WP_List_Table für die Anzeige der Audit-Logs
 */
class DAET_Audit_Log_Table extends WP_List_Table {
    
    /**
     * Konstruktor
     */
    public function __construct() {
        parent::__construct( array(
            'singular' => __( 'Audit Log Entry', 'dev-access' ),
            'plural'   => __( 'Audit Log Entries', 'dev-access' ),
            'ajax'     => false
        ) );
    }
    
    /**
     * Definiert die Spalten der Tabelle
     * 
     * @return array
     */
    public function get_columns() {
        return array(
            'cb'             => '<input type="checkbox" />',
            'event_type'     => __( 'Event Type', 'dev-access' ),
            'event_severity' => __( 'Severity', 'dev-access' ),
            'username'       => __( 'User', 'dev-access' ),
            'ip_address'     => __( 'IP Address', 'dev-access' ),
            'event_details'  => __( 'Details', 'dev-access' ),
            'event_time'     => __( 'Time', 'dev-access' )
        );
    }
    
    /**
     * Sortierbare Spalten definieren
     * 
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'event_type'     => array( 'event_type', false ),
            'event_severity' => array( 'event_severity', false ),
            'username'       => array( 'username', false ),
            'ip_address'     => array( 'ip_address', false ),
            'event_time'     => array( 'event_time', true ) // Default: neueste zuerst
        );
    }
    
    /**
     * Standard-Spalten-Ausgabe
     * 
     * @param array $item
     * @param string $column_name
     * @return string
     */
    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'event_type':
                return $this->format_event_type( $item[$column_name] );
            case 'event_severity':
                return $this->format_severity( $item[$column_name] );
            case 'username':
                return ! empty( $item[$column_name] ) ? esc_html( $item[$column_name] ) : __( 'Guest', 'dev-access' );
            case 'ip_address':
                return esc_html( $item[$column_name] );
            case 'event_details':
                return $this->format_event_details( $item[$column_name] );
            case 'event_time':
                return $this->format_time( $item[$column_name] );
            default:
                return '';
        }
    }
    
    /**
     * Checkbox-Spalte
     * 
     * @param array $item
     * @return string
     */
    public function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="bulk-delete[]" value="%s" />',
            $item['id']
        );
    }
    
    /**
     * Formatiert den Event-Type für bessere Lesbarkeit
     * 
     * @param string $type
     * @return string
     */
    private function format_event_type( $type ) {
        $types = array(
            'login_failed'        => __( 'Login Failed', 'dev-access' ),
            'login_success'       => __( 'Login Success', 'dev-access' ),
            'login_role_denied'   => __( 'Login Role Denied', 'dev-access' ),
            'rate_limit_exceeded' => __( 'Rate Limit Exceeded', 'dev-access' ),
            'honeypot_triggered'  => __( 'Honeypot Triggered', 'dev-access' ),
            'settings_changed'    => __( 'Settings Changed', 'dev-access' ),
            'access_denied'       => __( 'Access Denied', 'dev-access' ),
            'audit_log_cleared'   => __( 'Audit Log Cleared', 'dev-access' )
        );
        
        $formatted = isset( $types[$type] ) ? $types[$type] : ucwords( str_replace( '_', ' ', $type ) );
        
        return '<strong>' . esc_html( $formatted ) . '</strong>';
    }
    
    /**
     * Formatiert den Schweregrad mit Farbcodierung
     * 
     * @param string $severity
     * @return string
     */
    private function format_severity( $severity ) {
        return '<span class="daet-severity-' . esc_attr( $severity ) . '">' . 
               esc_html( ucfirst( $severity ) ) . 
               '</span>';
    }
    
    /**
     * Formatiert die Event-Details
     * 
     * @param string $details_json
     * @return string
     */
    private function format_event_details( $details_json ) {
        $details = json_decode( $details_json, true );
        
        if ( empty( $details ) ) {
            return '-';
        }
        
        $output = '<div class="daet-event-details">';
        
        // Spezielle Formatierung je nach Inhalt
        if ( isset( $details['username'] ) ) {
            $output .= sprintf( __( 'User: %s', 'dev-access' ), '<code>' . esc_html( $details['username'] ) . '</code>' ) . '<br>';
        }
        
        if ( isset( $details['attempts'] ) ) {
            $output .= sprintf( __( 'Attempts: %d', 'dev-access' ), intval( $details['attempts'] ) ) . '<br>';
        }
        
        if ( isset( $details['required_role'] ) ) {
            $output .= sprintf( __( 'Required Role: %s', 'dev-access' ), '<code>' . esc_html( $details['required_role'] ) . '</code>' ) . '<br>';
        }
        
        if ( isset( $details['requested_url'] ) ) {
            $output .= sprintf( __( 'URL: %s', 'dev-access' ), '<code>' . esc_html( $details['requested_url'] ) . '</code>' ) . '<br>';
        }
        
        if ( isset( $details['honeypot_value'] ) ) {
            $output .= sprintf( __( 'Honeypot: %s', 'dev-access' ), '<code>' . esc_html( $details['honeypot_value'] ) . '</code>' ) . '<br>';
        }
        
        // Settings-Änderungen
        if ( isset( $details['old'] ) || isset( $details['new'] ) ) {
            foreach ( $details as $key => $change ) {
                if ( is_array( $change ) && isset( $change['old'], $change['new'] ) ) {
                    $output .= '<strong>' . esc_html( $key ) . ':</strong> ';
                    $output .= esc_html( $change['old'] ) . ' → ' . esc_html( $change['new'] ) . '<br>';
                }
            }
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Formatiert die Zeit
     * 
     * @param string $time
     * @return string
     */
    private function format_time( $time ) {
        $timestamp = strtotime( $time );
        $time_diff = current_time( 'timestamp' ) - $timestamp;
        
        if ( $time_diff < HOUR_IN_SECONDS ) {
            $human_time = sprintf( __( '%d minutes ago', 'dev-access' ), round( $time_diff / 60 ) );
        } elseif ( $time_diff < DAY_IN_SECONDS ) {
            $human_time = sprintf( __( '%d hours ago', 'dev-access' ), round( $time_diff / HOUR_IN_SECONDS ) );
        } else {
            $human_time = sprintf( __( '%d days ago', 'dev-access' ), round( $time_diff / DAY_IN_SECONDS ) );
        }
        
        return sprintf( 
            '<span title="%s">%s</span>',
            esc_attr( $time ),
            esc_html( $human_time )
        );
    }
    
    /**
     * Bulk-Aktionen definieren
     * 
     * @return array
     */
    public function get_bulk_actions() {
        return array(
            'bulk-delete' => __( 'Delete', 'dev-access' )
        );
    }
    
    /**
     * Bereitet die Items für die Anzeige vor
     */
    public function prepare_items() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . DAET_AUDIT_TABLE;
        $per_page = 20;
        $current_page = $this->get_pagenum();
        
        // Sortierung
        $orderby = ! empty( $_GET['orderby'] ) ? sanitize_sql_orderby( $_GET['orderby'] ) : 'event_time';
        $order = ! empty( $_GET['order'] ) && in_array( strtoupper( $_GET['order'] ), array( 'ASC', 'DESC' ) ) ? $_GET['order'] : 'DESC';
        
        // Filter
        $where = '';
        if ( ! empty( $_GET['event_type'] ) ) {
            $where = $wpdb->prepare( ' WHERE event_type = %s', sanitize_text_field( $_GET['event_type'] ) );
        }
        
        // Gesamt-Anzahl
        $total_items = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" . $where );
        
        // Daten abrufen
        $offset = ( $current_page - 1 ) * $per_page;
        $query = "SELECT * FROM $table_name $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $this->items = $wpdb->get_results( 
            $wpdb->prepare( $query, $per_page, $offset ),
            ARRAY_A
        );
        
        // Spalten setzen
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = array( $columns, $hidden, $sortable );
        
        // Bulk-Actions verarbeiten
        $this->process_bulk_action();
        
        // Pagination
        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page
        ) );
    }
    
    /**
     * Verarbeitet Bulk-Aktionen
     */
    public function process_bulk_action() {
        if ( 'bulk-delete' === $this->current_action() ) {
            if ( ! empty( $_POST['bulk-delete'] ) && is_array( $_POST['bulk-delete'] ) ) {
                global $wpdb;
                $table_name = $wpdb->prefix . DAET_AUDIT_TABLE;
                
                $ids = array_map( 'intval', $_POST['bulk-delete'] );
                $ids_string = implode( ',', $ids );
                
                $wpdb->query( "DELETE FROM $table_name WHERE id IN($ids_string)" );
            }
        }
    }
    
    /**
     * Filter-Dropdown für Event-Types
     */
    protected function extra_tablenav( $which ) {
        if ( $which === 'top' ) {
            global $wpdb;
            $table_name = $wpdb->prefix . DAET_AUDIT_TABLE;
            
            // Alle Event-Types abrufen
            $event_types = $wpdb->get_col( "SELECT DISTINCT event_type FROM $table_name ORDER BY event_type" );
            
            if ( ! empty( $event_types ) ) {
                ?>
                <div class="alignleft actions">
                    <select name="event_type">
                        <option value=""><?php esc_html_e( 'All Event Types', 'dev-access' ); ?></option>
                        <?php foreach ( $event_types as $type ) : ?>
                            <option value="<?php echo esc_attr( $type ); ?>" <?php selected( isset( $_GET['event_type'] ) && $_GET['event_type'] === $type ); ?>>
                                <?php echo esc_html( $this->format_event_type_plain( $type ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php submit_button( __( 'Filter', 'dev-access' ), 'button', 'filter_action', false ); ?>
                </div>
                <?php
            }
        }
    }
    
    /**
     * Formatiert Event-Type ohne HTML
     * 
     * @param string $type
     * @return string
     */
    private function format_event_type_plain( $type ) {
        $types = array(
            'login_failed'        => __( 'Login Failed', 'dev-access' ),
            'login_success'       => __( 'Login Success', 'dev-access' ),
            'login_role_denied'   => __( 'Login Role Denied', 'dev-access' ),
            'rate_limit_exceeded' => __( 'Rate Limit Exceeded', 'dev-access' ),
            'honeypot_triggered'  => __( 'Honeypot Triggered', 'dev-access' ),
            'settings_changed'    => __( 'Settings Changed', 'dev-access' ),
            'access_denied'       => __( 'Access Denied', 'dev-access' ),
            'audit_log_cleared'   => __( 'Audit Log Cleared', 'dev-access' )
        );
        
        return isset( $types[$type] ) ? $types[$type] : ucwords( str_replace( '_', ' ', $type ) );
    }
}