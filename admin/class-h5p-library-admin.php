<?php
/**
 * H5P Plugin.
 *
 * @package   H5P
 * @author    Joubel <contact@joubel.com>
 * @license   MIT
 * @link      http://joubel.com
 * @copyright 2014 Joubel
 */

/**
 * H5P Library Admin class
 *
 * @package H5P_Plugin_Admin
 * @author Joubel <contact@joubel.com>
 */
class H5PLibraryAdmin {

  /**
   * @since 1.1.0
   */
  private $plugin_slug = NULL;
  
  /**
   * Keep track of the current library.
   * 
   * @since 1.1.0
   */
  private $library = NULL;
  
  /**
   * Initialize library admin
   *
   * @since 1.1.0
   * @param string $plugin_slug
   */
  public function __construct($plugin_slug) {
    $this->plugin_slug = $plugin_slug;
  }
  
  /**
   * Load content and alter page title for certain pages.
   * 
   * @since 1.1.0
   * @param string $page
   * @param string $admin_title
   * @param string $title
   * @return string
   */
  public function alter_title($page, $admin_title, $title) {
    $task = filter_input(INPUT_GET, 'task', FILTER_SANITIZE_STRING);
    
    // Find library title 
    $show = ($task === 'show');
    $delete = ($task === 'delete');
    $upgrade = ($task === 'upgrade');
    if ($show || $delete || $upgrade) {
      $library = $this->get_library();
      if ($library) {
        if ($delete) {
          $admin_title = str_replace($title, __('Delete'), $admin_title);
        }
        else if ($upgrade) {
          $admin_title = str_replace($title, __('Content Upgrade'), $admin_title);
          $plugin = H5P_Plugin::get_instance();
          $plugin->get_h5p_instance('core'); // Load core
        }
        $admin_title = esc_html($library->title) . ($upgrade ? ' (' . H5PCore::libraryVersion($library) . ')' : '') . ' &lsaquo; ' . $admin_title;
      }
    }
    
    return $admin_title;
  }
  
  /**
   * Load library
   *
   * @since 1.1.0
   * @param int $id optional
   */
  private function get_library($id = NULL) {
    global $wpdb;
    
    if ($this->library !== NULL) {
      return $this->library; // Return the current loaded library.
    }
    
    if ($id === NULL) {
      $id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
    }
    
    // Try to find content with $id.
    $this->library = $wpdb->get_row($wpdb->prepare(
        "SELECT id, title, name, major_version, minor_version, patch_version, runnable, fullscreen
          FROM {$wpdb->prefix}h5p_libraries
          WHERE id = %d",
        $id
      )
    );
    if (!$this->library) {
      H5P_Plugin_Admin::set_error(sprintf(__('Cannot find library with id: %d.', $this->plugin_slug), $id));
    }
    
    return $this->library;
  }
  
  /**
   * Display admin interface for managing content libraries.
   *
   * @since 1.1.0
   */
  public function display_libraries_page() {
    switch (filter_input(INPUT_GET, 'task', FILTER_SANITIZE_STRING)) {
      case NULL:
        $this->display_libraries();
        return;
      
      case 'show':
        $this->display_library_details();
        return;
        
      case 'delete':
        $library = $this->get_library();
        H5P_Plugin_Admin::print_messages();
        
        if ($library) {
          include_once('views/library-delete.php');
        }
        return;

      case 'upgrade':
        $library = $this->get_library();
        if ($library) {
          $settings = $this->display_content_upgrades($library);
        }
        
        include_once('views/library-content-upgrade.php');

        if (isset($settings)) {
          $plugin = H5P_Plugin::get_instance();
          $plugin->print_settings($settings);
        }
        return;
    }
    
    print '<div class="wrap"><h2>' . esc_html__('Unknown task.', $this->plugin_slug) . '</h2></div>';
  }
  
