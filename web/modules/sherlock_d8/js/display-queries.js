(function($) {
    $(document).ready(function() {
        $("#sherlock_show_queries_header").click(function () {
            toggleQueriesBlock();
        });
    });

    function toggleQueriesBlock() {
        var content = $("#sherlock_show_queries_contentarea");
        content.slideToggle(500);
    }
})(jQuery);
