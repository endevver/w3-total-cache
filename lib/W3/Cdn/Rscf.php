<?php

/**
 * Rackspace Cloud Files CDN engine
 */
require_once W3TC_LIB_W3_DIR . '/Cdn/Base.php';
require_once W3TC_LIB_CF_DIR . '/cloudfiles.php';

/**
 * Class W3_Cdn_Rscf
 */
class W3_Cdn_Rscf extends W3_Cdn_Base
{
    /**
     * Auth object
     * 
     * @var CF_Authentication
     */
    var $_auth = null;
    
    /**
     * Connection object
     * 
     * @var CF_Connection
     */
    var $_connection = null;
    
    /**
     * Container object
     * 
     * @var CF_Container
     */
    var $_container = null;
    
    /**
     * Init connection object
     * 
     * @param string $error
     * @return boolean
     */
    function _init(&$error)
    {
        if (empty($this->_config['user'])) {
            $error = 'Empty username';
            
            return false;
        }
        
        if (empty($this->_config['key'])) {
            $error = 'Empty API key';
            
            return false;
        }
        
        try {
            $this->_auth = new CF_Authentication($this->_config['user'], $this->_config['key']);
            $this->_auth->ssl_use_cabundle();
            $this->_auth->authenticate();
            
            $this->_connection = new CF_Connection($this->_auth);
            $this->_connection->ssl_use_cabundle();
        } catch (Exception $exception) {
            $error = $exception->getMessage();
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Init container object
     * 
     * @param string $error
     * @return boolean
     */
    function _init_container(&$error)
    {
        if (empty($this->_config['container'])) {
            $error = 'Empty container';
            
            return false;
        }
        
        try {
            $this->_container = $this->_connection->get_container($this->_config['container']);
        } catch (Exception $exception) {
            $error = $exception->getMessage();
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Uploads files to CDN
     * 
     * @param array $files
     * @param array $results
     * @param boolean $force_rewrite
     * @return integer
     */
    function upload($files, &$results, $force_rewrite = false)
    {
        $count = 0;
        $error = null;
        
        if (!$this->_init($error) || !$this->_init_container($error)) {
            $results = $this->get_results($files, W3TC_CDN_RESULT_HALT, $error);
            return false;
        }
        
        foreach ($files as $local_path => $remote_path) {
            if (!file_exists($local_path)) {
                $results[] = $this->get_result($local_path, $remote_path, W3TC_CDN_RESULT_ERROR, 'Source file not found');
                continue;
            }
            
            if (!$force_rewrite) {
                try {
                    list($status, $reason, $etag, $last_modified, $content_type, $content_length, $metadata) = $this->_container->cfs_http->head_object($this);
                } catch (Exception $exception) {
                    $results[] = $this->get_result($local_path, $remote_path, W3TC_CDN_RESULT_ERROR, 'Unable to get object info');
                    continue;
                }
                
                if ($status >= 200 && $status < 300) {
                    $hash = @md5_file($local_path);
                    
                    if ($hash === $etag) {
                        $results[] = $this->get_result($local_path, $remote_path, W3TC_CDN_RESULT_ERROR, 'Object already exists');
                        continue;
                    }
                }
            }
            
            try {
                $object = $this->_container->create_object($remote_path);
                $object->load_from_filename($local_path);
                
                $result = true;
                $count++;
            } catch (Exception $exception) {
                $result = false;
            }
            
            $results[] = $this->get_result($local_path, $remote_path, ($result ? W3TC_CDN_RESULT_OK : W3TC_CDN_RESULT_ERROR), ($result ? 'OK' : 'Unable to put object'));
        }
        
        return $count;
    }
    
    /**
     * Deletes files from CDN
     * 
     * @param array $files
     * @param array $results
     * @return integer
     */
    function delete($files, &$results)
    {
        $error = null;
        $count = 0;
        
        if (!$this->_init($error) || !$this->_init_container($error)) {
            $results = $this->get_results($files, W3TC_CDN_RESULT_HALT, $error);
            return false;
        }
        
        foreach ($files as $local_path => $remote_path) {
            try {
                $result = $this->_container->delete_object($remote_path);
                $results[] = $this->get_result($local_path, $remote_path, W3TC_CDN_RESULT_OK, 'OK');
                $count++;
            } catch (Exception $exception) {
                $results[] = $this->get_result($local_path, $remote_path, W3TC_CDN_RESULT_ERROR, 'Unable to delete object');
            }
        }
        
        return $count;
    }
    
    /**
     * Test CDN connection
     * 
     * @param string $error
     * @return boolean
     */
    function test(&$error)
    {
        if (!parent::test(&$error)) {
            return false;
        }
        
        if (!$this->_init($error) || !$this->_init_container($error)) {
            return false;
        }
        
        try {
            $string = 'test_rscf_' . md5(time());
            
            $object = $this->_container->create_object($string);
            $object->content_type = 'text/plain';
            $object->write($string, strlen($string));
            
            $object = $this->_container->get_object($string);
            $data = $object->read();
            
            if ($data != $string) {
                throw new Exception('Objects are not equal.');
            }
            
            $this->_container->delete_object($string);
        } catch (Exception $exception) {
            $error = $exception->getMessage();
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Returns CDN domain
     * 
     * @return string
     */
    function get_domains()
    {
        if (!empty($this->_config['cname'])) {
            return (array) $this->_config['cname'];
        } elseif (!empty($this->_config['id'])) {
            $domain = sprintf('%s.cdn.cloudfiles.rackspacecloud.com', $this->_config['id']);
            
            return array(
                $domain
            );
        }
        
        return array();
    }
    
    /**
     * Returns VIA string
     * 
     * @return string
     */
    function get_via()
    {
        return sprintf('Rackspace Cloud Files: %s', parent::get_via());
    }
    
    /**
     * Creates container
     * 
     * @param string $error
     * @return boolean
     */
    function create_container(&$error)
    {
        if (!$this->_init($error)) {
            return false;
        }
        
        try {
            $containers = $this->_connection->list_containers();
        } catch (Exception $exception) {
            $error = sprintf('Unable to list containers (%s).', $exception->getMessage());
            
            return false;
        }
        
        if (in_array($this->_config['container'], (array) $containers)) {
            $error = sprintf('Container already exists: %s.', $this->_config['container']);
            
            return false;
        }
        
        try {
            $container = $this->_connection->create_container($this->_config['container']);
            $container->make_public();
        } catch (Exception $exception) {
            $error = sprintf('Unable to create container: %s (%s).', $this->_config['container'], $exception->getMessage());
            
            return false;
        }
        
        return true;
    }
}