  /**
   * Display a list of all h5p content libraries.
   *
   * @since 1.1.0
   */
  private function display_libraries() {
    $plugin = H5P_Plugin::get_instance();
    $core = $plugin->get_h5p_instance('core');
    $interface = $plugin->get_h5p_instance('interface');

    $not_cached = $interface->getNotCached();
    $libraries = $interface->loadLibraries();

    $plugin->add_core_assets();
    $settings = $plugin->get_settings();

    // Find out which version of libraries that should be upgraded
    $minVersions = $core->getMinimumVersionsSupported(plugins_url('h5p/h5p-php-library/library-support.json'));
    $needsUpgrade = '';
    
    // Add settings for each library
    foreach ($libraries as $versions) {
      foreach ($versions as $library) {
        $usage = $interface->getLibraryUsage($library->id, $not_cached ? TRUE : FALSE);
        if ($library->runnable) {
          $upgrades = $core->getUpgrades($library, $versions);
          $upgradeUrl = empty($upgrades) ? FALSE : admin_url('admin.php?page=h5p_libraries&task=upgrade&id=' . $library->id);
        }
        else {
          $upgradeUrl = NULL;
        }
        
        // Check if this should be upgraded.
        if ($minVersions !== NULL && isset($minVersions[$library->name])) {
          $min = $minVersions[$library->name];
          if (!$core->isLibraryVersionSupported($library, $min->versions)) {
            $needsUpgrade .= '<li><a href="' . $min->downloadUrl . '">' . $library->name . '</a> (' . H5PCore::libraryVersion($library) . ')</li>';
          }
        }

        $settings['libraries']['listData'][] = array(
          'title' => $library->title . ' (' . H5PCore::libraryVersion($library) . ')',
          'numContent' => $interface->getNumContent($library->id),
          'numContentDependencies' => $usage['content'] < 1 ? '' : sprintf(_n('1 content', '%d contents', $usage['content'], $this->plugin_slug), $usage['content']),
          'numLibraryDependencies' => $usage['libraries'] === 0 ? '' : sprintf(_n('1 library', '%d libraries', $usage['libraries'], $this->plugin_slug), $usage['libraries']),
          'upgradeUrl' => $upgradeUrl,
          'detailsUrl' => admin_url('admin.php?page=h5p_libraries&task=show&id=' . $library->id),
          'deleteUrl' => admin_url('admin.php?page=h5p_libraries&task=delete&id=' . $library->id)
        );
      }
    }

    // Translations
    $settings['libraries']['listHeaders'] = array(
      __('Title', $this->plugin_slug), 
      __('Contents', $this->plugin_slug),
      array(
        'text' => __('Used in', $this->plugin_slug),
        'class' => 'h5p-used-in',
        'colspan' => 2
      ),
      __('Actions', $this->plugin_slug)
    ); 

    // Make it possible to rebuild all caches.
    if ($not_cached) {
      $settings['libraries']['notCached'] = $this->get_not_cached_settings($not_cached);
    }
    
    if ($needsUpgrade !== '') {
      // Set update message
      $interface->setErrorMessage('
          <p>The following libraries are outdated and should be upgraded:</p>
          <ul id="h5p-outdated">' . $needsUpgrade . '</ul>
          <p>To upgrade all the installed libraries, do the following:</p>
          <ol>
            <li>Download <a href="http://h5p.org/sites/default/files/upgrades.h5p">upgrades.h5p</a>.</li>
            <li>Select the downloaded <em>upgrades.h5p</em> file in the form below.</li>
            <li>Check off "Only upgrade" and click the <em>Upload</em> button.</li>
          </ol> </p>'
      );
    }

    // Assets
    $this->add_admin_assets();
    H5P_Plugin_Admin::add_script('library-list', 'h5p-php-library/js/h5p-library-list.js');

    H5P_Plugin_Admin::print_messages();
    include_once('views/libraries.php');
    $plugin->print_settings($settings);
  }
  
