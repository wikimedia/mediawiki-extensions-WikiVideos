WikiVideos = {

	init: function () {
		$( '.wikivideo-chapter-time' ).on( 'click', WikiVideos.jumpToTime );
	},

	jumpToTime: function ( event ) {
		var link = $( this );
		var seconds = link.data( 'seconds' );
		var video = link.closest( '.wikivideo-chapters' ).prev( 'video' );
		video[ 0 ].currentTime = seconds;
	}
};

$( WikiVideos.init );
