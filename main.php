<?php
/*
Plugin Name: Weaveworks Guide Sync[Mytory Markdown]
Description: Autosyncs Guides from Weaveworks guide repo master branch. A fork of Mytory Markdown plugin. [This plugin get markdown file path on dropbox public link or github raw content url. It convert markdown file to html, and put it to post content. It also provide real-time conversion editor. This feature don't need dropbox url. You can directly write markdown in editing page and see real-time conversion.]
Author: mytory (and mathew@weave.works)
Version: 1.5.2
Author URI: http://mytory.net
*/

class Mytory_Markdown {

    var $error = array(
        'status' => FALSE,
        'msg' => '',
    );

    var $post;
    var $worked;
    var $debug_msg = array();

    function Mytory_Markdown() {
        add_action('plugins_loaded', array(&$this, 'plugin_init'));
        if(get_option('manual_update') != 'yes'){
            add_action('pre_get_posts', array(&$this, 'conditional_apply_markdown'));
        }else{
            add_filter('the_content', array(&$this, 'manual_update_button'));
        }
        if(isset($_POST['mytory_markdown_manual_update'])
                && $_POST['mytory_markdown_manual_update'] == 'do'){
            add_action('pre_get_posts', array(&$this, 'apply_markdown'));
        }
        add_filter('the_content', array(&$this, 'attach_error_msg'));
        add_action('add_meta_boxes', array(&$this, 'register_meta_box'));
        add_action('save_post', array(&$this, 'update_post'));
        add_action('wp_ajax_mytory_md_update_editor', array(&$this, 'get_post_content_ajax'));
        add_action('admin_menu', array(&$this, 'add_menu'));
        add_action('admin_init', array(&$this, 'register_settings'));
        add_action('admin_enqueue_scripts', array(&$this, 'enqueue_scripts'));

    }

    function enqueue_scripts($hook) {
        if ( ! in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }
        wp_enqueue_script('marked', plugin_dir_url( __FILE__ ) . 'js/marked.min.js', array(), '0.3.5', true);
    }

    function plugin_init() {
        load_plugin_textdomain('mytory-markdown', false, dirname(plugin_basename( __FILE__ )) .'/lang' );
    }

    function conditional_apply_markdown($query){
        if($this->worked == true){
            return null;
        }
        $this->worked = true;

        $auto_update_only_writer_visits = get_option('auto_update_only_writer_visits');

        if($auto_update_only_writer_visits == 'y' AND ! current_user_can('edit_posts')){
            $this->debug_msg[] = "Auto update only writer or admin visits is Y and current user can't edit posts. So don't work.";
            return null;
        }

        $this->apply_markdown($query);
    }

    /**
     * apply markdown on pre_get_posts
     * @param $query
     * @return string
     */
    public function apply_markdown($query) {

        $auto_update_only_writer_visits = get_option('auto_update_only_writer_visits');

        ini_set('memory_limit', -1);

        ob_start();
        echo "<pre>";
        var_dump($query->query_vars);
        echo "</pre>";
        $this->debug_msg[] = ob_get_contents();
        ob_end_clean();

        if($query->query_vars['p']){
            // post인 경우
			$this->post = get_post($query->query_vars['p']);
            $this->debug_msg[] = "This is post.";

        }else if($query->query_vars['page_id']){
            // page인 경우
            $this->post = get_post($query->query_vars['page_id']);
            $this->debug_msg[] = "This is page.";

        }else if($query->query_vars['pagename'] OR $query->query_vars['name']){

            // page인 경우 OR slug 형태 주소인 경우.
            $slug = ($query->query_vars['pagename'] ? $query->query_vars['pagename'] : $query->query_vars['name']);
            $posts = get_posts(array('post_type' => 'any','name' => $slug));
            $this->debug_msg[] = "This is page or slug type permalink. Continue.";

            if(isset($posts[0])){
                $this->post = $posts[0];
            }else{
                $this->debug_msg[] = "There is not post/page that has slug '{$slug}'. So don't work.";
                return null;
            }

        }else{
            // post도 page도 아닌 경우
            $this->debug_msg[] = "This is not post/page. So don't work.";
            return null;
        }

        if( ! is_single() and ! is_page()){
            // single이 아닌 경우
            $this->debug_msg[] = "This is not single page. So don't work.";
            return null;
        }

        // 'Auto update per x visits' feature work only when 'Auto update only writer visits' feature disabled.
        if($auto_update_only_writer_visits != 'y'){

            // Auto update per x visits.
            $auto_update_per = get_option('auto_update_per');
            if( $auto_update_per !== FALSE ){
                $visits_count = get_post_meta($this->post->ID, 'mytory_md_visits_count', TRUE);
                if( ! $visits_count){
                    $visits_count = 0;
                }
                update_post_meta( $this->post->ID, 'mytory_md_visits_count', $visits_count + 1);
                $visits_count++;
                if($visits_count % $auto_update_per !== 0){
                    $this->debug_msg[] = "'Auto update per' option is enabled. And count is not full. So don't work.";
                    return null;
                }
            }
        }

        $markdown_path = get_post_meta($this->post->ID, 'mytory_md_path', TRUE);

        if( ! $markdown_path){
            $this->debug_msg[] = "This don't has markdown path. So don't work.";
            return null;
        }
        $markdown_path = str_replace('https://', 'http://', $markdown_path);

        if ($this->_need_to_save($markdown_path)) {

            update_post_meta($this->post->ID, '_mytory_markdown_etag', $this->_get_etag($markdown_path));
            $md_post = $this->_get_post($markdown_path);

            if ($this->error['status'] === TRUE) {
                if(current_user_can('edit_posts')){
                    return "<p>{$this->error['msg']}</p>" . $md_post['post_content'];
                }else{
                    return $md_post['post_content'];
                }
            }else{
                $postarr = array(
                    'ID' => $this->post->ID,
                    'post_title' => $md_post['post_title'],
                    'post_content' => $md_post['post_content'],
                );
                wp_update_post($postarr);
            }
        }else{
            $this->debug_msg[] = "Etag was not changed. So content has not been updated.";
        }
        return null;
    }

