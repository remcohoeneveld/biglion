$('#contact-form').submit(function(ev) {
    // Prevent the form from actually submitting
    ev.preventDefault();

    // Get the post data
    var data = $(this).serialize();

    // Send it to the server
    $.post('/', data, function(response) {
        if (response.success) {
            $('#mail-success').fadeIn();
        } else {

            $('#mail-fail').fadeIn();
            // response.error will be an object containing any validation errors that occurred, indexed by field name
            // e.g. response.error.fromName => ['From Name is required']
        }
    });
});