<?php

use Google\Cloud\TextToSpeech\V1\AudioConfig;
use Google\Cloud\TextToSpeech\V1\AudioEncoding;
use Google\Cloud\TextToSpeech\V1\SynthesisInput;
use Google\Cloud\TextToSpeech\V1\TextToSpeechClient;
use Google\Cloud\TextToSpeech\V1\VoiceSelectionParams;
use MediaWiki\MediaWikiServices;
// Temporary dependency
use Sophivorus\EasyWiki;

/**
 * This is the main class of the WikiVideos extension
 * Like the name suggests, it makes video files
 * It has two kinds of methods:
 * - The "make" methods make the files
 * - The "get" methods get data needed by the make methods
 */
class WikiVideosFactory {

	/**
	 * Make video file
	 *
	 * The main video is a WEBM file made by simply concatenating individual minivideos or "scenes"
	 * Each scene is its own WEBM, made from a single file shown while the corresponding text is read aloud
	 * This strategy of making videos out of scenes is mainly to support the use of mixed file types
	 * because there's no valid FFmpeg command that will make a video out of a soup of mixed file types
	 * Another very important reason is to avoid re-encoding everything when a single scene changes
	 *
	 * @param array $images Gallery images
	 * @param array $attribs Gallery attributes
	 * @param Parser $parser
	 * @return string Relative path to the video file
	 */
	public static function makeVideo( array $images, array $attribs, Parser $parser ) {
		global $wgUploadDirectory, $wgUploadPath, $wgTmpDirectory, $wgFFmpegLocation;

		$videoSize = self::getVideoSize( $images );
		$videoWidth = $videoSize[0];
		$videoHeight = $videoSize[1];

		$scenes = [];
		foreach ( $images as [ $imageTitle, $imageText ] ) {
			$scenePath = self::makeScene( $imageTitle, $imageText, $videoWidth, $videoHeight, $attribs, $parser );
			$scenes[] = $scenePath;
		}

		// If the video already exists, return immediately
		$videoHash = md5( json_encode( $scenes ) );
		$videoPath = "$wgUploadDirectory/wikivideos/videos/$videoHash.webm";
		if ( file_exists( $videoPath ) ) {
			return "$wgUploadPath/wikivideos/videos/$videoHash.webm";
		}

		// Make video by concatenating individual scenes
		// @todo Use tmpfile()
		$videoConcatFile = "$wgTmpDirectory/$videoHash.txt";
		$videoConcatText = '';
		foreach ( $scenes as $scenePath ) {
			$videoConcatText .= "file $scenePath" . PHP_EOL;
		}
		file_put_contents( $videoConcatFile, $videoConcatText );
		$command = "$wgFFmpegLocation -y -safe 0 -f concat -i $videoConcatFile -max_muxing_queue_size 9999 -c copy $videoPath";
		// echo $command; exit; // Uncomment to debug
		exec( $command );
		unlink( $videoConcatFile ); // Clean up

		// Return relative path for <video> tag
		return "$wgUploadPath/wikivideos/videos/$videoHash.webm";
	}