    /**
     * if error occurred, attach error message to post content.
     * @param $post_content
     * @return string
     */
    public function attach_error_msg($post_content){
        if ($this->error['status'] === TRUE AND current_user_can('edit_posts')) {
            $post_content =  "<p>{$this->error['msg']}</p>" . $post_content;
        }
        if ( ! empty($this->debug_msg) AND current_user_can('edit_posts') AND get_option('debug_msg') == 'yes'){
            $debug = '<ul>';
            foreach ($this->debug_msg as $msg) {
                $debug .= "<li>mytory markdown debug: {$msg}</li>";
            }
            $debug .= "</ul>";
            $post_content = $debug . $post_content;
        }

        return $post_content;
    }

    /**
     * get html converted from markdown file path.
     * if error occur, return false.
     * @param $markdown_path
     * @return boolean | string
     */
    private function _get_post($markdown_path){
        $md_content = $this->_file_get_contents($markdown_path);

        if($md_content === FALSE){
            return FALSE;
        }
		
		// Begin Weaveworks customization
		$post = array();
		preg_match('/^title: (.*?)$/m', $md_content, $matches);
		if (!empty($matches)) {
			$post['post_title'] = str_replace('"', '', $matches[1]); // Remove any quotes
		}
		
		// Remove the github header
		$md_content = preg_replace('/^---(.*?)^---/sm', '', $md_content);
		// Remove any includes
		$md_content = preg_replace('/^\{%(.*?)%\}$/m', '', $md_content);
		// Remove any other curly brace stuff
		$md_content = str_replace("{{", "", $md_content);
		$md_content = str_replace("}}", "", $md_content);
		// End Weaveworks customization

        if (!function_exists('Markdown')) {
            include_once 'markdown.php';
        }

        $content = Markdown($md_content);
		// Begin Weaveworks customization
		if(preg_match("#https?://(raw.githubusercontent.com/[^/]+/[^/]+/[^/]+)#", $markdown_path, $match)) {
			// Mirror images from github
			$this->_scrape_images($content, $match[1]); // Copy all images used by post to this site
			$content = str_replace('src="', 'src="/wp-content/uploads/'.$match[1], $content); // Fix up image URLs
		}
        if (empty($post)) { // Didn't find a title, so use whatever is between the first set of H1 tags
        	preg_match('/<h1>(.*?)<\/h1>/', $content, $matches);
	
        	if( ! empty($matches)){
            	$post['post_title'] = $matches[1];
        	}else{
            	$post['post_title'] = FALSE;
        	}
		}
		// End Weaveworks customization
        $post['post_content'] = preg_replace('/<h1>(.*)<\/h1>/', '', $content, 1);

        return $post;
    }

    public function get_post_content_ajax(){

        ini_set('display_errors', 1);
        error_reporting(E_ERROR | E_WARNING);

        $md_path = $_REQUEST['md_path'];

        $etag_new = $this->_get_etag($md_path);

        if( ! $etag_new){
            $res = array(
                'error' => TRUE,
                'error_msg' => $this->error['msg'],
                'post_title' => 'error',
                'post_content' => 'error',
                'curl_info' => $this->error['curl_info'],
            );
            echo json_encode($res);
            die();
        }

        update_post_meta($_REQUEST['post_id'], '_mytory_markdown_etag', $etag_new);

        $md_post = $this->_get_post($md_path);

        if( ! $md_post){
            $res = array(
                'error' => TRUE,
                'error_msg' => $this->error['msg'],
                'post_title' => 'error',
                'post_content' => 'error',
            );
        }else{
            $res = array(
                'error' => FALSE,
                'error_msg' => '',
                'post_title' => $md_post['post_title'],
                'post_content' => $md_post['post_content'],
            );
        }
        echo json_encode($res);
        die();
    }


