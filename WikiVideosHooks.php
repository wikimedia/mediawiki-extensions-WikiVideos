<?php

class WikiVideosHooks {

	/**
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		$out->addModules( 'ext.WikiVideos' );
		$out->addModuleStyles( 'ext.WikiVideos.styles' );
	}

	/**
	 * @param array &$modeArray
	 */
	public static function onGalleryGetModes( array &$modeArray ) {
		$modeArray['video'] = 'WikiVideosGallery';
	}

	/**
	 * Complete the extension installation by creating the necessary directories
	 *
	 * @note When debugging in a dev wiki, delete the main directory to start from scratch
	 */
	public static function onBeforeInitialize() {
		global $wgUploadDirectory;
		if ( file_exists( "$wgUploadDirectory/wikivideos" ) ) {
			return;
		}
		mkdir( "$wgUploadDirectory/wikivideos" );
		mkdir( "$wgUploadDirectory/wikivideos/videos" );
		mkdir( "$wgUploadDirectory/wikivideos/scenes" );
		mkdir( "$wgUploadDirectory/wikivideos/remote" );
		mkdir( "$wgUploadDirectory/wikivideos/audios" );
		mkdir( "$wgUploadDirectory/wikivideos/tracks" );
	}
}
