<?php

namespace Webgurus\Admin;

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Datastore\Datastore;

/**
 * Stores Settings of other pages in one option key
 */
class EV_Settings_Datastore extends Datastore {

    public $settings = [];
    private $fetched = false;
    public $keyname = null;
    private $autoload = false;

    public function __construct($keyname, $autoload = false) {
        $this->keyname = $keyname;
        $this->autoload = $autoload;
        add_action( 'carbon_fields_theme_options_container_saved', function($user_data, $class) {
            $datastore = $class->get_datastore();
            if (property_exists($datastore, 'keyname') && $datastore->keyname == $this->keyname) update_option($this->keyname, $this->settings, $this->autoload); 
        }, 10, 2);

    }

    public function init() {
    }

    public function load( \Carbon_Fields\Field\Field $field ) {
        if (!$this->fetched) {
            $settings = get_option($this->keyname);
            if (is_array($settings)) $this->settings = $settings;
            $this->fetched = true;
        }
        $key = $field->get_base_name();
        if (key_exists($key, $this->settings)) {
            $value = $this->settings[$key];
            return $value;    
        }
    }

    public function save( \Carbon_Fields\Field\Field $field ) {
        $key = $field->get_base_name();
        if ( is_a( $field, '\\Carbon_Fields\\Field\\Complex_Field' ) ) {
            $this->settings[$key] = [];
        }
        else {
            $value = $field->get_value();
            $hierarchy = $field->get_hierarchy();
            if (count($hierarchy) > 0) {
                $index = $field->get_hierarchy_index();
                $this->settings[$hierarchy[0]][$index[0]][$key] = $value;
            }
            else {
                $this->settings[$key] = $value;
            }
        }
    }

    public function delete( \Carbon_Fields\Field\Field $field ) {
    }

}

if (!class_exists('Webgurus\Admin\MainMenu')) {
    Class MainMenu {
        public static $mainmenu;
        public static function Boot() {
            self::$mainmenu = Container::make( 'theme_options', 'webgurus', 'WebGurus')
            ->add_fields ([ Field::make('html', 'webgurus_instruction')
                ->set_html(sprintf('<style>
            .webgurus-button-ok {
                background-color: #1a7efb;
                color: #fff;
                border-radius: 7px;
                border: 0px;
                padding: 12px 20px;
            }

            .webgurus-button-ok:hover {
                background-color: #136ecc; 
            }

            .webgurus-button-ok:active {
                background-color: #94ceff; 
            }

            .webgurus-centered-div {
                 max-width: 700px;
                margin: 0 auto;
                text-align: center;  
            }
            </style>
            <p>%s</p>
            <p><b><a href = "https://www.webgurus.net/wordpress/plugins/" target = "_blank">%s</a></b></p>
            <p><b><a href = "https://www.webgurus.net/blog/" target = "_blank">%s</a></b></p>
            <div class = "webgurus-centered-div">
            <p style="font-size: 1.2em;"><b>%s</b></p> 
            <button type="button" class="webgurus-button-ok" onclick="window.location.href=%s">%s</button> 
            </div>',
            __( 'Welcome to WebGurus WordPress plugins. We are trying to make your life on WordPress more productive.', 'webgurus-email-verify' ),
                __( 'Find Other Plugins', 'webgurus-email-verify' ),
                __( 'Read the Blog', 'webgurus-email-verify' ),
                __( 'If you want to stay up to date about plugin updates and news around WordPress and Marketing Automation, sign up for our newsletter.', 'webgurus-email-verify' ),
                "'https://www.webgurus.net/newsletter/'",
                __( 'Sign up Now', 'webgurus-email-verify' )
                ))
            ]);
        }    
    }
}

Class EvAdmin {
    public static $settings_ds;
    public static $settings_pg;
    public static function Boot() {
        //----------Webgurus Screen----------
        if (empty(MainMenu::$mainmenu)) MainMenu::boot();
        //--------------------Settings Screen -------------
        self::$settings_ds = new EV_Settings_Datastore('wg_emailverify_settings', true);
        self::$settings_pg = Container::make( 'theme_options', 'wg_emailverify_settings', __( 'Email Verification', 'webgurus-email-verify' ))
            ->set_page_parent( MainMenu::$mainmenu )
            ->add_fields ( [
                Field::make( 'html', 'debounce_text' )->set_html( sprintf( __( 'You need to sign up first for your account at %s. Then you need to create an API key and paste it here.', 'webgurus-email-verify'), '<a href = "https://debounce.io/?r=lters" target = "_blank">debounce.io</a>' )),
                Field::make( 'text', 'api_key', __( 'Your Debounce.io API Key', 'webgurus-email-verify' ) ) ->set_required( true ),
                Field::make( 'html', 'information_text' )->set_html( '<h3>'.__( 'Enable Verification for following components', 'webgurus-email-verify' ).'</h3>'),
                Field::make( 'checkbox', 'enable_comments', __( 'Comment Forms', 'webgurus-email-verify' ) ) ->set_option_value( 'yes' ),
            ] )
            ->set_datastore(self::$settings_ds);
        self::add_option(class_exists( 'WooCommerce' ), 'enable_woocommerce', 'WooCommerce');
        self::add_option(function_exists('wpFluentForm'), 'enable_fluentforms', 'Fluent Forms');
    }

    static function add_option ($enabled, $name, $label) {
        if ($enabled) {
            self::$settings_pg->add_fields ([Field::make( 'checkbox', $name, $label ) ->set_option_value( 'yes' )]);
        }
        else {
            self::$settings_pg->add_fields ([Field::make( 'html', $name )->set_html($label . ' (' . __( 'Not Installed', 'webgurus-email-verify' ) . ')')]);
        }
    }

}

