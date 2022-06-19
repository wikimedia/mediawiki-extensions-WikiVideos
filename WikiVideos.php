<?php

use MediaWiki\MediaWikiServices;
use Google\Cloud\TextToSpeech\V1\AudioConfig;
use Google\Cloud\TextToSpeech\V1\AudioEncoding;
use Google\Cloud\TextToSpeech\V1\SynthesisInput;
use Google\Cloud\TextToSpeech\V1\TextToSpeechClient;
use Google\Cloud\TextToSpeech\V1\VoiceSelectionParams;

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
		$parser->setHook( 'wikivideo', [ self::class, 'onWikivideoTag' ] );
	}

	/**
	 * @param string $input User input
	 * @param array $args User supplied arguments
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return HTML of the wikivideo
	 */
	public static function onWikivideoTag( $input, array $args, Parser $parser, PPFrame $frame ) {
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
			'src' => "$wgUploadPath/wikivideos/videos/$videoID.mp4",
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
				$name = array_key_exists( 1, $parts ) ? $parts[0] : null;
				$value = $name ? $parts[1] : $parts[0];
				if ( !$value ) {
					continue;
				}
				if ( !$name ) {
					$title = Title::newFromText( $value, NS_FILE );
					$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $title );
					if ( $file ) {
						$name = 0;
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
	 * @param array $contents Video contents
	 * @param array $options Video options
	 * @return string ID of the resulting MP4 file
	 */
	public static function getVideoID( $contents, $options = [] ) {
		global $wgUploadDirectory,
			$wgFFmpegLocation,
			$wgFFprobeLocation,
			$wgGoogleCloudKey;

		// This runs only the first time a wikivideo is created
		if ( !file_exists( "$wgUploadDirectory/wikivideos" ) ) {
			mkdir( "$wgUploadDirectory/wikivideos" );
			mkdir( "$wgUploadDirectory/wikivideos/videos" );
			mkdir( "$wgUploadDirectory/wikivideos/audios" );
			mkdir( "$wgUploadDirectory/wikivideos/tracks" );
			file_put_contents( "$wgUploadDirectory/wikivideos/google-text-to-speech-translated-chars", 0 );
			$image = imagecreatetruecolor( 1, 1 );
			imagejpeg( $image, "$wgUploadDirectory/wikivideos/black-pixel.jpg" );
		}

		$videoID = md5( json_encode( [ $contents, $options ] ) );
		$videoPath = "$wgUploadDirectory/wikivideos/videos/$videoID.mp4";
		if ( file_exists( $videoPath ) ) {
			return $videoID;
		}

		// Initialize Google Text-to-Speech Client
		$GoogleTextToSpeechClient = new TextToSpeechClient( [
			'credentials' => $wgGoogleCloudKey
		] );

		// Build the text files
		$videoText = '';
		$audioText = '';
		$trackText = 'WEBVTT';
		$timeElapsed = 0;
		$timeBeforeAudio = 0.5;
		$timeAfterAudio = 0.5;
		$videoWidth = 0;
		$videoHeight = 0;
		foreach ( $contents as $param ) {
			$file = $param[0] ?? '';
			$text = $param[1] ?? '';

			$beforeAudioID = self::getAudioID( $timeBeforeAudio );
			$textAudioID = self::getAudioID( $text, $options, $GoogleTextToSpeechClient );
			$afterAudioID = self::getAudioID( $timeAfterAudio );
			$videoDuration = 0;
			if ( $beforeAudioID ) {
				$audioText .= "file $wgUploadDirectory/wikivideos/audios/$beforeAudioID.mp3" . PHP_EOL;
				$audioText .= "duration $timeBeforeAudio" . PHP_EOL;
				$videoDuration += $timeBeforeAudio;
			}
			if ( $textAudioID ) {
				$audioDuration = exec( "$wgFFprobeLocation -i $wgUploadDirectory/wikivideos/audios/$textAudioID.mp3 -show_format -v quiet | sed -n 's/duration=//p'" );
				$audioText .= "file $wgUploadDirectory/wikivideos/audios/$textAudioID.mp3" . PHP_EOL;
				$audioText .= "duration $audioDuration" . PHP_EOL;
				$videoDuration += $audioDuration;
			}
			if ( $afterAudioID ) {
				$audioText .= "file $wgUploadDirectory/wikivideos/audios/$beforeAudioID.mp3" . PHP_EOL;
				$audioText .= "duration $timeAfterAudio" . PHP_EOL;
				$videoDuration += $timeAfterAudio;
			}

			if ( $text ) {
				$trackStart = $timeElapsed + $timeBeforeAudio;
				$trackEnd = $timeElapsed + $videoDuration - $timeBeforeAudio;
				$trackText .= PHP_EOL . PHP_EOL;
				$trackText .= date( 'i:s.v', $trackStart );
				$trackText .= ' --> ';
				$trackText .= date( 'i:s.v', $trackEnd );
				$trackText .= PHP_EOL . $text;
			}
			$timeElapsed += $videoDuration;

			$fileTitle = Title::newFromText( $file, NS_FILE );
			$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $fileTitle );
			if ( $file ) {
				$filePath = $file->getRel();
				$filePath = "$wgUploadDirectory/$filePath";
			} else {
				$filePath = "$wgUploadDirectory/wikivideos/black-pixel.jpg";
			}
			$videoText .= "file $filePath" . PHP_EOL;
			$videoText .= "duration $videoDuration" . PHP_EOL;
		}

		// Create the files
		$audioFile = "$wgUploadDirectory/wikivideos/audios/$videoID.txt";
		file_put_contents( $audioFile, $audioText );

		$trackFile = "$wgUploadDirectory/wikivideos/tracks/$videoID.vtt";
		file_put_contents( $trackFile, $trackText );

		$videoText .= "file $filePath"; // Due to a ffmpeg quirk, the last image needs to be specified twice, see https://trac.ffmpeg.org/wiki/Slideshow
		$videoFile = "$wgUploadDirectory/wikivideos/videos/$videoID.txt";
		file_put_contents( $videoFile, $videoText );

		// Make the video
		$videoSize = self::getVideoSize( $contents );
		$videoWidth = $videoSize[0];
		$videoHeight = $videoSize[1];
		$command = "$wgFFmpegLocation -y -safe 0 -f concat -i $videoFile -safe 0 -f concat -i $audioFile -vsync vfr -pix_fmt yuv420p -filter:v 'scale=iw*min($videoWidth/iw\,$videoHeight/ih):ih*min($videoWidth/iw\,$videoHeight/ih), pad=$videoWidth:$videoHeight:($videoWidth-iw*min($videoWidth/iw\,$videoHeight/ih))/2:($videoHeight-ih*min($videoWidth/iw\,$videoHeight/ih))/2' $videoPath";
		//echo $command; exit; // Uncomment to debug
		exec( $command, $output );
		//var_dump( $output ); exit; // Uncomment to debug

		// Clean up
		unlink( $audioFile );
		unlink( $videoFile );

		return $videoID;
	}

	/**
	 * Convert text into audio using Google's text-to-speech service
	 * @param string $text Text to convert
	 * @return string Absolute path to the resulting MP3 file
	 */
	public static function getAudioID( $text, $options = [], $GoogleTextToSpeechClient = null ) {
		global $wgUploadDirectory,
			$wgFFmpegLocation,
			$wgGoogleTextToSpeechMaxChars,
			$wgWikiVideosVoiceLanguage,
			$wgWikiVideosVoiceGender,
			$wgWikiVideosVoiceName;

		if ( !$text ) {
			return;
		}

		// If the text is just a number
		// use FFMPEG to make a silent MP3 of that many seconds
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
		$chars = file_get_contents( "$wgUploadDirectory/wikivideos/google-text-to-speech-translated-chars" );
		$chars += strlen( $text );
		if ( $chars > $wgGoogleTextToSpeechMaxChars ) {
			return;
		}
		file_put_contents( "$wgUploadDirectory/wikivideos/google-text-to-speech-translated-chars", $chars );

		// Do the request
		$input = new SynthesisInput();
		$input->setText( $text );
		$voice = new VoiceSelectionParams();
		$voice->setLanguageCode( $wgWikiVideosVoiceLanguage );
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
	 * @param array $contents Video contents
	 * @return array Video Width and height
	 */
	public static function getVideoSize( $contents ) {
		global $wgUploadDirectory, $wgWikiVideosMaxWidth, $wgWikiVideosMaxHeight;
		$videoWidth = 0;
		$videoHeight = 0;
		foreach ( $contents as $content ) {
			if ( !array_key_exists( 0, $content ) ) {
				continue;
			}
			$fileName = $content[0];
			$fileTitle = Title::newFromText( $fileName );
			$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $fileTitle );
			if ( !$file ) {
				continue;
			}
			$filePath = $file->getRel();
			$filePath = "$wgUploadDirectory/$filePath";
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
	 * and this interface may become somwhat redundant
	 * but printing the text also makes it easy to browse
	 * and indexable by search engines
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
