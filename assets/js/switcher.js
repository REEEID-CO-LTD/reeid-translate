jQuery(function($){
// Toggle dropdown menu
$(document).on('click', '.reeid-dropdown__btn', function(e){
e.stopPropagation();
var $dd = $(this).closest('.reeid-dropdown');
$('.reeid-dropdown').not($dd).removeClass('open'); // close others
$dd.toggleClass('open');
});

// Close menu when clicking outside
$(document).on('click', function(e){
if ( ! $(e.target).closest('.reeid-dropdown').length ) {
$('.reeid-dropdown').removeClass('open');
}
});
});
