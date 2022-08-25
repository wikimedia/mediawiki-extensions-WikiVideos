<?php

use MediaWiki\MediaWikiServices;
use Google\Cloud\TextToSpeech\V1\AudioConfig;
use Google\Cloud\TextToSpeech\V1\AudioEncoding;
use Google\Cloud\TextToSpeech\V1\SynthesisInput;
use Google\Cloud\TextToSpeech\V1\TextToSpeechClient;
use Google\Cloud\TextToSpeech\V1\VoiceSelectionParams;
use Sophivorus\EasyWiki;

class WikiVideos {

	/**
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		$out->addModules( 'ext.WikiVideos' );
		$out->addModuleStyles( 'ext.WikiVideos.styles' );
	}

	/**
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'wikivideo', [ self::class, 'onWikiVideoTag' ] );
	}

	/**
	 * @param string $input User input
	 * @param array $args User supplied arguments
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return HTML of the wikivideo
	 */
	public static function onWikiVideoTag( $input, array $args, Parser $parser, PPFrame $frame ) {
		global $wgUploadPath,
			$wgWikiVideosControls,
			$wgWikiVideosAutoplay,
			$wgWikiVideosCaptions,
			$wgWikiVideosChapters;

		$videoContents = self::getVideoContents( $input );
		$videoOptions = self::getVideoOptions( $args );
		$videoID = self::getVideoID( $videoContents, $videoOptions );
		if ( !$videoID ) {
			return Html::element( 'div', [ 'class' => 'error' ], wfMessage( 'wikivideos-error' ) );
		}
		$videoSize = self::getVideoSize( $videoContents );
		$videoWidth = $videoSize[0];
		$videoHeight = $videoSize[1];
		$attribs = [
			'id' => $videoID,
			'src' => "$wgUploadPath/wikivideos/videos/$videoID.webm",
			'class' => 'wikivideo',
			'width' => $args['width'] ?? ( $videoWidth > $videoHeight ? $videoWidth : 'auto' ),
			'height' => $args['height'] ?? ( $videoHeight > $videoWidth ? $videoHeight : 'auto' ),
			'controls' => $args['controls'] ?? $wgWikiVideosControls,
			'autoplay' => $args['autoplay'] ?? $wgWikiVideosAutoplay,
			'poster' => self::getPoster( $videoContents, $args ),
		];
		$attribs = array_filter( $attribs );
		$captions = $args['captions'] ?? $wgWikiVideosCaptions;
		$tracks = Html::element( 'track', [
			'default' => $captions ? true : false,
			'kind' => 'captions',
			'src' => "$wgUploadPath/wikivideos/tracks/$videoID.vtt"
		] );
		$chapters = $args['chapters'] ?? $wgWikiVideosChapters;
		$tracks .= Html::element( 'track', [
			'default' => $chapters ? true : false,
			'kind' => 'chapters',
			'src' => "$wgUploadPath/wikivideos/tracks/$videoID.vtt"
		] );
		$html = Html::rawElement( 'video', $attribs, $tracks );
		if ( $chapters ) {
			$html .= self::getChaptersHTML( $videoID );
		}
		$html = Html::rawElement( 'div', [ 'class' => 'wikivideo-wrapper' ], $html );
		return $html;
	}

	/**
	 * Sanitize user input
	 * 
	 * @param string $input User input
	 * @return array Sanitized video contents
	 */
	public static function getVideoContents( $input ) {
		$videoContents = [];
		$input = explode( PHP_EOL, $input );
		$input = array_filter( $input ); // Remove empty lines
		foreach ( $input as $line ) {
			$params = explode( '|', $line );
			$params = array_filter( $params ); // Remove empty params
			$normalizedParams = [];
			foreach ( $params as $param ) {
				$parts = explode( '=', $param );
				$name = array_key_exists( 1, $parts ) ? trim( $parts[0] ) : null;
				$value = $name ? trim( $parts[1] ) : trim( $parts[0] );
				if ( !$value ) {
					continue;
				}
				if ( !$name ) {
					// @todo Make more robust
					if ( preg_match( '/\.[a-zA-Z0-9]+$/', $value ) ) {
						$name = 0;
						$title = Title::newFromText( $value, NS_FILE );
						$value = $title->getFullText();
					} else {
						$name = 1;
					}
				}
				$name = trim( strtolower( $name ) );
				$value = trim( $value );
				$normalizedParams[ $name ] = $value;
			}
			ksort( $normalizedParams );
			$videoContents[] = $normalizedParams;
		}
		return $videoContents;
	}

