<?php
/**
 * Plugin Name: 封鎖使用者
 * Plugin URI: https://yangsheep.com
 * Description: 封鎖使用者
 * Version: 1.0
 * Author: YANGSHEEP DESIGN
 * Author URI: https://yangsheep.com
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
            'user_login' => '使用者名稱',
            'user_email' => 'EMAIL',
            'display_name' => 'FULL NAME',
        );
    }

    public function prepare_items() {
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = array();
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

        $user_query = new WP_User_Query( $args );
        return $user_query->get_results();
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
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
        add_action( 'admin_init', array( $this, 'save_blocked_users' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );

        add_filter( 'wp_authenticate_user', array( $this, 'block_login' ), 10, 2 );
        add_filter( 'preprocess_comment', array( $this, 'block_comments' ) );
        add_action( 'woocommerce_checkout_process', array( $this, 'block_orders' ) );
    }

    public function save_blocked_users() {
        if ( isset( $_REQUEST['page'] ) && $_REQUEST['page'] === 'ys-black-user' && isset( $_REQUEST['user'] ) ) {
            $blocked_users = array_map( 'intval', $_REQUEST['user'] );
            update_option( 'ys_black_user_ids', $blocked_users );

            $blocked_emails = array();
            foreach( $blocked_users as $user_id ) {
                $user = get_user_by( 'ID', $user_id );
                if ( $user ) {
                    $blocked_emails[] = $user->user_email;
                }
            }
            update_option( 'ys_black_user_emails', $blocked_emails );
        }
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
        if ( in_array( $commentdata['comment_author_email'], $blocked_emails ) ) {
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
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $blocked_users = get_option( 'ys_black_user_ids', array() );
            if ( in_array( $user->ID, $blocked_users ) ) {
                wc_add_notice( '該信箱/用戶已被封鎖，請聯絡管理員', 'error' );
            }
        } else {
            $email = $_POST['billing_email'];
            $blocked_emails = get_option( 'ys_black_user_emails', array() );
            if ( in_array( $email, $blocked_emails ) ) {
                wc_add_notice( '該信箱/用戶已被封鎖，請聯絡管理員', 'error' );
            }
        }
    }

    public function setup_list_table() {
        $this->user_list_table = new YS_User_List_Table();
    }

    public function admin_menu() {
        add_menu_page(
            '封鎖使用者',
            '封鎖使用者',
            'manage_options',
            'ys-black-user',
            array( $this, 'admin_page' ),
            'dashicons-admin-users',
            99
        );
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
                <input type="submit" name="submit" class="button button-primary" value="儲存">
            </form>
        </div>
        <?php
    }

}

new YS_Black_User();
