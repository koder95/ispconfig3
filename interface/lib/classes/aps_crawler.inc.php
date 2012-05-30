<?php
/*
Copyright (c) 2012, ISPConfig UG
Contributors: web wack creations,  http://www.web-wack.at
All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice,
      this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice,
      this list of conditions and the following disclaimer in the documentation
      and/or other materials provided with the distribution.
    * Neither the name of ISPConfig nor the names of its contributors
      may be used to endorse or promote products derived from this software without
      specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/
require_once('aps_base.inc.php');

@set_time_limit(0);
@ignore_user_abort(1);

class ApsCrawler extends ApsBase
{
   /**
    * Constructor
    *
    * @param $app the application instance (db handle + log method)
    * @param $interface_mode act in interface (true) or server mode (false)
    */
    public function __construct($app, $interface_mode = false)
    {
        parent::__construct($app, 'APS crawler: ', $interface_mode);
    }
    
    /**
     * Before the cron is executed, make sure all necessary options are set
     * and all functions (i.e. cURL) are available
     */
    private function checkRequirements()
    {
        try
        {
            // Check if allow_url_fopen is enabled
            if(!@ini_get('allow_url_fopen')) throw new Exception('allow_url_fopen is not enabled');
            // Check if the cURL module is available
            if(!function_exists('curl_version')) throw new Exception('cURL is not available');
            
            // Check if used folders are writable (chmod 777)
            if($this->interface_mode)
            {
                if(!is_writable($this->interface_pkg_dir)) 
                    throw new Exception('the folder '.basename($this->interface_pkg_dir).' is not writable');  
            }   
            else 
            {
                if(!is_writable($this->packages_dir)) 
                    throw new Exception('the folder '.basename($this->packages_dir).' is not writable');
            }
            
            return true;
        }
        catch(Exception $e)
        {
            $this->app->log($this->log_prefix.'Aborting execution because '.$e->getMessage(), LOGLEVEL_ERROR);
            return false;
        }
    }
    
    /**
     * Remove a directory recursively
     * In case of error be silent
     * 
     * @param $dir the directory to remove
     */
    private function removeDirectory($dir)
    {
        if(is_dir($dir))
        {
            $files = scandir($dir);
            foreach($files as $file)
            {
                if($file != '.' && $file != '..')
                    if(filetype($dir.'/'.$file) == 'dir') rrmdir($dir.'/'.$file); 
                    else @unlink($dir.'/'.$file);
            }
            reset($files);
            @rmdir($dir);
        }
    }

    
    /**
     * Fetch HTML data from one or more given URLs
     * If a string is given, a string is returned, if an array of URLs should
     * be fetched, the responses of the parallel queries are returned as array
     *
     * @param $input the string or array to fetch
     * @return $ret a query response string or array
     */
    private function fetchPage($input)
    {
        $ret = array();
        $url = array();
        $conn = array();

        // Make sure we are working with an array, further on
        if(!is_array($input)) $url[] = $input;
        else $url = $input;
        
        // Build the single cURL handles and add them to a multi handle
        $mh = curl_multi_init();
        for($i = 0; $i < count($url); $i++)
        {
            $conn[$i] = curl_init('http://'.$this->fetch_url.$url[$i]);
            curl_setopt($conn[$i], CURLOPT_RETURNTRANSFER, true);
            curl_multi_add_handle($mh, $conn[$i]);
        }
        
        $active = 0;
        do curl_multi_exec($mh, $active);
        while($active > 0);

        // Get the response(s)
        for($i = 0; $i < count($url); $i++)
        {
            $ret[$i] = curl_multi_getcontent($conn[$i]);
            curl_multi_remove_handle($mh, $conn[$i]);
            curl_close($conn[$i]);
        }
        curl_multi_close($mh);
        
        if(count($url) == 1) $ret = $ret[0];
        
        return $ret;
    }
    
    /**
     * Fetch binary data from a given array
     * The data is retrieved in binary mode and 
     * then directly written to an output file
     *
     * @param $input a specially structed array
     * @see $this->startUpdate()
     */
    private function fetchFiles($input)
    {
        $fh = array();
        $url = array();
        $conn = array();

        // Build the single cURL handles and add them to a multi handle
        $mh = curl_multi_init();
        
        // Process each app 
        for($i = 0; $i < count($input); $i++)
        {
            $conn[$i] = curl_init($input[$i]['url']);
            $fh[$i] = fopen($input[$i]['localtarget'], 'wb'); 
            
            curl_setopt($conn[$i], CURLOPT_BINARYTRANSFER, true);
            curl_setopt($conn[$i], CURLOPT_FILE, $fh[$i]);
            curl_setopt($conn[$i], CURLOPT_TIMEOUT, 0);
            curl_setopt($conn[$i], CURLOPT_FAILONERROR, 1);
            curl_setopt($conn[$i], CURLOPT_FOLLOWLOCATION, 1); 
            
            curl_multi_add_handle($mh, $conn[$i]);
        }
        
        $active = 0;
        do curl_multi_exec($mh, $active);
        while($active > 0);

        // Close the handles
        for($i = 0; $i < count($input); $i++)
        {
            fclose($fh[$i]);
            curl_multi_remove_handle($mh, $conn[$i]);
            curl_close($conn[$i]);
        }
        curl_multi_close($mh);
    }
    
    /**
     * A method to build query URLs out of a list of vendors
     *
    */
    private function formatVendorCallback(&$array_item, $key)
    {
        $array_item = str_replace(' ', '%20', $array_item);
        $array_item = str_replace('http://', '', $array_item);
        $array_item = '/'.$this->aps_version.'.atom?vendor='.$array_item.'&pageSize=100';
    }
    
    /**
     * The main method which performs the actual crawling
     */    
    public function startCrawler() 
    {
        try
        {
            // Make sure the requirements are given so that this script can execute
            $req_ret = $this->checkRequirements();
            if(!$req_ret) return false;
            
            // Execute the open task and first fetch all vendors (APS catalog API 1.1, p. 12)
            $this->app->log($this->log_prefix.'Fetching data from '.$this->fetch_url);

            $vendor_page = $this->fetchPage('/all-app/'); //$vendor_page = $this->fetchPage('/'.$this->aps_version.'/');
            preg_match_all("/\<a href=\"(.+)\/\" class=\"vendor\"/i", $vendor_page, $matches);
            $vendors = array_map('urldecode', $matches[1]);
            if(!$vendors) throw new Exception('Unable to fetch vendors. Aborting');

            // Format all vendors for further processing (i.e. typo3.org -> /1.atom?vendor=typo3.org&pageSize=100
            array_walk($vendors, array($this, 'formatVendorCallback'));
            
            // Process all vendors in chunks of 50 entries
            $vendor_chunks = array_chunk($vendors, 50);
            //var_dump($vendor_chunks); 

            // Get all known apps from the database and the highest known version
            // Note: A dirty hack is used for numerical sorting of the VARCHAR field Version: +0 -> cast
            // A longer but typesafe way would be: ORDER BY CAST(REPLACE(Version, '.', '') AS UNSIGNED) DESC
            $existing_apps = $this->db->queryAllRecords("SELECT * FROM (
                SELECT name AS Name, CONCAT(version, '-', CAST(`release` AS CHAR)) AS CurrentVersion 
                FROM aps_packages ORDER BY REPLACE(version, '.', '')+0 DESC, `release` DESC
                ) as Versions GROUP BY name");
            //var_dump($existing_apps); 
            
            // Used for statistics later
            $apps_in_repo = 0; 
            $apps_updated = 0;
            $apps_downloaded = 0;
            
            $apps_to_dl = array();
            
            for($i = 0; $i < count($vendor_chunks); $i++)
            {
                // Fetch all apps for the current chunk of vendors
                $apps = $this->fetchPage($vendor_chunks[$i]);
                
                for($j = 0; $j < count($apps); $j++)
                {
                    // Before parsing, make sure it's worth the work by checking if at least one app exists
                    $apps_count = substr_count($apps[$j], '<opensearch:totalResults>0</opensearch:totalResults>');
                    if($apps_count == 0) // obviously this vendor provides one or more apps
                    {
                        // Rename namespaces and register them 
                        $xml = str_replace("xmlns=", "ns=", $apps[$j]);
                        $sxe = new SimpleXMLElement($xml);
                        $namespaces = $sxe->getDocNamespaces(true);
                        foreach($namespaces as $ns => $url) $sxe->registerXPathNamespace($ns, $url);
                        
                        // Fetching values of interest
                        $app_name = parent::getXPathValue($sxe, 'entry[position()=1]/a:name');
                        $app_version = parent::getXPathValue($sxe, 'entry[position()=1]/a:version');
                        $app_release = parent::getXPathValue($sxe, 'entry[position()=1]/a:release');
                        
                        // Find out a (possibly) existing package version
                        $ex_ver = '';
                        array_walk($existing_apps, 
                            create_function('$v, $k, $ex_ver', 'if($v["Name"] == "'.$app_name.'") $ex_ver = $v["CurrentVersion"];'), &$ex_ver);
                        
                        $new_ver = $app_version.'-'.$app_release;
                        $local_intf_folder = $this->interface_pkg_dir.'/'.$app_name.'-'.$new_ver.'.app.zip/';

                        // Proceed if a newer or at least equal version has been found with server mode or 
                        // interface mode is activated and there's no valid APP-META.xml existing yet
                        if((!$this->interface_mode && version_compare($new_ver, $ex_ver) >= 0)
                         || ($this->interface_mode 
                              && (!file_exists($local_intf_folder.'APP-META.xml') || filesize($local_intf_folder.'APP-META.xml') == 0)
                            )
                        )
                        {
                            // Check if we already have an old version of this app
                            if(!empty($ex_ver) && version_compare($new_ver, $ex_ver) == 1) $apps_updated++; 

                            $app_dl = parent::getXPathValue($sxe, "entry[position()=1]/link[@a:type='aps']/@href");
                            $app_filesize = parent::getXPathValue($sxe, "entry[position()=1]/link[@a:type='aps']/@length");
                            $app_metafile = parent::getXPathValue($sxe, "entry[position()=1]/link[@a:type='meta']/@href");

                            // Skip ASP.net packages because they can't be used at all
                            $asp_handler = parent::getXPathValue($sxe, '//aspnet:handler');
                            $asp_permissions = parent::getXPathValue($sxe, '//aspnet:permissions');
                            $asp_version = parent::getXPathValue($sxe, '//aspnet:version');
                            if(!empty($asp_handler) || !empty($asp_permissions) || !empty($asp_version)) continue;

                            // Interface mode (download only parts)
                            if($this->interface_mode)
                            {
                                // Delete an obviously out-dated version from the system and DB
                                if(!empty($ex_ver) && version_compare($new_ver, $ex_ver) == 1)
                                {
                                    $old_folder = $this->interface_pkg_dir.'/'.$app_name.'-'.$ex_ver.'.app.zip';
                                    if(file_exists($old_folder)) $this->removeDirectory($old_folder);
                                    
									/*
                                    $this->db->query("UPDATE aps_packages SET package_status = '".PACKAGE_OUTDATED."' WHERE name = '".
                                        $this->db->quote($app_name)."' AND CONCAT(version, '-', CAST(`release` AS CHAR)) = '".
                                        $this->db->quote($ex_ver)."';");
									*/
									$tmp = $this->db->queryOneRecord("SELECT id FROM aps_packages WHERE name = '".
                                        $this->db->quote($app_name)."' AND CONCAT(version, '-', CAST(`release` AS CHAR)) = '".
                                        $this->db->quote($ex_ver)."';");
									$this->db->datalogUpdate('aps_packages', "package_status = ".PACKAGE_OUTDATED, 'id', $tmp['id']);
									unset($tmp);
                                }
                                
                                // Create the local folder if not yet existing
                                if(!file_exists($local_intf_folder)) @mkdir($local_intf_folder, 0777, true);
                                
                                // Download the meta file
                                $local_metafile = $local_intf_folder.'APP-META.xml';
                                if(!file_exists($local_metafile) || filesize($local_metafile) == 0) 
                                {
                                    $apps_to_dl[] = array('name' => 'APP-META.xml', 
                                                          'url' => $app_metafile, 
                                                          'filesize' => 0, 
                                                          'localtarget' => $local_metafile);
                                    $apps_downloaded++;
                                }
                                
                                // Download package license
                                $license = parent::getXPathValue($sxe, "entry[position()=1]/link[@a:type='eula']/@href");
                                if($license != '')
                                {
                                    $local_license = $local_intf_folder.'LICENSE';
                                    if(!file_exists($local_license) || filesize($local_license) == 0)
                                    {
                                        $apps_to_dl[] = array('name' => basename($license), 
                                                              'url' => $license, 
                                                              'filesize' => 0, 
                                                              'localtarget' => $local_license);
                                    }
                                }
                                
                                // Download package icon
                                $icon = parent::getXPathValue($sxe, "entry[position()=1]/link[@a:type='icon']/@href");
                                if($icon != '')
                                {
                                    $local_icon = $local_intf_folder.basename($icon);
                                    if(!file_exists($local_icon) || filesize($local_icon) == 0)
                                    {
                                        $apps_to_dl[] = array('name' => basename($icon), 
                                                              'url' => $icon, 
                                                              'filesize' => 0, 
                                                              'localtarget' => $local_icon);
                                    }
                                }
                                
                                // Download available screenshots
                                $screenshots = parent::getXPathValue($sxe, "entry[position()=1]/link[@a:type='screenshot']", true);
                                if(!empty($screenshots))
                                {
                                    foreach($screenshots as $screen)
                                    {
                                        $local_screen = $local_intf_folder.basename($screen['href']);
                                        if(!file_exists($local_screen) || filesize($local_screen) == 0)
                                        {
                                            $apps_to_dl[] = array('name' => basename($screen['href']), 
                                                                  'url' => $screen['href'], 
                                                                  'filesize' => 0, 
                                                                  'localtarget' => $local_screen);
                                        }
                                    }
                                }
                            }
                            else // Server mode (download whole ZIP archive)
                            {
                                // Delete an obviously out-dated version from the system
                                if(!empty($ex_ver) && version_compare($new_ver, $ex_ver) == 1)
                                {
                                    $old_file = $this->packages_dir.'/'.$app_name.'-'.$ex_ver.'.app.zip';
                                    if(file_exists($old_file)) $this->removeDirectory($old_file);
                                }
                                
                                // Attention: $new_ver can also be == $ex_ver (according to version_compare >= 0)
                                $local_zip = $this->packages_dir.'/'.$app_name.'-'.$new_ver.'.app.zip';
                            
                                // Before re-downloading a file, make sure it's not yet existing on HDD (due to DB inconsistency)
                                if((file_exists($local_zip) && (filesize($local_zip) == $app_filesize)) === false)
                                {
                                    $apps_to_dl[] = array('name' => $app_name, 
                                                          'url' => $app_dl, 
                                                          'filesize' => $app_filesize, 
                                                          'localtarget' => $local_zip);
                                    $apps_downloaded++;
                                }
                            }
                        }
                        
                        unset($sxe);
                        $apps_in_repo++;
                    }
                }
                //var_dump($apps);
                
                // For memory reasons, unset the current vendor and his apps
                unset($apps);
            }
            
            // Shuffle the download array (in order to compensate unexpected php aborts)
            shuffle($apps_to_dl);
            
            // After collecting all provisioned apps, download them
            $apps_to_dl_chunks = array_chunk($apps_to_dl, 10);

            for($i = 0; $i < count($apps_to_dl_chunks); $i++)
            {
                $this->fetchFiles($apps_to_dl_chunks[$i]);
                
                // Check the integrity of all downloaded files
                // but exclude cases where no filesize is available (i.e. screenshot or metafile download)
                for($j = 0; $j < count($apps_to_dl_chunks[$i]); $j++)
                {
                    if($apps_to_dl_chunks[$i][$j]['filesize'] != 0 &&
                       $apps_to_dl_chunks[$i][$j]['filesize'] != filesize($apps_to_dl_chunks[$i][$j]['localtarget']))
                    {
                            $this->app->log($this->log_prefix.' The filesize of the package "'.
                                $apps_to_dl_chunks[$i][$j]['name'].'" is wrong. Download failure?', LOGLEVEL_WARN);
                    }
                }
            }
            
            $this->app->log($this->log_prefix.'Processed '.$apps_in_repo.
                ' apps from the repo. Downloaded '.$apps_updated.
                ' updates, '.$apps_downloaded.' new apps');
        }
        catch(Exception $e)
        {
            $this->app->log($this->log_prefix.$e->getMessage(), LOGLEVEL_ERROR);
            return false;
        }
    }
    
    /**
     * Read in all possible packages from the interface packages folder and 
     * check if they are not ASP.net code (as this can't be processed).
     * 
     * Note: There's no need to check if the packages to register are newer
     * than those in the database because this already happended in startCrawler()
     */
    public function parseFolderToDB()
    {
        try
        {
            // This method must be used in server mode
            if(!$this->interface_mode) return false; 
            
            $pkg_list = array();
        
            // Read in every package having a correct filename
            $temp_handle = @dir($this->interface_pkg_dir);
            if(!$temp_handle) throw new Exception('The temp directory is not accessible');
            while($folder = $temp_handle->read()) 
                if(substr($folder, -8) == '.app.zip') $pkg_list[] = $folder;
            $temp_handle->close();
            
            // If no packages are available -> exception (because at this point there should exist packages)
            if(empty($pkg_list)) throw new Exception('No packages to read in');
            
            // Get registered packages and mark non-existant packages with an error code to omit the install
            $existing_packages = array();
            $path_query = $this->db->queryAllRecords('SELECT path AS Path FROM aps_packages;');
            foreach($path_query as $path) $existing_packages[] = $path['Path']; 
            $diff = array_diff($existing_packages, $pkg_list);
            foreach($diff as $todelete)
                /*$this->db->query("UPDATE aps_packages SET package_status = '".PACKAGE_ERROR_NOMETA."' 
                    WHERE path = '".$this->db->quote($todelete)."';");*/
				$tmp = $this->db->queryOneRecord("SELECT id FROM aps_packages WHERE path = '".$this->db->quote($todelete)."';");
				$this->db->datalogUpdate('aps_packages', "package_status = ".PACKAGE_ERROR_NOMETA, 'id', $tmp['id']);
				unset($tmp);
            
            // Register all new packages
            $new_packages = array_diff($pkg_list, $existing_packages);
            foreach($new_packages as $pkg)
            {
                // Load in meta file if existing and register its namespaces
                $metafile = $this->interface_pkg_dir.'/'.$pkg.'/APP-META.xml';
                if(!file_exists($metafile)) 
                {
                    $this->app->log($this->log_prefix.'Cannot read metadata from '.$pkg, LOGLEVEL_ERROR);
                    continue;
                }
        
                $metadata = file_get_contents($metafile);
                $metadata = str_replace("xmlns=", "ns=", $metadata);
                $sxe = new SimpleXMLElement($metadata);
                $namespaces = $sxe->getDocNamespaces(true);
                foreach($namespaces as $ns => $url) $sxe->registerXPathNamespace($ns, $url);
                
                // Insert the new package
                $pkg_name = parent::getXPathValue($sxe, 'name');
                $pkg_category = parent::getXPathValue($sxe, '//category');
                $pkg_version = parent::getXPathValue($sxe, 'version');
                $pkg_release = parent::getXPathValue($sxe, 'release');
                
				/*
                $this->db->query("INSERT INTO `aps_packages` 
                    (`path`, `name`, `category`, `version`, `release`, `package_status`) VALUES 
                    ('".$this->db->quote($pkg)."', '".$this->db->quote($pkg_name)."',
                    '".$this->db->quote($pkg_category)."', '".$this->db->quote($pkg_version)."',
                    ".$this->db->quote($pkg_release).", ".PACKAGE_ENABLED.");");
				*/
				
				$insert_data = "(`path`, `name`, `category`, `version`, `release`, `package_status`) VALUES 
                    ('".$this->db->quote($pkg)."', '".$this->db->quote($pkg_name)."',
                    '".$this->db->quote($pkg_category)."', '".$this->db->quote($pkg_version)."',
                    ".$this->db->quote($pkg_release).", ".PACKAGE_ENABLED.");";
				
				$this->app->db->datalogInsert('aps_packages', $insert_data, 'id');
            }
        }
        catch(Exception $e)
        {
            $this->app->log($this->log_prefix.$e->getMessage(), LOGLEVEL_ERROR);
			$this->app->error($e->getMessage());
            return false;
        }
    }
}
?>