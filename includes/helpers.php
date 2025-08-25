<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
function pwr_min_cap() { return apply_filters( 'pwr_min_capability', 'manage_options' ); }
function pwr_verify_nonce( $nonce_action, $nonce_name = '_wpnonce' ) {
    if ( empty( $_REQUEST[ $nonce_name ] ) ) { return false; }
    return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_name ] ) ), $nonce_action );
}
