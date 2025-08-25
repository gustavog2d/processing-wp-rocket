<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class PWR_REST {
    public function __construct() { add_action( 'rest_api_init', [ $this, 'register_routes' ] ); }
    public function register_routes() {
        register_rest_route( 'processing-wp-rocket/v1', '/purge', [
            'methods'  => 'POST', 'callback' => [ $this, 'handle_purge' ],
            'permission_callback' => function(){ return current_user_can( pwr_min_cap() ); },
            'args' => [ 'url'=>['required'=>true,'type'=>'string'], 'type'=>['required'=>true,'type'=>'string','enum'=>['page','rucss','priority']], '_wpnonce'=>['required'=>true,'type'=>'string'] ],
        ] );
        register_rest_route( 'processing-wp-rocket/v1', '/bulk-purge', [
            'methods'  => 'POST', 'callback' => [ $this, 'handle_bulk_purge' ],
            'permission_callback' => function(){ return current_user_can( pwr_min_cap() ); },
            'args' => [ 'urls'=>['required'=>true,'type'=>'array'], 'type'=>['required'=>true,'type'=>'string','enum'=>['page','rucss','priority']], '_wpnonce'=>['required'=>true,'type'=>'string'] ],
        ] );
    }
    public function handle_purge( WP_REST_Request $req ) {
        $nonce_param = $req->get_param( '_wpnonce' );
        if ( $nonce_param && ! wp_verify_nonce( $nonce_param, 'pwr_rest' ) ) {
            return new WP_Error( 'pwr_bad_nonce', __( 'Invalid nonce.', 'processing-wp-rocket' ), [ 'status' => 403 ] );
        }
        $url  = esc_url_raw( (string) $req->get_param( 'url' ) );
        $type = sanitize_text_field( (string) $req->get_param( 'type' ) );
        switch ( $type ) {
            case 'page': $res = PWR_WPRocket_Adapter::purge_page_cache( $url ); break;
            case 'rucss': $res = PWR_WPRocket_Adapter::clear_used_css_for_url( $url ); break;
            case 'priority': $res = PWR_WPRocket_Adapter::clear_priority_elements_data( $url ); break;
            default: $res = new WP_Error( 'pwr_unknown', __( 'Unknown action.', 'processing-wp-rocket' ) );
        }
        if ( is_wp_error( $res ) ) { return new WP_REST_Response( [ 'ok'=>false, 'message'=>$res->get_error_message() ], 200 ); }
        return wp_send_json_success();
    }
    public function handle_bulk_purge( WP_REST_Request $req ) {
        $nonce_param = $req->get_param( '_wpnonce' );
        if ( $nonce_param && ! wp_verify_nonce( $nonce_param, 'pwr_rest' ) ) {
            return new WP_Error( 'pwr_bad_nonce', __( 'Invalid nonce.', 'processing-wp-rocket' ), [ 'status' => 403 ] );
        }
        $urls = (array) $req->get_param( 'urls' ); $type = sanitize_text_field( (string) $req->get_param( 'type' ) );
        $results = [];
        foreach ( $urls as $u ) {
            $u = esc_url_raw( (string) $u ); if ( empty( $u ) ) continue;
            switch ( $type ) {
                case 'page': $res = PWR_WPRocket_Adapter::purge_page_cache( $u ); break;
                case 'rucss': $res = PWR_WPRocket_Adapter::clear_used_css_for_url( $u ); break;
                case 'priority': $res = PWR_WPRocket_Adapter::clear_priority_elements_data( $u ); break;
                default: $res = new WP_Error( 'pwr_unknown', __( 'Unknown action.', 'processing-wp-rocket' ) );
            }
            $results[ $u ] = is_wp_error( $res ) ? $res->get_error_message() : true;
        }
        return wp_send_json_success();
    }
}
new PWR_REST();
