<?php
/*
Plugin Name: BDN CDN
Description: Rewrites your media files to a CDN. Can be used to easily rewrite all files on a multisite instance. Based a bit on WP CDN Rewrite by Chris Scott, Michael Pretty, Kevin Langley.
Version: 1.0
Author: William P. Davis
Contributors: wpdavis
Author URI: http://dev.bangordailynews.com/
*/

class Bdn_Cdn {

	//You can set your preferences as $bdn_cdn in wp-config.php or elsewhere.
	private
		//http root to rewrite files to.
		$cdn_root_http = false,
		//https root to rewrite files to. To disable rewrite on https, keep as false
		$cdn_root_https = false,
		//The file extensions to match
		$file_extensions = array( 'bmp','bz2','gif','ico','gz','jpg','jpeg','mp3','pdf','png','rar','rtf','swf','tar','tgz','txt','wav','zip', 'css', 'js' ),
		//Include files in these directories
		//You can also include top-level files like I did here with favicon
		//If you want to reference a specific file, do not include the extension
		$include = array( 'wp-content/', 'wp-includes/', 'favicon' ),
		//Automatically include these domains in the rewrite
		//In other words, if you want all references to google.com to be included in the rewrite, even if your
		//domain is bing.com, include google.com here
		$domains = false,
		//Whether to rewrite the /files/ directory on older MS installs to /wp-content/blogs.dir/{i}...
		$rewrite_files_dir = true,
		//Whether to attempt to rewrite URLs in JSON output. Might break things.
		$rewrite_json = true,
		//Warning, this could break JSON and XML responses
		$debug = false,
		//These are private. Don't try to set them
		$_cdn_root = false,
		$_preg_include_exclude = array(),
		$_preg_domains = array(),
		$_files_dir = false,
		$_blogs_dir = false,
		$_regex = false,
		$_json_regex = false;


	function Bdn_Cdn() {
		
		global $bdn_cdn;
		
		if( empty( $bdn_cdn ) )
			return;
		
		//Default cdn root is the http root
		$this->_cdn_root = $this->cdn_root_http = trailingslashit( $bdn_cdn[ 'cdn_root_http' ] );
		
		//Set the preferences in $bdn_cdn
		foreach( $bdn_cdn as $key => $value ) {
			if( isset( $this->$key ) && $key != 'cdn_root_http' && substr( $key, 0, 1 ) != '_' )
				$this->$key = $value;
		}
		
		if( $this->cdn_root_https )
			$this->cdn_root_https = trailingslashit( $this->cdn_root_https );
		
		//Create a preg-safe array of the included directories and files
		if( !empty( $this->include ) && is_array( $this->include ) ) {
			foreach( $this->include as $key => $value )
				$this->_preg_include_exclude[] = preg_quote( $value );
		}
		
		//If the page is SSL and we don't have an HTTPS URL, don't replace URLs
		if( is_ssl() && !$this->cdn_root_https )
			return;
		
		//If the page is SSL, change the CDN root to the https root
		if( is_ssl() )
			$this->_cdn_root = $this->cdn_root_https;
		
		$this->domains();
		$this->rewrite_files_dir();
		
		//We start this as early as possible because we might be in a fight with other plugins
		//that have output buffering
		ob_start( array( &$this, 'filter_urls' ) );
		
		//If we're debugging, print out this object at the end of the pageload.
		if( $this->debug ) {
			add_action( 'shutdown', function() {
				echo '<!--';
				print_r( $this );
				echo '-->';
			});
		}
	}
	
	/**
	 * The domains to look for to rewrite
	 *
	 */
	function domains() {
	
		if( !empty( $this->domains ) && is_array( $this->domains ) ) {
			foreach( $this->domains as $domain )
				$this->_preg_domains[] = preg_quote( trailingslashit( $domain ) );
		}
		
		$this->_preg_domains[] = preg_quote( trailingslashit( site_url() ) );
		
		if( is_multisite() )
			$this->_preg_domains[] = preg_quote( trailingslashit( network_site_url() ) );
			
		$this->_preg_domains = array_filter( $this->_preg_domains );
	
	}
	
