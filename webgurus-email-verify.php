<?php
namespace Webgurus\Emailverify;

/**
 * Plugin Name: Webgurus Email Verification
 * Plugin URI: https://www.webgurus.net/wordpress/plugins/email-verification
 * Description: Verifies the email submitted at various WordPress forms using the debounce.io API.
 * Version: 1.0.4
 * Author: Martin Neumann
 * Author URI: https://www.webgurus.net
 * Text Domain: webgurus-email-verify
 */

if (!defined('ABSPATH'))
    exit;

require_once __DIR__ . '/crud.php';

Class AcceptAllDNS {
    use CRUD_Object;
    
    var $id;
    var $DNS;
    var $count;
    var $block;
    var $correctDNS;
    var $fetchDate;
    public static $fields = [
    'id' =>['type'=>'primary'],
    'DNS' =>['type'=>'char', 'length'=>80, 'null'=>true],
    'count' =>['type'=>'bigint'],
    'block' =>['type'=>'boolean'],
    'correctDNS' =>['type'=>'char', 'length'=>80],
    'fetchDate' =>['type'=>'date'],
    ];
    public static $table_name = 'wg_accept_all_dns';
}

Class VerifiedEmail {
    use CRUD_Object;
    
    var $id;
    var $email;
    var $result;
    var $fetchDate;
    public static $fields = [
    'id' =>['type'=>'primary'],
    'email' =>['type'=>'char', 'length'=>80, 'null'=>true],
    'result' =>['type'=>'array'],
    'fetchDate' =>['type'=>'date'],
    ];
    public static $table_name = 'wg_verified_emails';
}

register_activation_hook(__FILE__, '\Webgurus\Emailverify\install');

function install() {
    global $wpdb;

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( AcceptAllDNS::create_table_sql() );
    dbDelta( VerifiedEmail::create_table_sql() );
}

add_action('plugins_loaded', function () {
    require_once __DIR__ . '/vendor/autoload.php';
    \Carbon_Fields\Carbon_Fields::boot();
}, 2);

add_action('carbon_fields_register_fields', function () {
    if ( is_admin()) {
        include_once __DIR__ . '/admin.php';
        \Webgurus\Admin\EvAdmin::Boot();
    }
},90);

add_action('init', function() {
    load_plugin_textdomain('webgurus-email-verify', false, basename(dirname(__FILE__)) . '/languages');
});

add_action('wp_ajax_wg_verify_email', '\Webgurus\Emailverify\verify_email_callback');
add_action('wp_ajax_nopriv_wg_verify_email', '\Webgurus\Emailverify\verify_email_callback');

// AJAX callback function to verify email and submit an error message
function verify_email_callback() {
    check_ajax_referer("webgurus_email_ajax_callback_nonce");
    $email = sanitize_email($_POST['email']);
    $result = wg_email_validation( $email );
    $color = 'red';
    switch ($result['code']) {
        case 0:
            wp_send_json_error();

        case 1:
        case 2:
        case 6:
            if (empty($result['did_you_mean'])) {
                $message = __('This is not a valid email.','webgurus-email-verify');
            }
            else {
                $message = sprintf(
                    /* translators: %s: Email Address */
                    __('Email not valid. Did you mean %s?','webgurus-email-verify'), $result['did_you_mean']);
            }
            break;

        case 4:
        case 7:
            $message = __("This email address can't be verified for correctness. Double check the spelling before submitting.",'webgurus-email-verify');
            $color = 'orange';
            break;

        case 8:
            $message = __("We can't guarantee perfect delivery to role based emails, but use it if needed.",'webgurus-email-verify');
            $color = 'orange';
            break;

        case 3:
            $message = __('Disposable emails not allowed.','webgurus-email-verify');
            break;

        case 5:
            $message = __('Email correct!','webgurus-email-verify');
            $color = 'green';
    }
    wp_send_json_success(['message'=>$message, 'color'=>$color]);

}

// Make sure we have the necessary dependencies.
/*if ( ! class_exists( 'FluentForm\App\Services\Integrations\IntegrationManager' ) ) {
	return;
}*/

