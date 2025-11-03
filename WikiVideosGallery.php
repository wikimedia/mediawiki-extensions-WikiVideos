<?php

use MediaWiki\Html\Html;

/**
 * WikiVideos are currently implemented as a type of gallery
 * to take advantage of the gallery dialog provided by the visual editor
 * and the native parsing of gallery contents provided by MediaWiki.
 * However, in the future they may get their own <wikivideo> tag
 */
class WikiVideosGallery extends ImageGalleryBase {

	/**
	 * Register additional gallery options along with their defaults
	 *
	 * @param array $options
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
		$this->mAttribs['ken-burns-effect'] = $options['ken-burns-effect'] ?? false;
	}

	/**
	 * Output the main <video> tag and supporting HTML
	 *
	 * @return string
	 */
	public function toHTML(): string {
		// Set basic variables
		$parser = $this->mParser;
		$images = $this->mImages;
		$attribs = $this->mAttribs;

		// Make video file
		$videoPath = WikiVideosFactory::makeVideo( $images, $attribs, $parser );
		if ( !$videoPath ) {
			return Html::element( 'div', [ 'class' => 'error' ], wfMessage( 'wikivideos-error' ) );
		}

		// Set video tag attributes
		$videoSize = WikiVideosFactory::getVideoSize( $images );
		$videoWidth = $videoSize[0];
		$videoHeight = $videoSize[1];
		$videoTagAttribs = [
			'src' => $videoPath,
			'class' => 'wikivideo',
			'width' => $attribs['width'] ?? ( $videoWidth > $videoHeight ? $videoWidth : 'auto' ),
			'height' => $attribs['height'] ?? ( $videoHeight > $videoWidth ? $videoHeight : 'auto' ),
			'controls' => $attribs['controls'],
			'autoplay' => $attribs['autoplay'],
			'poster' => WikiVideosFactory::getVideoPoster( $attribs, $images ),
		];

		// Make track tag
		$captions = $attribs['captions'];
		$trackPath = WikiVideosFactory::makeTrack( $images, $attribs, $parser );
		$track = Html::element( 'track', [
			'default' => (bool)$captions,
			'kind' => 'captions',
			'src' => $trackPath
		] );

		// Make video tag
		$html = Html::rawElement( 'video', $videoTagAttribs, $track );

		// Make chapters list
		$chapters = $attribs['chapters'];
		if ( $chapters ) {
			$html .= Html::openElement( 'ol', [ 'class' => 'wikivideo-chapters' ] );
			$seconds = 0;
			foreach ( $images as [ $imageTitle, $imageText ] ) {
				$time = date( 'i:s', floor( $seconds ) );
				$link = Html::element( 'a', [ 'class' => 'wikivideo-chapter-time', 'data-seconds' => round( $seconds ) ], $time );
				$span = Html::rawElement( 'span', [ 'class' => 'wikivideo-chapter-text' ], $imageText );
				$item = Html::rawElement( 'li', [ 'class' => 'wikivideo-chapter' ], $link . PHP_EOL . $span );
				$html .= $item;
				$seconds += WikiVideosFactory::getSceneDuration( $imageTitle, $imageText, $attribs, $parser );
			}
			$html .= Html::closeElement( 'ol' );
		}

		// Make wrapper and return
		$html = Html::rawElement( 'div', [ 'class' => 'wikivideo-wrapper' ], $html );
		return $html;
	}
}
