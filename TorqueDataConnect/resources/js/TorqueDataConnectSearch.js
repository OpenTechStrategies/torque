(function ($, mw) {
  $(function() {
    $('.torquedataconnect-filtercheckbox').on('click', function () {
      window.location = $(this).attr("url");
    });
  });
}(jQuery, mediaWiki));
