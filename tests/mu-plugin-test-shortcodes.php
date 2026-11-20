<?php
// Copy to wp-content/mu-plugins/ on a test site only.
// Test fixtures: one shortcode everywhere, one only on the front end.
add_action( 'init', function () {
	add_shortcode( 'my_test_box', function () { return 'BOX'; } );
	if ( ! is_admin() ) {
		add_shortcode( 'front_only_box', function () { return 'FRONT'; } );
	}
} );