	/**
	 * Make scene file
	 *
	 * Unfortunately, the width and height of the final video are parameters of this method
	 * so if the size of the final video changes, for example because of a new scene, then all scenes will be regenerated.
	 * However, if we don't make scenes depend on the size of the final video, then we can't just concatenate the final video, we need to re-encode it, which is absurdly slow.
	 * Another option is to hard-code the size of the final video (like YouTube does) but this doesn't play well with vertical videos or any othe aspect ratio.
	 * Yet another option is to set the width and height of the videos from the <video> tag, but this results in weird files when downloaded.
	 * None is perfect.
	 *
	 * @param Title $imageTitle
	 * @param string $imageText
	 * @param int $videoWidth
	 * @param int $videoHeight
	 * @param array $attribs
	 * @param Parser $parser
	 * @return string Absolute path to the scene file
	 */
	public static function makeScene( Title $imageTitle, string $imageText, int $videoWidth, int $videoHeight, array $attribs, Parser $parser ) {
		global $wgUploadDirectory, $wgTmpDirectory, $wgFFmpegLocation;

		$imagePath = self::getImagePath( $imageTitle );
		$audioPath = self::makeAudio( $imageText, $attribs, $parser );
		$kenBurnsEffect = (bool)$attribs['ken-burns-effect'];

		// If the scene already exists, return immediately
		$sceneHash = md5( json_encode( [ $imagePath, $audioPath, $videoWidth, $videoHeight, $kenBurnsEffect ] ) );
		$scenePath = "$wgUploadDirectory/wikivideos/scenes/$sceneHash.webm";
		if ( file_exists( $scenePath ) ) {
			return $scenePath;
		}

		// Make silent audio to add before and after each scene
		// @todo Somehow this shouldn't be necessary
		$silentAudioPath = self::makeSilentAudio( 0.5 );

		// Make scene
		// @todo Use tmpfile()
		$sceneConcatFile = "$wgTmpDirectory/$sceneHash.txt";
		$sceneConcatText = "file $silentAudioPath" . PHP_EOL;
		$sceneConcatText .= "file $audioPath" . PHP_EOL;
		$sceneConcatText .= "file $silentAudioPath" . PHP_EOL;
		file_put_contents( $sceneConcatFile, $sceneConcatText );
		$filter = "scale=iw*min($videoWidth/iw\,$videoHeight/ih):ih*min($videoWidth/iw\,$videoHeight/ih)";
		$filter .= ",pad=$videoWidth:$videoHeight:($videoWidth-iw*min($videoWidth/iw\,$videoHeight/ih))/2:($videoHeight-ih*min($videoWidth/iw\,$videoHeight/ih))/2";

		// Experimental feature, very slow!
		if ( $attribs['ken-burns-effect'] ) {
			$sceneDuration = self::getSceneDuration( $imageTitle, $imageText, $attribs, $parser );
			$sceneFPS = 25; // FFmpeg default
			$filter .= ",scale=8000:-1"; // Necessary to avoid jerky motion, see https://trac.ffmpeg.org/ticket/4298
			$filter .= ",zoompan=z=(zoom+0.001):x=iw/2-(iw/zoom/2):y=ih/2-(ih/zoom/2):d=$sceneDuration*$sceneFPS:s=$videoWidth\x$videoHeight";
		}

		// Run the FFmpeg command
		$command = "$wgFFmpegLocation -y -safe 0 -f concat -i $sceneConcatFile -i '$imagePath' -vsync vfr -pix_fmt yuv420p -filter:v '$filter' $scenePath";
		// echo $command; exit; // Uncomment to debug
		exec( $command );
		unlink( $sceneConcatFile ); // Clean up

		return $scenePath;
	}

	/**
	 * Make audio by using Google's text-to-speech service
	 *
	 * @param string $text Text to convert
	 * @param array $attribs Gallery attributes
	 * @param Parser $parser
	 * @return string Absolute path to the audio file
	 */
	public static function makeAudio( string $text, array $attribs, Parser $parser ) {
		global $wgUploadDirectory,
			$wgFFmpegLocation,
			$wgGoogleCloudCredentials,
			$wgLanguageCode,
			$wgWikiVideosVoiceGender,
			$wgWikiVideosVoiceName;

		$plainText = self::getPlainText( $text, $parser );
		$voiceLanguage = $attribs['voice-language'];
		$voiceGender = $attribs['voice-gender'];
		$voiceName = $attribs['voice-name'];

		// @todo Use page language instead
		if ( !$voiceLanguage ) {
			$voiceLanguage = $wgLanguageCode;
		}

		// @todo i18n
		switch ( strtolower( $voiceGender ) ) {
			// phpcs:ignore PSR2.ControlStructures.SwitchDeclaration.TerminatingComment
			case 'male':
				$voiceGender = 1;
			case 'female':
				$voiceGender = 2;
		}

		// If the audio already exists, return immediately
		$audioHash = md5( json_encode( [ $plainText, $voiceLanguage, $voiceGender, $voiceName ] ) );
		$audioPath = "$wgUploadDirectory/wikivideos/audios/$audioHash.mp3";
		if ( file_exists( $audioPath ) ) {
			return $audioPath;
		}

		// Do the request to the Google Text-to-Speech API
		$GoogleTextToSpeechClient = new TextToSpeechClient( [
			'credentials' => $wgGoogleCloudCredentials
		] );
		$input = new SynthesisInput();
		$input->setText( $plainText );
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

		// Save and return
		file_put_contents( $audioPath, $response->getAudioContent() );
		return $audioPath;
	}

