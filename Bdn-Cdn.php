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
		//http root to rewrite image files to.
		$cdn_image_root_http = false,
		//https root to rewrite files to. To disable rewrite on https, keep as false
		$cdn_root_https = false,
		//http root to rewrite image files to.
		$cdn_image_root_https = false,
		//The file extensions to match
		$file_extensions = array( 'bmp','bz2','gif','ico','gz','mp3','pdf','rar','rtf','swf','tar','tgz','txt','wav','zip', 'css', 'js' ),
		//The image file types which are supported by photon.
		$image_file_extensions = array( 'jpg','jpeg','png' ),
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
		$_cdn_image_root = false,
		$_preg_include_exclude = array(),
		$_preg_domains = array(),
		$_files_dir = false,
		$_blogs_dir = false,
		$_regex = array();


	public function __construct( $bdn_cdn ) {
	
		//Add the new domain to do the CDN when we do switch_to_blog
		add_action( 'switch_blog', array( $this, 'switch_to_blog' ) );
		
		//Default cdn root is the http root
		$this->_cdn_root = $this->_cdn_image_root = $this->cdn_image_root_http = $this->cdn_root_http = trailingslashit( $bdn_cdn[ 'cdn_root_http' ] );
		
		//Set the preferences in $bdn_cdn 
		foreach( $bdn_cdn as $key => $value ) {

			if( !( isset( $this->$key ) && $key != 'cdn_root_http' && substr( $key, 0, 1 ) != '_' ) )
				continue;

			$this->$key = $value;

		}
		
		if( $this->cdn_root_https )
			$this->cdn_root_https = trailingslashit( $this->cdn_root_https );
			
		if( $this->cdn_image_root_http ) {
			$this->cdn_image_root_http = trailingslashit( $this->cdn_image_root_http );
			$this->_cdn_image_root = $this->cdn_image_root_http;
		}
		
		if( $this->cdn_image_root_https )
			$this->cdn_image_root_https = trailingslashit( $this->cdn_image_root_https );
		
		//Create a preg-safe array of the included directories and files
		if( !empty( $this->include ) && is_array( $this->include ) ) {
			foreach( $this->include as $key => $value )
				$this->_preg_include_exclude[] = preg_quote( $value );
		}
		
		//If the page is SSL and we don't have an HTTPS URL, don't replace URLs
		if( is_ssl() && !$this->cdn_root_https && !$this->cdn_image_root_https )
			return;
		
		//If the page is SSL, change the CDN root to the https root
		if( is_ssl() ) {
			$this->_cdn_root = $this->cdn_root_https;
			$this->_cdn_image_root = $this->cdn_image_root_https;
		}
		
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
	 * After we switch_to_blog(), add the new domain to our list of domains
	 * to replace
	 *
	 */
	public function switch_to_blog() {

		$this->add_domain( site_url() );
		$this->rewrite_files_dir();

	}	
	
	/**
	 * Wrapper function to handle adding a domain to the list of domains to replace
	 *
	 */
	public function add_domain( $domain ) {
	
		//For the matching of the links, whether they are ssl or not
		$this->_preg_domains[] = str_replace( array( 'http\:', 'https\:' ), 'https?\:', preg_quote( trailingslashit( $domain ) ) );
		$this->_preg_domains = array_unique( array_filter( $this->_preg_domains ) );
	
	}
	
	/**
	 * The domains to look for to rewrite
	 *
	 */
	public function domains() {
	
		if( !empty( $this->domains ) && is_array( $this->domains ) ) {
			foreach( $this->domains as $domain )
				$this->add_domain( $domain );
		}
		
		$this->add_domain( site_url() );
		
		if( is_multisite() )
			$this->add_domain( network_site_url() );
	
	}
	
	/**
	 * Grab the raw root to files, to avoid the old-style ms-files.php rewrite.
	 * In other words, site2.bing.com/files/2014/09/13/myfile.jpg will be rewritten to
	 * site2.bing.com/wp-content/blogs.dir/2/files/2014/09/13/myfile.jpg
	 * and then mycdn.com/wp-content/...
	 *
	 */
	public function rewrite_files_dir() {
		
		if( !is_multisite() )
			$this->rewrite_files_dir = false;
		
		if( is_main_site() )
			$this->rewrite_files_dir = false;
		
		if( !$this->rewrite_files_dir )
			return;
		
		$upload_dirs = wp_upload_dir();

		//Replace the original baseurl with our new baseurl, which uses the basedir path (e.g. /wp-content/blogs.dir/{id}/files/)
		$this->_blogs_dir_urls[ $upload_dirs[ 'baseurl' ] ] = str_replace( ABSPATH, trailingslashit( site_url() ), $upload_dirs[ 'basedir' ] );
		
	}
	
	/**
	 * Start output buffering.
	 *
	 */
	public function start_buffer() {
		
		ob_start( array( &$this, 'filter_urls' ) );

	}
	
	/**
	 * Escapes / so they match a JSON string
	 *
	 */
	public function json_escape( $string, $for_regex = true ) {
		//replace / without a preceding \ with a \/. If we're preparing this for regex, we need an extra \\/.
		return preg_replace( '|((?<!\\\)/)|', ( $for_regex ? '\\' : '' ) . '\\\\/', $string );
	}


	/**
	 * @TODO: Document!
	 *
	 */
	public function build_extension_regex( $extensions = array(), $for_json = false ) {
		
		$preg_domains = implode( '|', $this->_preg_domains );
		$include_exclude = implode( '|', array_filter( $this->_preg_include_exclude ) );
		
		if( !empty( $for_json ) ) {
			$preg_domains = $this->json_escape( $preg_domains );
			$include_exclude = $this->json_escape( $include_exclude );
		}
	
		$regex = '#';
			$regex .= '(?P<context>src)?';
			$regex .= '(?P<opening>\=\"|\=\\\')?';
			$regex .= '(?P<link>';
				$regex .= '(?P<domain>' . $preg_domains . ')';
				$regex .= '(?P<path>' ;
					$regex .= ( ( !empty( $include_exclude ) ) ? '(' . $include_exclude . ')' : '' );
					$regex .= '([^\r\n\t\f"\'> ]+?)';
					$regex .= '(\.(?P<ext>' . implode( '|', array_filter( $extensions ) ) . '))';
				$regex .= ')';
			$regex .= ')';
			$regex .= '(?P<termination>\\\'|\")?';
		$regex .= '#i';
		
		$this->_regex[] = $regex;
		
		return $regex;

	}

	
	/**
	 * Callback for output buffering.  Search content for urls to replace
	 *
	 * @param string $content
	 * @return string
	 */
	public function filter_urls( $content ) {
	
		//If we want to fix ms-files.php, do that first
		if( $this->rewrite_files_dir ) {
			foreach( $this->_blogs_dir_urls as $original => $replace ) {
		
				$content = str_replace( $original, $replace, $content );
				if( $this->rewrite_json ) 
					$content = str_replace( $this->json_escape( $original ), $this->json_escape( $replace ), $content );

			}
		}
		
		//This is the rewrite for photon-supported image assets.
		$content = preg_replace_callback( $this->build_extension_regex( $this->image_file_extensions ),
										  array( &$this, 'image_url_rewrite' ),
	   									  $content );

		//This is the rewrite for normal assets
		$content = preg_replace_callback( $this->build_extension_regex( $this->file_extensions ), 
										  array( &$this, 'url_rewrite' ),
	   									  $content );


		//If the asset is in a JSON object, you need to escape the /
		if( $this->rewrite_json ) {
			$content = preg_replace_callback( $this->build_extension_regex( $this->image_file_extensions, true ), array( &$this, 'image_url_rewrite_json' ), $content );
			$content = preg_replace_callback( $this->build_extension_regex( $this->file_extensions, true ), array( &$this, 'url_rewrite_json' ), $content );
		}

		return $content;
	}

	
	/**
	 * Callback for url preg_replace_callback.  Returns CDN image url.
	 *
	 * @param array $match
	 * @return string
	 */
	public function image_url_rewrite( $match ) {
		global $blog_id;
		
		// Serve low-quality images by default, upgrade them later.
		// FASTER LOAD TIMES.
		
		if( empty( $this->_cdn_image_root ) )
			return $match[ 0 ];
		
		$replace_with = $this->_cdn_image_root . $match[ 'path' ];
		
		
		if( $this->debug ) {
			$this->_matches[] = $match;
			$this->_replacements[] = array( 'callback' => 'image',
											'original' => reset( $match ), 
											str_replace( $match[ 'link' ], $replace_with, $match[ 0 ] ) );
		}
		
		return str_replace( $match[ 'link' ], $replace_with, $match[ 0 ] );
		
	}
	
	
	/**
	 * Callback for url preg_replace_callback.  Returns CDN url.
	 *
	 * @param array $match
	 * @return string
	 */
	public function url_rewrite( $match ) {
		global $blog_id;
		
		if( empty( $this->_cdn_root ) )
			return $match[ 0 ];
		
		$replace_with = $this->_cdn_root . $match[ 'path' ];
		
		if( $this->debug ) {
			$this->_matches[] = $match;
			$this->_replacements[] = array( 'callback' => 'default',
											'original' => reset( $match ),
		   									'replaced' => str_replace( $match[ 'link' ], $replace_with, $match[ 0 ] ) );
		}
		
		return str_replace( $match[ 'link' ], $replace_with, $match[ 0 ] );
		
	}
	
	
	/**
	 * Callback for url preg_replace_callback.  Returns a JSON escaped CDN url.
	 *
	 * @param array $match
	 * @return string
	 */
	public function url_rewrite_json( $match, $root = false ) {
		global $blog_id;
		
		$root = empty( $root ) ? $this->_cdn_root : $root;
		
		if( empty( $root ) )
			return $match[ 0 ];
		
		$replace_with = $this->json_escape( $root, false ) . $match[ 'path' ];
		
		if( $this->debug ) {
			$this->_matches[] = $match;
			$this->_replacements[] = array( 'callback' => 'json',
											'original' => reset( $match ),
											'replaced' => str_replace( $match[ 'link' ], $replace_with, $match[ 0 ] ) );
		}
		
		return str_replace( $match[ 'link' ], $replace_with, $match[ 0 ] );
		
	}
	
	public function image_url_rewrite_json( $match ) {

		return $this->url_rewrite_json( $match, $this->_cdn_image_root );
		
	}

}

if( isset( $bdn_cdn ) )
	$bdn_cdn = new Bdn_Cdn( $bdn_cdn );
