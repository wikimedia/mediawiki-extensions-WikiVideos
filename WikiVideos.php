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
		global $wgUploadDirectory;

		// This completes the extension installation
		if ( !file_exists( "$wgUploadDirectory/wikivideos" ) ) {
			mkdir( "$wgUploadDirectory/wikivideos" );
			mkdir( "$wgUploadDirectory/wikivideos/videos" );
			mkdir( "$wgUploadDirectory/wikivideos/scenes" );
			mkdir( "$wgUploadDirectory/wikivideos/remote" );
			mkdir( "$wgUploadDirectory/wikivideos/audios" );
			mkdir( "$wgUploadDirectory/wikivideos/tracks" );
			$blackPixel = imagecreatetruecolor( 1, 1 );
			imagejpeg( $blackPixel, "$wgUploadDirectory/wikivideos/black-pixel.jpg" );
		}

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

		$videoContents = self::normalizeVideoContents( $input );
		$videoOptions = self::normalizeVideoOptions( $args );
		$videoID = self::getVideo( $videoContents, $videoOptions, $parser );
		if ( !$videoID ) {
			return Html::element( 'div', [ 'class' => 'error' ], wfMessage( 'wikivideos-error' ) );
		}

		// Attributes
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
			'poster' => self::getPoster( $videoContents, $videoOptions ),
		];
		$attribs = array_filter( $attribs );

		// Captions
		$captions = $args['captions'] ?? $wgWikiVideosCaptions;
		$trackID = self::getTrack( $videoContents, $videoOptions, $parser );
		$track = Html::element( 'track', [
			'default' => $captions ? true : false,
			'kind' => 'captions',
			'src' => "$wgUploadPath/wikivideos/tracks/$trackID.vtt"
		] );

		// Main tag
		$html = Html::rawElement( 'video', $attribs, $track );

		// Chapters
		$chapters = $args['chapters'] ?? $wgWikiVideosChapters;
		if ( $chapters ) {
			$html .= self::getChapters( $videoContents, $videoOptions, $parser );
		}

		$html = Html::rawElement( 'div', [ 'class' => 'wikivideo-wrapper' ], $html );
		return $html;
	}

	/**
	 * Normalize video contents
	 * 
	 * This is important because making videos is very expensive
	 * so we'll make the video ID depend on the normalized video contents and options
	 * to avoid regenerating videos when trivial edits are made (like spacing changes).
	 * Normalized video content and options also make the code
	 * for generating videos infinitely simpler.
	 * 
	 * @param string $input User input
	 * @return array Normalized video contents
	 */
	private static function normalizeVideoContents( string $input ) {
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
	 * Normalize video options
	 *
	 * This is important because making videos is expensive
	 * so we'll make the video ID depend on the normalized video contents and options
	 * to avoid regenerating videos when trivial edits are made (like spacing changes).
	 * Normalized video content and options also make the code
	 * for generating videos infinitely simpler.
	 *
	 * @param array $args User supplied arguments
	 * @return array Normalized video options
	 */
	private static function normalizeVideoOptions( array $args ) {
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
	 * Make video file out normalized <wikivideo> contents and options
	 * 
	 * We build the final video by concatenating many individual minivideos or "scenes"
	 * Each scene is made up of a single file (or no file, in which case a single black pixel is used)
	 * displayed while the corresponding text is read aloud by a text-to-speech service (so far only Google's)
	 * This strategy of building videos out of scenes is mainly to support the use of mixed file types
	 * because there's no valid ffmpeg command that will make a video out of a soup of mixed file types
	 * 
	 * @param array $contents Normalized <wikivideo> contents
	 * @param array $options Normalized <wikivideo> options
	 * @param Parser $parser
	 * @return string ID of the video file
	 */
	private static function getVideo( array $contents, array $options, Parser $parser ) {
		global $wgUploadDirectory,
			$wgTmpDirectory,
			$wgFFmpegLocation,
			$wgWikiVideosMaxVideoSize;

		// Identify videos based on their normalized content and options
		// so if nothing changes, we don't regenerate them
		$videoID = md5( json_encode( [ $contents, $options ] ) );
		$videoPath = "$wgUploadDirectory/wikivideos/videos/$videoID.webm";
		if ( file_exists( $videoPath ) ) {
			return $videoID;
		}

		// Make silent audio to add before and after each audio
		// @todo Make duration configurable per-scene via inline parameters
		$silentAudioDuration = 0.5;
		$silentAudioID = self::getSilentAudio( $silentAudioDuration );
		$silentAudioPath = "$wgUploadDirectory/wikivideos/audios/$silentAudioID.mp3";

		// Make scenes
		$scenes = [];
		foreach ( $contents as $content ) {
			$file = $content[0] ?? '';
			$text = $content[1] ?? '';

			$fileID = 'black-pixel';
			$filePath = "$wgUploadDirectory/wikivideos/$fileID.jpg";
			if ( $file ) {
				$fileTitle = Title::newFromText( $file, NS_FILE );
				$fileObject = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $fileTitle );
				if ( $fileObject ) {
					$fileID = $fileObject->getSha1();
					$fileRel = $fileObject->getRel();
					$filePath = "$wgUploadDirectory/$fileRel";
				} else {
					$fileID = self::getRemoteFile( $fileTitle );
					$filePath = "$wgUploadDirectory/wikivideos/remote/$fileID";
				}
			}

			$audioID = self::getAudio( $text, $options, $parser );
			$audioPath = "$wgUploadDirectory/wikivideos/audios/$audioID.mp3";

			$sceneID = md5( json_encode( [ $fileID, $audioID ] ) );
			$scenePath = "$wgUploadDirectory/wikivideos/scenes/$sceneID.webm";
			$scenes[] = $scenePath;
			if ( file_exists( $scenePath ) ) {
				continue;
			}

			// Make the scene by displaying the image for the duration of the audio
			// plus a bit of silence before and after
			$sceneConcatFile = "$wgTmpDirectory/$sceneID.txt";
			$sceneConcatText = "file $silentAudioPath" . PHP_EOL;
			$sceneConcatText .= "file $audioPath" . PHP_EOL;
			$sceneConcatText .= "file $silentAudioPath" . PHP_EOL;
			file_put_contents( $sceneConcatFile, $sceneConcatText );
			$command = "$wgFFmpegLocation -y -safe 0 -f concat -i $sceneConcatFile -i '$filePath' -vsync vfr -pix_fmt yuv420p -filter:v 'scale=min($wgWikiVideosMaxVideoSize\,min(iw\,round($wgWikiVideosMaxVideoSize*iw/ih))):-2' $scenePath";
			//echo $command; exit; // Uncomment to debug
			exec( $command, $output );
			//var_dump( $output ); exit; // Uncomment to debug
			unlink( $sceneConcatFile ); // Clean up
		}

		// Make final video
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

		// Clean up and return
		unlink( $videoConcatFile );
		return $videoID;
	}

	/**
	 * Make track file (subtitles) out normalized <wikivideo> contents
	 * 
	 * @param array $contents Normalized <wikivideo> contents
	 * @param array $options Normalized <wikivideo> options
	 * @param Parser $parser
	 * @return string ID of the track file
	 */
	private static function getTrack( array $contents, array $options, Parser $parser ) {
		global $wgUploadDirectory;

		// Identify tracks based on their content
		// so if nothing changes, we don't regenerate them
		$trackID = md5( json_encode( [ $contents, $options ] ) );
		$trackPath = "$wgUploadDirectory/wikivideos/tracks/$trackID.vtt";
		if ( file_exists( $trackPath ) ) {
			return $trackID;
		}

		// Make the track file
		$trackText = 'WEBVTT';
		$timeElapsed = 0;
		foreach ( $contents as $content ) {
			$sceneDuration = self::getSceneDuration( $content, $options, $parser );
			$text = $content[1] ?? '';
			if ( $text ) {
				$text = self::getPlainText( $text, $parser );
				$trackStart = $timeElapsed;
				$trackEnd = $timeElapsed + $sceneDuration;
				$trackText .= PHP_EOL . PHP_EOL;
				$trackText .= date( 'i:s.v', $trackStart );
				$trackText .= ' --> ';
				$trackText .= date( 'i:s.v', $trackEnd );
				$trackText .= PHP_EOL . $text;
			}
			$timeElapsed += $sceneDuration;
		}
		file_put_contents( $trackPath, $trackText );
		return $trackID;
	}

	/**
	 * Get the duration of a scene
	 * 
	 * The scene duration is determined by the audio duration
	 * or by the file duration (whichever is longer)
	 * 
	 * @param array $content Text content of the scene
	 * @param array $options Normalized options of the <wikivideo> tag
	 * @param Parser $parser
	 * @return float Duration of the scene, in seconds
	 */
	private static function getSceneDuration( array $content, array $options, Parser $parser ) {
		global $wgUploadDirectory, $wgFFprobeLocation;

		$file = $content[0] ?? '';
		$fileDuration = 0;
		if ( $file ) {
			$fileTitle = Title::newFromText( $file, NS_FILE );
			$fileObject = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $fileTitle );
			if ( $fileObject ) {
				$fileRel = $fileObject->getRel();
				$filePath = "$wgUploadDirectory/$fileRel";
			} else {
				$fileKey = $fileTitle->getDBKey();
				$filePath = "$wgUploadDirectory/wikivideos/remote/$fileKey";
			}
			$fileDuration = exec( "$wgFFprobeLocation -i $filePath -show_format -v quiet | sed -n 's/duration=//p'" );
			if ( $fileDuration === 'N/A' ) {
				$fileDuration = 0;
			}
		}

		$text = $content[1] ?? '';
		$audioDuration = 0;
		if ( $text ) {
			$audioID = self::getAudio( $text, $options, $parser );
			$audioPath = "$wgUploadDirectory/wikivideos/audios/$audioID.mp3";
			$audioDuration = exec( "$wgFFprobeLocation -i $audioPath -show_format -v quiet | sed -n 's/duration=//p'" );
		}

		$silentAudioDuration = 0.5; // @todo Shouldn't be hardcoded
		$sceneDuration = $silentAudioDuration + $audioDuration + $silentAudioDuration;
		if ( $fileDuration > $audioDuration ) {
			$sceneDuration = $fileDuration;
		}

		return $sceneDuration;
	}

	/**
	 * Make a silent audio file
	 * 
	 * @param float $duration Duration of the silent audio
	 * @return string ID of the resulting silent audio file
	 */
	private static function getSilentAudio( float $duration ) {
		global $wgUploadDirectory, $wgFFmpegLocation;
		$audioID = md5( $duration );
		$audioPath = "$wgUploadDirectory/wikivideos/audios/$audioID.mp3";
		if ( !file_exists( $audioPath ) ) {
			exec( "$wgFFmpegLocation -f lavfi -i anullsrc=r=44100:cl=mono -t $duration -q:a 9 -acodec libmp3lame $audioPath" );
		}
		return $audioID;
	}

	/**
	 * Convert text to audio using Google's text-to-speech service
	 * 
	 * @param string $text Text to convert
	 * @param array $options Audio options
	 * @param Parser $parser
	 * @return string ID of the audio file
	 */
	private static function getAudio( string $text, array $options, Parser $parser ) {
		global $wgUploadDirectory,
			$wgFFmpegLocation,
			$wgGoogleCloudCredentials,
			$wgGoogleTextToSpeechMaxChars,
			$wgLanguageCode,
			$wgWikiVideosVoiceGender,
			$wgWikiVideosVoiceName;

		// Generate the audio ID from the relevant text and options
		// so if nothing relevant changes, we reuse existing audio
		$text = self::getPlainText( $text, $parser );
		$relevantOptions = [ 'voice-language', 'voice-gender', 'voice-name' ];
		foreach ( $options as $key => $value ) {
			if ( !in_array( $key, $relevantOptions ) ) {
				unset( $key );
			}
		}
		ksort( $options );
		$audioID = md5( json_encode( [ $text, $options ] ) );
		$audioPath = "$wgUploadDirectory/wikivideos/audios/$audioID.mp3";
		if ( file_exists( $audioPath ) ) {
			return $audioID;
		}

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
		file_put_contents( $audioPath, $response->getAudioContent() );

		// Return the id of the audio file
		return $audioID;
	}

	/**
	 * Infer the appropriate video size out of the video contents
	 * 
	 * @param array $contents Normalized video contents
	 * @return array Video width and height
	 */
	private static function getVideoSize( array $contents ) {
		global $wgUploadDirectory,
			$wgWikiVideosMinSize,
			$wgWikiVideosMaxSize;

		$videoWidth = $wgWikiVideosMinSize;
		$videoHeight = $wgWikiVideosMinSize;

		foreach ( $contents as $content ) {
			$file = $content[0] ?? '';
			if ( !$file ) {
				continue;
			}
			$fileTitle = Title::newFromText( $file, NS_FILE );
			$fileObject = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $fileTitle );
			if ( $fileObject ) {
				$fileRel = $fileObject->getRel();
				$filePath = "$wgUploadDirectory/$fileRel";
			} else {
				$fileKey = $fileTitle->getDBKey();
				$filePath = "$wgUploadDirectory/wikivideos/remote/$fileKey";
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
		if ( $videoWidth > $videoHeight && $videoWidth > $wgWikiVideosMaxSize ) {
			$videoWidth = $wgWikiVideosMaxSize;
			$videoHeight = round( $videoWidth * $videoRatio );
		}
		if ( $videoHeight > $videoWidth && $videoHeight > $wgWikiVideosMaxSize ) {
			$videoHeight = $wgWikiVideosMaxSize;
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
	 * Get video poster out of poster argument
	 * or out of normalized <wikivideo> contents
	 * 
	 * @param array $contents Normalized video contents
	 * @param array $options Normalized video options
	 * @return string Relative URL of the video poster
	 */
	private static function getPoster( array $contents, array $options ) {
		if ( array_key_exists( 'poster', $options ) ) {
			$poster = $options['poster'];
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
	 * Make chapters HTML file
	 * 
	 * @param array $contents Normalized <wikivideo> contents
	 * @param array $options Normalized <wikivideo> options
	 * @param Parser $parser
	 * @return string HTML of the chapters
	 */
	private static function getChapters( array $contents, array $options, Parser $parser ) {
		$list = Html::openElement( 'ol', [ 'class' => 'wikivideo-chapters' ] );
		$seconds = 0;
		foreach ( $contents as $content ) {
			$file = $content[0] ?? '';
			$text = $content[1] ?? '';
			$time = date( 'i:s', $seconds );
			$link = Html::element( 'a', [
				'class' => 'wikivideo-chapter-time',
				'data-seconds' => round( $seconds )
			], $time );
			$html = $parser->recursiveTagParseFully( $text );
			$span = Html::rawElement( 'span', [ 'class' => 'wikivideo-chapter-text' ], $html );
			$item = Html::rawElement( 'li', [ 'class' => 'wikivideo-chapter' ], $link . PHP_EOL . $span );
			$list .= $item;
			$seconds += self::getSceneDuration( $content, $options, $parser );
		}
		$list .= Html::closeElement( 'ol' );
		return $list;
	}

	/**
	 * Get plain text out of wikitext
	 * 
	 * @todo Make more robust
	 * 
	 * @param string $text Wikitext
	 * @param Parser $parser
	 * @return string Plain text
	 */
	private static function getPlainText( string $text, Parser $parser ) {
		$text = preg_replace( '/<ref[^>]*>.*?<\/ref>/is', '', $text );
		$text = $parser->recursiveTagParseFully( $text );
		$text = strip_tags( $text );
		return $text;
	}

	/**
	 * Download file from Commons
	 * 
	 * @param Title $file Title object of the file page
	 * @return string ID of the remote file
	 */
	private static function getRemoteFile( Title $file ) {
		global $wgUploadDirectory, $wgWikiVideosUserAgent;

		$fileID = $file->getDBKey();
		$filePath = "$wgUploadDirectory/wikivideos/remote/$fileID";
		if ( file_exists( $filePath ) ) {
			return $fileID;
		}

		// Get file URL
		// @todo Use internal methods
		// @todo Limit image size by $wgWikiVideosMaxSize
		$commons = new EasyWiki( 'https://commons.wikimedia.org/w/api.php' );
		$params = [
		    'titles' => $file->getFullText(),
		    'action' => 'query',
		    'prop' => 'imageinfo',
		    'iiprop' => 'url'
		];
		$fileURL = $commons->query( $params, 'url' );

		// Download file
		$curl = curl_init( $fileURL );
		$filePointer = fopen( $filePath, 'wb' );
		curl_setopt( $curl, CURLOPT_FILE, $filePointer );
		curl_setopt( $curl, CURLOPT_USERAGENT, $wgWikiVideosUserAgent );
		curl_exec( $curl );
		curl_close( $curl );
		fclose( $filePointer );

		return $fileID;
	}
}