	/**
	 * Make track file (subtitles)
	 *
	 * @param array $images Gallery images
	 * @param array $attribs Gallery attributes
	 * @param Parser $parser
	 * @return string Relative path to the track file
	 */
	public static function makeTrack( array $images, array $attribs, Parser $parser ) {
		global $wgUploadDirectory, $wgUploadPath;

		// @todo Should include image titles too
		$trackElements = [];
		foreach ( $images as [ $imageTitle, $imageText ] ) {
			$trackElements[] = trim( $imageText );
		}

		// If the track already exists, return immediately
		$trackHash = md5( json_encode( $trackElements ) );
		$trackPath = "$wgUploadDirectory/wikivideos/tracks/$trackHash.vtt";
		if ( file_exists( $trackPath ) ) {
			return "$wgUploadPath/wikivideos/tracks/$trackHash.vtt";
		}

		// Make the track file
		$trackText = 'WEBVTT';
		$timeElapsed = 0;
		foreach ( $images as [ $imageTitle, $imageText ] ) {
			$sceneDuration = self::getSceneDuration( $imageTitle, $imageText, $attribs, $parser );
			if ( $imageText ) {
				$plainText = self::getPlainText( $imageText, $parser );
				$trackStart = $timeElapsed;
				$trackEnd = $timeElapsed + $sceneDuration;
				$trackText .= PHP_EOL . PHP_EOL;
				$trackText .= date( 'i:s.v', $trackStart );
				$trackText .= ' --> ';
				$trackText .= date( 'i:s.v', $trackEnd );
				$trackText .= PHP_EOL . $plainText;
			}
			$timeElapsed += $sceneDuration;
		}
		file_put_contents( $trackPath, $trackText );

		// Return relative path for <track> tag
		return "$wgUploadPath/wikivideos/tracks/$trackHash.vtt";
	}

	/**
	 * Make silent audio file
	 *
	 * @todo This doesn't deserve to be a make method
	 *
	 * @param float $duration Duration of the silent audio
	 * @return string Absolute path to the resulting silent audio file
	 */
	public static function makeSilentAudio( float $duration ) {
		global $wgUploadDirectory, $wgFFmpegLocation;
		$audioHash = md5( $duration );
		$audioPath = "$wgUploadDirectory/wikivideos/audios/$audioHash.mp3";
		if ( !file_exists( $audioPath ) ) {
			exec( "$wgFFmpegLocation -f lavfi -i anullsrc=r=44100:cl=mono -t $duration -q:a 9 -acodec libmp3lame $audioPath" );
		}
		return $audioPath;
	}

	/**
	 * Get scene size (width and height)
	 *
	 * The scene size is determined by the image
	 * or by the max/min video size from the config
	 *
	 * @param Title $imageTitle Image title, may be local or remote, JPG, PNG, GIF, WEBM, etc.
	 * @return array Scene width and height, may be larger than final video size
	 */
	public static function getSceneSize( Title $imageTitle ) {
		global $wgUploadDirectory, $wgWikiVideosMinSize, $wgWikiVideosMaxSize;

		$sceneWidth = $wgWikiVideosMinSize;
		$sceneHeight = $wgWikiVideosMinSize;

		$imagePath = self::getImagePath( $imageTitle );
		$imageSize = getimagesize( $imagePath );
		$sceneWidth = $imageSize[0];
		$sceneHeight = $imageSize[1];

		// Make sure the scene size doesn't exceed the limit
		$sceneRatio = $sceneWidth / $sceneHeight;
		if ( $sceneWidth > $sceneHeight && $sceneWidth > $wgWikiVideosMaxSize ) {
			$sceneWidth = $wgWikiVideosMaxSize;
			$sceneHeight = round( $sceneWidth * $sceneRatio );
		}
		if ( $sceneHeight > $sceneWidth && $sceneHeight > $wgWikiVideosMaxSize ) {
			$sceneHeight = $wgWikiVideosMaxSize;
			$sceneWidth = round( $sceneHeight * $sceneRatio );
		}

		// Make the scene size even
		if ( $sceneWidth % 2 ) {
			$sceneWidth--;
		}
		if ( $sceneHeight % 2 ) {
			$sceneHeight--;
		}

		return [ $sceneWidth, $sceneHeight ];
	}

	/**
	 * Get scene duration (in seconds)
	 *
	 * The scene duration is determined by the audio duration
	 * or by the image duration in case it's a GIF, WEBM, etc.
	 * (whichever is longer)
	 *
	 * @param Title $imageTitle Image of the scene
	 * @param string $imageText Text of the scene
	 * @param array $attribs Gallery attributes
	 * @param Parser $parser
	 * @return float Duration of the scene (in seconds)
	 */
	public static function getSceneDuration( Title $imageTitle, string $imageText, array $attribs, Parser $parser ) {
		global $wgUploadDirectory, $wgFFprobeLocation;

		$imagePath = self::getImagePath( $imageTitle );
		$imageDuration = exec( "$wgFFprobeLocation -i $imagePath -show_format -v quiet | sed -n 's/duration=//p'" );
		if ( $imageDuration === 'N/A' ) {
			$imageDuration = 0;
		}

		$audioDuration = 0;
		if ( $imageText ) {
			$audioPath = self::makeAudio( $imageText, $attribs, $parser );
			$audioDuration = exec( "$wgFFprobeLocation -i $audioPath -show_format -v quiet | sed -n 's/duration=//p'" );
		}

		$silentAudioDuration = 0.5; // @todo Shouldn't be hardcoded
		$sceneDuration = $silentAudioDuration + $audioDuration + $silentAudioDuration;
		if ( $imageDuration > $audioDuration ) {
			$sceneDuration = $imageDuration;
		}

		return $sceneDuration;
	}