  /**
   * Handles upload of H5P libraries.
   * 
   * @since 1.1.0
   */
  public function process_libraries() {
    $post = (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST');
    
    if ($post && isset($_FILES['h5p_file']) && $_FILES['h5p_file']['error'] === 0) {
      check_admin_referer('h5p_library', 'lets_upgrade_that'); // Verify form
      $plugin_admin = H5P_Plugin_Admin::get_instance();
      $plugin_admin->handle_upload(NULL, filter_input(INPUT_POST, 'h5p_upgrade_only') ? TRUE : FALSE);
      return;
    }
    
    $task = filter_input(INPUT_GET, 'task');
    if ($task === 'delete') {
      $library = $this->get_library();
      if (!$library) {
        return;
      }
      
      $plugin = H5P_Plugin::get_instance();
      $interface = $plugin->get_h5p_instance('interface');
      
      // Check if this library can be deleted
      $usage = $interface->getLibraryUsage($library->id, $interface->getNotCached() ? TRUE : FALSE);
      if ($usage['content'] !== 0 || $usage['libraries'] !== 0) {
        H5P_Plugin_Admin::set_error(__('This Library is used by content or other libraries and can therefore not be deleted.'));
        return; // Nope
      }
      
      if ($post) {
        check_admin_referer('h5p_library', 'lets_delete_this'); // Verify delete form
        $interface->deleteLibrary($this->library);
        wp_safe_redirect(admin_url('admin.php?page=h5p_libraries'));
      }
    }
  }
  
  /**
   * Display details for a given content library.
   *
   * @since 1.1.0
   */
  private function display_library_details() {
    global $wpdb;

    $library = $this->get_library();
    H5P_Plugin_Admin::print_messages();
    if (!$library) {
      return;
    }

    // Add settings and translations
    $plugin = H5P_Plugin::get_instance();
    $interface = $plugin->get_h5p_instance('interface');
    $plugin->add_core_assets();
    $settings = $plugin->get_settings();

    // Build the translations needed
    $settings['library']['translations'] = array(
      'noContent' => __('No content is using this library', $this->plugin_slug),
      'contentHeader' => __('Content using this library', $this->plugin_slug),
      'pageSizeSelectorLabel' => __('Elements per page', $this->plugin_slug),
      'filterPlaceholder' => __('Filter content', $this->plugin_slug),
      'pageXOfY' => __('Page $x of $y', $this->plugin_slug),
    ); 

    $notCached = $interface->getNotCached();
    if ($notCached) {
      $settings['library']['notCached'] = $this->get_not_cached_settings($notCached);
    }
    else {
      // List content which uses this library
      $contents = $wpdb->get_results($wpdb->prepare(
          "SELECT DISTINCT hc.id, hc.title 
            FROM {$wpdb->prefix}h5p_contents_libraries hcl
            JOIN {$wpdb->prefix}h5p_contents hc ON hcl.content_id = hc.id
            WHERE hcl.library_id = %d
            ORDER BY hc.title",
          $library->id
        )
      );
      foreach($contents as $content) {
        $settings['library']['content'][] = array(
          'title' => $content->title, 
          'url' => admin_url('admin.php?page=h5p&task=show&id=' . $content->id),
        );
      }
    }
    
    // Build library info
    $settings['library']['info'] = array(
      __('Version', $this->plugin_slug) => H5PCore::libraryVersion($library),
      __('Fullscreen', $this->plugin_slug) => $library->fullscreen ? __('Yes', $this->plugin_slug) : __('No', $this->plugin_slug),
      __('Content library', $this->plugin_slug) => $library->runnable ? __('Yes', $this->plugin_slug) : __('No', $this->plugin_slug),
      __('Used by', $this->plugin_slug) => (isset($contents) ? sprintf(_n('1 content', '%d contents', count($contents), $this->plugin_slug), count($contents)) : __('N/A', $this->plugin_slug)),
    );
    
    $this->add_admin_assets();
    H5P_Plugin_Admin::add_script('library-list', 'h5p-php-library/js/h5p-library-details.js');

    include_once('views/library-details.php');
    $plugin->print_settings($settings);
  }
  
  /**
   * Display a list of all h5p content libraries.
   *
   * @since 1.1.0
   */
  private function display_content_upgrades($library) {
    global $wpdb;
    
    $plugin = H5P_Plugin::get_instance();
    $core = $plugin->get_h5p_instance('core');
    $interface = $plugin->get_h5p_instance('interface');
        
    $versions = $wpdb->get_results($wpdb->prepare(
        "SELECT hl2.id, hl2.name, hl2.title, hl2.major_version, hl2.minor_version, hl2.patch_version
          FROM {$wpdb->prefix}h5p_libraries hl1
          JOIN {$wpdb->prefix}h5p_libraries hl2
            ON hl2.name = hl1.name
          WHERE hl1.id = %d
          ORDER BY hl2.title ASC, hl2.major_version ASC, hl2.minor_version ASC",
        $library->id
    ));

    foreach ($versions as $version) {
      if ($version->id === $library->id) {
        $upgrades = $core->getUpgrades($version, $versions);
        break;
      }
    }
    
    if (count($versions) < 2) {
      H5P_Plugin_Admin::set_error(__('There are no available upgrades for this library.'));
      return NULL;
    }
  
    // Get num of contents that can be upgraded
    $contents = $interface->getNumContent($library->id);
    if (!$contents) {
      H5P_Plugin_Admin::set_error(__("There's no content instances to upgrade."));
      return NULL;
    }
  
    $contents_plural = sprintf(_n('1 content', '%d contents', $contents, $this->plugin_slug), $contents);

    // Add JavaScript settings
    $return = filter_input(INPUT_GET, 'destination');
    $settings = $plugin->get_settings();
    $settings['library'] = array(
      'message' => sprintf(__('You are about to upgrade %s. Please select upgrade version.', $this->plugin_slug), $contents_plural),
      'inProgress' => __('Upgrading to %ver...', $this->plugin_slug),
      'error' => __('An error occurred while processing parameters:', $this->plugin_slug),
      'errorData' => __('Could not load data for library %lib.', $this->plugin_slug),
      'errorScript' => __('Could not load upgrades script for %lib.', $this->plugin_slug),
      'done' => sprintf(__('You have successfully upgraded %s.', $this->plugin_slug), $contents_plural) . ($return ? ' ' . l(t('Return'), $return) : ''),
      'library' => array(
        'name' => $library->name,
        'version' => $library->major_version . '.' . $library->minor_version,
      ),
      'libraryBaseUrl' => admin_url('admin-ajax.php?action=h5p_content_upgrade_library&library='),
      'versions' => $upgrades,
      'contents' => $contents,
      'buttonLabel' => __('Upgrade', $this->plugin_slug),
      'infoUrl' => admin_url('admin-ajax.php?action=h5p_content_upgrade_progress&id=' . $library->id),
      'total' => $contents,
      'token' => wp_create_nonce('h5p_content_upgrade')
    );
  
    $this->add_admin_assets();
    H5P_Plugin_Admin::add_script('library-list', 'h5p-php-library/js/h5p-content-upgrade.js');
    
    return $settings;
  }
  
  /**
   * Helps rebuild all content caches.
   * 
   * @since 1.1.0
   */
  public function ajax_rebuild_cache() {
    global $wpdb;
    
    if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') !== 'POST') {
      exit; // POST is required
    }
    
    $plugin = H5P_Plugin::get_instance();
    $core = $plugin->get_h5p_instance('core');
    
    // Do as many as we can in five seconds.
    $start = microtime(TRUE);
    
    $contents = $wpdb->get_results(
        "SELECT id
          FROM {$wpdb->prefix}h5p_contents
          WHERE filtered = ''"
    );

    $done = 0;
    foreach($contents as $content) {
      $content = $core->loadContent($content->id);
      $core->filterParameters($content);
      $done++;

      if ((microtime(TRUE) - $start) > 5) {
        break;
      }
    }

    print (count($contents) - $done);
    exit;
  }
  
