WikiVideos = {

	init: function () {
		$( '.wikivideo-chapter-time' ).on( 'click', WikiVideos.jumpToTime );
	},

	jumpToTime: function ( event ) {
		const $link = $( this );
		const $video = $link.closest( '.wikivideo-chapters' ).prev( 'video' );
		const seconds = $link.data( 'seconds' );
		$video[ 0 ].currentTime = seconds;
	}
};

$( WikiVideos.init );