	/**
	 * Get video size (width and height)
	 *
	 * The video size is determined by the largest scene
	 * or by the min video size if there're no scenes
	 *
	 * @param array $images Gallery images
	 * @return array Video width and height
	 */
	public static function getVideoSize( array $images ) {
		global $wgWikiVideosMinSize;

		$videoWidth = $wgWikiVideosMinSize;
		$videoHeight = $wgWikiVideosMinSize;

		foreach ( $images as [ $imageTitle ] ) {
			$sceneSize = self::getSceneSize( $imageTitle );
			$sceneWidth = $sceneSize[0];
			$sceneHeight = $sceneSize[1];
			if ( $sceneWidth > $videoWidth ) {
				$videoWidth = $sceneWidth;
			}
			if ( $sceneHeight > $videoHeight ) {
				$videoHeight = $sceneHeight;
			}
		}

		return [ $videoWidth, $videoHeight ];
	}

	/**
	 * Get video poster
	 *
	 * The video poster is determined by the poster argument
	 * or by the first suitable image
	 *
	 * @param array $images Gallery images
	 * @param array $attribs Gallery attribs
	 * @return string Relative URL of the video poster
	 */
	public static function getVideoPoster( array $images, array $attribs ) {
		if ( array_key_exists( 'poster', $attribs ) ) {
			$poster = $attribs['poster'];
			$posterTitle = Title::newFromText( $poster, NS_FILE );
			$posterFile = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $posterTitle );
			if ( $posterFile ) {
				return $posterFile->getUrl();
			}
		}
		foreach ( $images as [ $imageTitle ] ) {
			$imageFile = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $imageTitle );
			if ( $imageFile ) {
				return $imageFile->getUrl();
			}
		}
	}

	/**
	 * Get plain text out of wikitext
	 *
	 * @todo Make more elegant, efficient and robust
	 *
	 * @param string $text Wikitext
	 * @param Parser $parser
	 * @return string Plain text
	 */
	public static function getPlainText( string $text, Parser $parser ) {
		$parser->replaceLinkHolders( $text );
		$text = strip_tags( $text );
		$text = $parser->recursiveTagParseFully( $text );
		$text = preg_replace( '#<sup[^>]+class="reference">.*?</sup>#', '', $text ); // Remove parsed <ref> tags
		$text = strip_tags( $text );
		return $text;
	}

	/**
	 * Get image path
	 *
	 * @param Title $imageTitle Title object of the file page
	 * @return string Absolute path to the image file
	 */
	public static function getImagePath( Title $imageTitle ) {
		global $wgUploadDirectory, $wgWikiVideosUserAgent;

		// @todo What if the image doesn't exist
		$imageFile = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $imageTitle );
		if ( $imageFile ) {
			$imageRel = $imageFile->getRel();
			$imagePath = "$wgUploadDirectory/$imageRel";
			return $imagePath;
		}

		// If we get to this point, the image is remote
		$imageKey = $imageTitle->getDBKey();
		$imagePath = "$wgUploadDirectory/wikivideos/remote/$imageKey";
		if ( file_exists( $imagePath ) ) {
			return $imagePath;
		}

		// Get image URL
		// @todo Use internal methods rather then EasyWiki
		// @todo Limit image size by $wgWikiVideosMaxSize
		$commons = new EasyWiki( 'https://commons.wikimedia.org/w/api.php' );
		$params = [
			'titles' => $imageTitle->getFullText(),
			'action' => 'query',
			'prop' => 'imageinfo',
			'iiprop' => 'url'
		];
		$imageURL = $commons->query( $params, 'url' );

		// Download file
		$curl = curl_init( $imageURL );
		$imagePointer = fopen( $imagePath, 'wb' );
		curl_setopt( $curl, CURLOPT_FILE, $imagePointer );
		curl_setopt( $curl, CURLOPT_USERAGENT, $wgWikiVideosUserAgent );
		curl_exec( $curl );
		curl_close( $curl );
		fclose( $imagePointer );

		return $imagePath;
	}
}
