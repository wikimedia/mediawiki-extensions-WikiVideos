<?php

use MediaWiki\MediaWikiServices;
use Google\Cloud\TextToSpeech\V1\AudioConfig;
use Google\Cloud\TextToSpeech\V1\AudioEncoding;
use Google\Cloud\TextToSpeech\V1\SynthesisInput;
use Google\Cloud\TextToSpeech\V1\TextToSpeechClient;
use Google\Cloud\TextToSpeech\V1\VoiceSelectionParams;
use Sophivorus\EasyWiki; // Temporary dependency

class WikiVideosFactory {

	/**
	 * Make video file out of gallery images and attributes
	 * 
	 * The final video is made by concatenating many individual minivideos or "scenes"
	 * Each scene is made up of a single file displayed while the corresponding text
	 * is read aloud by a text-to-speech service (so far only Google's)
	 * This strategy of making the scenes first is mainly to support the use of mixed file types
	 * because there's no valid ffmpeg command that will make a video out of a soup of mixed file types
	 * 
	 * @param array $images Gallery images
	 * @param array $attribs Gallery attributes
	 * @param Parser $parser
	 * @return string Video ID
	 */
	public static function makeVideo( array $images, array $attribs, Parser $parser ) {
		global $wgUploadDirectory,
			$wgTmpDirectory,
			$wgFFmpegLocation,
			$wgWikiVideosMaxSize;

		// Make the video ID out of the elements that define the video
		// so if nothing relevant changes, we don't regenerate the video
		$videoElements = [];
        foreach ( $images as [ $imageTitle, $imageText ] ) {
            $videoElements[] = $imageTitle->getText();
            $videoElements[] = trim( $imageText );
        }
        $videoElements[] = $attribs['voice-language'];
        $videoElements[] = $attribs['voice-gender'];
        $videoElements[] = $attribs['voice-name'];
		$videoID = md5( json_encode( $videoElements ) );
		$videoPath = "$wgUploadDirectory/wikivideos/videos/$videoID.webm";
		if ( file_exists( $videoPath ) ) {
			return $videoID;
		}

		// Make silent audio to add before and after each audio
		$silentAudioDuration = 0.5;
		$silentAudioID = self::makeSilentAudio( $silentAudioDuration );
		$silentAudioPath = "$wgUploadDirectory/wikivideos/audios/$silentAudioID.mp3";

		// Make scenes
		$scenes = [];
        foreach ( $images as [ $imageTitle, $imageText ] ) {

			$imageFile = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $imageTitle );
			if ( $imageFile ) {
				$imageID = $imageFile->getSha1();
				$imageRel = $imageFile->getRel();
				$imagePath = "$wgUploadDirectory/$imageRel";
			} else {
				$imageID = self::getRemoteFile( $imageTitle );
				$imagePath = "$wgUploadDirectory/wikivideos/remote/$imageID";
			}

			$audioID = self::makeAudio( $imageText, $attribs, $parser );
			$audioPath = "$wgUploadDirectory/wikivideos/audios/$audioID.mp3";

			$sceneID = md5( json_encode( [ $imageID, $audioID ] ) );
			$scenePath = "$wgUploadDirectory/wikivideos/scenes/$sceneID.webm";
			$scenes[] = $scenePath;
			if ( file_exists( $scenePath ) ) {
				continue;
			}

			// Make scene
			$sceneConcatFile = "$wgTmpDirectory/$sceneID.txt";
			$sceneConcatText = "file $silentAudioPath" . PHP_EOL;
			$sceneConcatText .= "file $audioPath" . PHP_EOL;
			$sceneConcatText .= "file $silentAudioPath" . PHP_EOL;
			file_put_contents( $sceneConcatFile, $sceneConcatText );
			$command = "$wgFFmpegLocation -y -safe 0 -f concat -i $sceneConcatFile -i '$imagePath' -vsync vfr -pix_fmt yuv420p -filter:v 'scale=min($wgWikiVideosMaxSize\,min(iw\,round($wgWikiVideosMaxSize*iw/ih))):-2' $scenePath";
			//echo $command; exit; // Uncomment to debug
			exec( $command, $output );
			//var_dump( $output ); exit; // Uncomment to debug
			unlink( $sceneConcatFile ); // Clean up
		}

