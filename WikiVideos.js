WikiVideos = {

	init: function () {
		$( '.wikivideo-chapter-time' ).on( 'click', WikiVideos.jumpToTime );
	},

	jumpToTime: function ( event ) {
		var $link = $( this );
		var $video = $link.closest( '.wikivideo-chapters' ).prev( 'video' );
		var seconds = $link.data( 'seconds' );
		$video[ 0 ].currentTime = seconds;
	}
};

$( WikiVideos.init );
