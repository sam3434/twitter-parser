jQuery(document).ready(function($) {
        $('li.stream-item a').each(function(index, el) {
              var href = $(this).attr("href")
              if (href.indexOf("http://")==-1 && href.indexOf("https://")==-1)
                    $(this).attr("href", "http://twitter.com"+href)
        });
});