		// Make final video
		$videoSize = self::getVideoSize( $images );
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
	 * Make track file (subtitles)
	 * 
	 * @param array $images Gallery images
	 * @param array $attribs Gallery attributes
	 * @param Parser $parser
	 * @return string Track ID
	 */
	public static function makeTrack( array $images, array $attribs, Parser $parser ) {
		global $wgUploadDirectory;

		// Make the track ID out of the elements that define the track
		// so if nothing relevant changes, we don't regenerate the track
		$trackElements = [];
        foreach ( $images as [ $imageTitle, $imageText ] ) {
            $trackElements[] = trim( $imageText );
        }
		$trackID = md5( json_encode( $trackElements ) );
		$trackPath = "$wgUploadDirectory/wikivideos/tracks/$trackID.vtt";
		if ( file_exists( $trackPath ) ) {
			return $trackID;
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
		return $trackID;
	}

	/**
	 * Get the duration of a scene
	 * 
	 * The scene duration is determined by the audio duration
	 * or by the file duration (whichever is longer)
	 * 
	 * @param Title $imageTitle Image of the scene
	 * @param string $imageText Text of the scene
	 * @param array $array Gallery attributes
	 * @param Parser $parser
	 * @return float Duration of the scene, in seconds
	 */
	public static function getSceneDuration( Title $imageTitle, string $imageText, array $attribs, Parser $parser ) {
		global $wgUploadDirectory, $wgFFprobeLocation;

		$imageDuration = 0;
		$imageFile = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $imageTitle );
		if ( $imageFile ) {
			$imageRel = $imageFile->getRel();
			$imagePath = "$wgUploadDirectory/$imageRel";
		} else {
			$imageKey = $imageTitle->getDBKey();
			$imagePath = "$wgUploadDirectory/wikivideos/remote/$imageKey";
		}
		$imageDuration = exec( "$wgFFprobeLocation -i $imagePath -show_format -v quiet | sed -n 's/duration=//p'" );
		if ( $imageDuration === 'N/A' ) {
			$imageDuration = 0;
		}

		$audioDuration = 0;
		if ( $imageText ) {
			$audioID = self::makeAudio( $imageText, $attribs, $parser );
			$audioPath = "$wgUploadDirectory/wikivideos/audios/$audioID.mp3";
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
	 * Make a silent audio file
	 * 
	 * @param float $duration Duration of the silent audio
	 * @return string ID of the resulting silent audio file
	 */
	public static function makeSilentAudio( float $duration ) {
		global $wgUploadDirectory, $wgFFmpegLocation;
		$audioID = md5( $duration );
		$audioPath = "$wgUploadDirectory/wikivideos/audios/$audioID.mp3";
		if ( !file_exists( $audioPath ) ) {
			exec( "$wgFFmpegLocation -f lavfi -i anullsrc=r=44100:cl=mono -t $duration -q:a 9 -acodec libmp3lame $audioPath" );
		}
		return $audioID;
	}

	/**
	 * Make audio by using Google's text-to-speech service
	 * 
	 * @param string $text Text to convert
	 * @param array $attribs Gallery attributes
	 * @param Parser $parser
	 * @return string ID of the audio file
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
			case 'male':
				$voiceGender = 1;
			case 'female':
				$voiceGender = 2;
		}

		// Generate the audio ID out of the text and options
		// so if nothing relevant changes, we reuse existing audio
		$audioID = md5( json_encode( [ $plainText, $voiceLanguage, $voiceGender, $voiceName ] ) );
		$audioPath = "$wgUploadDirectory/wikivideos/audios/$audioID.mp3";
		if ( file_exists( $audioPath ) ) {
			return $audioID;
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

		// Save the audio file
		file_put_contents( $audioPath, $response->getAudioContent() );

		// Return the id of the audio file
		return $audioID;
	}

	/**
	 * Infer the appropriate video size out of the video contents
	 * 
	 * @param array $images Gallery images
	 * @return array Video width and height
	 */
	public static function getVideoSize( array $images ) {
		global $wgUploadDirectory,
			$wgWikiVideosMinSize,
			$wgWikiVideosMaxSize;

		$videoWidth = $wgWikiVideosMinSize;
		$videoHeight = $wgWikiVideosMinSize;

		foreach ( $images as [ $imageTitle ] ) {
			$imageFile = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->findFile( $imageTitle );
			if ( $imageFile ) {
				$imageRel = $imageFile->getRel();
				$imagePath = "$wgUploadDirectory/$imageRel";
			} else {
				$imageKey = $imageTitle->getDBKey();
				$imagePath = "$wgUploadDirectory/wikivideos/remote/$imageKey";
			}
			$imageSize = getimagesize( $imagePath );
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
	 * @param array $attribs Gallery attribs
	 * @param array $images Gallery images
	 * @return string Relative URL of the video poster
	 */
	public static function getVideoPoster( array $attribs, array $images ) {
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
	 * Make chapters HTML file
	 * 
	 * @param array $images Gallery images
	 * @param array $attribs Gallery attribs
	 * @param Parser $parser
	 * @return string HTML of the chapters
	 */
	public static function makeChapters( array $images, array $attribs, Parser $parser ) {
		$list = Html::openElement( 'ol', [ 'class' => 'wikivideo-chapters' ] );
		$seconds = 0;
		foreach ( $images as [ $imageTitle, $imageText ] ) {
			$time = date( 'i:s', $seconds );
			$link = Html::element( 'a', [
				'class' => 'wikivideo-chapter-time',
				'data-seconds' => round( $seconds )
			], $time );
			$span = Html::rawElement( 'span', [ 'class' => 'wikivideo-chapter-text' ], $imageText );
			$item = Html::rawElement( 'li', [ 'class' => 'wikivideo-chapter' ], $link . PHP_EOL . $span );
			$list .= $item;
			$seconds += self::getSceneDuration( $imageTitle, $imageText, $attribs, $parser );
		}
		$list .= Html::closeElement( 'ol' );
		return $list;
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
	 * Download file from Commons
	 * 
	 * @param Title $fileTitle Title object of the file page
	 * @return string ID of the remote file
	 */
	public static function getRemoteFile( Title $fileTitle ) {
		global $wgUploadDirectory, $wgWikiVideosUserAgent;

		$fileID = $fileTitle->getDBKey();
		$filePath = "$wgUploadDirectory/wikivideos/remote/$fileID";
		if ( file_exists( $filePath ) ) {
			return $fileID;
		}

		// Get file URL
		// @todo Use internal methods
		// @todo Limit image size by $wgWikiVideosMaxSize
		$commons = new EasyWiki( 'https://commons.wikimedia.org/w/api.php' );
		$params = [
		    'titles' => $fileTitle->getFullText(),
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
