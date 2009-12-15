<?php

/**
 * W3 Total Cache CDN Plugin
 */
require_once W3TC_LIB_W3_DIR . '/Plugin.php';

/**
 * Class W3_Plugin_Cdn
 */
class W3_Plugin_Cdn extends W3_Plugin
{
    /**
     * Array of replaced URLs
     *
     * @var array
     */
    var $replaced_urls = array();
    
    /**
     * CDN reject reason
     * 
     * @var string
     */
    var $cdn_reject_reason = '';
    
    /**
     * Run plugin
     */
    function run()
    {
        register_activation_hook(W3TC_FILE, array(
            &$this, 
            'activate'
        ));
        
        register_deactivation_hook(W3TC_FILE, array(
            &$this, 
            'deactivate'
        ));
        
        add_filter('cron_schedules', array(
            &$this, 
            'cron_schedules'
        ));
        
        if ($this->_config->get_boolean('cdn.enabled')) {
            if ($this->_config->get_string('cdn.engine') != 'mirror') {
                add_action('delete_attachment', array(
                    &$this, 
                    'delete_attachment'
                ));
                
                add_filter('wp_generate_attachment_metadata', array(
                    &$this, 
                    'generate_attachment_metadata'
                ));
                
                add_action('w3_cdn_cron_queue_process', array(
                    &$this, 
                    'cron_queue_process'
                ));
            }
            
            if ($this->can_cdn()) {
                ob_start(array(
                    &$this, 
                    'ob_callback'
                ));
            }
        }
    }
    
    /**
     * Returns plugin instance
     *
     * @return W3_Plugin_Cdn
     */
    function &instance()
    {
        static $instances = array();
        
        if (! isset($instances[0])) {
            $class = __CLASS__;
            $instances[0] = & new $class();
        }
        
        return $instances[0];
    }
    
