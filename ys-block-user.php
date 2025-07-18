<?php
/**
 * Plugin Name: 封鎖使用者
 * Plugin URI: https://yangsheep.com.tw
 * Description: 封鎖使用者
 * Version: 1.2
 * Author: YANGSHEEP CLOUD
 * Author URI: https://yangsheep.com.tw
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class YS_User_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( array(
            'singular' => 'user',
            'plural'   => 'users',
            'ajax'     => false
        ) );
    }

    public function get_columns() {
        return array(
            'cb'        => '<input type="checkbox" />',
            'ID'        => 'ID',
            'user_login' => '使用者名稱',
            'user_email' => 'EMAIL',
            'display_name' => 'FULL NAME',
        );
    }

    public function get_sortable_columns() {
        return array(
            'ID' => array( 'ID', false ),
            'user_login' => array( 'user_login', false ),
            'user_email' => array( 'user_email', false ),
            'display_name' => array( 'display_name', false ),
        );
    }

    public function prepare_items() {
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array( $columns, $hidden, $sortable );

        $per_page = $this->get_items_per_page( 'users_per_page', 50 );
        $current_page = $this->get_pagenum();
        $total_items = $this->get_total_users();

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page
        ) );

        $this->items = $this->get_users( $per_page, $current_page );
    }

    public function get_total_users() {
        $args = array(
            'role__not_in' => 'Administrator',
            'fields' => 'ID',
        );

        if ( isset( $_REQUEST['s'] ) ) {
            $args['search'] = '*' . $_REQUEST['s'] . '*';
        }

        $user_query = new WP_User_Query( $args );
        return $user_query->get_total();
    }

    public function get_users( $per_page = 50, $current_page = 1 ) {
        $args = array(
            'role__not_in' => 'Administrator',
            'number' => $per_page,
            'offset' => ( $current_page - 1 ) * $per_page,
        );

        if ( isset( $_REQUEST['s'] ) ) {
            $args['search'] = '*' . $_REQUEST['s'] . '*';
        }

        $orderby = ( isset( $_REQUEST['orderby'] ) ) ? $_REQUEST['orderby'] : 'ID';
        $order = ( isset( $_REQUEST['order'] ) ) ? $_REQUEST['order'] : 'asc';
        $args['orderby'] = $orderby;
        $args['order'] = $order;


        $user_query = new WP_User_Query( $args );
        return $user_query->get_results();
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'ID':
            case 'user_login':
            case 'user_email':
            case 'display_name':
                return $item->$column_name;
            default:
                return print_r( $item, true );
        }
    }

    public function column_cb( $item ) {
        $blocked_users = get_option( 'ys_black_user_ids', array() );
        $checked = in_array( $item->ID, $blocked_users ) ? 'checked' : '';
        return sprintf(
            '<input type="checkbox" name="user[]" value="%s" %s />', $item->ID, $checked
        );
    }

}


class YS_Black_User {

    public $user_list_table;

    public function __construct() {
        add_action( 'admin_init', array( $this, 'setup_list_table' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        add_action( 'wp_ajax_ys_black_user_update', array( $this, 'ajax_update_blocked_user' ) );

        add_filter( 'wp_authenticate_user', array( $this, 'block_login' ), 10, 2 );
        add_filter( 'preprocess_comment', array( $this, 'block_comments' ) );
        add_action( 'woocommerce_checkout_process', array( $this, 'block_orders' ) );
    }

    public function enqueue_scripts( $hook ) {
        if ( 'toplevel_page_ys-black-user' != $hook ) {
            return;
        }
        wp_enqueue_script( 'ys-black-user-main-js', plugin_dir_url( __FILE__ ) . 'js/main.js', array( 'jquery' ), '1.2', true );
        $nonce = wp_create_nonce( 'ys_black_user_nonce' );
        wp_localize_script( 'ys-black-user-main-js', 'ys_black_user_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => $nonce,
        ) );
        wp_enqueue_style( 'ys-black-user-main-css', plugin_dir_url( __FILE__ ) . 'css/main.css' );
    }

    public function ajax_update_blocked_user() {
        check_ajax_referer( 'ys_black_user_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '權限不足' );
        }

        $user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : 0;
        $is_blocked = isset( $_POST['is_blocked'] ) ? ( $_POST['is_blocked'] === 'true' ) : false;

        if ( $user_id === 0 ) {
            wp_send_json_error( '無效的使用者 ID' );
        }


        $blocked_users = get_option( 'ys_black_user_ids', array() );
        $blocked_emails = get_option( 'ys_black_user_emails', array() );

        $user = get_user_by( 'ID', $user_id );
        if ( ! $user ) {
            wp_send_json_error( '找不到使用者' );
        }

        if ( $is_blocked ) {
            if ( ! in_array( $user_id, $blocked_users ) ) {
                $blocked_users[] = $user_id;
            }
            if ( ! in_array( $user->user_email, $blocked_emails ) ) {
                $blocked_emails[] = $user->user_email;
            }
        } else {
            if ( ( $key = array_search( $user_id, $blocked_users ) ) !== false ) {
                unset( $blocked_users[$key] );
            }
            if ( ( $key = array_search( $user->user_email, $blocked_emails ) ) !== false ) {
                unset( $blocked_emails[$key] );
            }
        }

        update_option( 'ys_black_user_ids', array_values( $blocked_users ) );
        update_option( 'ys_black_user_emails', array_values( $blocked_emails ) );

        wp_send_json_success( '已更新' );
    }

    public function block_login( $user, $password ) {
        if ( is_wp_error( $user ) ) {
            return $user;
        }

        $blocked_users = get_option( 'ys_black_user_ids', array() );
        if ( in_array( $user->ID, $blocked_users ) ) {
            return new WP_Error( 'blocked', '該信箱/用戶已被封鎖，請聯絡管理員' );
        }

        return $user;
    }

    public function block_comments( $commentdata ) {
        $blocked_emails = get_option( 'ys_black_user_emails', array() );
        if ( isset( $commentdata['comment_author_email'] ) && in_array( $commentdata['comment_author_email'], $blocked_emails ) ) {
            wp_die( '該信箱/用戶已被封鎖，請聯絡管理員' );
        }

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $blocked_users = get_option( 'ys_black_user_ids', array() );
            if ( in_array( $user->ID, $blocked_users ) ) {
                wp_die( '該信箱/用戶已被封鎖，請聯絡管理員' );
            }
        }

        return $commentdata;
    }

    public function block_orders() {
        $error_message = '該信箱/用戶已被封鎖，請聯絡管理員';
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $blocked_users = get_option( 'ys_black_user_ids', array() );
            if ( in_array( $user->ID, $blocked_users ) ) {
                wc_add_notice( $error_message, 'error' );
            }
        } else if ( isset( $_POST['billing_email'] ) ) {
            $email = sanitize_email( $_POST['billing_email'] );
            $blocked_emails = get_option( 'ys_black_user_emails', array() );
            if ( in_array( $email, $blocked_emails ) ) {
                wc_add_notice( $error_message, 'error' );
            }
        }
    }

    public function setup_list_table() {
        $this->user_list_table = new YS_User_List_Table();
    }

    public function screen_option() {
        $option = 'per_page';
        $args = array(
            'label' => '使用者',
            'default' => 50,
            'option' => 'users_per_page'
        );
        add_screen_option( $option, $args );
    }

    public function admin_menu() {
        $hook = add_menu_page(
            '封鎖使用者',
            '封鎖使用者',
            'manage_options',
            'ys-black-user',
            array( $this, 'admin_page' ),
            'dashicons-admin-users',
            99
        );
        add_action( "load-$hook", array( $this, 'screen_option' ) );
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>封鎖使用者</h1>
            <form method="get">
                <input type="hidden" name="page" value="ys-black-user">
                <?php
                $this->user_list_table->prepare_items();
                $this->user_list_table->search_box( '搜尋', 'user-search-input' );
                $this->user_list_table->display();
                ?>
            </form>
        </div>
        <?php
    }

}

new YS_Black_User();