	/**
	 * Weaveworks customization
	 * Download any images that are found inside the HTML for the guide
	 *
	 */
	 
	 private function _scrape_images($content, $root) {
		 $bdir = ABSPATH . "/wp-content/uploads/$root";
		 preg_match_all('/\<img src="(\/[^"]+)"/', $content, $matches);
		 $paths = $matches[1];
		 //error_log(json_encode($paths));
		 foreach ($paths as $p) {
			 preg_match('/^(.*)\/(.*)$/', $p, $matches);
			 //error_log(json_encode($matches));
			 $dir = $bdir . $matches[1] . "/";
			 $fname = $matches[2];
			 $fullname = $dir . "/" . $fname;
			 //error_log("dir:fname is " . $dir . " : " . $fname);
			 if (!file_exists($dir)) {
				 mkdir($dir, 0777, true);
			 }
			 $url = "http://$root" . $p;
			 //error_log($url);
			 $image = $this->_file_get_contents($url);
			 $h = fopen($fullname, "w+");
			 if (!$h) {
				 error_log("fopen didn't work: " . $fullname);
			 } else {
				//error_log("fopened : " . $fullname);
			 	fwrite($h, $image);
			 	fclose($h);
			 }
		 }
	 }

    /**
     * This function use etag in http header.
     * If etag change, need new save.
     * @param  string $url
     * @return boolean
     */
    private function _need_to_save($url) {
        $post = $this->post;

        // If not single page, don't connect for prevent time-wasting.
        // return FALSE that means 'no need to save' to print HTML that is saved.
        // 싱글 페이지가 아니라면 굳이 접속해서 시간낭비할 거 없이 
        // 바로 저장된 HTML을 뿌려줄 수 있도록 save할 필요 없다고 신호를 준다.
        if (!is_single() AND !is_page()) {
            return FALSE;
        }

        $etag_saved = get_post_meta($post->ID, '_mytory_markdown_etag', TRUE);

        if ($etag_saved) {
            $etag_remote = $this->_get_etag($url);

            // if there is not etag, don't need to save.
            if ($etag_remote === NULL) {
                return FALSE;
            }

            // if etag different each other, need to save
            return ($etag_saved != $etag_remote);
        } else {
            // if no cache, need to save
            return TRUE;
        }
    }

    /**
     * get etag from url
     * @param  string $url
     * @return string
     */
    private function _get_etag($url) {
        $header = $this->_get_header_from_url($url);
        $header = $this->_http_parse_headers($header);

        foreach ($header as $key => $value) {
            $key_lower = strtolower($key);
            $header[$key_lower] = $value;
        }

        if (!empty($header['etag'])) {
            return $header['etag'];
        } else {
			// No etag -- set error message
			$this->error = array('status' => TRUE,
				'msg' => 'No cache ETag found for markown file. Is the URL correct? If on GitHub, are you linking to the raw file?');
			return NULL;
        }
    }

    /**
     * get header from url
     * @param  string $url dropbox public url
     * @return string
     */
    private function _get_header_from_url($url) {
        if( ! function_exists('curl_init') ){
            $this->error = array(
                'status' => TRUE,
                'msg' => 'Mytory Markdown plugin need PHP cURL module. But, your Server has not the module. So you cannot use this plugin. I\'m Sorry. If you can, request to install cURL module to your hosting service. Common hosting service provides cURL module.',
            );
            return FALSE;
        }
        $curl = curl_init($url);
        // curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, TRUE);
        curl_setopt($curl, CURLOPT_NOBODY, TRUE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        if(!ini_get('open_basedir')){
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
        }
        $header = curl_exec($curl);
		
        if( ! $this->_check_curl_error($curl)){
            return FALSE;
        }

        return $header;
    }

    /**
     * get contents form url
     * @param  string $url dropbox public url
     * @return string
     */
    private function _file_get_contents($url) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, FALSE);
        curl_setopt($curl, CURLOPT_NOBODY, FALSE);
        if(!ini_get('open_basedir')){
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        $content = curl_exec($curl);

        if( ! $this->_check_curl_error($curl)){
            return FALSE;
        }

        return $content;
    }