  /**
   * Add generic admin interface assets.
   *
   * @since 1.1.0
   */
  private function add_admin_assets() {
    H5P_Plugin_Admin::add_script('integration', 'public/scripts/h5p-integration.js');
    foreach (H5PCore::$adminScripts as $script) {
      H5P_Plugin_Admin::add_script('admin-' . $script, 'h5p-php-library/' . $script);
    }
    H5P_Plugin_Admin::add_style('h5p', 'h5p-php-library/styles/h5p.css');
    H5P_Plugin_Admin::add_style('admin', 'h5p-php-library/styles/h5p-admin.css');
  }
  
  /**
   * JavaScript settings needed to rebuild content caches.
   *
   * @since 1.1.0
   */
  private function get_not_cached_settings($num) {
    return array(
      'num' => $num,
      'url' => admin_url('admin-ajax.php?action=h5p_rebuild_cache'),
      'message' => __('Not all content has gotten their cache rebuilt. This is required to be able to delete libraries, and to display how many contents that uses the library.', $this->plugin_slug),
      'progress' => sprintf(_n('1 content need to get its cache rebuilt.', '%d contents needs to get their cache rebuilt.', $num, $this->plugin_slug), $num),
      'button' => __('Rebuild cache', $this->plugin_slug)
    );
  }
  
