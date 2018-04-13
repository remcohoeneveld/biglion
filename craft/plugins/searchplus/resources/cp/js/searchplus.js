(function ($) {

    $('#deletemodalshow').click(function () {
        var $content = $('#deleteform');
        var myModal = new Garnish.Modal($content);
    });

    $('#clearmodalshow').click(function () {
        var $content = $('#clearform');
        var myModal = new Garnish.Modal($content);
    });

    $('#unmapmodalshow').click(function () {
        var $content = $('#unmapform');
        var myModal = new Garnish.Modal($content);
    });




    $(document).on('submit', 'form.algolia-populatetask', function(e) {
        e.preventDefault();

        var $form = $(this),
            $progressbar = $('.progressbar'),
            $progressbarinner = $('.progressbar-inner'),
            $alldone = $('.alldone'),
            $errored = $('.errored'),
            $inputs = $form.find('input'),
            $data = $form.serialize();

        $(this).find('.fields').fadeOut('fast', function() {
            $progressbar.fadeIn('fast');
            $progressbar.addClass('pending');
        });

        runajax();

        function runajax() {
            $.ajax({
                url     : $form.attr('action'),
                type    : $form.attr('method'),
                data    : $data,
                success : function( data ) {
                    $progressbar.removeClass('pending');

                    if(data.success == true) {
                        // Now trigger the Run tasks js if possible

                        Craft.cp.runPendingTasks();
                        Craft.cp.trackTaskProgress();


                        $progressbarinner.css('width', '100%');
                        $('.index-status span.status').removeClass('pending').addClass('live');
                        $('.index-status span.title').html('Index Populated');
                        $('.index-status .index-count').html(data.complete);

                        // Cleanup
                        $progressbar.fadeOut('fast', function() {
                            $alldone.fadeIn();
                        });

                    } else {
                        // Hmm. error somewhere.
                        $progressbarinner.css('width', '100%');
                        $('.index-status span.status').removeClass('pending').addClass('error');
                        $('.index-status span.title').html('Error starting population task');

                        // Cleanup
                        $progressbar.fadeOut('fast', function() {
                            $errored.fadeIn();
                        });
                    }
                },
                error   : function( xhr, err ) {
                    $progressbarinner.css('width', '100%');
                    $('.index-status span.status').removeClass('pending').addClass('error');
                    $('.index-status span.title').html('Error starting population task');

                    // Cleanup
                    $progressbar.fadeOut('fast', function() {
                        $errored.fadeIn();
                    });
                }
            });
            return false;
        }
    });


    $(document).on('submit', 'form.algolia-indexpopulate', function(e) {

        e.preventDefault();

        var $form = $(this),
            $progressbar = $('.progressbar'),
            $progressbarinner = $('.progressbar-inner'),
            $alldone = $('.alldone'),
            $inputs = $form.find('input'),
            $data = $form.serialize();

        $(this).find('.fields').fadeOut('fast', function() {
            $progressbar.fadeIn('fast');
            $progressbar.addClass('pending');
        });

        runajax();

        function runajax() {
            $.ajax({
                url     : $form.attr('action'),
                type    : $form.attr('method'),
                data    : $data,
                success : function( data ) {
                    $progressbar.removeClass('pending');

                    console.log(data);

                    if(data.status == 'inprogress') {

                        $('.index-status span.status').removeClass('expired').addClass('pending');
                        $('.index-status span.title').html('Populating..');

                        $progressbarinner.css('width', data.percent);
                        $('input[name="firstrun"]').val('0');

                        $('.index-status .index-count').html(data.complete);
                        $data = $form.serialize();
                        runajax();
                    } else if(data.status == 'error') {
                        alert('nope');
                    } else if(data.status == 'complete') {

                        $progressbarinner.css('width', '100%');
                        $('.index-status span.status').removeClass('pending').addClass('live');
                        $('.index-status span.title').html('Index Populated');
                        $('.index-status .index-count').html(data.complete);

                        // Cleanup
                        $progressbar.fadeOut('fast', function() {
                            $alldone.fadeIn();
                        });
                    }

                },
                error   : function( xhr, err ) {
                    console.log(err);
                }
            });
            return false;
        }
    });


})(jQuery);