    private function _check_curl_error($curl){
        $curl_info = curl_getinfo($curl);
        if($curl_info['http_code'] != '200'){
            $this->error = array(
                'status' => TRUE,
                'msg' => __('Network Error! HTTP STATUS is ', 'mytory-markdown') . $curl_info['http_code']);
            if($curl_info['http_code'] == '404'){
                $this->error['msg'] = 'Incorrect URL. File not found.';
            }
            if($curl_info['http_code'] == 0){
                $this->error['msg'] = __('Network Error! Maybe, connection error.', 'mytory-markdown');
            }
            $this->error['curl_info'] = $curl_info;
            return FALSE;
        }
        return TRUE;
    }

    /**
     * parse header to array
     * http://www.php.net/manual/en/function.http-parse-headers.php#112986
     * @param  string $raw_headers
     * @return array
     */
    private function _http_parse_headers($raw_headers) {
        $headers = array();
        $key = ''; // [+]

        foreach (explode("\n", $raw_headers) as $i => $h) {
            $h = explode(':', $h, 2);

            if (isset($h[1])) {
                if (!isset($headers[$h[0]]))
                    $headers[$h[0]] = trim($h[1]);
                elseif (is_array($headers[$h[0]])) {
                    // $tmp = array_merge($headers[$h[0]], array(trim($h[1]))); // [-]
                    // $headers[$h[0]] = $tmp; // [-]
                    $headers[$h[0]] = array_merge($headers[$h[0]], array(trim($h[1]))); // [+]
                } else {
                    // $tmp = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [-]
                    // $headers[$h[0]] = $tmp; // [-]
                    $headers[$h[0]] = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [+]
                }

                $key = $h[0]; // [+]
            } else // [+]
            { // [+]
                if (substr($h[0], 0, 1) == "\t") // [+]
                $headers[$key] .= "\r\n\t" . trim($h[0]); // [+]
                elseif (!$key) // [+]
                $headers[0] = trim($h[0]);
                trim($h[0]); // [+]
            } // [+]
        }

        return $headers;
    }

    function register_meta_box() {
        add_meta_box(
            'mytory-markdown-path',
            __('Markdown File Path', 'mytory-markdown'),
            array(&$this, 'meta_box_inner')
        );
    }

    function meta_box_inner() {
        $md_path = '';
        $md_mode = 'url';
        $md_text = '';
        if(isset($_GET['post'])){
            $md_path = get_post_meta($_GET['post'], 'mytory_md_path', TRUE);
            $md_mode = get_post_meta($_GET['post'], 'mytory_md_mode', TRUE);
            $md_text = get_post_meta($_GET['post'], 'mytory_md_text', TRUE);
        }
        include 'meta-box.php';
    }

    function update_post($post_id) {
        if (!current_user_can('edit_post', $post_id)) {
            return null;
        }

        // 데이터 저장
        if(isset($_POST['mytory_md_path'])){
            update_post_meta($post_id, 'mytory_md_path', $_POST['mytory_md_path']);
            update_post_meta($post_id, 'mytory_md_text', $_POST['mytory_md_text']);
            update_post_meta($post_id, 'mytory_md_mode', $_POST['mytory_md_mode']);
        }
    }

    function register_settings() { // whitelist options
        if ( ! current_user_can('activate_plugins') ){
            return null;
        }
        register_setting( 'mytory-markdown-option-group', 'auto_update_only_writer_visits' );
        register_setting( 'mytory-markdown-option-group', 'auto_update_per' );
        register_setting( 'mytory-markdown-option-group', 'debug_msg' );
        register_setting( 'mytory-markdown-option-group', 'manual_update' );
    }

    function add_menu() {
        if ( ! current_user_can('activate_plugins') ){
            return null;
        }
        add_submenu_page('options-general.php', 'Mytory Markdown Setting', 'Weave Guide Sync', 'activate_plugins', 'mytory-markdown',
                array(&$this, 'print_setting_page'));
    }

    function print_setting_page(){
        include "setting.php";
    }

    function manual_update_button($post_content){
        global $post;

        if ( ! current_user_can('edit_post', get_the_ID())
                or ! get_post_meta($post->ID, 'mytory_md_path', true)
                or get_post_meta($post->ID, 'mytory_md_mode', 'text')
        ) {
            return $post_content;
        }

        ob_start();
        ?>
        <form style="margin: 1em 0; text-align: center" method="post">
            <input type="hidden" name="mytory_markdown_manual_update" value="do">
            <input type="submit" value="<?php _e('Manual Update with Mytory Markdown', 'mytory-markdown') ?>">
        </form>
        <?php
        $manual_update_button_html = ob_get_contents();
        ob_end_clean();

        return $manual_update_button_html . $post_content . $manual_update_button_html;
    }
}

$mytory_markdown = new Mytory_Markdown;