  /**
   * AJAX processing for content upgrade script.
   */
  public function ajax_upgrade_progress() {
    global $wpdb;
    header('Cache-Control: no-cache');
    
    if (!wp_verify_nonce(filter_input(INPUT_POST, 'token'), 'h5p_content_upgrade')) {
      print __('Error, invalid security token!', $this->plugin_slug);
      exit;
    }
    
    $library_id = filter_input(INPUT_GET, 'id');
    if (!$library_id) {
      print __('Error, missing library!', $this->plugin_slug);
      exit;
    }
  
    // Get the library we're upgrading to
    $to_library = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, major_version, minor_version 
          FROM {$wpdb->prefix}h5p_libraries
          WHERE id = %d",
        filter_input(INPUT_POST, 'libraryId')
    ));
    if (!$to_library) {
      print __('Error, invalid library!', $this->plugin_slug);
      exit;
    }
  
    // Prepare response
    $out = new stdClass();
    $out->params = array();
    $out->token = wp_create_nonce('h5p_content_upgrade');
  
    // Get updated params
    $params = filter_input(INPUT_POST, 'params');
    if ($params !== NULL) {
      // Update params.
      $params = json_decode($params);
      foreach ($params as $id => $param) {
        $wpdb->update(
            $wpdb->prefix . 'h5p_contents', 
            array(
              'updated_at' => current_time('mysql', 1),
              'parameters' => $param,
              'library_id' => $to_library->id,
              'filtered' => ''
            ), 
            array(
              'id' => $id
            ), 
            array(
              '%s',
              '%s',
              '%d',
              '%s'
            ), 
            array(
              '%d'
            )
        );
      }
    }
  
    // Prepare our interface
    $plugin = H5P_Plugin::get_instance();
    $interface = $plugin->get_h5p_instance('interface');
    
    // Get number of contents for this library
    $out->left = $interface->getNumContent($library_id);
  
    if ($out->left) {
      // Find the 10 first contents using library and add to params
      $contents = $wpdb->get_results($wpdb->prepare(
          "SELECT id, parameters
            FROM {$wpdb->prefix}h5p_contents
            WHERE library_id = %d
            LIMIT 10",
          $library_id
      ));
      foreach ($contents as $content) {
        $out->params[$content->id] = $content->parameters;
      }
    }

    header('Content-type: application/json');
    print json_encode($out);
    exit;
  }

  /**
   * AJAX loading of libraries for content upgrade script.
   * 
   * @param string $name
   * @param int $major
   * @param int $minor
   */
  public function ajax_upgrade_library() {
    header('Cache-Control: no-cache');
    
    $library_string = filter_input(INPUT_GET, 'library');
    if (!$library_string) {
      print __('Error, missing library!', $this->plugin_slug);
      exit;
    }

    $library_parts = explode('/', $library_string);
    if (count($library_parts) !== 4) {
      print __('Error, invalid library!', $this->plugin_slug);
      exit;
    }

    $library = (object) array(
      'name' => $library_parts[1],
      'version' => (object) array(
        'major' => $library_parts[2],
        'minor' => $library_parts[3]
      )
    );

    $plugin = H5P_Plugin::get_instance();
    $core = $plugin->get_h5p_instance('core');

    $library->semantics = $core->loadLibrarySemantics($library->name, $library->version->major, $library->version->minor);
    if ($library->semantics === NULL) {
      print __('Error, could not library semantics!', $this->plugin_slug);
      exit;
    }

    // TODO: Library development mode
//    if ($core->development_mode & H5PDevelopment::MODE_LIBRARY) {
//      $dev_lib = $core->h5pD->getLibrary($library->name, $library->version->major, $library->version->minor);
//    }

    if (isset($dev_lib)) {
      $upgrades_script_path = $upgrades_script_url = $dev_lib['path'] . '/upgrades.js';
    }
    else {
      $suffix = '/libraries/' . $library->name . '-' . $library->version->major . '.' . $library->version->minor . '/upgrades.js';
      $upgrades_script_path = $plugin->get_h5p_path() . $suffix;
      $upgrades_script_url = $plugin->get_h5p_url() . $suffix;
    }
    
    if (file_exists($upgrades_script_path)) {
      $library->upgradesScript = $upgrades_script_url;
    }

    header('Content-type: application/json');
    print json_encode($library);
    exit;
  }
}