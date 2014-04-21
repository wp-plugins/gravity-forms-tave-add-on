<?php
/*
Plugin Name: Gravity Forms T&aacute;ve Add-On
Plugin URI: http://www.rowellphoto.com/gravity-forms-tave/
Description: Connects your WordPress web site to your T&aacute;ve account for collecting leads using the power of Gravity Forms.
Version: 2014.04.18
Author: Ryan Rowell
Author URI: http://www.rowellphoto.com/

------------------------------------------------------------------------
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

add_action('init',  array('GFTave', 'init'));
register_activation_hook( __FILE__, array("GFTave", "add_permissions"));

class GFTave {

    private static $version = "1";
    private static $min_gravityforms_version = "1.5";

    //Plugin starting point. Will load appropriate files
    public static function init(){

        //if gravity forms version is not supported, abort
        if(!self::is_gravityforms_supported()){
           return;
        }

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravityformstave', FALSE, '/gravityforms_tave/languages' );

            //creates a new Settings page on Gravity Forms' settings screen
            if(self::has_access("gravityforms_tave")){
                RGForms::add_settings_page("T&aacute;ve", array("GFTave", "settings_page"), self::get_base_url() . "/images/tave_icon_32.png");
            }
        }

        //integrating with Members plugin
        if(function_exists('members_get_capabilities'))
            add_filter('members_get_capabilities', array("GFTave", "members_get_capabilities"));

        //creates the subnav left menu
        add_filter("gform_addon_navigation", array('GFTave', 'create_menu'));
		//adds Settings link in the plugins list
		add_filter('plugin_action_links', array('GFTave', 'plugin_settings_link'), 10, 2);

        //loading Gravity Forms tooltips
		if (self::is_gravity_page()) {
			require_once(GFCommon::get_base_path() . "/tooltips.php");
			add_filter('gform_tooltips', array('GFTave', 'tooltips'));
		}

        if(self::is_tave_page()){

            //loading data lib
            require_once(self::get_base_path() . "/data.php");

            //runs the setup when version changes
            self::setup();

        }
        else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

            //loading data class
            require_once(self::get_base_path() . "/data.php");

            //Hooks for AJAX operations.
            //1- When clicking the active/inactive icon on the Feed list page
            add_action('wp_ajax_rg_update_feed_active', array('GFTave', 'update_feed_active'));

            //2- When selecting a form on the feed edit page
            add_action('wp_ajax_gf_select_tave_form', array('GFTave', 'select_tave_form'));

        }
        else{
             //Handling post submission. This is where the integration will happen 
			 //(will get fired right after the form gets submitted)
            add_action("gform_post_submission", array('GFTave', 'export'), 10, 2);
        }

    }

    public static function update_feed_active(){
        check_ajax_referer('rg_update_feed_active','rg_update_feed_active');
        $id = rgpost("feed_id");
        $feed = GFTaveData::get_feed($id);
        GFTaveData::update_feed($id, $feed["form_id"], rgpost("is_active"), $feed["meta"]);
    }


    //Returns true if the current page is a feed page. Returns false if not.
    private static function is_tave_page(){
        $current_page = trim(strtolower(rgget("page")));
        $tave_pages = array("gf_tave");

        return in_array($current_page, $tave_pages);
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup(){

        if(get_option("gf_tave_version") != self::$version)
            GFTaveData::update_table();

        update_option("gf_tave_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $tave_tooltips = array(
            "tave_gravity_form" => "<h6>" . __("Gravity Form", "gravityformstave") . "</h6>" . __("Select the Gravity Form you would like to integrate with T&aacute;ve.", "gravityformstave"),
            "tave_api" => "<h6>" . __("T&aacute;ve Secret Key", "gravityformstave") . "</h6>" . __("Enter the Secret Key associated with your T&aacute;ve account.", "gravityformstave"),
            "tave_brand" => "<h6>" . __("T&aacute;ve Studio ID", "gravityformstave") . "</h6>" . __("Enter the T&aacute;ve Studio ID for the brand you wish to connect.", "gravityformstave"),
            "tave_mapping" => "<h6>" . __("Field Mapping", "gravityformstave") . "</h6>" . __("Map your Form Fields to the available T&aacute;ve contact fields. Fields in red are required by T&aacute;ve.", "gravityformstave")

        );
        return array_merge($tooltips, $tave_tooltips);
    }

    //Creates Tave left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_tave");
        if(!empty($permission))
            $menus[] = array("name" => "gf_tave", "label" => __("T&aacute;ve", "gravityformstave"), "callback" =>  array("GFTave", "tave_page"), "permission" => $permission);

        return $menus;
    }

    public static function settings_page(){

        $is_valid_api = true;
		$validation_icon = ($is_valid_api) ? "/images/tick.png" : "/images/error.png";
		
		if(!rgempty("uninstall")){
            check_admin_referer("uninstall", "gf_tave_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms T&aacute;ve Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravityformstave")?></div>
            <?php
            return;
        } else if(!rgempty("gf_tave_submit")){
            check_admin_referer("update", "gf_tave_update");
            $settings = array(
				"apikey" => trim(rgpost("gf_tave_apikey")), 
				"brand" => trim(rgpost("gf_tave_brand")),
				"no_email" => trim(rgpost("gf_tave_no_email"))
			);
			
			//validate the API key to make sure it's the right format
			$valid_pattern = "/^[A-Z0-9]{40}$/i";
			$is_valid_api = (preg_match($valid_pattern,$settings["apikey"]) != 0);
			
			if (!$is_valid_api) {
				$validation_icon = "/images/error.png";
				?>
				<div class="delete-alert alert_red" style="padding:6px"><?php _e("The Secret Key you provided is in the <i>wrong format!</i> You may not have copied the entire string, or have made a typo if manually entering the Key. Please try again.", "gravityformstave") ?></div>
				<?php
			} else {
				update_option("gf_tave_settings", $settings);
				?>
				<div class="updated fade" style="padding:6px"><?php echo sprintf(__("Your T&aacute;ve Settings have been saved. Now you can %sconfigure a new feed%s!", "gravityformstave"), "<a href='?page=gf_tave&view=edit&id=0'>", "</a>") ?></div>
				<?php
			}

        } else {
            $settings = get_option("gf_tave_settings");
        }
        ?>

        <form method="post" action="">
            <?php wp_nonce_field("update", "gf_tave_update") ?>
            <h3><?php _e("T&aacute;ve Settings", "gravityformstave") ?></h3>
			
			<p style="text-align: left;"><?php _e("Here is where you will connect your T&aacute;ve account to the plugin. To find your Secret Key and Studio ID, visit the New Lead API page from the bottom of your Settings tab on T&aacute;ve (You can", "gravityformstave") ?> <a href="https://my.tave.com/Settings/NewLeadAPI" title="Go to the New Lead API page on T&aacute;ve" target="_blank"><?php _e("head straight there", "gravityformstave") ?></a> <?php _e("if you are already logged in.) Copy the Secret Key and Studio ID into their respective fields below.", "gravityformstave") ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="gf_tave_apikey"><?php _e("T&aacute;ve Secret Key", "gravityformstave"); ?>  <?php gform_tooltip("tave_api") ?></label></th>
                    <td>
                        <input type="text" id="gf_tave_apikey" name="gf_tave_apikey" value="<?php echo esc_attr($settings["apikey"]) ?>" size="50"/>
						<?php if (strlen($settings["apikey"]) != 0) echo "<img src=\"" . self::get_base_url() . "/" . $validation_icon . "\" />"; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_tave_brand"><?php _e("T&aacute;ve Studio ID", "gravityformstave"); ?>  <?php gform_tooltip("tave_brand") ?></label></th>
                    <td>
                        <input type="text" id="gf_tave_brand" name="gf_tave_brand" value="<?php echo esc_attr($settings["brand"]) ?>" size="50"/>
						<?php if (strlen($settings["brand"]) != 0) echo "<img src=\"" . self::get_base_url() . "/images/tick.png\" />"; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_tave_no_email"><?php _e("Dont Send T&aacute;ve Email", "gravityformstave"); ?></label></th>
                    <td>
                        <input type="checkbox" id="gf_tave_no_email" name="gf_tave_no_email" value="checked" <?php checked( 'checked', $settings["no_email"]); ?> />
                        Check this box to stop receiving the T&aacute;ve email.
                    </td>
                </tr>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_tave_submit" class="button-primary" value="<?php _e("Save Settings", "gravityformstave") ?>" /></td>
                </tr>
            </table>
        </form>
		<div class="hr-divider"></div>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_tave_uninstall") ?>
            <?php
            if(GFCommon::current_user_can_any("gravityforms_tave_uninstall")){ ?>
                <div class="hr-divider"></div>
				
                <h3><?php _e("Uninstall T&aacute;ve Add-On", "gravityformstave") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL T&aacute;ve Feeds, disconnecting your Gravity Forms from your T&aacute;ve account.", "gravityformstave") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall T&aacute;ve Add-On", "gravityformstave") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL T&aacute;ve Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravityformstave") . '\');"/>';
                    echo apply_filters("gform_tave_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php
            } ?>
        </form>
		<div style="clear: both;"></div>
        <?php
    }

    public static function tave_page(){
        $view = rgget("view");
        if($view == "edit")
            self::edit_page(rgget("id"));
        else
            self::list_page();
    }

    //Displays the tave feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("T&aacute;ve Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravityformstave"));
        }

        if(rgpost("action") == "delete"){
            check_admin_referer("list_action", "gf_tave_list");

            $id = absint(rgpost("action_argument"));
            GFTaveData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravityformstave") ?></div>
            <?php
        }
        else if (!rgempty("bulk_action")){
            check_admin_referer("list_action", "gf_tave_list");
            $selected_feeds = rgpost("feed");
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFTaveData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravityformstave") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            <h2><?php _e("T&aacute;ve Feeds", "gravityformstave"); ?>
            <a class="button add-new-h2" href="admin.php?page=gf_tave&view=edit&id=0"><?php _e("Add New", "gravityformstave") ?></a>
            </h2>
			
			
            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_tave_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px; 0">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravityformstave") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravityformstave") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravityformstave") ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php _e("Apply", "gravityformstave") ?>" onclick="if( jQuery('#bulk_action').val() == 'delete' && !confirm('<?php  echo __("Delete selected feeds? \'Cancel\' to stop, \'OK\' to delete.", "gravityformstave") ?>')) { return false; } return true;" />
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformstave") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Message", "gravityformstave") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformstave") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Message", "gravityformstave") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php
                        $settings = get_option("gf_tave_settings");
                        $feeds = GFTaveData::get_feeds();
                        if(is_array($feeds) && sizeof($feeds) > 0){
                            foreach($feeds as $feed){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $feed["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($feed["is_active"]) ?>.png" alt="<?php echo $feed["is_active"] ? __("Active", "gravityformstave") : __("Inactive", "gravityformstave");?>" title="<?php echo $feed["is_active"] ? __("Active", "gravityformstave") : __("Inactive", "gravityformstave");?>" onclick="ToggleActive(this, <?php echo $feed['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_tave&view=edit&id=<?php echo $feed["id"] ?>" title="<?php _e("Edit", "gravityformstave") ?>"><?php echo $feed["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="Edit this setting" href="admin.php?page=gf_tave&view=edit&id=<?php echo $feed["id"] ?>" title="<?php _e("Edit", "gravityformstave") ?>"><?php _e("Edit", "gravityformstave") ?></a>
                                            |
                                            </span>

                                            <span class="edit">
                                            <a title="<?php _e("Delete", "gravityformstave") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravityformstave") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravityformstave") ?>')){ DeleteSetting(<?php echo $feed["id"] ?>);}"><?php _e("Delete", "gravityformstave")?></a>

                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date"><?php echo strlen($feed["meta"]["brand"]) > 100 ? substr($feed["meta"]["brand"], 0, 100) : $feed["meta"]["brand"] ?></td>
                                </tr>
                                <?php
                            }
                        }
                        else if(!empty($settings["apikey"]) && !empty($settings["brand"])){
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php _e(sprintf("You don't have any T&aacute;ve feeds configured. Let's go %screate one%s!", '<a href="admin.php?page=gf_tave&view=edit&id=0">', "</a>"), "gravityformstave"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        else{
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php _e(sprintf("To get started, please configure your %sT&aacute;ve Settings%s.", '<a href="admin.php?page=gf_settings&addon=T%26aacute%3Bve">', "</a>"), "gravityformstave"); ?>
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
                    jQuery(img).attr('title','<?php _e("Inactive", "gravityformstave") ?>').attr('alt', '<?php _e("Inactive", "gravityformstave") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravityformstave") ?>').attr('alt', '<?php _e("Active", "gravityformstave") ?>');
                }

                jQuery.post(ajaxurl,{action:"rg_update_feed_active", rg_update_feed_active:"<?php echo wp_create_nonce("rg_update_feed_active") ?>",
                                    feed_id: feed_id,
                                    is_active: is_active ? 0 : 1,
                                    cookie: encodeURIComponent(document.cookie)});

                return true;
            }

        </script>
        <?php
    }

    private static function edit_page(){
        ?>
		<link rel="stylesheet" href="<?php echo GFCommon::get_base_url()?>/css/admin.css" />
        <style>
            #tave_submit_container{clear:both;}
            #tave_field_group div{float:left;}
            .tave_col_heading{padding-bottom:2px; font-weight:bold; width:150px;}
            .tave_field_cell {padding: 6px 17px 0 0; margin-right:15px;}
			.tave_required_field {color: #c00;}
            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0; padding-left:5px;}
            .margin_vertical_30{margin: 30px 0; padding-left:5px;}
            .width-1{width:300px;}
            .left_header{float:left; width:200px;}
        </style>
        <script type="text/javascript">
            var form = Array();
        </script>
        <div class="wrap">
            <h2><?php _e("Add/Edit T&aacute;ve Feed", "gravityformstave") ?></h2>

        <?php

        //getting setting id (0 when creating a new one)
        $id = !rgempty("tave_setting_id") ? rgpost("tave_setting_id") : absint(rgget("id"));
        $config = empty($id) ? array("is_active" => true, "meta" => array()) : GFTaveData::get_feed($id);

        //updating meta information
        if(!rgempty("gf_tave_submit")){

            $config["form_id"] = absint(rgpost("gf_tave_form"));

            $lead_fields = self::get_lead_fields();
            $config["meta"]["lead_fields"] = array();
            foreach($lead_fields as $field){
                $config["meta"]["lead_fields"][$field["name"]] = $_POST["tave_lead_field_{$field["name"]}"];
            }
            //-----------------
			// TODO: Add validation for required fields
            $id = GFTaveData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
			
            ?>
            <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Congratulations! Your feed was saved successfully. You can now %sgo back to the feeds list%s", "gravityformstave"), "<a href='?page=gf_tave'>", "</a>") ?></div>
            <input type="hidden" name="tave_setting_id" value="<?php echo $id ?>"/>
            <?php
        }
		
		$settings = get_option("gf_tave_settings");
		if (!empty($settings["apikey"]) && !empty($settings["brand"])) {
		
		$form = isset($config["form_id"]) && $config["form_id"] ? RGFormsModel::get_form_meta($config["form_id"]) : array();
		require_once(self::get_base_path() . "/data.php");
        ?>
            <form method="post" action="">
                <input type="hidden" name="tave_setting_id" value="<?php echo $id ?>"/>
				
                <div id="tave_form_container" valign="top" class="margin_vertical_10">
                    <label for="gf_tave_form" class="left_header"><?php _e("Gravity Form", "gravityformstave"); ?> <?php gform_tooltip("tave_gravity_form") ?></label>
                    <div style="margin-top:25px;">
                        <select id="gf_tave_form" name="gf_tave_form" onchange="SelectForm(jQuery(this).val());">
                            <option value=""><?php _e("Select a form", "gravityformstave"); ?> </option>
                            <?php
							$active_form = rgar($config, 'form_id');
							$forms = GFTaveData::get_available_forms($active_form);
                            foreach($forms as $current_form){
                                $selected = absint($current_form->id) == $config["form_id"] ? "selected='selected'" : "";
                                ?>
                                <option value="<?php echo absint($current_form->id) ?>"  <?php echo $selected ?>><?php echo esc_html($current_form->title) ?></option>
                                <?php
                            }
                            ?>
                        </select>
                        &nbsp;&nbsp;
                        <img src="<?php echo GFTave::get_base_url() ?>/images/loading.gif" id="tave_wait" style="display: none;"/>
                    </div>
					
				<div id="tave_field_group" valign="top" <?php echo empty($config["form_id"]) ? "style='display:none;'" : "" ?>>
					
					<div id="tave_mapping_notice">
						<p><?php _e("For each T&aacute;ve Field below, select or &quot;map&quot; the corresponding Form Field from the list of fields. You <b>must</b> map the T&aacute;ve Fields in red, but the remaining Fields are optional. Any Form Fields that are not mapped will also be sent to T&aacute;ve and will appear below the Remarks in the Additional Information section.", "gravityformstave");?></p>
					</div>
					<div style="clear: all;"></div>
					
				   <div class="margin_vertical_10">
						<label class="left_header"><?php _e("Field Mapping", "gravityformstave"); ?> <?php gform_tooltip("tave_mapping") ?></label>
						<div id="gf_tave_brand_variable_select">
							<?php
								if(!empty($form))
									echo self::get_lead_information($form, $config);
							?>
						</div>
					</div>
                </div>

                    <div id="tave_submit_container" class="margin_vertical_30" style="clear:both;">
                        <input type="submit" name="gf_tave_submit" value="<?php echo empty($id) ? __("Save Feed", "gravityformstave") : __("Update Feed", "gravityformstave"); ?>" class="button-primary"/>
						<input type="button" value="<?php _e("Cancel", "gravityformstave"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_tave'" />
                    </div>
                </div>
            </form>
        </div>
        <script type="text/javascript">
			
            function SelectForm(formId){
                if(!formId){
                    jQuery("#tave_field_group").slideUp();
                    return;
                }

                jQuery("#tave_wait").show();
                jQuery("#tave_field_group").slideUp();
                jQuery.post(ajaxurl,{action:"gf_select_tave_form", gf_select_tave_form:"<?php echo wp_create_nonce("gf_select_tave_form") ?>",
                                    form_id: formId,
                                    cookie: encodeURIComponent(document.cookie)},

                                    function(data){
                                        //setting global form object
                                        //form = data.form;
                                        //fields = data["fields"];
                                        jQuery("#gf_tave_brand_variable_select").html(data);

                                        jQuery("#tave_field_group").slideDown();
                                        jQuery("#tave_wait").hide();
                                    }, "json"
                );
            }
			
            function InsertVariable(element_id, callback, variable){
                if(!variable)
                    variable = jQuery('#' + element_id + '_variable_select').val();

                var brandElement = jQuery("#" + element_id);

                if(document.selection) {
                    // Go the IE way
                    brandElement[0].focus();
                    document.selection.createRange().text=variable;
                }
                else if(brandElement[0].selectionStart) {
                    // Go the Gecko way
                    obj = brandElement[0]
                    obj.value = obj.value.substr(0, obj.selectionStart) + variable + obj.value.substr(obj.selectionEnd, obj.value.length);
                }
                else {
                    brandElement.val(variable + brandElement.val());
                }

                jQuery('#' + element_id + '_variable_select')[0].selectedIndex = 0;

                if(callback && window[callback])
                    window[callback].call();
            }

        </script>

        <?php
		} else {
			?>
			<div class="gforms_help_alert alert_yellow" style="margin-top: 20px;">
			<?php _e(sprintf("Whoa there! You must configure your %sT&aacute;ve Settings%s before creating any feeds.", '<a href="admin.php?page=gf_settings&addon=T%26aacute%3Bve">', "</a>"), "gravityformstave"); ?>
			</div>
			<?php
		}
    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_tave");
        $wp_roles->add_cap("administrator", "gravityforms_tave_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_tave", "gravityforms_tave_uninstall"));
    }

    public static function disable_tave(){
        delete_option("gf_tave_settings");
    }

    public static function select_tave_form(){

        check_ajax_referer("gf_select_tave_form", "gf_select_tave_form");
        $form_id =  intval($_POST["form_id"]);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);
        $fields = self::get_lead_information($form);

        $result = $fields;
        die(GFCommon::json_encode($result));
    }

    private static function get_form_fields($form){
        $fields = array();

        if(is_array($form) && is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(is_array($field["inputs"])){

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(!rgar($field, "displayOnly")){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }
	
    public static function export($entry, $form){

		//loading data class
		require_once(self::get_base_path() . "/data.php");
		$feed = GFTaveData::get_feed_by_form($form["id"], true);
		if (isset($feed) && isset($feed[0])) {		
			$form = RGFormsModel::get_form_meta($entry["form_id"]);
			// TESTING ONLY
			//echo "<b>ENTRY:</b><br /><pre>"; print_r($entry); echo "</pre>";
			self::send_to_tave($entry, $form, $feed);
		}
    }
	
    public static function send_to_tave($entry, $form, $feed){
    	
		$settings = get_option("gf_tave_settings");
		$admin_email = get_option("admin_email");
		$url = "https://my.tave.com/WebService/CreateLead/{$settings["brand"]}";
		$map = $feed[0]["meta"]["lead_fields"];
		$convertFunction = function_exists('mb_convert_encoding');

		/* create a data structure to send to Tave */
		$lead = array();
		$lead["SecretKey"] = $settings["apikey"];
		$lead["FirstName"] = self::get_entry_data("FirstName", $entry, $map);
		$lead["LastName"] = self::get_entry_data("LastName", $entry, $map);
		$lead["MobilePhone"] = self::get_entry_data("MobilePhone", $entry, $map);
		$lead["HomePhone"] = self::get_entry_data("HomePhone", $entry, $map);
		$lead["WorkPhone"] = self::get_entry_data("WorkPhone", $entry, $map);
		$lead["Email"] = self::get_entry_data("Email", $entry, $map);
		$lead["JobType"] = self::get_entry_data("JobType", $entry, $map);
		$lead["EventDate"] = self::get_entry_data("EventDate", $entry, $map);
		$lead["Source"] = self::get_entry_data("Source", $entry, $map);
		$lead["Message"] = self::get_entry_data("Message", $entry, $map);
		$lead["EventLocation"] = self::get_entry_data("EventLocation", $entry, $map);
		$lead["CeremonyLocation"] = self::get_entry_data("CeremonyLocation", $entry, $map);
		$lead["ReceptionLocation"] = self::get_entry_data("ReceptionLocation", $entry, $map);
		$lead["GuestCount"] = self::get_entry_data("GuestCount", $entry, $map);
		$lead["Brand"] = self::get_entry_data("Brand", $entry, $map);
		
		foreach (array_keys($lead) as $k) {
			if (empty($lead[$k])) {
				unset($lead[$k]);
			}
		}
		
		foreach ($lead as $key => $value) {
		
			// checking if mb_convert_encoding is alive and kicking, then using it to convert all form data to UTF-8 encoding
			if ($convertFunction) {
				$lead[$key] = mb_convert_encoding(trim($value), 'HTML-ENTITIES', 'UTF-8');
			}
			else {
				$lead[$key] = trim($value);
			}
		}

		/* send this data to Tave via the API */
		$ch = curl_init();
		// if the checkbox for tave forms is checked dont send the tave form.
		if ($settings["no_email"] == "checked") {
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Tave-No-Email-Notification: true'));
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $lead);

		/* get the response from the Tave API */
		$response = curl_exec($ch);
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		/* close the connection */
		curl_close($ch);
	
    }
	
	//retrieves the requested field information from the submitted form
	public static function get_entry_data($key, $entry, $map, $default=null) {
		
		if (isset($map[$key])) $str = trim($entry[$map[$key]]);
				
		//if the fields was empty and we provided a default, use the default
		if (strlen($str) == 0) $str = $default;
		return $str;
	}
	
	//collects all of the form fields not mapped to the Tave fields
	public static function get_extras($entry, $map) {
		$form = RGFormsModel::get_form_meta($entry["form_id"]);
		$fields = $form["fields"];
		$useless = "html,section,captcha,page"; //form "fields" that don't gather useful info
		$extras = array(); //array to be returned
		
		for ($id = 0; $id < count($fields); $id++) {
			$tmpField = $fields[$id];
			//if it's not a mapped or useless field, collect the info...
			if (array_search($tmpField["id"], $map) === false && 
					strpos($useless, $tmpField["type"]) === false) {
				$tmpLabel = (isset($tmpField["adminLabel"]) && strlen($tmpField["adminLabel"]) > 0) ? $tmpField["adminLabel"] : $tmpField["label"];
				//$tmpLabel = $id . " " . $tmpLabel; //show Tave Support the IDs for sorting. Remove when done.
				
				$tmpValue = "";
				if ($tmpField["type"] == "checkbox") {
					$fieldInputs = $tmpField["inputs"];
					$choices = array();
					for ($cb = 0; $cb < count($fieldInputs); $cb++) {
						$tmpID = (string) $fieldInputs[$cb]["id"];
						if (strlen($entry[$tmpID]) > 0) {
							$choices[] = trim($entry[$tmpID]);
						}
					}
					$tmpValue = implode(", ", $choices);
					
				} elseif ($tmpField["type"] == "list") {
					$tmpValue = unserialize(trim($entry[$tmpField["id"]]));
					if (is_array($tmpValue[0])) {
						$columns = array();
						$valArray = $tmpValue;
						while(list($key, $val) = each($valArray[0])) {
							$columns[] = $key;
						}
						
						for ($col = 0; $col < count($valArray[0]); $col++) {
							$rowVals = array();
							for ($row = 0; $row < count($valArray); $row++) {
								$rowVals[] = $valArray[$row][$columns[$col]];
							}
							$tmpValues[] = $columns[$col] . " = " . implode(", ", $rowVals);
							unset($rowVals);
						}
						$tmpValue = implode("; ", $tmpValues);
					} else {
						$tmpValue = @implode(", ", $tmpValue); //don't raise error if empty
					}
				} else {
					$tmpValue = trim($entry[$tmpField["id"]]);
				}
			
				//eliminate blank values
				if (strlen($tmpValue) > 0) {
					$extras[$tmpLabel] = $tmpValue;
				}
			}
		}
		
		return $extras;
	}
	
    private static function get_lead_information($form, $config=null){

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form);

        $str = "<table cellpadding=\"0\" cellspacing=\"0\"><tr><td class=\"tave_col_heading\">" . __("T&aacute;ve Fields", "gravityformstave") . "</td><td class=\"tave_col_heading\">" . __("Form Fields", "gravityformstave") . "</td></tr>";
        $lead_fields = self::get_lead_fields();
		
        foreach($lead_fields as $field){
            $selected_field = $config ? $config["meta"]["lead_fields"][$field["name"]] : "";
			$required = array_key_exists("required", $field) ? " tave_required_field" : "";
            $str .= "<tr><td class=\"tave_field_cell$required\">" . $field["label"]  . "</td><td class=\"tave_field_cell\">" . self::get_mapped_field_list($field["name"], $selected_field, $form_fields) . "</td></tr>";
        }
        $str .= "<tr><td colspan=\"2\" style=\"padding: 6px 0;\">Missing a required field? Go <a href=\"admin.php?page=gf_edit_forms&id=" . $form["id"] . "\">edit this form</a></td></tr>";
		$str .= "</table>";

        return $str;
    }

    private static function get_lead_fields(){
        //the "required" key exists only for fields that are required by the Tave API
		return array(
			array("name" => "JobType", "label" => "JobType", "required" => "true"), 
			array("name" => "FirstName", "label" => "FirstName", "required" => "true"), 
			array("name" => "LastName", "label" =>"LastName", "required" => "true"),
			array("name" => "Email", "label" =>"Email", "required" => "true"), 
			array("name" => "Brand", "label" =>"Brand"), 
			array("name" => "EventDate", "label" => "EventDate"),
			array("name" => "Source", "label" => "Source"),
			array("name" => "Message", "label" => "Message"),
			array("name" => "HomePhone", "label" =>"HomePhone"), 
			array("name" => "WorkPhone", "label" =>"WorkPhone"), 
			array("name" => "MobilePhone", "label" =>"CellPhone"), 
			array("name" => "GuestCount", "label" =>"GuestsCount"),
			array("name" => "EventLocation", "label" =>"EventLocation"), 
			array("name" => "CeremonyLocation", "label" =>"CeremonyLocation"), 
			array("name" => "ReceptionLocation", "label" =>"ReceptionLocation")
		);
    }
	
    private static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "tave_lead_field_" . $variable_name;
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

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        if(!GFTave::has_access("gravityforms_tave_uninstall"))
            die(__("You don't have adequate permission to uninstall the T&aacute;ve Add-On.", "gravityformstave"));

        //droping all tables
        GFTaveData::drop_tables();

        //removing options
        delete_option("gf_tave_settings");
        delete_option("gf_tave_version");

        //Deactivating plugin
        $plugin = "gravity-forms-tave-add-on/tave.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
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
	
	//Returns whether or not we are on a Gravity Forms admin page.
	protected static function is_gravity_page() {
        $current_page = trim( strtolower( RGForms::get( "page" ) ) );
        $gf_pages = array( "gf_edit_forms", "gf_new_form", "gf_entries", "gf_settings", "gf_export", "gf_help", "gf_tave" );
        return in_array( $current_page, $gf_pages );
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

	public static function plugin_settings_link( $links, $file ) {
		if ( $file != plugin_basename( __FILE__ ))
			return $links;

		array_unshift($links, '<a href="' . admin_url("admin.php") . '?page=gf_settings&addon=T%26aacute%3Bve">' . __( 'Settings', 'gravityformstave' ) . '</a>');

		return $links;
    }

}
?>