	/**
	 * Sanitize user arguments
	 * 
	 * @param array $args User supplied arguments
	 * @return array Sanitized video options
	 */
	public static function getVideoOptions( $args ) {
		$videoOptions = [];
		$validOptions = [ 'voice-language', 'voice-gender', 'voice-name' ];
		foreach ( $args as $name => $value ) {
			if ( in_array( $name, $validOptions ) ) {
				$videoOptions[ $name ] = $value;
			}
		}
		ksort( $videoOptions );
		return $videoOptions;
	}

	/**
	 * Convert video contents and options into video
	 * 
	 * We build the video by concatenating many individual minivideos or "scenes"
	 * Each scene is made up of a single file (or no file, in which case a single black pixel is used)
	 * displayed while the corresponding text is read aloud by a text-to-speech service (so far only Google's)
	 * This strategy of building the video out of many small ones is mainly to support the use of mixed file types
	 * because there's no valid ffmpeg command that will take a soup of mixed file types and make a video
	 * 
	 * @param array $contents Video contents
	 * @param array $options Video options
	 * @return string ID of the resulting WEBM file
	 */
	public static function getVideoID( $contents, $options = [] ) {
		global $wgUploadDirectory,
			$wgTmpDirectory,
			$wgFFmpegLocation,
			$wgFFprobeLocation,
			$wgWikiVideosUserAgent,
			$wgWikiVideosMaxVideoWidth,
			$wgWikiVideosMaxVideoHeight;

		// This runs only the first time a wikivideo is created (completes the installation)
		if ( !file_exists( "$wgUploadDirectory/wikivideos" ) ) {
			mkdir( "$wgUploadDirectory/wikivideos" );
			mkdir( "$wgUploadDirectory/wikivideos/videos" );
			mkdir( "$wgUploadDirectory/wikivideos/scenes" );
			mkdir( "$wgUploadDirectory/wikivideos/images" );
			mkdir( "$wgUploadDirectory/wikivideos/audios" );
			mkdir( "$wgUploadDirectory/wikivideos/tracks" );
			$blackPixel = imagecreatetruecolor( 1, 1 );
			imagejpeg( $blackPixel, "$wgUploadDirectory/wikivideos/black-pixel.jpg" );
			file_put_contents( "$wgUploadDirectory/wikivideos/google-text-to-speech-charcount", 0 );
		}

		// Videos are identified by their normalized contents and options
		$videoID = md5( json_encode( [ $contents, $options ] ) );
		$videoPath = "$wgUploadDirectory/wikivideos/videos/$videoID.webm";
		if ( file_exists( $videoPath ) ) {
			return $videoID;
		}

		// Store the scenes for later
		$scenes = [];

		// We'll use this loop to build the track file too (subtitles)
		$trackText = 'WEBVTT';
		$timeElapsed = 0;

		// We'll add a bit of silence before and after each audio
		$silenceDuration = 0.5;
		$silenceID = self::getAudioID( $silenceDuration );
		$silencePath = "$wgUploadDirectory/wikivideos/audios/$silenceID.mp3";

		foreach ( $contents as $param ) {
			$file = $param[0] ?? '';
			$text = $param[1] ?? '';

			if ( $file ) {
				$fileTitle = Title::newFromText( $file, NS_FILE );
				$fileObject = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $fileTitle );
				if ( $fileObject ) {
					$fileID = $fileObject->getSha1();
					$filePath = "$wgUploadDirectory/" . $fileObject->getRel();
				} else {
					$fileID = $fileTitle->getDBKey();
					$filePath = "$wgUploadDirectory/wikivideos/images/$fileID";
					if ( !file_exists( $filePath ) ) {
	
						// Get the file URL
						// @todo Use internal methods
						// @todo Limit size of images by $wgWikiVideosMaxVideoWidth and $wgWikiVideosMaxVideoHeight
						$commons = new EasyWiki( 'https://commons.wikimedia.org/w/api.php' );
						$params = [
						    'titles' => $file,
						    'action' => 'query',
						    'prop' => 'imageinfo',
						    'iiprop' => 'url'
						];
						$fileURL = $commons->query( $params, 'url' );
	
						// Download the file
						$curl = curl_init( $fileURL );
						$filePointer = fopen( $filePath, 'wb' );
						curl_setopt( $curl, CURLOPT_FILE, $filePointer );
						curl_setopt( $curl, CURLOPT_HEADER, 0 );
						curl_setopt( $curl, CURLOPT_USERAGENT, $wgWikiVideosUserAgent );
						curl_exec( $curl );
						curl_close( $curl );
						fclose( $filePointer );
					}
				}
			} else {
				$fileID = 'black-pixel';
				$filePath = "$wgUploadDirectory/wikivideos/$fileID.jpg";
			}

			$audioID = self::getAudioID( $text, $options );
			$audioPath = "$wgUploadDirectory/wikivideos/audios/$audioID.mp3";
			$audioDuration = exec( "$wgFFprobeLocation -i $audioPath -show_format -v quiet | sed -n 's/duration=//p'" );

			if ( $text ) {
				$trackStart = $timeElapsed + $silenceDuration;
				$trackEnd = $timeElapsed + $audioDuration - $silenceDuration;
				$trackText .= PHP_EOL . PHP_EOL;
				$trackText .= date( 'i:s.v', $trackStart );
				$trackText .= ' --> ';
				$trackText .= date( 'i:s.v', $trackEnd );
				$trackText .= PHP_EOL . $text;
			}
			$timeElapsed += $silenceDuration + $audioDuration + $silenceDuration;

			$sceneID = md5( json_encode( [ $fileID, $audioID ] ) );
			$scenePath = "$wgUploadDirectory/wikivideos/scenes/$sceneID.webm";
			$scenes[] = $scenePath;
			if ( file_exists( $scenePath ) ) {
				continue;
			}

			// Make the scene by displaying the image for the duration of the audio
			// plus a bit of silence before and after
			$sceneConcatFile = "$wgTmpDirectory/$sceneID.txt";
			$sceneConcatText = "file $silencePath" . PHP_EOL;
			$sceneConcatText .= "file $audioPath" . PHP_EOL;
			$sceneConcatText .= "file $silencePath" . PHP_EOL;
			file_put_contents( $sceneConcatFile, $sceneConcatText );
			// @todo Scaling should depend on $wgWikiVideosMaxVideoWidth and $wgWikiVideosMaxVideoHeight
			$command = "$wgFFmpegLocation -y -safe 0 -f concat -i $sceneConcatFile -i $filePath -vsync vfr -pix_fmt yuv420p -filter:v 'scale=min(1280\,min(iw\,round(1280*iw/ih))):-2' $scenePath";
			//echo $command; exit; // Uncomment to debug
			exec( $command, $output );
			//var_dump( $output ); exit; // Uncomment to debug
			unlink( $sceneConcatFile ); // Clean up
		}

