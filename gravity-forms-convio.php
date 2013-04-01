<?php
/*
Plugin Name: Gravity Forms Convio Add-on
Plugin URI: https://github.com/bhays/gravity-forms-convio
Description: Integrates Gravity Forms with Convio allowing form submissions to be automatically sent to your Convio account
Version: 0.2
Author: Ben Hays
Author URI: http://benhays.com

------------------------------------------------------------------------
Copyright 2013 Ben Hays

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('init',  array('GFConvio', 'init'));
register_activation_hook( __FILE__, array("GFConvio", "add_permissions"));

class GFConvio {

    private static $path = "gravity-forms-convio/gravity-forms-convio.php";
    private static $url = "http://www.gravityforms.com";
    private static $slug = "gravity-forms-convio";
    private static $version = "0.2";
    private static $min_gravityforms_version = "1.5";
    private static $supported_fields = array(
	    				"checkbox", "radio", "select", "text", "website", "textarea", "email", 
	    				"hidden", "number", "phone", "multiselect", "post_title",
	                    "post_tags", "post_custom_field", "post_content", "post_excerpt"
					);

    //Plugin starting point. Will load appropriate files
    public static function init(){
		//supports logging
		add_filter("gform_logging_supported", array("GFConvio", "set_logging_supported"));

		if(basename($_SERVER['PHP_SELF']) == "plugins.php") {

            //loading translations
            load_plugin_textdomain('gravity-forms-convio', FALSE, '/gravity-forms-convio/languages' );

			//force new remote request for version info on the plugin page
			//self::flush_version_info();
        }

        if(!self::is_gravityforms_supported()){
           return;
        }

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravity-forms-convio', FALSE, '/gravity-forms-convio/languages' );

            add_filter("transient_update_plugins", array('GFConvio', 'check_update'));
            add_filter("site_transient_update_plugins", array('GFConvio', 'check_update'));

            add_action('install_plugins_pre_plugin-information', array('GFConvio', 'display_changelog'));

            //creates a new Settings page on Gravity Forms' settings screen
            if(self::has_access("gravityforms_convio")){
                RGForms::add_settings_page("Convio", array("GFConvio", "settings_page"));
            }
        }

        //integrating with Members plugin
        if(function_exists('members_get_capabilities'))
            add_filter('members_get_capabilities', array("GFConvio", "members_get_capabilities"));

        //creates the subnav left menu
        add_filter("gform_addon_navigation", array('GFConvio', 'create_menu'));

        if(self::is_convio_page()){

            //enqueueing sack for AJAX requests
            wp_enqueue_script(array("sack"));

            //loading data lib
            require_once(self::get_base_path() . "/inc/data.php");

            //loading Gravity Forms tooltips
            require_once(GFCommon::get_base_path() . "/tooltips.php");
            add_filter('gform_tooltips', array('GFConvio', 'tooltips'));

            //runs the setup when version changes
            self::setup();

         }
         else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

            //loading data class
            require_once(self::get_base_path() . "/inc/data.php");

            add_action('wp_ajax_rg_update_feed_active', array('GFConvio', 'update_feed_active'));
            add_action('wp_ajax_gf_select_convio_form', array('GFConvio', 'select_convio_form'));

        }
        else{
             //handling post submission.
            add_action("gform_after_submission", array('GFConvio', 'export'), 10, 2);
        }
    }

    public static function update_feed_active(){
        check_ajax_referer('rg_update_feed_active','rg_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFConvioData::get_feed($id);
        GFConvioData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //Displays current version details on Plugin's page
    public static function display_changelog(){
        if($_REQUEST["plugin"] != self::$slug)
            return;

        RGConvioUpgrade::display_changelog(self::$slug, self::get_key(), self::$version);
    }

    public static function check_update($update_plugins_option){
		if ( get_option( 'gf_convio_version' ) != self::$version ) {
			require_once( 'inc/data.php' );
			GFConvioData::update_table();
		}

		update_option( 'gf_convio_version', self::$version );
    }

    private static function get_key(){
        if(self::is_gravityforms_supported())
            return GFCommon::get_key();
        else
            return "";
    }
    //---------------------------------------------------------------------------------------

    //Returns true if the current page is an Feed pages. Returns false if not
    private static function is_convio_page(){
        $current_page = trim(strtolower(rgget("page")));
        $convio_pages = array("gf_convio");

        return in_array($current_page, $convio_pages);
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup(){

        if(get_option("gf_convio_version") != self::$version)
            GFConvioData::update_table();

        update_option("gf_convio_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $convio_tooltips = array(
            "convio_contact_list" => "<h6>" . __("Convio Survey", "gravity-forms-convio") . "</h6>" . __("Select the Convio survey you would like to add your contacts to.", "gravity-forms-convio"),
            "convio_gravity_form" => "<h6>" . __("Gravity Form", "gravity-forms-convio") . "</h6>" . __("Select the Gravity Form you would like to integrate with Convio. Contacts generated by this form will be automatically added to your Convio constituents account.", "gravity-forms-convio"),
            "convio_welcome" => "<h6>" . __("Send Welcome Email", "gravity-forms-convio") . "</h6>" . __("When this option is enabled, users will receive an automatic welcome email from Convio upon being added to your Convio list.", "gravity-forms-convio"),
            "convio_map_fields" => "<h6>" . __("Map Fields", "gravity-forms-convio") . "</h6>" . __("Associate your Convio survey questions to the appropriate Gravity Form fields by selecting.", "gravity-forms-convio"),
            "convio_optin_condition" => "<h6>" . __("Opt-In Condition", "gravity-forms-convio") . "</h6>" . __("When the opt-in condition is enabled, form submissions will only be exported to Convio when the condition is met. When disabled all form submissions will be exported.", "gravity-forms-convio"),
            "convio_double_optin" => "<h6>" . __("Double Opt-In", "gravity-forms-convio") . "</h6>" . __("When the double opt-in option is enabled, Convio will send a confirmation email to the user and will only add them to your Convio list upon confirmation.", "gravity-forms-convio")
        );
        return array_merge($tooltips, $convio_tooltips);
    }

    //Creates Convio left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_convio");
        if(!empty($permission))
            $menus[] = array("name" => "gf_convio", "label" => __("Convio", "gravity-forms-convio"), "callback" =>  array("GFConvio", "convio_page"), "permission" => $permission);

        return $menus;
    }

    public static function settings_page(){

        if(rgpost("uninstall")){
            check_admin_referer("uninstall", "gf_convio_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms Convio Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravity-forms-convio")?></div>
            <?php
            return;
        }
        else if(rgpost("gf_convio_submit")){
            check_admin_referer("update", "gf_convio_update");
            
            $settings = array(
            	"apikey" => stripslashes($_POST["gf_convio_apikey"]),
            	"username" => stripslashes($_POST["gf_convio_username"]), 
            	"password" => stripslashes($_POST["gf_convio_password"]), 
            	"shortname" => $_POST["gf_convio_shortname"]
            );
            
            update_option("gf_convio_settings", $settings);
        }
        else{
            $settings = get_option("gf_convio_settings");
        }

        // Make sure username, password and short name are valid
        $is_valid = self::is_valid_login($settings);
        
		if( $is_valid['status'] ){
			$message = __("Your credentials are valid.", "gravity-forms-convio");
			$class = "valid_credentials";
		}
		else {
			$message = __("Something went wrong, see the response from Convio below.", "gravity-forms-convio");
			$message .= '<br/>'.$is_valid['message'];
			$class = "invalid_credentials";
		}

        ?>
        <style>
            .valid_credentials{color:green; padding-left: 25px !important; background: url(<?php echo self::get_base_url() ?>/images/tick.png) no-repeat left 8px;}
            .invalid_credentials{color:red; padding-left: 25px !important;  background: url(<?php echo self::get_base_url() ?>/images/stop.png) no-repeat left 8px;}
        </style>

        <form method="post" action="">
            <?php wp_nonce_field("update", "gf_convio_update") ?>
            <h3><?php _e("Convio Open API Information", "gravity-forms-convio") ?></h3>
            <p style="text-align: left;">
                <?php _e("Convio is a pain in the ass. Using the Convio Open API might not be so bad.", "gravity-forms-convio") ?>
            </p>
            <p style="text-align: left;">
	            <?php _e(sprintf("Make sure your server IP is set through the Convio API Settings. Your current IP is: %s", $_SERVER['SERVER_ADDR']), 'gravity-forms-convio'); ?>
            </p>

            <table class="form-table">
				<tr>
                    <td colspan="2" class="<?php echo empty($class) ? "" : $class ?>"><?php echo empty($message) ? "" : $message ?></td>
                </tr>
				<tr>
                    <th scope="row"><label for="gf_convio_apikey"><?php _e("Convio Open API Key", "gravity-forms-convio"); ?></label> </th>
                    <td>
                        <input type="text" id="gf_convio_apikey" name="gf_convio_apikey" value="<?php echo empty($settings["apikey"]) ? "" : esc_attr($settings["apikey"]) ?>" size="50"/>
                    </td>
                </tr>
				<tr>
                    <th scope="row"><label for="gf_convio_shortname"><?php _e("Site Short Name", "gravity-forms-convio"); ?></label> </th>
                    <td>
                        <input type="text" id="gf_convio_shortname" name="gf_convio_shortname" value="<?php echo empty($settings["shortname"]) ? "" : esc_attr($settings["shortname"]) ?>" size="10"/>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_convio_username"><?php _e("API Username", "gravity-forms-convio"); ?></label> </th>
                    <td><input type="text" id="gf_convio_username" name="gf_convio_username" value="<?php echo esc_attr($settings["username"]) ?>"/></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_convio_password"><?php _e("API Password", "gravity-forms-convio"); ?></label> </th>
                    <td><input type="text" id="gf_convio_password" name="gf_convio_password" value="<?php echo esc_attr($settings["password"]) ?>"/></td>
                </tr>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_convio_submit" class="button-primary" value="<?php _e("Save Settings", "gravity-forms-convio") ?>" /></td>
                </tr>
            </table>
        </form>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_convio_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_convio_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall Convio Add-On", "gravity-forms-convio") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL Convio Survey Feeds.", "gravity-forms-convio") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall Convio Add-On", "gravity-forms-convio") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL Convio Survey Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravity-forms-convio") . '\');"/>';
                    echo apply_filters("gform_convio_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php } ?>
        </form>
        <?php
    }

    public static function convio_page(){
        $view = rgar($_GET, 'view');
        if( $view == 'edit' )
            self::edit_page($_GET['id']);
        else
            self::list_page();
    }

    //Displays the convio feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("Convio Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravity-forms-convio"));
        }

        if(rgpost("action") == "delete"){
            check_admin_referer("list_action", "gf_convio_survey");

            $id = absint($_POST["action_argument"]);
            GFConvioData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravity-forms-convio") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_convio_survey");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFConvioData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravity-forms-convio") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            
            <h2><?php _e("Convio Survey Feeds", "gravity-forms-convio"); ?>
            <a class="add-new-h2" href="admin.php?page=gf_convio&view=edit&id=0"><?php _e("Add New", "gravity-forms-convio") ?></a>
            </h2>


            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_convio_survey') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravity-forms-convio") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravity-forms-convio") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravity-forms-convio") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravity-forms-convio") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravity-forms-convio") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-convio") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-convio") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Convio Survey", "gravity-forms-convio") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-convio") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Convio Survey", "gravity-forms-convio") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php

                        $settings = GFConvioData::get_feeds();
                        if(is_array($settings) && sizeof($settings) > 0){
                            foreach($settings as $setting){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-convio") : __("Inactive", "gravity-forms-convio");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-convio") : __("Inactive", "gravity-forms-convio");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_convio&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-convio") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a href="admin.php?page=gf_convio&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-convio") ?>"><?php _e("Edit", "gravity-forms-convio") ?></a>
                                            |
                                            </span>

                                            <span class="trash">
                                            <a title="<?php _e("Delete", "gravity-forms-convio") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravity-forms-convio") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-convio") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravity-forms-convio")?></a>

                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date"><?php echo $setting["meta"]["survey_name"] ?></td>
                                </tr>
                                <?php
                            }
                        }
                        else if(self::get_api()){
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php _e(sprintf("You don't have any Convio Survey feeds configured. Let's go %screate one%s!", '<a href="admin.php?page=gf_convio&view=edit&id=0">', "</a>"), "gravity-forms-convio"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        else{
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php _e(sprintf("To get started, please configure your %sConvio Settings%s.", '<a href="admin.php?page=gf_settings&addon=Convio">', "</a>"), "gravity-forms-convio"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravity-forms-convio") ?>').attr('alt', '<?php _e("Inactive", "gravity-forms-convio") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravity-forms-convio") ?>').attr('alt', '<?php _e("Active", "gravity-forms-convio") ?>');
                }

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "rg_update_feed_active" );
                mysack.setVar( "rg_update_feed_active", "<?php echo wp_create_nonce("rg_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravity-forms-convio" ) ?>' )};
                mysack.runAJAX();

                return true;
            }
        </script>
        <?php
    }

    private static function is_valid_login($settings){
    	if( !class_exists('ConvioOpenAPI') ){
	    	require_once('inc/ConvioOpenAPI.php');
    	}
    	
    	if( !empty($settings) ){
	    	extract($settings);    	
    	}

        if( !empty($username) && !empty($password) && !empty($apikey) && !empty($shortname) ) {
	
	        self::log_debug("Validating login for api key '{$apikey}', username '{$username}',  password '{$password}' and short name '{$shortname}'");
	        
	        $api = new ConvioOpenAPI;
			$api->host            = 'secure2.convio.net';
			$api->api_key         = $apikey;
			$api->short_name      = $shortname;
	        $api->login_name      = $username;
	        $api->login_password  = $password;
	
			// Get Auth token
			$auth = $api->call('SRConsAPI_getSingleSignOnToken');
			
			// Set logs and return response
			if( empty($auth) ){
	        	self::log_error("Login valid: false. Nothing returned from Convio.");
	        	return array('status' => false, 'message' => 'Site short name not found.');			
			}
			else if (isset($auth->errorResponse) ){
	        	self::log_error("Login valid: false. Error " . $auth->errorResponse->code . " - " . $auth->errorResponse->message);
	        	return array('status' => false, 'message' => $auth->errorResponse->message);
			}
			else {
				self::log_debug("Login valid: true");
	        	return array('status' => true);
			}
		}
		return array('status' => false, 'message' => "No credentials set yet.");
    }

    private static function get_api() {
        //global convio settings
        $settings = get_option("gf_convio_settings");
        $api = null;
        
        if( !empty($settings) ){
	        extract($settings);        
        }

        if( !empty($username) && !empty($password) && !empty($apikey) && !empty($shortname) ) {
	    	if( !class_exists('ConvioOpenAPI') ){
		    	require_once('inc/ConvioOpenAPI.php');
	    	}
	    	self::log_debug("Retriving authorization code for api key '{$apikey}', username '{$username}',  password '{$password}' and short name '{$shortname}'");

	        $api = new ConvioOpenAPI;
			$api->host            = 'secure2.convio.net';
			$api->api_key         = $apikey;
			$api->short_name      = $shortname;
	        $api->login_name      = $username;
	        $api->login_password  = $password;
	
			// Check
			$auth = $api->call('SRConsAPI_getSingleSignOnToken');

        } else {
            self::log_debug("API credentials not set");
            return null;
        }

        if(!$api){
            self::log_error("Failed to set up the API");
            return null;
        } elseif (isset($auth->errorResponse)) {
            self::log_error("No response received or an error: " . $auth->errorResponse->code . " - " . $auth->errorResponse->message);
            return null;
        }

		self::log_debug("Successful API response received");

        return $api;
	    
    }
    private static function get_auth_token(){

	    //global convio settings
        $settings = get_option("gf_convio_settings");
        $api = self::get_api();

		// Get Auth token
		$auth = $api->call('SRConsAPI_getSingleSignOnToken');

        if(!$auth){
            self::log_error("Failed to set up the Auth Token");
            return null;
        } elseif (isset($auth->errorResponse)) {
            self::log_error("No response received or an error: " . $auth->errorResponse->code . " - " . $auth->errorResponse->message);
            return null;
        }

		self::log_debug("Auth Token received");

        return $auth->getSingleSignOnTokenResponse->token;
    }

    private static function edit_page(){
        ?>
        <style>
            .convio_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold;}
            .convio_field_cell {padding: 6px 17px 0 0; margin-right:15px;}
            .gfield_required{color:red;}

            .feeds_validation_error{ background-color:#FFDFDF;}
            .feeds_validation_error td{ margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border-top:1px dotted #C89797; border-bottom:1px dotted #C89797}

            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0;}
            #convio_doubleoptin_warning{padding-left: 5px; padding-bottom:4px; font-size: 10px;}
            .convio_group_condition{padding-bottom:6px; padding-left:20px;}
        </style>
        <script type="text/javascript">
            var form = Array();
        </script>
        <div class="wrap">
            <h2><?php _e("Convio Feed", "gravity-forms-convio") ?></h2>
            <p><?php _e("Currently only works with Convio Surveys.", "gravity-forms-convio") ?></p>
        <?php
        //getting Convio API
        $api = self::get_api();

        //ensures valid credentials were entered in the settings page
        if(!$api){
            ?>
            <div><?php echo sprintf(__("We are unable to login to Convio with the provided credentials. Please make sure they are valid in the %sSettings Page%s", "gravity-forms-convio"), "<a href='?page=gf_settings&addon=Convio'>", "</a>"); ?></div>
            <?php
            return;
        }

		//getting setting id (0 when creating a new one)
        $id = !empty($_POST["convio_setting_id"]) ? $_POST["convio_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array("double_optin" => true), "is_active" => true) : GFConvioData::get_feed($id);
        
        if(!isset($config["meta"]))
            $config["meta"] = array();

		// Get details from survey if we have one
        if (rgempty("survey_id", $config["meta"]))
        {
			$merge_vars = array();
        }
        else
        {
            $details = self::get_survey_details( $config["meta"]["survey_id"] );
        }

        //updating meta information
        if(rgpost("gf_convio_submit")){

            list($survey_id, $list_name) = explode("|:|", stripslashes($_POST["gf_convio_survey"]));
            $config["meta"]["survey_id"] = $survey_id;
            $config["meta"]["survey_name"] = $list_name;
            $config["form_id"] = absint($_POST["gf_convio_form"]);

            $is_valid = true;
            $details = self::get_survey_details( $config["meta"]["survey_id"] );

        	$field_map = array();
            foreach($details as $d){
				if( $d->questionType == 'ConsQuestion' ){
	            	// Constituent data here
					foreach( $d->questionTypeData->consRegInfoData->contactInfoField as $f){
						$field_name = "convio_map_field_".$f->fieldName;
						
		                $mapped_field = stripslashes($_POST[$field_name]);
		                
		                if(!empty($mapped_field)){
							$field_map[$f->fieldName] = $mapped_field;
		                }
		                else{
							unset($field_map[$f->fieldName]);
							if( $f->fieldStatus == 'REQUIRED' ){
								$is_valid = false;
							}      
						}
					}	
            	}
            	else {
	            	// Something else?
	                $field_name = "convio_map_field_" . 'question_'.$d->questionID;
	                $mapped_field = stripslashes($_POST[$field_name]);
	
	                if(!empty($mapped_field)){
	                    $field_map['question_'.$field->questionID] = $mapped_field;
	                }
	                else{
	                    unset($field_map['question_'.$d->questionID]);
	                    if( $field->questionRequired ){
		                    $is_valid = false;                    
	                    }
	                }
            	}
            }

            $config["meta"]["field_map"] = $field_map;
            $config["meta"]["double_optin"] = rgpost("convio_double_optin") ? true : false;
            $config["meta"]["welcome_email"] = rgpost("convio_welcome_email") ? true : false;

            $config["meta"]["optin_enabled"] = rgpost("convio_optin_enable") ? true : false;
            $config["meta"]["optin_field_id"] = $config["meta"]["optin_enabled"] ? rgpost("convio_optin_field_id") : "";
            $config["meta"]["optin_operator"] = $config["meta"]["optin_enabled"] ? rgpost("convio_optin_operator") : "";
            $config["meta"]["optin_value"] = $config["meta"]["optin_enabled"] ? rgpost("convio_optin_value") : "";

            if($is_valid){
                $id = GFConvioData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
                <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravity-forms-convio"), "<a href='?page=gf_convio'>", "</a>") ?></div>
                <input type="hidden" name="convio_setting_id" value="<?php echo $id ?>"/>
                <?php
            }
            else{
                ?>
                <div class="error" style="padding:6px"><?php echo __("Feed could not be updated. Please enter all required information below.", "gravity-forms-convio") ?></div>
                <?php
            }
        }

        ?>
        <form method="post" action="">
            <input type="hidden" name="convio_setting_id" value="<?php echo $id ?>"/>
            <div class="margin_vertical_10">
                <label for="gf_convio_survey" class="left_header"><?php _e("Convio survey", "gravity-forms-convio"); ?> <?php gform_tooltip("convio_contact_list") ?></label>
                <?php

                //global convio settings
                $settings = get_option("gf_convio_settings");

                //getting all Convio Surveys
                self::log_debug("Retrieving surveys");
                $surveys = $api->call('CRSurveyAPI_listSurveys');

                if(isset($surveys->listSurveysResponse) && isset($surveys->listSurveysResponse->surveys))
                {
                    $surveys = $surveys->listSurveysResponse->surveys;
					self::log_debug("Number of surveys: " . count($surveys));
				}

                if (!$surveys):
                    echo __("Could not load Convio data. <br/>Error: ", "gravity-forms-convio") . $api->errorMessage;
                    self::log_debug("Could not load Convio data. Error " . $api->errorCode . " - " . $api->errorMessage);
                else:
                    ?>
                    <select id="gf_convio_survey" name="gf_convio_survey" onchange="SelectList(jQuery(this).val());">
                        <option value=""><?php _e("Select a Convio Survey", "gravity-forms-convio"); ?></option>
                    <?php
                    foreach ($surveys as $s):
                        $selected = $s->surveyId == $config["meta"]["survey_id"] ? "selected='selected'" : "";
                        ?>
                        <option value="<?php echo esc_attr($s->surveyId) . "|:|" . esc_attr($s->surveyName) ?>" <?php echo $selected ?>><?php echo esc_html($s->surveyName) ?></option>
                        <?php
                    endforeach;
                    ?>
                  </select>
                <?php
                endif;
                ?>
            </div>

            <div id="convio_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["survey_id"]) ? "style='display:none;'" : "" ?>>
                <label for="gf_convio_form" class="left_header"><?php _e("Gravity Form", "gravity-forms-convio"); ?> <?php gform_tooltip("convio_gravity_form") ?></label>

                <select id="gf_convio_form" name="gf_convio_form" onchange="SelectForm(jQuery('#gf_convio_survey').val(), jQuery(this).val());">
                <option value=""><?php _e("Select a form", "gravity-forms-convio"); ?> </option>
                <?php
                $forms = RGFormsModel::get_forms();
                foreach($forms as $form){
                    $selected = absint($form->id) == rgar($config,"form_id") ? "selected='selected'" : "";
                    ?>
                    <option value="<?php echo absint($form->id) ?>"  <?php echo $selected ?>><?php echo esc_html($form->title) ?></option>
                    <?php
                }
                ?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFConvio::get_base_url() ?>/images/loading.gif" id="convio_wait" style="display: none;"/>
            </div>
            <div id="convio_field_group" valign="top" <?php echo empty($config["meta"]["survey_id"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>
                <div id="convio_field_container" valign="top" class="margin_vertical_10" >
                    <label for="convio_fields" class="left_header"><?php _e("Map Fields", "gravity-forms-convio"); ?> <?php gform_tooltip("convio_map_fields") ?></label>

                    <div id="convio_field_list">
                    <?php
                    if(!empty($config["form_id"])){

                        //getting list of all Convio details for the selected survey
                        if(empty($details))
                        {
                        	$details = self::get_survey_details($config["meta"]["survey_id"]);
						}
                        //getting field map UI
                        echo self::get_field_mapping($config, $config["form_id"], $details);

                        //getting list of selection fields to be used by the optin
                        $form_meta = RGFormsModel::get_form_meta($config["form_id"]);
                    }
                    ?>
                    </div>
                </div>

                <div id="convio_optin_container" valign="top" class="margin_vertical_10">
                    <label for="convio_optin" class="left_header"><?php _e("Opt-In Condition", "gravity-forms-convio"); ?> <?php gform_tooltip("convio_optin_condition") ?></label>
                    <div id="convio_optin">
                        <table>
                            <tr>
                                <td>
                                    <input type="checkbox" id="convio_optin_enable" name="convio_optin_enable" value="1" onclick="if(this.checked){jQuery('#convio_optin_condition_field_container').show('slow');} else{jQuery('#convio_optin_condition_field_container').hide('slow');}" <?php echo rgar($config["meta"],"optin_enabled") ? "checked='checked'" : ""?>/>
                                    <label for="convio_optin_enable"><?php _e("Enable", "gravity-forms-convio"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="convio_optin_condition_field_container" <?php echo !rgar($config["meta"],"optin_enabled") ? "style='display:none'" : ""?>>
                                        <div id="convio_optin_condition_fields" style="display:none">
                                            <?php _e("Export to Convio if ", "gravity-forms-convio") ?>
                                            <select id="convio_optin_field_id" name="convio_optin_field_id" class='optin_select' onchange='jQuery("#convio_optin_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'></select>
                                            <select id="convio_optin_operator" name="convio_optin_operator" >
                                                <option value="is" <?php echo rgar($config["meta"], "optin_operator") == "is" ? "selected='selected'" : "" ?>><?php _e("is", "gravity-forms-convio") ?></option>
                                                <option value="isnot" <?php echo rgar($config["meta"], "optin_operator") == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "gravity-forms-convio") ?></option>
                                                <option value=">" <?php echo rgar($config['meta'], 'optin_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "gravity-forms-convio") ?></option>
                                                <option value="<" <?php echo rgar($config['meta'], 'optin_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "gravity-forms-convio") ?></option>
                                                <option value="contains" <?php echo rgar($config['meta'], 'optin_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "gravity-forms-convio") ?></option>
                                                <option value="starts_with" <?php echo rgar($config['meta'], 'optin_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "gravity-forms-convio") ?></option>
                                                <option value="ends_with" <?php echo rgar($config['meta'], 'optin_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "gravity-forms-convio") ?></option>
                                            </select>
                                            <div id="convio_optin_value_container" name="convio_optin_value_container" style="display:inline;"></div>
                                        </div>
                                        <div id="convio_optin_condition_message" style="display:none">
                                            <?php _e("To create an Opt-In condition, your form must have a field supported by conditional logic.", "gravityform") ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <script type="text/javascript">
                        <?php
                        if(!empty($config["form_id"])){
                            ?>
                            //creating Javascript form object
                            form = <?php echo GFCommon::json_encode($form_meta)?> ;

                            //initializing drop downs
                            jQuery(document).ready(function(){
                                var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["optin_field_id"])?>";
                                var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["optin_value"])?>";
                                SetOptin(selectedField, selectedValue);

                            });
                        <?php
                        }
                        ?>
                    </script>
                </div>
                <?php /* Hide Options for now
                <div id="convio_options_container" valign="top" class="margin_vertical_10">
                    <label for="convio_options" class="left_header"><?php _e("Options", "gravity-forms-convio"); ?></label>
                    <div id="convio_options">
                        <table>
                            <tr><td><input type="checkbox" name="convio_double_optin" id="convio_double_optin" value="1" <?php echo rgar($config["meta"],"double_optin") ? "checked='checked'" : "" ?> onclick="var element = jQuery('#convio_doubleoptin_warning'); if(this.checked){element.hide('slow');} else{element.show('slow');}"/> <?php _e("Double Opt-In" , "gravity-forms-convio") ?>  <?php gform_tooltip("convio_double_optin") ?> <br/><span id='convio_doubleoptin_warning' <?php echo rgar($config["meta"], "double_optin") ? "style='display:none'" : "" ?>>(<?php _e("Abusing this may cause your Convio account to be suspended.", "gravity-forms-convio") ?>)</span></td></tr>
                            <tr><td><input type="checkbox" name="convio_welcome_email" id="convio_welcome_email" value="1" <?php echo rgar($config["meta"],"welcome_email") ? "checked='checked'" : "" ?>/> <?php _e("Send Welcome Email" , "gravity-forms-convio") ?> <?php gform_tooltip("convio_welcome") ?></td></tr>
                        </table>
                    </div>
                </div>
                */?>
                <div id="convio_submit_container" class="margin_vertical_10">
                    <input type="submit" name="gf_convio_submit" value="<?php echo empty($id) ? __("Save", "gravity-forms-convio") : __("Update", "gravity-forms-convio"); ?>" class="button-primary"/>
                    <input type="button" value="<?php _e("Cancel", "gravity-forms-convio"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_convio'" />
                </div>
            </div>
        </form>
        </div>
        <script type="text/javascript">

            function SelectList(listId){
                if(listId){
                    jQuery("#convio_form_container").slideDown();
                    jQuery("#gf_convio_form").val("");
                }
                else{
                    jQuery("#convio_form_container").slideUp();
                    EndSelectForm("");
                }
            }

            function SelectForm(listId, formId){
                if(!formId){
                    jQuery("#convio_field_group").slideUp();
                    return;
                }

                jQuery("#convio_wait").show();
                jQuery("#convio_field_group").slideUp();

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_convio_form" );
                mysack.setVar( "gf_select_convio_form", "<?php echo wp_create_nonce("gf_select_convio_form") ?>" );
                mysack.setVar( "survey_id", listId);
                mysack.setVar( "form_id", formId);
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() {jQuery("#convio_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravity-forms-convio") ?>' )};
                mysack.runAJAX();

                return true;
            }

            function SetOptin(selectedField, selectedValue){

                //load form fields
                jQuery("#convio_optin_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#convio_optin_field_id").val();

                if(optinConditionField){
                    jQuery("#convio_optin_condition_message").hide();
                    jQuery("#convio_optin_condition_fields").show();
                    jQuery("#convio_optin_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
                    jQuery("#convio_optin_value").val(selectedValue);
                }
                else{
                    jQuery("#convio_optin_condition_message").show();
                    jQuery("#convio_optin_condition_fields").hide();
                }
            }

            function EndSelectForm(fieldList, form_meta, grouping, groups){
                //setting global form object
                form = form_meta;
                if(fieldList){

                    SetOptin("","");

                    jQuery("#convio_field_list").html(fieldList);
                    jQuery("#convio_groupings").html(grouping);

                    for(var i in groups)
                        SetGroupCondition(groups[i]["main"], groups[i]["sub"],"","");

                    //initializing convio group tooltip
                    jQuery('.tooltip_convio_groups').qtip({
                         content: jQuery('.tooltip_convio_groups').attr('tooltip'), // Use the tooltip attribute of the element for the content
                         show: { delay: 500, solo: true },
                         hide: { when: 'mouseout', fixed: true, delay: 200, effect: 'fade' },
                         style: "gformsstyle",
                         position: {
                          corner: {
                               target: "topRight",
                               tooltip: "bottomLeft"
                               }
                          }
                      });

                    jQuery("#convio_field_group").slideDown();

                }
                else{
                    jQuery("#convio_field_group").slideUp();
                    jQuery("#convio_field_list").html("");
                }
                jQuery("#convio_wait").hide();
            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters, inputName){
                if(!inputName){
                    inputName = 'convio_optin_value';
                }

                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field)
                    return "";

                var isAnySelected = false;

                if(field["type"] == "post_category" && field["displayAllCategories"]){
					str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "convio_optin_value", "name"=> "convio_optin_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
				}
				else if(field.choices){
					str += '<select id="' + inputName +'" name="' + inputName +'" class="optin_select">';

	                for(var i=0; i<field.choices.length; i++){
	                    var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
	                    var isSelected = fieldValue == selectedValue;
	                    var selected = isSelected ? "selected='selected'" : "";
	                    if(isSelected)
	                        isAnySelected = true;

	                    str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
	                }

	                if(!isAnySelected && selectedValue){
	                    str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
	                }
	            	str += "</select>";
				}
				else
				{
					selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
					//create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
					str += "<input type='text' placeholder='<?php _e("Enter value", "gravityforms"); ?>' id='" + inputName + "' name='" + inputName +"' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
				}

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;

                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if (IsConditionalLogicField(form.fields[i])) {
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

            function IsConditionalLogicField(field){
			    inputType = field.inputType ? field.inputType : field.type;
			    var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", 
			    "email", "hidden", "number", "phone", "multiselect", "post_title",
			                            "post_tags", "post_custom_field", "post_content", "post_excerpt"];

			    var index = jQuery.inArray(inputType, supported_fields);

			    return index >= 0;
			}

        </script>

        <?php

    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_convio");
        $wp_roles->add_cap("administrator", "gravityforms_convio_uninstall");
    }

    public static function selected($selected, $current){
        return $selected === $current ? " selected='selected'" : "";
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_convio", "gravityforms_convio_uninstall"));
    }

    public static function disable_convio(){
        delete_option("gf_convio_settings");
    }

    public static function select_convio_form(){

        check_ajax_referer("gf_select_convio_form", "gf_select_convio_form");
        $form_id =  intval(rgpost("form_id"));
        list($survey_id, $list_name) =  explode("|:|", rgpost("survey_id"));
        $setting_id =  intval(rgpost("setting_id"));

        $api = self::get_api();
        if(!$api)
            die("EndSelectForm();");

        //getting list of all Convio details for the selected contact list
        $details = self::get_survey_details($survey_id);

        //getting configuration
        $config = GFConvioData::get_feed($setting_id);

        //getting field map UI
        $field_map = self::get_field_mapping($config, $form_id, $details);
        
        // Escape quotes and strip extra whitespace and line breaks
        $field_map = str_replace("'","\'",$field_map);
		//$field_map = preg_replace('/[ \t]+/', ' ', preg_replace('/\s*$^\s*/m', "\n", $field_map));
        
		self::log_debug("Field map is set to: " . $field_map);
        
        //getting list of selection fields to be used by the optin
        $form_meta = RGFormsModel::get_form_meta($form_id);
        $selection_fields = GFCommon::get_selection_fields($form_meta, rgars($config, "meta/optin_field_id"));
        $group_condition = array();
        $group_names = array();
        $grouping = '';
        
        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);
        die("EndSelectForm('".$field_map."', ".GFCommon::json_encode($form).", '" . str_replace("'", "\'", $grouping) . "', " . json_encode($group_names) . " );");
    }

    private static function get_field_mapping($config, $form_id, $details){
	    	    
        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form_id);

        $str = "<table cellpadding='0' cellspacing='0'><tr><td class='convio_col_heading'>" . __("Survey Fields", "gravity-forms-convio") . "</td><td class='convio_col_heading'>" . __("Form Fields", "gravity-forms-convio") . "</td></tr>";
        
        if(!isset($config["meta"]))
            $config["meta"] = array("field_map" => "");
		
		foreach( $details as $d ){
			// Constituant Data 
			if( $d->questionType == "ConsQuestion" ){
				foreach( $d->questionTypeData->consRegInfoData->contactInfoField as $f){
		            $selected_field = rgar($config["meta"]["field_map"], $f->fieldName);
		            $required = $f->fieldStatus == 'REQUIRED' ? "<span class='gfield_required'>*</span>" : '';
		            
		            $error_class = $f->fieldStatus == 'REQUIRED' && empty($selected_field) && !empty($_POST["gf_convio_submit"]) ? " feeds_validation_error" : "";
		            
		            $str .= "<tr class='$error_class'><td class='convio_field_cell'>".self::ws_clean($f->label)." $required</td><td class='convio_field_cell'>".self::get_mapped_field_list($f->fieldName, $selected_field, $form_fields)."</td></tr>";
				}	
			}
			else {
				$selected_field = rgar($config["meta"]["field_map"], 'question_'.$d->questionId);
				$required = $d->questionRequired == true ? "<span class='gfield_required'>*</span>" : '';
				$error_class = $d->questionRequired == true && empty($selected_field) && !empty($_POST["gf_convio_submit"]) ? " feeds_validation_error" : "";
				$str .= "<tr class='$error_class'><td class='convio_field_cell'>".self::ws_clean($d->questionText)." $required</td><td class='convio_field_cell'>".self::get_mapped_field_list('question_'.$d->questionId, $selected_field, $form_fields)."</td></tr>";
			}
		}
		
        $str .= "</table>";

        return $str;
    }

    public static function get_form_fields($form_id){
        $form = RGFormsModel::get_form_meta($form_id);
        $fields = array();

        //Adding default fields
        array_push($form["fields"],array("id" => "date_created" , "label" => __("Entry Date", "gravity-forms-convio")));
        array_push($form["fields"],array("id" => "ip" , "label" => __("User IP", "gravity-forms-convio")));
        array_push($form["fields"],array("id" => "source_url" , "label" => __("Source Url", "gravity-forms-convio")));
        array_push($form["fields"],array("id" => "form_title" , "label" => __("Form Title", "gravity-forms-convio")));
        $form = self::get_entry_meta($form);
        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(is_array(rgar($field, "inputs"))){

                    //If this is an address field, add full name to the list
                    if(RGFormsModel::get_input_type($field) == "address")
                        $fields[] =  array($field["id"], GFCommon::get_label($field) . " (" . __("Full" , "gravity-forms-convio") . ")");

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(!rgar($field,"displayOnly")){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    private static function get_entry_meta($form){
        $entry_meta = GFFormsModel::get_entry_meta($form["id"]);
        $keys = array_keys($entry_meta);
        foreach ($keys as $key){
            array_push($form["fields"],array("id" => $key , "label" => $entry_meta[$key]['label']));
        }
        return $form;
    }

    private static function get_address($entry, $field_id){
        $street_value = str_replace("  ", " ", trim($entry[$field_id . ".1"]));
        $street2_value = str_replace("  ", " ", trim($entry[$field_id . ".2"]));
        $city_value = str_replace("  ", " ", trim($entry[$field_id . ".3"]));
        $state_value = str_replace("  ", " ", trim($entry[$field_id . ".4"]));
        $zip_value = trim($entry[$field_id . ".5"]);
        $country_value = GFCommon::get_country_code(trim($entry[$field_id . ".6"]));

        $address = $street_value;
        $address .= !empty($address) && !empty($street2_value) ? "  $street2_value" : $street2_value;
        $address .= !empty($address) && (!empty($city_value) || !empty($state_value)) ? "  $city_value" : $city_value;
        $address .= !empty($address) && !empty($city_value) && !empty($state_value) ? "  $state_value" : $state_value;
        $address .= !empty($address) && !empty($zip_value) ? "  $zip_value" : $zip_value;
        $address .= !empty($address) && !empty($country_value) ? "  $country_value" : $country_value;

        return $address;
    }

    public static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "convio_map_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''></option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    public static function add_paypal_settings($config, $form) {

        $settings_style = self::has_convio(rgar($form, "id")) ? "" : "display:none;";

        $convio_feeds = array();
        foreach(GFConvioData::get_feeds() as $feed) {
            $convio_feeds[] = $feed['form_id'];
        }
        ?>
        <li style="<?php echo $settings_style?>" id="gf_delay_convio_subscription_container">
            <input type="checkbox" name="gf_paypal_delay_convio_subscription" id="gf_paypal_delay_convio_subscription" value="1" <?php echo rgar($config['meta'], 'delay_convio_subscription') ? "checked='checked'" : ""?> />
            <label class="inline" for="gf_paypal_delay_convio_subscription">
                <?php
                _e("Subscribe user to Convio only when payment is received.", "gravity-forms-convio");
                ?>
            </label>
        </li>

        <script type="text/javascript">
            jQuery(document).ready(function($){
                jQuery(document).bind('paypalFormSelected', function(event, form) {

                    var convio_form_ids = <?php echo json_encode($convio_feeds); ?>;
                    var has_registration = false;

                    if(jQuery.inArray(String(form['id']), convio_form_ids) != -1)
                        has_registration = true;

                    if(has_registration == true) {
                        jQuery("#gf_delay_convio_subscription_container").show();
                    } else {
                        jQuery("#gf_delay_convio_subscription_container").hide();
                    }
                });
            });
        </script>

        <?php
    }

    public static function export($entry, $form, $is_fulfilled = false){

        //Login to Convio
        $api = self::get_api();
        if(!$api)
            return;

        //loading data class
        require_once(self::get_base_path() . "/inc/data.php");

        //getting all active feeds
        $feeds = GFConvioData::get_feed_by_form($form["id"], true);
        foreach($feeds as $feed){
            //only export if user has opted in
            if(self::is_optin($form, $feed, $entry))
            {
				self::export_feed($entry, $form, $feed, $api);
                //updating meta to indicate this entry has already been subscribed to Convio. This will be used to prevent duplicate subscriptions.
        		self::log_debug("Marking entry " . $entry["id"] . " as subscribed");
        		gform_update_meta($entry["id"], "convio_is_subscribed", true);
			}
			else
			{
				self::log_debug("Opt-in condition not met; not subscribing entry " . $entry["id"] . " to list");
			}
        }
    }

    public static function has_convio($form_id){
        if(!class_exists("GFConvioData"))
            require_once(self::get_base_path() . "/inc/data.php");

        //Getting Mail Chimp settings associated with this form
        $config = GFConvioData::get_feed_by_form($form_id);

        if(!$config)
            return false;

        return true;
    }
    
    // Magic goes here
    public static function export_feed($entry, $form, $feed, $api){
	    
		$double_optin = $feed["meta"]["double_optin"] ? true : false;
        $send_welcome = $feed["meta"]["welcome_email"] ? true : false;
        $email_field_id = $feed["meta"]["field_map"]["cons_email"];

        // Build parameter list of questions and values
		$params = array(
			'sso_auth_token' => self::get_auth_token(),
			'survey_id' => $feed['meta']['survey_id'],
		);
        
        foreach( $feed['meta']['field_map'] as $k => $v ){
    		$field = RGFormsModel::get_field($form, $v);

            if($v == intval($v) && RGFormsModel::get_input_type($field) == "address"){
            	//handling full address
	            $params[$k] = self::get_address($entry, $v);
			}
            else {
				$params[$k] = apply_filters("gform_convio_field_value", rgar($entry, $v), $form['id'], $v, $entry);
			}
        }
        
        // Send info to Convio
		$res = $api->call('CRSurveyAPI_submitSurvey', $params);
		
        //listSubscribe and listUpdateMember return true/false
        if (isset($res->errorResponse))
        {
			self::log_error( "Transaction failed. Error " . $res->errorResponse->code . " - " . $res->errorResponse->message);
        }
        else
        {
			self::log_debug("Transaction successful");
        }
    }

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/inc/data.php");

        if(!GFConvio::has_access("gravityforms_convio_uninstall"))
            die(__("You don't have adequate permission to uninstall Convio Add-On.", "gravity-forms-convio"));

        //droping all tables
        GFConvioData::drop_tables();

        //removing options
        delete_option("gf_convio_settings");
        delete_option("gf_convio_version");

        //Deactivating plugin
        $plugin = "gravity-forms-convio/convio.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    public static function is_optin($form, $settings, $entry){
        $config = $settings["meta"];

        $field = RGFormsModel::get_field($form, $config["optin_field_id"]);

        if(empty($field) || !$config["optin_enabled"])
            return true;

        $operator = isset($config["optin_operator"]) ? $config["optin_operator"] : "";
        $field_value = RGFormsModel::get_field_value($field, array());
        $is_value_match = RGFormsModel::is_value_match($field_value, $config["optin_value"], $operator);
        $is_visible = !RGFormsModel::is_field_hidden($form, $field, array(), $entry);

        $is_optin = $is_value_match && $is_visible;

        return $is_optin;

    }
    
    private static function get_survey_details( $survey_id )
    {
		$api = self::get_api();
		
		self::log_debug("Retrieving details for survey " . $survey_id);
		$params = array(
			'sso_auth_token' => self::get_auth_token(),
			'survey_id' => $survey_id,
		);
		
		$details = $api->call('CRSurveyAPI_getSurvey', $params);
		
		// Check for errors
		if( isset($details->errorResponse) ){
        	self::log_error("Getting survey details. Error " . $details->errorResponse->code . " - " . $details->errorResponse->message);
        	return NULL;
		}
		
		// Modify results to only send back questions
		if( is_array($details->getSurveyResponse->survey->surveyQuestions) ){
			$ret = $details->getSurveyResponse->survey->surveyQuestions;
		}
		else {
			$ret[] = $details->getSurveyResponse->survey->surveyQuestions;			
		}
		
		//self::log_debug("Details retrieved: " . print_r($ret,true));

		return $ret;
    }
    
    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }
	
	// Clean strings from Convio, we don't need any HTML or line breaks 
    protected function ws_clean($string){
	    $chars = array("
", "\n", "\r", "chr(13)",  "\t", "\0", "\x0B");
	    $string = str_replace($chars, '', trim(strip_tags($string)));
	    return $string;
    }
    
    //Returns the url of the plugin's root folder
    protected function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }

    function set_logging_supported($plugins)
	{
		$plugins[self::$slug] = "Convio";
		return $plugins;
	}

    private static function log_error($message){
        if(class_exists("GFLogging"))
        {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
        }
    }

    private static function log_debug($message){
		if(class_exists("GFLogging"))
        {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
        }
    }
}

if(!function_exists("rgget")){
function rgget($name, $array=null){
    if(!isset($array))
        $array = $_GET;

    if(isset($array[$name]))
        return $array[$name];

    return "";
}
}

if(!function_exists("rgpost")){
function rgpost($name, $do_stripslashes=true){
    if(isset($_POST[$name]))
        return $do_stripslashes ? stripslashes_deep($_POST[$name]) : $_POST[$name];

    return "";
}
}

if(!function_exists("rgar")){
function rgar($array, $name){
    if(isset($array[$name]))
        return $array[$name];

    return '';
}
}

if(!function_exists("rgempty")){
function rgempty($name, $array = null){
    if(!$array)
        $array = $_POST;

    $val = rgget($name, $array);
    return empty($val);
}
}

if(!function_exists("rgblank")){
function rgblank($text){
    return empty($text) && strval($text) != "0";
}
}

?>