//Fluentform submission validation
add_filter( 'fluentform_validate_input_item_input_email', function ($message, $field, $formData, $fields, $form) {
    $settings = get_option('wg_emailverify_settings');
    if ($settings['enable_fluentforms'] != 'yes') return $message;
    $fieldName = $field['name'];
    if (empty($formData[$fieldName])) {
        return $message;
    }
    $email = $formData[$fieldName];
    return submit_validation ($email, $message);
}, 10, 5);

//WooCommerce Checkout submission validation
add_action( 'woocommerce_after_checkout_validation', function() {
    $settings = get_option('wg_emailverify_settings');
    if ($settings['enable_woocommerce'] != 'yes') return;

    $email = $_POST['billing_email'];
    if ( !empty ($email) ) {
        $message = submit_validation ($email, '');
        if ( !empty ($message) ) {
            wc_add_notice( '<b>' . __('Billing email','woocommerce') . ':</b> ' . $message, 'error' );
        }
    }  
} );

// Comment submission verification
add_filter('pre_comment_approved', function($approved, $commentdata){
    if (empty($commentdata['comment_author_email'])) return $approved;

    $settings = get_option('wg_emailverify_settings');
    if ($settings['enable_comments'] != 'yes') return $approved;

    $message = submit_validation ($commentdata['comment_author_email'], '');
    if ( empty ($message) )  return $approved;
    return new \WP_Error( 'require_valid_email', $message, 200 );
}, 10, 2); 

// generic validation messages after submission
function submit_validation ($email, $message) {
    $result = wg_email_validation( $email );
    switch ($result['code']) {
        case 1:
        case 2:
        case 6:
            if (empty($result['did_you_mean'])) {
                $message = __('This is not a valid email.','webgurus-email-verify');
            }
            else {
                $message = sprintf(/* translators: %s: Email Address */__('Email not valid. Did you mean %s?','webgurus-email-verify'), $result['did_you_mean']);
            }
            break;

        case 3:
            $message = __('Disposable emails not allowed.','webgurus-email-verify');
            break;
    }
    return $message;
    
}

/**
 * Validates an email address using the debounce.io API.
 * @param string $email    The email address to validate.
 * @return array with response.
 */
function wg_email_validation( $email ) {
  $parts = explode('@', $email);
  if (count($parts) == 2) {
    $dns = $parts[1];
    //----Look if we have the email cached-----
    $getEmail = VerifiedEmail::get_row('email=%s', $email);
        if (!empty($getEmail)){
            if ($getEmail->fetchDate->getTimestamp() > (time() - MONTH_IN_SECONDS)) return $getEmail->result;
        }
        //-----Look if we have cached a catchall DNS name------
        $lookup = AcceptAllDNS::get_row('DNS=%s', $dns);
        if (!empty($lookup)){
            $lookup->count++;
            $lookup->save();
            if ($lookup->block == true) {
                $value = ['code' => 6];
                if (!empty($lookup->correctDNS)) $value['did_you_mean'] = $parts[0] . '@' . $lookup->correctDNS;
                return $value;
            }
            if ($lookup->fetchDate->getTimestamp() > (time() - MONTH_IN_SECONDS)) return ['code' => 4];
        }    
    // Check if DNS is valid
    $ip = gethostbyname($dns);
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        $settings = get_option('wg_emailverify_settings');
        // Set up the debounce.io API endpoint and request parameters.
        $key      = $settings['api_key'];

        // Send the request to the debounce.io API.
        $response = wp_remote_get( "https://api.debounce.io/v1/?email=$email&api=$key" );

        // If the request was successful, check the response for a valid status.
        if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            $value = $data['debounce'];
            $nowdate = new \DateTime();
            if ($value['code'] == 4) {
                if ($lookup == null) {
                    $lookup = new AcceptAllDNS(['DNS'=>$dns, 'count'=>1, 'block'=>false, 'fetchDate'=> $nowdate]);
               }
                else {
                    $lookup->fetchDate = $nowdate;
                }
                $lookup->save();
            }
            if (empty($getEmail)) {
                $getEmail = new VerifiedEmail(['email'=>$email, 'result'=>$value, 'fetchDate'=> $nowdate]);
            }
            else {
                $getEmail->fetchDate = $nowdate;
                $getEmail->result = $value;
            }
            $getEmail->save();
            return $value;
        } else {
            return array('code' => 0);
        }
    }
  }
  return array('code' => 1);
}