		// Make the track file
		$trackFile = "$wgUploadDirectory/wikivideos/tracks/$videoID.vtt";
		file_put_contents( $trackFile, $trackText );

		// Make the video file
		$videoSize = self::getVideoSize( $contents );
		$videoWidth = $videoSize[0];
		$videoHeight = $videoSize[1];
		$videoConcatFile = "$wgTmpDirectory/$videoID.txt";
		$videoConcatText = '';
		foreach ( $scenes as $scene ) {
			$videoConcatText .= "file $scene" . PHP_EOL;
		}
		file_put_contents( $videoConcatFile, $videoConcatText );
		$command = "$wgFFmpegLocation -y -safe 0 -f concat -i $videoConcatFile -max_muxing_queue_size 9999 -filter:v 'scale=iw*min($videoWidth/iw\,$videoHeight/ih):ih*min($videoWidth/iw\,$videoHeight/ih), pad=$videoWidth:$videoHeight:($videoWidth-iw*min($videoWidth/iw\,$videoHeight/ih))/2:($videoHeight-ih*min($videoWidth/iw\,$videoHeight/ih))/2' $videoPath";
		//echo $command; exit; // Uncomment to debug
		exec( $command, $output );
		//var_dump( $output ); exit; // Uncomment to debug
		unlink( $videoConcatFile ); // Clean up

