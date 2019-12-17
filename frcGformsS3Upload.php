<?php
/* * @wordpress-plugin
 * Plugin Name: Frc Gravity Forms S3 Upload
 * Plugin URI:
 * Description:
 * Version:     0.0.1
 * Author:      Janne Aalto / Frantic Oy
 * Author URI:  http://www.frantic.com
 * Text Domain: frc-gforms-s3
 * Domain Path: /languages/
 * Requires at least: 4.0
 * Tested up to: 4.4.2
 * License:      LGPL2.1
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class FrcGformsS3Upload {

    private static $instance = null;
    private $td = 'frc-gforms-s3';
    const DEFAULT_ACL = 'public-read';
    private $activePlugins = [];

    public function __construct() {
    }

    public static function getInstance() {
        if (is_null(self::$instance)) {
            self::$instance = new FrcGformsS3Upload();
        }

        return self::$instance;
    }

    private function setActivePlugins() {
        $this->activePlugins = apply_filters('active_plugins', get_option('active_plugins'));
        if (is_multisite()) {
            $multisitePlugins    = get_site_option('active_sitewide_plugins');
            $multisitePlugins    = array_keys($multisitePlugins);
            $this->activePlugins = array_merge($this->activePlugins, $multisitePlugins);
        }

        return $this->activePlugins;
    }

    private function gFormsActive() {
        return in_array('gravityforms/gravityforms.php', $this->activePlugins);
    }

    private function s3Active() {
        return (in_array('wp-amazon-s3-and-cloudfront/wordpress-s3.php', $this->activePlugins) || in_array('amazon-s3-and-cloudfront/wordpress-s3.php', $this->activePlugins));
    }

    private function checkForActivePlugins() {
        return $this->gFormsActive() && $this->s3Active();
    }

    public function init() {
        load_plugin_textdomain($this->td, false, basename(dirname(__FILE__)) . '/languages');
        $this->setActivePlugins();

        if ($this->checkForActivePlugins()) {
            add_action('gform_post_multifile_upload', [$this, 'gformPostMultifileUploadToAws'], 10, 5);
            add_action('gform_post_data', [$this, 'gformPrefilterImage'], 5, 3);
            add_action('gform_pre_submission', [$this, 'gformPreSubmissionRenameUploadedFiles'], 5, 1);
            add_filter('gform_entry_post_save', [$this, 'entryPostSaveAws'], 5, 2);
        }
    }

    private function uniqueFilename($uploaded_filename, $tmp_file_name) {

        $filename = remove_accents(sanitize_file_name($uploaded_filename));
        $tmp_info = pathinfo($tmp_file_name);
        $info     = pathinfo($filename);
        $ext      = !empty($info['extension']) ? '.' . $info['extension'] : '';

        //generate unique filename by appending tmp filename
        if ($tmp_info['filename'] != '') {
            $name = $info['filename'] . '-' . $tmp_info['filename'];
        } else {
            $name = $info['filename'];
        }
        // edge case: if file is named '.ext', treat as an empty name
        if ($name === $ext) {
            $name = '';
        }

        // rebuild filename with lowercase extension as S3 will have converted extension on upload
        $ext      = strtolower($ext);
        $filename = $name . $ext;

        return $filename;
    }

    /**
     * @return Amazon_S3_And_CloudFront|null
     */
    private function getAs3cfInstance():?Amazon_S3_And_CloudFront {
        global $as3cf;
        if (false === $as3cf instanceof Amazon_S3_And_CloudFront || !$as3cf->is_plugin_setup() || !$as3cf->get_setting('copy-to-s3')) {
            return null;
        }

        return $as3cf;
    }

    /**
     * @param $form_id
     *
     * @return bool|string
     */
    private function gformsPath($form_id) {

        $as3cf = $this->getAs3cfInstance();
        if (is_null($as3cf)) {
            return false;
        }

        $dir            = wp_upload_dir();
        $ym             = $as3cf->get_year_month_directory_name();
        $multisite_path = str_replace($ym, '', $as3cf->get_dynamic_prefix());
        $gforms_path    = str_replace($dir['basedir'], '', GFFormsModel::get_upload_path($form_id));

        return '/' . ltrim(untrailingslashit($multisite_path . $gforms_path . $ym), '/') . '/';
    }

    /**
     * @param $filename
     *
     * @return string
     */
    private function renameFile($filename) {

        $file_info    = pathinfo($filename);
        $new_filename = $this->removeSlashes(date('YmdHis', current_time('timestamp')) . '-' . $this->uniqueFilename($file_info['filename'], ''));

        return sprintf('%s.%s', $new_filename, rgar($file_info, 'extension'));
    }

    /**
     * @param $value
     *
     * @return string
     */
    private function removeSlashes($value) {
        return stripslashes(str_replace('/', '', $value));
    }

    /**
     * @param $field
     * @param bool $upload
     *
     * @return bool
     */
    private function isApplicableField($field, $upload = false) {

        $fields = ['fileupload', 'post_image'];

        if ($upload) {
            $fields = ['fileupload'];
        }

        $is_file_upload_field = in_array(GFFormsModel::get_input_type($field), $fields);

        return $is_file_upload_field;
    }

    /**
     * @param $array
     *
     * @return array
     */
    private function superUnique($array) {
        $result = array_map("unserialize", array_unique(array_map("serialize", $array)));

        foreach ($result as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->superUnique($value);
            }
        }

        return $result;
    }

    /**
     * @param $file_path
     * @param $type
     *
     * @return bool
     */
    private function shouldGzip($file_path, $type) {
        $mimes = apply_filters('as3cf_gzip_mime_types', [
            'css'   => 'text/css',
            'eot'   => 'application/vnd.ms-fontobject',
            'html'  => 'text/html',
            'ico'   => 'image/x-icon',
            'js'    => 'application/javascript',
            'json'  => 'application/json',
            'otf'   => 'application/x-font-opentype',
            'rss'   => 'application/rss+xml',
            'svg'   => 'image/svg+xml',
            'ttf'   => 'application/x-font-ttf',
            'woff'  => 'application/font-woff',
            'woff2' => 'application/font-woff2',
            'xml'   => 'application/xml',
        ], true);

        if (in_array($type, $mimes) && is_readable($file_path)) {
            return true;
        }

        return false;
    }

    private function getAwsUrl() {
        $as3cf = $this->getAs3cfInstance();
        if (is_null($as3cf)) {
            return false;
        }

        $scheme = '';
        if (method_exists($as3cf, 'get_s3_url_scheme')) {
            $scheme = $as3cf->get_s3_url_scheme();
        } else {
            $scheme = $as3cf->get_url_scheme();
        }

        $domain = '';
        $bucket = $as3cf->get_setting('bucket');
        $region = $as3cf->get_setting('region');
        if (method_exists($as3cf, 'get_s3_url_domain')) {
            $domain = $as3cf->get_s3_url_domain($bucket, $region);
        } else {
            $domain = $as3cf->get_provider()->get_url_domain($bucket, $region);
        }

        return $scheme . '://' . $domain;
    }

    private function gravityFormsUploadToAws($file_path, $filename, $upload_path = '') {


        $as3cf = $this->getAs3cfInstance();
        if (is_null($as3cf)) {
            return false;
        }

        if (!file_exists($file_path)) {
            return false;
        }

        $filename = basename($filename);

        if ('' === $upload_path) {
            $dir         = wp_upload_dir();
            $upload_path = str_replace([$dir['basedir'], $filename], '', $file_path);
        }

        $region = $as3cf->get_setting('region');
        if (method_exists($as3cf, 'get_s3client')) {
            $s3client = $as3cf->get_s3client($region);
        } else {
            $s3client = $as3cf->get_provider_client($region);
        }

        $key_prefix = ltrim(untrailingslashit($as3cf->get_object_prefix()), '/');

        $type = wp_check_filetype($file_path);
        $type = $type['type'];

        $args = [
            'Bucket'       => $as3cf->get_setting('bucket'),
            'Key'          => $key_prefix . $upload_path . $filename,
            'SourceFile'   => $file_path,
            'ACL'          => $this::DEFAULT_ACL,
            'ContentType'  => $type,
            'CacheControl' => 'max-age=31536000',
        ];

        // If far future expiration checked (10 years)
        if ($as3cf->get_setting('expires')) {
            $args['Expires'] = date('D, d M Y H:i:s O', time() + 315360000);
        }

        // Handle gzip on supported items
        if ($this->shouldGzip($file_path, $type)) {
            $gzip_body = gzencode(wp_remote_retrieve_body(wp_safe_remote_get($file_path)));
            if (false !== $gzip_body) {
                unset($args['SourceFile']);
                $args['Body']            = $gzip_body;
                $args['ContentEncoding'] = 'gzip';
            }
        }

        $fake_file_object = [
            'name'     => $filename,
            'type'     => $type,
            'tmp_name' => $file_path,
            'error'    => 0,
            'size'     => filesize($file_path),
        ];

        apply_filters('wp_handle_upload_prefilter', $fake_file_object);

        if (method_exists($s3client, 'putObject')) {
            $s3client->putObject($args);
        } else {
            $s3client->upload_object($args);
        }
        // Upload to S3

        // Update GF entry with the new S3 URL
        $url = rtrim($this->getAwsUrl(), '/') . '/' . ltrim($key_prefix . $upload_path . $filename, '/');

        return $url;

    }

    //upload multifiles straight to aws

    /**
     * @param $form
     * @param $field
     * @param $uploaded_filename
     * @param $tmp_file_name
     * @param $file_path
     */
    public function gformPostMultifileUploadToAws($form, $field, $uploaded_filename, $tmp_file_name, $file_path) {

        $as3cf = $this->getAs3cfInstance();
        if (is_null($as3cf)) {
            return false;
        }

        if (!$this->isApplicableField($field, true)) {
            return;
        }

        if (!file_exists($file_path)) {
            return;
        }

        $filename    = $this->uniqueFilename($uploaded_filename, $tmp_file_name);
        $upload_path = $this->gformsPath($form['id']);

        $this->gravityFormsUploadToAws($file_path, $filename, $upload_path);

    }

    /**
     * @param $post_data
     * @param $form
     * @param $lead
     *
     * @return mixed
     */
    public function gformPrefilterImage($post_data, $form, $lead) {

        if (!empty($post_data['images'])) {
            // Creating post images.

            foreach ($post_data['images'] as $key => $image) {
                if (empty($image['url'])) {
                    continue;
                }

                $file = $image['url'];

                $dir       = wp_upload_dir();
                $file_path = str_replace($dir['baseurl'], $dir['basedir'], $file);
                $filename  = basename($file);

                $type = wp_check_filetype($file_path);
                $type = $type['type'];

                $fake_file_object = [
                    'name'     => $filename,
                    'type'     => $type,
                    'tmp_name' => $file_path,
                    'error'    => 0,
                    'size'     => filesize($file_path),
                ];

                apply_filters('wp_handle_upload_prefilter', $fake_file_object);

            }
        }

        return $post_data;

    }
    // bug fix -> file uploaded to heroku -> uploaded to aws
    // file with the same filename uploaded to heroku the next day (heroku removes files)
    // -> wp doesn't rename the file -> file overwritten in aws
    public function gformPreSubmissionRenameUploadedFiles($form) {

        $files = GFCommon::json_decode(stripslashes(GFForms::post('gform_uploaded_files')));

        foreach ($form['fields'] as &$field) {

            if (!$this->isApplicableField($field)) {
                continue;
            }

            $is_multi_file  = rgar($field, 'multipleFiles') === true;
            $input_name     = sprintf('input_%s', $field['id']);
            $uploaded_files = rgars(GFFormsModel::$uploaded_files, "{$form['id']}/{$input_name}");

            if ($is_multi_file && !empty($uploaded_files) && is_array($uploaded_files)) {

                foreach ($uploaded_files as &$file) {

                    $file['uploaded_filename'] = $this->uniqueFilename($file['uploaded_filename'], $file['temp_filename']);

                }

                if (isset($files[$input_name])) {
                    foreach ($files[$input_name] as &$post_file) {
                        $post_file['uploaded_filename'] = $this->uniqueFilename($post_file['uploaded_filename'], $post_file['temp_filename']);
                    }
                }

                GFFormsModel::$uploaded_files[$form['id']][$input_name] = $uploaded_files;

            } else {

                if (empty($uploaded_files)) {

                    $uploaded_files = rgar($_FILES, $input_name); // Input var ok.
                    if (empty($uploaded_files) || empty($uploaded_files['name'])) {
                        continue;
                    }

                    $uploaded_files['name'] = $this->renameFile($uploaded_files['name']);
                    $_FILES[$input_name]    = $uploaded_files;

                } else {

                    $uploaded_files                                         = $this->renameFile($uploaded_files);
                    GFFormsModel::$uploaded_files[$form['id']][$input_name] = $uploaded_files;

                }
            }
        } // End foreach().

        if (isset($_POST) && isset($_POST['gform_uploaded_files'])) { // Input var ok.
            $_POST['gform_uploaded_files'] = wp_slash(wp_json_encode($files, true));
        }

    }

    //other filter fire too late for emails -> heroku removes file -> email link to 404
    public function entryPostSaveAws($entry, $form) {

        $as3cf = $this->getAs3cfInstance();
        if (is_null($as3cf)) {
            return false;
        }

        $files       = GFCommon::json_decode(stripslashes(GFForms::post('gform_uploaded_files')));
        $upload_path = $this->gformsPath($form['id']);
        $key_prefix  = ltrim(untrailingslashit($as3cf->get_object_prefix()), '/');
        $upload_url  = rtrim($this->getAwsUrl(), '/') . '/' . ltrim($key_prefix . $upload_path, '/');
        $dir         = wp_upload_dir();
        $update      = false;

        foreach ($form['fields'] as $field) {

            if (!$this->isApplicableField($field, true)) {
                continue;
            }

            $is_multi_file  = rgar($field, 'multipleFiles') === true;
            $input_name     = sprintf('input_%s', $field['id']);
            $uploaded_files = rgars(GFFormsModel::$uploaded_files, "{$form['id']}/{$input_name}");

            if ($is_multi_file) {

                if (!is_array($uploaded_files)) {
                    $uploaded_files = [];
                }

                if (isset($files[$input_name])) {
                    $check          = $files[$input_name];
                    $uploaded_files = $this->superUnique(array_merge($uploaded_files, $check));
                }

                if (!empty($uploaded_files) && is_array($uploaded_files)) {

                    $input_files         = array_map(function ($uploaded_file) use ($upload_url) {
                        return $upload_url . $uploaded_file;
                    }, array_column($uploaded_files, 'uploaded_filename'));
                    $entry[$field['id']] = wp_json_encode($input_files);
                    $update              = true;
                }
            } else {

                if (!empty($uploaded_files)) {

                    $file      = $entry[$field['id']];
                    $file_path = str_replace($dir['baseurl'], $dir['basedir'], $file);
                    $filename  = $uploaded_files;
                    $url       = $this->gravityFormsUploadToAws($file_path, $filename, $upload_path);

                    $entry[$field['id']] = $url;
                    $update              = true;

                }
            }
        } // End foreach().

        if ($update) {
            GFAPI::update_entry($entry);
        }

        return $entry;
    }
}

add_action('init', [FrcGformsS3Upload::getInstance(), 'init']);
