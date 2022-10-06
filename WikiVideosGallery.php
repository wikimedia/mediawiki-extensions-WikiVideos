<?php

/**
 * WikiVideos are currently implemented as a type of gallery
 * to take advantage of the gallery dialog provided by the visual editor
 * and the native parsing of gallery contents provided by MediaWiki.
 * However, in the future they may get their own <wikivideo> tag
 */

class WikiVideosGallery extends ImageGalleryBase {

	/**
	 * Register additional gallery options along with their defaults
	 */
    public function setAdditionalOptions( $options ) {
        global $wgWikiVideosControls,
        	$wgWikiVideosAutoplay,
        	$wgWikiVideosCaptions,
        	$wgWikiVideosChapters,
        	$wgWikiVideosVoiceLanguage,
        	$wgWikiVideosVoiceGender,
        	$wgWikiVideosVoiceName;
        $this->mAttribs['width'] = $options['width'] ?? null;
        $this->mAttribs['height'] = $options['height'] ?? null;
        $this->mAttribs['controls'] = $options['controls'] ?? $wgWikiVideosControls;
        $this->mAttribs['autoplay'] = $options['autoplay'] ?? $wgWikiVideosAutoplay;
        $this->mAttribs['captions'] = $options['captions'] ?? $wgWikiVideosCaptions;
        $this->mAttribs['chapters'] = $options['chapters'] ?? $wgWikiVideosChapters;
        $this->mAttribs['voice-language'] = $options['voice-language'] ?? $wgWikiVideosVoiceLanguage;
        $this->mAttribs['voice-gender'] = $options['voice-gender'] ?? $wgWikiVideosVoiceGender;
        $this->mAttribs['voice-name'] = $options['voice-name'] ?? $wgWikiVideosVoiceName;
    }

	/**
	 * Output the main <video> tag and supporting HTML
	 */
    public function toHTML() {
        global $wgUploadPath;

		// Set useful variables
		$parser = $this->mParser;
		$images = $this->mImages;
		$attribs = $this->mAttribs;

		// Make video file
		$videoID = WikiVideosFactory::makeVideo( $images, $attribs, $parser );
		if ( !$videoID ) {
			return Html::element( 'div', [ 'class' => 'error' ], wfMessage( 'wikivideos-error' ) );
		}

		// Define video tag attributes
		$videoSize = WikiVideosFactory::getVideoSize( $images );
		$videoWidth = $videoSize[0];
		$videoHeight = $videoSize[1];
		$videoTagAttribs = [
			'id' => $videoID,
			'src' => "$wgUploadPath/wikivideos/videos/$videoID.webm",
			'class' => 'wikivideo',
			'width' => $attribs['width'] ?? ( $videoWidth > $videoHeight ? $videoWidth : 'auto' ),
			'height' => $attribs['height'] ?? ( $videoHeight > $videoWidth ? $videoHeight : 'auto' ),
			'controls' => $attribs['controls'],
			'autoplay' => $attribs['autoplay'],
			'poster' => WikiVideosFactory::getVideoPoster( $attribs, $images ),
		];

		// Make track tag
		$captions = $attribs['captions'];
		$trackID = WikiVideosFactory::makeTrack( $images, $attribs, $parser );
		$track = Html::element( 'track', [
			'default' => $captions ? true : false,
			'kind' => 'captions',
			'src' => "$wgUploadPath/wikivideos/tracks/$trackID.vtt"
		] );

		// Make main video tag
		$html = Html::rawElement( 'video', $videoTagAttribs, $track );

		// Make chapters list
		$chapters = $attribs['chapters'];
		if ( $chapters ) {
			$html .= WikiVideosFactory::makeChapters( $images, $attribs, $parser );
		}

		// Wrap everything and return
		$html = Html::rawElement( 'div', [ 'class' => 'wikivideo-wrapper' ], $html );
		return $html;
    }
}