		return $videoID;
	}

	/**
	 * Convert text into audio using Google's text-to-speech service
	 * 
	 * @param string $text Text to convert
	 * @return string Absolute path to the resulting MP3 file
	 */
	public static function getAudioID( $text, $options = [] ) {
		global $wgUploadDirectory,
			$wgFFmpegLocation,
			$wgGoogleCloudCredentials,
			$wgGoogleTextToSpeechMaxChars,
			$wgLanguageCode,
			$wgWikiVideosVoiceGender,
			$wgWikiVideosVoiceName;

		if ( !$text ) {
			return;
		}

		// If the text is just a number
		// make a silent MP3 of that many seconds
		if ( is_numeric( $text ) ) {
			$audioID = md5( $text );
			$audioFile = "$wgUploadDirectory/wikivideos/audios/$audioID.mp3";
			if ( !file_exists( $audioFile ) ) {
				exec( "$wgFFmpegLocation -f lavfi -i anullsrc=r=44100:cl=mono -t $text -q:a 9 -acodec libmp3lame $audioFile" );
			}
			return $audioID;
		}

		// Generate the audio ID from the relevant options
		// so if anything relevant changes, we make a new audio
		// but if not we reuse the old one, saving requests
		$relevantOptions = [ 'voice-language', 'voice-gender', 'voice-name' ];
		foreach ( $options as $key => $value ) {
			if ( !in_array( $key, $relevantOptions ) ) {
				unset( $key );
			}
		}
		ksort( $options );
		$audioID = md5( json_encode( [ $text, $options ] ) );
		$audioFile = "$wgUploadDirectory/wikivideos/audios/$audioID.mp3";
		if ( file_exists( $audioFile ) ) {
			return $audioID;
		}

		// Keep track of the translated characters
		$chars = file_get_contents( "$wgUploadDirectory/wikivideos/google-text-to-speech-charcount" );
		$chars += strlen( $text );
		if ( $chars > $wgGoogleTextToSpeechMaxChars ) {
			return;
		}
		file_put_contents( "$wgUploadDirectory/wikivideos/google-text-to-speech-charcount", $chars );

		// Figure out the preferred voice
		$voiceLanguage = $options['voice-language'] ?? $wgLanguageCode; // @todo Use page language instead
		$voiceName = $options['voice-name'] ?? $wgWikiVideosVoiceName;
		$voiceGender = $options['voice-gender'] ?? $wgWikiVideosVoiceGender;
		switch ( strtolower( $voiceGender ) ) {
			case 'male': // @todo i18n
				$voiceGender = 1;
			case 'female':
				$voiceGender = 2;
		}

		// Do the request to the Google Text-to-Speech API
		$GoogleTextToSpeechClient = new TextToSpeechClient( [
			'credentials' => $wgGoogleCloudCredentials
		] );
		$input = new SynthesisInput();
		$input->setText( $text );
		$voice = new VoiceSelectionParams();
		$voice->setLanguageCode( $voiceLanguage );
		if ( $voiceGender ) {
			$voice->setSsmlGender( $voiceGender );
		}
		if ( $voiceName ) {
			$voice->setName( $voiceName );
		}
		$audioConfig = new AudioConfig();
		$audioConfig->setAudioEncoding( AudioEncoding::MP3 );
		$response = $GoogleTextToSpeechClient->synthesizeSpeech( $input, $voice, $audioConfig );

		// Save the audio file
		file_put_contents( $audioFile, $response->getAudioContent() );

		// Return the path to the audio file
		return $audioID;
	}

	/**
	 * Infer the appropriate video size out of the video contents
	 * 
	 * @param array $contents Video contents
	 * @return array Video Width and height
	 */
	public static function getVideoSize( $contents ) {
		global $wgUploadDirectory,
			$wgWikiVideosMinWidth,
			$wgWikiVideosMinHeight,
			$wgWikiVideosMaxWidth,
			$wgWikiVideosMaxHeight;

		$videoWidth = $wgWikiVideosMinWidth;
		$videoHeight = $wgWikiVideosMinHeight;
		foreach ( $contents as $content ) {
			$file = $content[0] ?? '';
			if ( $file ) {
				$fileTitle = Title::newFromText( $file, NS_FILE );
				$fileObject = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $fileTitle );
				if ( $fileObject ) {
					$filePath = "$wgUploadDirectory/" . $fileObject->getRel();
				} else {
					$filePath = "$wgUploadDirectory/wikivideos/images/" . $fileTitle->getDBKey();
				}
			} else {
				$filePath = "$wgUploadDirectory/wikivideos/black-pixel.jpg";
			}
			$imageSize = getimagesize( $filePath );
			$imageWidth = $imageSize[0];
			$imageHeight = $imageSize[1];
			if ( $imageWidth > $videoWidth ) {
				$videoWidth = $imageWidth;
			}
			if ( $imageHeight > $videoHeight ) {
				$videoHeight = $imageHeight;
			}
		}
		$videoRatio = $videoWidth / $videoHeight;
		if ( $videoWidth > $videoHeight && $videoWidth > $wgWikiVideosMaxWidth ) {
			$videoWidth = $wgWikiVideosMaxWidth;
			$videoHeight = round( $videoWidth * $videoRatio );
		}
		if ( $videoHeight > $videoWidth && $videoHeight > $wgWikiVideosMaxHeight ) {
			$videoHeight = $wgWikiVideosMaxHeight;
			$videoWidth = round( $videoHeight * $videoRatio );
		}
		if ( $videoWidth % 2 ) {
			$videoWidth--;
		}
		if ( $videoHeight % 2 ) {
			$videoHeight--;
		}
		return [ $videoWidth, $videoHeight ];
	}

	/**
	 * Get the video poster out of the user supplied arguments
	 * or out of the video contents
	 * 
	 * @param array $contents Video contents
	 * @param array $args User supplied arguments
	 * @return string Relative URL of the video poster
	 */
	public static function getPoster( $contents, $args ) {
		if ( array_key_exists( 'poster', $args ) ) {
			$poster = $args['poster'];
		} else {
			foreach ( $contents as $content ) {
				if ( array_key_exists( 0, $content ) ) {
					$poster = $content[0];
					break;
				}
			}
		}
		$title = Title::newFromText( $poster, NS_FILE );
		$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $title );
		if ( $file ) {
			return $file->getUrl();
		}
	}

	/**
	 * Get the HTML of the chapters list for the given video ID
	 * 
	 * Someday browsers may provide a native interface for navigating chapters
	 * and this interface may become somewhat redundant
	 * but printing the full text also makes it easy to browse
	 * makes it indexable by search engines
	 * and adds some accessibility support
	 * 
	 * @param string $videoID Video ID
	 * @return string HTML of the chapters list
	 */
	public static function getChaptersHTML( $videoID ) {
		global $wgUploadDirectory;
		$track = "$wgUploadDirectory/wikivideos/tracks/$videoID.vtt";
		if ( !file_exists( $track ) ) {
			return;
		}
		$list = Html::openElement( 'ol', [ 'class' => 'wikivideo-chapters' ] );
		$lines = file( $track, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		array_shift( $lines ); // Skip "WEBVTT"
		while ( $lines ) {
			$times = array_shift( $lines );
			preg_match( '/^(\d+):(\d+)/', $times, $matches );
			$time = $matches[0];
			$minutes = $matches[1];
			$seconds = $matches[2];
			$seconds += $minutes * 60;
			$link = Html::element( 'a', [
				'class' => 'wikivideo-chapter-time',
				'data-seconds' => $seconds
			], $time );
			$text = array_shift( $lines );
			if ( preg_match( '/^(\d+):(\d+)/', $text ) ) {
				array_unshift( $lines, $text );
				$text = null;
			} else {
				$text = Html::element( 'span', [ 'class' => 'wikivideo-chapter-text' ], $text );
			}
			$item = Html::rawElement( 'li', [ 'class' => 'wikivideo-chapter' ], $link . PHP_EOL . $text );
			$list .= $item;
		}
		$list .= Html::closeElement( 'ol' );
		return $list;
	}
}