    /**
     * Activation action
     */
    function activate()
    {
        global $wpdb;
        
        $upload_info = w3_upload_info();
        
        if (! $upload_info) {
            $upload_path = get_option('upload_path');
            $upload_path = trim($upload_path);
            
            if (empty($upload_path)) {
                echo 'Your store uploads folder is not available. Default WordPress directories will be created: <strong>wp-content/uploads/</strong>.<br />';
                $upload_path = WP_CONTENT_DIR . '/uploads';
            }
            
            w3_writable_error($upload_path);
        }
        
        $sql = sprintf('DROP TABLE IF EXISTS `%s%s`', $wpdb->prefix, W3TC_CDN_TABLE_QUEUE);
        
        $wpdb->query($sql);
        
        $sql = sprintf("CREATE TABLE IF NOT EXISTS `%s%s` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `local_path` varchar(150) NOT NULL DEFAULT '',
            `remote_path` varchar(150) NOT NULL DEFAULT '',
            `command` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '1 - Upload, 2 - Delete',
            `last_error` varchar(150) NOT NULL DEFAULT '',
            `date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY (`id`),
            UNIQUE KEY `path` (`local_path`, `remote_path`),
            KEY `date` (`date`)
        ) /*!40100 CHARACTER SET latin1 */", $wpdb->prefix, W3TC_CDN_TABLE_QUEUE);
        
        $wpdb->query($sql);
        
        if (! $wpdb->result) {
            die(sprintf('Unable to create table <strong>%s%s</strong>: %s', $wpdb->prefix, W3TC_CDN_TABLE_QUEUE, $wpdb->last_error));
        }
        
        $this->schedule();
    }
    
    /**
     * Deactivation action
     */
    function deactivate()
    {
        global $wpdb;
        
        $this->unschedule();
        
        $sql = sprintf('DROP TABLE IF EXISTS `%s%s`', $wpdb->prefix, W3TC_CDN_TABLE_QUEUE);
        $wpdb->query($sql);
    }
    
    /**
     * Schedules cron events
     */
    function schedule()
    {
        if ($this->_config->get_boolean('cdn.enabled') && $this->_config->get_string('cdn.engine') != 'mirror') {
            if (! wp_next_scheduled('w3_cdn_cron_queue_process')) {
                wp_schedule_event(time(), 'every_15_min', 'w3_cdn_cron_queue_process');
            }
        } else {
            $this->unschedule();
        }
    }
    
    /**
     * Unschedules cron events
     */
    function unschedule()
    {
        if (wp_next_scheduled('w3_cdn_cron_queue_process')) {
            wp_clear_scheduled_hook('w3_cdn_cron_queue_process');
        }
    }
    
    /**
     * Cron queue process event
     */
    function cron_queue_process()
    {
        $limit_queue = $this->_config->get_integer('cdn.limit.queue');
        $this->queue_process($limit_queue);
    }
    
    /**
     * On attachment delete action
     *
     * @param integer $attachment_id
     */
    function delete_attachment($attachment_id)
    {
        $files = $this->get_attachment_files($attachment_id);
        $files = apply_filters('w3tc_cdn_delete_attachment_files', $files);
        
        $results = array();
        
        $this->delete($files, true, $results);
    }
    
    /**
     * Generate attachment metadata filter
     *
     * @param array $metadata
     * @return array
     */
    function generate_attachment_metadata($metadata)
    {
        $files = $this->get_metadata_files($metadata);
        $files = apply_filters('w3tc_cdn_upload_attachment_files', $files);
        
        $results = array();
        
        $this->upload($files, true, $results);
        
        return $metadata;
    }
    
    /**
     * Cron schedules filter
     *
     * @paran array $schedules
     * @return array
     */
    function cron_schedules($schedules)
    {
        return array_merge($schedules, array(
            'every_15_min' => array(
                'interval' => 900, 
                'display' => 'Every 15 minutes'
            )
        ));
    }
    
    /**
     * Returns attachment files by attachment ID
     *
     * @param integer $attachment_id
     * @return array
     */
    function get_attachment_files($attachment_id)
    {
        $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
        
        if (isset($metadata['file'])) {
            $files = $this->get_metadata_files($metadata);
        } else {
            $file = get_post_meta($attachment_id, '_wp_attached_file', true);
            $files = array(
                $this->normalize_attachment_file($file)
            );
        }
        
        return $files;
    }
    
    /**
     * OB Callback
     *
     * @param string $buffer
     * @return string
     */
    function ob_callback($buffer)
    {
        if ($buffer != '' && w3_is_xml($buffer)) {
            $site_url_regexp = w3_get_site_url_regexp();
            $upload_info = w3_upload_info();
            $regexps = array();
            
            if ($upload_info) {
                $regexps[] = '~(["\'])((' . $site_url_regexp . ')?/?(' . w3_preg_quote($upload_info['upload_url']) . '[^"\'>]+))~';
            }
            
            if ($this->_config->get_boolean('cdn.includes.enable')) {
                $mask = $this->_config->get_string('cdn.includes.files');
                if (! empty($mask)) {
                    $regexps[] = '~(["\'])((' . $site_url_regexp . ')?/?(' . w3_preg_quote(WPINC) . '/(' . $this->get_regexp_by_mask($mask) . ')))~';
                }
            }
            
            if ($this->_config->get_boolean('cdn.theme.enable')) {
                $theme_dir = preg_replace('~' . $site_url_regexp . '~i', '', get_stylesheet_directory_uri());
                $mask = $this->_config->get_string('cdn.theme.files');
                if (! empty($mask)) {
                    $regexps[] = '~(["\'])((' . $site_url_regexp . ')?/?(' . w3_preg_quote($theme_dir) . '/(' . $this->get_regexp_by_mask($mask) . ')))~';
                }
            }
            
            if ($this->_config->get_boolean('cdn.minify.enable')) {
                $regexps[] = '~(["\'])((' . $site_url_regexp . ')?/?(' . w3_preg_quote(W3TC_CONTENT_MINIFY_DIR_NAME) . '/[a-z0-9-_]+\.include(-footer)?(-nb)?\.(css|js)))~';
            }
            
            if ($this->_config->get_boolean('cdn.custom.enable')) {
                $masks = $this->_config->get_array('cdn.custom.files');
                if (! empty($masks)) {
                    $mask_regexps = array();
                    foreach ($masks as $mask) {
                        $mask = ltrim(preg_replace('~' . $site_url_regexp . '~i', '', $mask), '/\\');
                        $mask_regexps[] = $this->get_regexp_by_mask($mask);
                    }
                    $regexps[] = '~(["\'])((' . $site_url_regexp . ')?/?(' . implode('|', $mask_regexps) . '))~';
                }
            }
            
            foreach ($regexps as $regexp) {
                $buffer = preg_replace_callback($regexp, array(
                    &$this, 
                    'link_replace_callback'
                ), $buffer);
            }
            
            if ($this->_config->get_boolean('cdn.debug')) {
                $buffer .= "\r\n\r\n" . $this->get_debug_info();
            }
        }
        
        return $buffer;
    }
    
    /**
     * Returns attachment files by metadata
     *
     * @param array $metadata
     * @return array
     */
    function get_metadata_files($metadata)
    {
        $files = array();
        
        $upload_info = w3_upload_info();
        
        if ($upload_info) {
            if (isset($metadata['file'])) {
                $file = $this->normalize_attachment_file($metadata['file']);
                $local_file = $upload_info['upload_dir'] . '/' . $file;
                $remote_file = $upload_info['upload_url'] . '/' . $file;
                $files[$local_file] = $remote_file;
                if (isset($metadata['sizes'])) {
                    $file_dir = dirname($file);
                    foreach ((array) $metadata['sizes'] as $size) {
                        if (isset($size['file'])) {
                            $local_file = $upload_info['upload_dir'] . '/' . $file_dir . '/' . $size['file'];
                            $remote_file = $upload_info['upload_url'] . '/' . $file_dir . '/' . $size['file'];
                            $files[$local_file] = $remote_file;
                        }
                    }
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Adds file to queue
     *
     * @param string $local_path
     * @param string $remote_path
     * @param integer $command
     * @param string $last_error
     * @return ingteer
     */
    function queue_add($local_path, $remote_path, $command, $last_error)
    {
        global $wpdb;
        
        $table = $wpdb->prefix . W3TC_CDN_TABLE_QUEUE;
        $sql = sprintf('SELECT id FROM %s WHERE local_path = "%s" AND remote_path = "%s" AND command != %d', $table, $wpdb->escape($local_path), $wpdb->escape($remote_path), $command);
        
        if (($row = $wpdb->get_row($sql))) {
            $sql = sprintf('DELETE FROM %s WHERE id = %d', $table, $row->id);
        } else {
            $sql = sprintf('REPLACE INTO %s (local_path, remote_path, command, last_error, date) VALUES ("%s", "%s", %d, "%s", NOW())', $table, $wpdb->escape($local_path), $wpdb->escape($remote_path), $command, $wpdb->escape($last_error));
        }
        
        return $wpdb->query($sql);
    }
    
    /**
     * Updates file date in the queue
     *
     * @param integer $queue_id
     * @param string $last_error
     * @return integer
     */
    function queue_update($queue_id, $last_error)
    {
        global $wpdb;
        
        $sql = sprintf('UPDATE %s SET last_error = "%s", date = NOW() WHERE id = %d', $wpdb->prefix . W3TC_CDN_TABLE_QUEUE, $wpdb->escape($last_error), $queue_id);
        
        return $wpdb->query($sql);
    }
    
    /**
     * Removes from queue
     *
     * @param integer $queue_id
     * @return integer
     */
    function queue_delete($queue_id)
    {
        global $wpdb;
        
        $sql = sprintf('DELETE FROM %s WHERE id = %d', $wpdb->prefix . W3TC_CDN_TABLE_QUEUE, $queue_id);
        
        return $wpdb->query($sql);
    }
    
    /**
     * Empties queue
     *
     * @param integer $command
     * @return integer
     */
    function queue_empty($command)
    {
        global $wpdb;
        
        $sql = sprintf('DELETE FROM %s WHERE command = %d', $wpdb->prefix . W3TC_CDN_TABLE_QUEUE, $command);
        
        return $wpdb->query($sql);
    }
    
    /**
     * Returns queue
     *
     * @param integer $limit
     * @return array
     */
    function queue_get($limit = null)
    {
        global $wpdb;
        
        $sql = sprintf('SELECT * FROM %s%s ORDER BY date', $wpdb->prefix, W3TC_CDN_TABLE_QUEUE);
        
        if ($limit) {
            $sql .= sprintf(' LIMIT %d', $limit);
        }
        
        $results = $wpdb->get_results($sql);
        $queue = array();
        
        if ($results) {
            foreach ((array) $results as $result) {
                $queue[$result->command][] = $result;
            }
        }
        
        return $queue;
    }
    
    /**
     * Process queue
     *
     * @param integer $limit
     */
    function queue_process($limit)
    {
        $commands = $this->queue_get($limit);
        
        if (count($commands)) {
            $cdn = & $this->get_cdn();
            foreach ($commands as $command => $queue) {
                $files = array();
                $results = array();
                $map = array();
                
                foreach ($queue as $result) {
                    $files[$result->local_path] = $result->remote_path;
                    $map[$result->local_path] = $result->id;
                }
                
                switch ($command) {
                    case W3TC_CDN_COMMAND_UPLOAD:
                        $cdn->upload($files, $results);
                        break;
                    
                    case W3TC_CDN_COMMAND_DELETE:
                        $cdn->delete($files, $results);
                        break;
                }
                
                foreach ($results as $result) {
                    if ($result['result'] == W3_CDN_RESULT_OK) {
                        $this->queue_delete($map[$result['local_path']]);
                    } else {
                        $this->queue_update($map[$result['local_path']], $result['error']);
                    }
                }
            }
        }
    }
    
    /**
     * Uploads files to CDN
     *
     * @param array $files
     * @param boolean $queue_failed
     * @param array $results
     * @return boolean
     */
    function upload($files, $queue_failed, &$results)
    {
        $upload = array();
        
        foreach ($files as $local_file => $remote_file) {
            $local_path = $this->format_local_path($local_file);
            $remote_path = $this->format_remote_path($remote_file);
            $upload[$local_path] = $remote_path;
        }
        
        $cdn = & $this->get_cdn();
        if (! $cdn->upload($upload, $results)) {
            if ($queue_failed) {
                foreach ($results as $result) {
                    if ($result['result'] != W3_CDN_RESULT_OK) {
                        $this->queue_add($result['local_path'], $result['remote_path'], W3TC_CDN_COMMAND_UPLOAD, $result['error']);
                    }
                }
            }
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Deletes files frrom CDN
     *
     * @param array $files
     * @param boolean $queue_failed
     * @param array $results
     * @return boolean
     */
    function delete($files, $queue_failed, &$results)
    {
        $delete = array();
        
        foreach ($files as $local_file => $remote_file) {
            $local_path = $this->format_local_path($local_file);
            $remote_path = $this->format_remote_path($remote_file);
            $delete[$local_path] = $remote_path;
        }
        
        $cdn = & $this->get_cdn();
        if (! $cdn->delete($delete, $results)) {
            if ($queue_failed) {
                foreach ($results as $result) {
                    if ($result['result'] != W3_CDN_RESULT_OK) {
                        $this->queue_add($result['local_path'], $result['remote_path'], W3TC_CDN_COMMAND_DELETE, $result['error']);
                    }
                }
            }
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Export library to CDN
     *
     * @param integer $limit
     * @param integer $offset
     * @param integer $count
     * @param integer $total
     * @param array $results
     * @return boolean
     */
    function export_library($limit, $offset, &$count, &$total, &$results)
    {
        global $wpdb;
        
        $count = 0;
        $total = 0;
        
        $upload_info = w3_upload_info();
        
        if ($upload_info) {
            $sql = sprintf('SELECT
        		pm.meta_value AS file,
                pm2.meta_value AS metadata
            FROM
                %sposts AS p
            LEFT JOIN
                %spostmeta AS pm ON p.ID = pm.post_ID AND pm.meta_key = "_wp_attached_file" 
            LEFT JOIN
            	%spostmeta AS pm2 ON p.ID = pm2.post_ID AND pm2.meta_key = "_wp_attachment_metadata"    
            WHERE
                p.post_type = "attachment"
            GROUP BY
            	p.ID', $wpdb->prefix, $wpdb->prefix, $wpdb->prefix);
            
            if ($limit) {
                $sql .= sprintf(' LIMIT %d', $limit);
                
                if ($offset) {
                    $sql .= sprintf(' OFFSET %d', $offset);
                }
            }
            
            $posts = $wpdb->get_results($sql);
            
            if ($posts) {
                $count = count($posts);
                $total = $this->get_attachments_count();
                $files = array();
                
                foreach ($posts as $post) {
                    if (! empty($post->metadata)) {
                        $metadata = @unserialize($post->metadata);
                    } else {
                        $metadata = array();
                    }
                    if (isset($metadata['file'])) {
                        $files = array_merge($files, $this->get_metadata_files($metadata));
                    } elseif (! empty($post->file)) {
                        $file = $this->normalize_attachment_file($post->file);
                        $local_file = $upload_info['upload_dir'] . '/' . $file;
                        $remote_file = $upload_info['upload_url'] . '/' . $file;
                        $files[$local_file] = $remote_file;
                    }
                }
                
                return $this->upload($files, false, $results);
            }
        }
        
        return false;
    }
    
    /**
     * Imports library
     *
     * @param integer $limit
     * @param integer $offset
     * @param integer $count
     * @param integer $total
     * @param array $results
     * @return boolean
     */
    function import_library($limit, $offset, &$count, &$total, &$results)
    {
        global $wpdb;
        
        $count = 0;
        $total = 0;
        $results = array();
        
        $site_url = w3_get_site_url();
        $site_url_regexp = w3_get_site_url_regexp();
        $upload_info = w3_upload_info();
        
        if ($upload_info) {
            $sql = sprintf('SELECT
        		ID,
        		post_content,
        		post_date
            FROM
                %sposts
            WHERE
                post_status = "publish"
                AND post_type = "post"
                AND (post_content LIKE "%%src=%%"
                	OR post_content LIKE "%%href=%%")
       		', $wpdb->prefix);
            
            if ($limit) {
                $sql .= sprintf(' LIMIT %d', $limit);
                
                if ($offset) {
                    $sql .= sprintf(' OFFSET %d', $offset);
                }
            }
            
            $posts = $wpdb->get_results($sql);
            
            if ($posts) {
                $count = count($posts);
                $total = $this->get_import_posts_count();
                $regexp = $this->get_regexp_by_mask($this->_config->get_string('cdn.import.files'));
                $import_external = $this->_config->get_boolean('cdn.import.external');
                
                foreach ($posts as $post) {
                    $matches = null;
                    $post_content = $post->post_content;
                    
                    if (preg_match_all('~(href|src)=[\'"]?([^\'"<>\s]+)[\'"]?~', $post_content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $src = ltrim(preg_replace('~' . $site_url_regexp . '~i', '', $match[2]), '/\\');
                            
                            if (preg_match('~(' . $regexp . ')$~', $src)) {
                                $src_dir = date('Y/m', strtotime($post->post_date));
                                $src_base = basename($src);
                                $dst = sprintf('%s/%s/%s', $upload_info['upload_dir'], $src_dir, $src_base);
                                $dst_dir = dirname($dst);
                                $dst_path = ABSPATH . $dst;
                                $dst_url = sprintf('%s%s/%s/%s', $site_url, $upload_info['upload_url'], $src_dir, $src_base);
                                $result = false;
                                $error = '';
                                $download_result = null;
                                
                                w3_mkdir($dst_dir, 0755, ABSPATH);
                                
                                // file already exists
                                if (! file_exists($dst_path)) {
                                    // source is external URL
                                    if (w3_is_url($src)) {
                                        if ($import_external) {
                                            $download_result = $this->download($src, $dst_path);
                                        } else {
                                            $error = 'External file import is disabled';
                                        }
                                        // source is local file not in wp-content/uploads dir
                                    } elseif (strstr($src, $upload_info['upload_dir']) === false) {
                                        $src_path = ABSPATH . $src;
                                        $download_result = @copy($src_path, $dst_path);
                                        // file is already in wp-content/uploads dir
                                    } else {
                                        $error = 'Source file already exists';
                                    }
                                    
                                    if ($download_result !== null) {
                                        if ($download_result) {
                                            $title = $src_base;
                                            $guid = $upload_info['upload_url'] . '/' . $title;
                                            $mime_type = w3_get_mime_type($src_base);
                                            
                                            $GLOBALS['wp_rewrite'] = & new WP_Rewrite();
                                            
                                            $id = wp_insert_attachment(array(
                                                'post_mime_type' => $mime_type, 
                                                'guid' => $guid, 
                                                'post_title' => $title, 
                                                'post_content' => ''
                                            ), $dst_path);
                                            
                                            if (! is_wp_error($id)) {
                                                require_once ABSPATH . 'wp-admin/includes/image.php';
                                                wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $dst_path));
                                                
                                                $post_content = str_replace($src, $dst_url, $post_content);
                                                $result = true;
                                                $error = 'OK';
                                            } else {
                                                $error = 'Unable to insert attachment';
                                            }
                                        } else {
                                            $error = 'Unable to download file';
                                        }
                                    }
                                } else {
                                    $error = 'Destination file already exists';
                                }
                                
                                $results[] = array(
                                    'src' => $src, 
                                    'dst' => $dst, 
                                    'result' => $result, 
                                    'error' => $error
                                );
                            }
                        }
                    }
                    
                    if ($post_content != $post->post_content) {
                        wp_update_post(array(
                            'ID' => $post->ID, 
                            'post_content' => $post_content
                        ));
                    }
                }
            }
        }
    }
    
    /**
     * Rename domain
     *
     * @param array $names
     * @param integer $limit
     * @param integer $offset
     * @param integer $count
     * @param integer $total
     * @param integer $results
     * @return void
     */
    function rename_domain($names, $limit, $offset, &$count, &$total, &$results)
    {
        global $wpdb;
        
        $count = 0;
        $total = 0;
        $results = array();
        
        $site_url = w3_get_site_url();
        $upload_info = w3_upload_info();
        
        foreach ($names as $index => $name) {
            $names[$index] = str_ireplace('www.', '', $name);
        }
        
        if ($upload_info) {
            $sql = sprintf('SELECT
        		ID,
        		post_content,
        		post_date
            FROM
                %sposts
            WHERE
                post_status = "publish"
                AND post_type = "post"
                AND (post_content LIKE "%%src=%%"
                	OR post_content LIKE "%%href=%%")
       		', $wpdb->prefix);
            
            if ($limit) {
                $sql .= sprintf(' LIMIT %d', $limit);
                
                if ($offset) {
                    $sql .= sprintf(' OFFSET %d', $offset);
                }
            }
            
            $posts = $wpdb->get_results($sql);
            
            if ($posts) {
                $count = count($posts);
                $total = $this->get_rename_posts_count();
                $names_quoted = array_map('w3_preg_quote', $names);
                
                foreach ($posts as $post) {
                    $matches = null;
                    $post_content = $post->post_content;
                    $regexp = '~(href|src)=[\'"]?(https?://(www\.)?(' . implode('|', $names_quoted) . ')/' . w3_preg_quote($upload_info['upload_url']) . '([^\'"<>\s]+))[\'"]~';
                    
                    if (preg_match_all($regexp, $post_content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $old_url = $match[2];
                            $new_url = sprintf('%s%s%s', $site_url, $upload_info['upload_url'], $match[5]);
                            $post_content = str_replace($old_url, $new_url, $post_content);
                            
                            $results[] = array(
                                'old' => $old_url, 
                                'new' => $new_url, 
                                'result' => true, 
                                'error' => 'OK'
                            );
                        }
                    }
                    
                    if ($post_content != $post->post_content) {
                        wp_update_post(array(
                            'ID' => $post->ID, 
                            'post_content' => $post_content
                        ));
                    }
                }
            }
        }
    }
    
    /**
     * Returns attachments count
     *
     * @return integer
     */
    function get_attachments_count()
    {
        global $wpdb;
        
        $sql = sprintf('SELECT
        		COUNT(DISTINCT p.ID)
            FROM
                %sposts AS p
            JOIN
                %spostmeta AS pm ON p.ID = pm.post_ID AND (pm.meta_key = "_wp_attached_file" OR pm.meta_key = "_wp_attachment_metadata")
            WHERE
                p.post_type = "attachment"', $wpdb->prefix, $wpdb->prefix);
        
        return $wpdb->get_var($sql);
    }
    
    /**
     * Returns import posts count
     *
     * @return integer
     */
    function get_import_posts_count()
    {
        global $wpdb;
        
        $sql = sprintf('SELECT
        		COUNT(*)
            FROM
                %sposts
            WHERE
                post_status = "publish"
                AND post_type = "post"
                AND (post_content LIKE "%%src=%%"
                	OR post_content LIKE "%%href=%%")
                ', $wpdb->prefix);
        
        return $wpdb->get_var($sql);
    }
    
    /**
     * Returns rename posts count
     *
     * @return integer
     */
    function get_rename_posts_count()
    {
        return $this->get_import_posts_count();
    }
    
    /**
     * Exports includes to CDN
     */
    function get_files_includes()
    {
        $files = $this->search_files(ABSPATH . WPINC, WPINC, $this->_config->get_string('cdn.includes.files'));
        
        return $files;
    }
    
    /**
     * Exports theme to CDN
     */
    function get_files_theme()
    {
        $theme_dir = ltrim(str_replace(ABSPATH, '', get_stylesheet_directory()), '/\\');
        $files = $this->search_files(get_stylesheet_directory(), $theme_dir, $this->_config->get_string('cdn.theme.files'));
        
        return $files;
    }
    
    /**
     * Exports min files to CDN
     */
    function get_files_minify()
    {
        $files = array();
        
        if (W3TC_PHP5) {
            require_once W3TC_LIB_W3_DIR . '/Plugin/Minify.php';
            $minify = & W3_Plugin_Minify::instance();
            $urls = $minify->get_urls();
            
            foreach ($urls as $url) {
                $file = basename($url);
                if ($this->download($url, W3TC_CONTENT_MINIFY_DIR . '/' . $file)) {
                    $files[] = W3TC_CONTENT_MINIFY_DIR_NAME . '/' . $file;
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Exports custom files to CDN
     */
    function get_files_custom()
    {
        $site_url_regexp = w3_get_site_url_regexp();
        $custom_files = $this->_config->get_array('cdn.custom.files');
        $files = array();
        
        foreach ($custom_files as $custom_file) {
            if (! empty($custom_file)) {
                $custom_file = ltrim(preg_replace('~' . $site_url_regexp . '~i', '', $custom_file), '/\\');
                $dir = trim(dirname($custom_file), '/\\');
                if ($dir == '.') {
                    $dir = '';
                }
                $mask = basename($custom_file);
                $files = array_merge($files, $this->search_files(ABSPATH . $dir, $dir, $mask));
            }
        }
        
        return $files;
    }
    
    /**
     * Downloads URL
     *
     * @param string $url
     * @param string $file
     * @return boolean
     */
    function download($url, $file)
    {
        if (($data = w3_url_get($url)) && ($fp = @fopen($file, 'w'))) {
            @fputs($fp, $data);
            @fclose($fp);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Formats local file path
     *
     * @param string $file
     * @return string
     */
    function format_local_path($file)
    {
        return ABSPATH . $file;
    }
    
    /**
     * Formats remote file path
     *
     * @param string $file
     * @return string
     */
    function format_remote_path($file)
    {
        return w3_get_site_path() . $file;
    }
    
    /**
     * Link replace callback
     *
     * @param array $matches
     * @return string
     */
    function link_replace_callback($matches)
    {
        global $wpdb;
        static $queue = null;
        
        if (in_array($matches[2], $this->replaced_urls)) {
            return $matches[0];
        }
        
        if ($queue === null) {
            $sql = sprintf('SELECT remote_path FROM %s', $wpdb->prefix . W3TC_CDN_TABLE_QUEUE);
            $queue = $wpdb->get_col($sql);
        }
        
        if (in_array(ltrim($matches[4], '/'), $queue)) {
            return $matches[0];
        }
        
        $path = '/' . w3_get_site_path() . $matches[4];
        
        $cdn = & $this->get_cdn();
        $url = $cdn->format_url($path);
        
        if (! $url) {
            return $matches[0];
        }
        
        $this->replaced_urls[] = $matches[2];
        
        $replacement = sprintf('%s%s', $matches[1], $url);
        
        return $replacement;
    }
    
    /**
     * Search files
     *
     * @param string $search_dir
     * @param string $mask
     * @param boolean $recursive
     * @return array
     */
    function search_files($search_dir, $base_dir, $mask = '*.*', $recursive = true)
    {
        static $stack = array();
        
        $files = array();
        $dir = @opendir($search_dir);
        
        if ($dir) {
            while (($entry = @readdir($dir))) {
                if ($entry != '.' && $entry != '..') {
                    $path = $search_dir . '/' . $entry;
                    if (is_dir($path) && $recursive) {
                        array_push($stack, $entry);
                        $files = array_merge($files, $this->search_files($path, $base_dir, $mask, $recursive));
                        array_pop($stack);
                    } else {
                        $regexp = '~^' . $this->get_regexp_by_mask($mask) . '$~i';
                        if (preg_match($regexp, $entry)) {
                            $files[] = ($base_dir != '' ? $base_dir . '/' : '') . (($p = implode('/', $stack)) != '' ? $p . '/' : '') . $entry;
                        }
                    }
                }
            }
            @closedir($dir);
        }
        
        return $files;
    }
    
    /**
     * Returns regexp by mask
     *
     * @param string $mask
     * @return string
     */
    function get_regexp_by_mask($mask)
    {
        $regexp = str_replace(array(
            '\*', 
            '\?', 
            '\[', 
            '\]', 
            ';'
        ), array(
            '[^\s"\'>]*', 
            '[^\s"\'>]', 
            '[', 
            ']', 
            '|'
        ), w3_preg_quote($mask));
        
        return $regexp;
    }
    
    /**
     * Normalizes attachment file
     *
     * @param string $file
     * @return string
     */
    function normalize_attachment_file($file)
    {
        $upload_info = w3_upload_info();
        if ($upload_info) {
            $file = ltrim(str_replace($upload_info['basedir'], '', $file), '/\\');
            $matches = null;
            
            if (preg_match('~(\d{4}/\d{2}/)?[^/]+$~', $file, $matches)) {
                $file = $matches[0];
            }
        }
        
        return $file;
    }
    
    /**
     * Returns CDN object
     *
     * @return W3_Cdn_Base
     */
    function &get_cdn()
    {
        static $cdn = array();
        
        if (! isset($cdn[0])) {
            $engine = $this->_config->get_string('cdn.engine');
            $engine_config = array();
            
            switch ($engine) {
                case 'mirror':
                    $engine_config = array(
                        'domain' => $this->_config->get_string('cdn.mirror.domain')
                    );
                    break;
                
                case 'ftp':
                    $engine_config = array(
                        'host' => $this->_config->get_string('cdn.ftp.host'), 
                        'user' => $this->_config->get_string('cdn.ftp.user'), 
                        'pass' => $this->_config->get_string('cdn.ftp.pass'), 
                        'path' => $this->_config->get_string('cdn.ftp.path'), 
                        'pasv' => $this->_config->get_boolean('cdb.ftp.pasv'), 
                        'domain' => $this->_config->get_string('cdn.ftp.domain')
                    );
                    break;
                
                case 's3':
                    $engine_config = array(
                        'key' => $this->_config->get_string('cdn.s3.key'), 
                        'secret' => $this->_config->get_string('cdn.s3.secret'), 
                        'bucket' => $this->_config->get_string('cdn.s3.bucket')
                    );
                    break;
                
                case 'cf':
                    $engine_config = array(
                        'key' => $this->_config->get_string('cdn.cf.key'), 
                        'secret' => $this->_config->get_string('cdn.cf.secret'), 
                        'bucket' => $this->_config->get_string('cdn.cf.bucket'), 
                        'id' => $this->_config->get_string('cdn.cf.id'), 
                        'cname' => $this->_config->get_string('cdn.cf.cname')
                    );
                    break;
            
            }
            
            require_once W3TC_LIB_W3_DIR . '/Cdn.php';
            $cdn[0] = & W3_Cdn::instance($engine, $engine_config);
        }
        
        return $cdn[0];
    }
    
    /**
     * Returns debug info
     *
     * @return string
     */
    function get_debug_info()
    {
        $debug_info = "<!-- W3 Total Cache: CDN debug info:\r\n";
        $debug_info .= sprintf("%s%s\r\n", str_pad('Engine: ', 20), $this->_config->get_string('cdn.engine'));
        
        if (count($this->replaced_urls)) {
            $debug_info .= "Replaced URLs:\r\n";
            
            foreach ($this->replaced_urls as $replaced_url) {
                $debug_info .= sprintf("%s\r\n", $replaced_url);
            }
        }
        
        $debug_info .= '-->';
        
        return $debug_info;
    }
    
    /**
     * Check if we can do CDN logic
     * @return boolean
     */
    function can_cdn()
    {
        /**
         * Skip if CDN is disabled
         */
        if (! $this->_config->get_boolean('cdn.enabled')) {
            $this->cdn_reject_reason = 'CDN is disabled';
            
            return false;
        }
        
        /**
         * Skip if admin
         */
        if (defined('WP_ADMIN')) {
            $this->cdn_reject_reason = 'wp-admin';
            
            return false;
        }
        
        /**
         * Check User agent
         */
        if (! $this->check_ua()) {
            $this->cdn_reject_reason = 'user agent is rejected';
            
            return false;
        }
        
        /**
         * Check request URI
         */
        if (! $this->check_request_uri()) {
            $this->cdn_reject_reason = 'request URI is rejected';
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Checks User Agent
     *
     * @return boolean
     */
    function check_ua()
    {
        foreach ($this->_config->get_array('cdn.reject.ua') as $ua) {
            if (stristr($_SERVER['HTTP_USER_AGENT'], $ua) !== false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Checks request URI
     *
     * @return boolean
     */
    function check_request_uri()
    {
        $auto_reject_uri = array(
            'wp-login', 
            'wp-register'
        );
        
        foreach ($auto_reject_uri as $uri) {
            if (strstr($_SERVER['REQUEST_URI'], $uri) !== false) {
                return false;
            }
        }
        
        foreach ($this->_config->get_array('cdn.reject.uri') as $expr) {
            $expr = trim($expr);
            if ($expr != '' && preg_match('@' . $expr . '@i', $_SERVER['REQUEST_URI'])) {
                return false;
            }
        }
        
        return true;
    }
}