add_filter ('http_request_timeout', function ($timeout, $url) {
    $pos = strpos($url, 'https://api.debounce.io/v1/');
    if ($pos === 0) return 20;
    return $timeout;
}, 10, 2);

add_action( 'wp_head', function() {
$javacode=".on('change keyup blur input', function () {
    fld = $(this);
    var email = fld.val();
    if (email.length < 2) return;
    if (email.length > 3 && email.indexOf('@') > -1) {
        var regex = /^([a-zA-Z0-9_.+-])+\@(([a-zA-Z0-9-])+\.)+([a-zA-Z0-9]{2,20})+$/;
        if (regex.test(email)) { 
            clearTimeout(wgTimerId);
            wgTimerId = setTimeout(function(){
              $.ajax({
                url: '" . admin_url( 'admin-ajax.php' ) . "',
                type: 'POST',
                dataType: 'json',
                data: {
                  action: 'wg_verify_email',
                  _ajax_nonce: '" . wp_create_nonce("webgurus_email_ajax_callback_nonce") . "',
                  email: email
                },
                success: function(response) {
                  if (response.success) {
                    wgErrmsg(fld, response.data.message, response.data.color);
                  } else {
                    wgErrmsg(fld, '" . __('An error occured while validating the email. Double check the spelling and submit.','webgurus-email-verify') . "', 'orange');
                  }
                },
                error: function(xhr,status,error) {
                    wgErrmsg(fld, '" . __('An error occured while validating the email. Double check the spelling and submit.','webgurus-email-verify') . "', 'orange');
                }
              })
            }, 700);
        } else {
            wgErrmsg(fld, '" . __('Not yet a valid email','webgurus-email-verify') . "', 'red');
        }
    } else {
       wgErrmsg(fld, '" . __('Keep typing','webgurus-email-verify') . "', 'orange');
    }
}) });

";
$errcode = <<<'EOT'

function wgErrmsg($e, text, color) {
    el = $e.parent().parent().has('.error');
    if (el.length > 0) {
        el.find('.error').text(text);
        el.find('.error').css('color', color);
    }
    else {
        $e.parent().after(`<div class="error text-danger" style="color:${color}; font-size: 12px;">${text}</div>`);
    }
}
EOT;

if ( function_exists( 'is_checkout' ) && is_checkout() ) {
    // This is a WooCommerce checkout page
    $settings = get_option('wg_emailverify_settings');
    if ($settings['enable_woocommerce'] != 'yes') return;
    wp_add_inline_script( 'wc-checkout', "var wgTimerId; jQuery(document).ready(function($) { $('#billing_email')" . $javacode . $errcode);
} else {
    // This is not a WooCommerce checkout page, posssibly output script for Comments and FluentForms
    if (comments_open()) {
        $settings = get_option('wg_emailverify_settings');
        if ($settings['enable_comments'] != 'yes') return;
    
        ?>
        <script>
            var wgTimerId; 
            jQuery(document).ready(function($) { 
                $('input[type=email]')<?php echo $javacode ?>
                
            function wgErrmsg($e, text, color) {
                el = $e.parent().has('.error');
                if (el.length > 0) {
                    el.find('.error').text(text);
                    el.find('.error').css('color', color);
                }
                else {
                    $e.after(`<div class="error text-danger" style="color:${color}; font-size: 12px;">${text}</div>`);
                }
            }
    </script>
    <?php        
    } else {
        $settings = get_option('wg_emailverify_settings');
        if ($settings['enable_fluentforms'] != 'yes') return;

        wp_add_inline_script( 'fluent-form-submission', "var wgTimerId; jQuery(document).ready(function($) { $('input[type=email].ff-el-form-control')" . $javacode . $errcode);
    }
    
}
}, 9000, 0);