	/**
	 * Grab the raw root to files, to avoid the old-style ms-files.php rewrite.
	 * In other words, site2.bing.com/files/2014/09/13/myfile.jpg will be rewritten to
	 * site2.bing.com/wp-content/blogs.dir/2/files/2014/09/13/myfile.jpg
	 * and then mycdn.com/wp-content/...
	 *
	 */
	function rewrite_files_dir() {
		
		if( !is_multisite() )
			$this->rewrite_files_dir = false;
		
		if( is_main_site() )
			$this->rewrite_files_dir = false;
		
		if( !$this->rewrite_files_dir )
			return;
		
		$upload_dirs = wp_upload_dir();
		
		$this->_files_url = $upload_dirs[ 'baseurl' ];
		$this->_blogs_dir_url = str_replace( ABSPATH, trailingslashit( site_url() ), $upload_dirs[ 'basedir' ] );
		
	}
	
	/**
	 * Start output buffering.
	 *
	 */
	function start_buffer() {
		
		ob_start( array( &$this, 'filter_urls' ) );

	}
	
	/**
	 * Escapes / so they match a JSON string
	 *
	 */
	function json_escape( $string, $for_regex = true ) {
		//replace / without a preceding \ with a \/. If we're preparing this for regex, we need an extra \\/.
		return preg_replace( '|((?<!\\\)/)|', ( $for_regex ? '\\' : '' ) . '\\\\/', $string );
	}
	
	/**
	 * Callback for output buffering.  Search content for urls to replace
	 *
	 * @param string $content
	 * @return string
	 */
	function filter_urls( $content ) {
	
		//If we want to fix ms-files.php, do that first
		if( $this->rewrite_files_dir ) {
			$content = str_replace( $this->_files_url, $this->_blogs_dir_url, $content );
			if( $this->rewrite_json ) 
				$content = str_replace( $this->json_escape( $this->_files_url ), $this->json_escape( $this->_blogs_dir_url ), $content );
		}
		
		//This is the rewrite for normal assets
		$this->_regex = '#(' . implode( '|', $this->_preg_domains ) . ')(' . ( ( !empty( $this->_preg_include_exclude ) ) ? '(' . implode( '|', array_filter( $this->_preg_include_exclude ) ) . ')' : '' ) . '([^\r\n\t\f"\'> ]+?)(\.(' . implode( '|', array_filter( $this->file_extensions ) ) . ')))#i';
		$content = preg_replace_callback( $this->_regex, array( &$this, 'url_rewrite' ), $content );
		
		//If the asset is in a JSON object, you need to escape the /
		if( $this->rewrite_json ) {
			$this->_json_regex = '#(' . $this->json_escape( implode( '|', $this->_preg_domains ) ) . ')(' . ( ( !empty( $this->_preg_include_exclude ) ) ? '(' . $this->json_escape( implode( '|', array_filter( $this->_preg_include_exclude ) ) ) . ')' : '' ) . '([^\r\n\t\f"\'> ]+?)(\.(' . implode( '|', array_filter( $this->file_extensions ) ) . ')))#i';
			$content = preg_replace_callback( $this->_json_regex, array( &$this, 'url_rewrite_json' ), $content );
		}

		return $content;
	}
	
	/**
	 * Callback for url preg_replace_callback.  Returns CDN url.
	 *
	 * @param array $match
	 * @return string
	 */
	function url_rewrite( $match ) {
		global $blog_id;
		
		$replace_with = $this->_cdn_root . $match[ 2 ];
		
		if( $this->debug ) {
			$this->_matches[] = $match;
			$this->_replacements[] = array( 'callback' => 'default', 'original' => reset( $match ), 'replaced' => $replace_with );
		}
		
		return $replace_with;
		
	}
	
	
	/**
	 * Callback for url preg_replace_callback.  Returns a JSON escaped CDN url.
	 *
	 * @param array $match
	 * @return string
	 */
	function url_rewrite_json( $match ) {
		global $blog_id;
		
		$replace_with = $this->json_escape( $this->_cdn_root, false ) . $match[ 2 ];
		
		if( $this->debug ) {
			$this->_matches[] = $match;
			$this->_replacements[] = array( 'callback' => 'json', 'original' => reset( $match ), 'replaced' => $replace_with );
		}
		
		return $replace_with;
		
	}

}

new Bdn_Cdn;
