(function ($, mw) {
  $(function() {
    $('.torque-filtercheckbox').on('click', function () {
      window.location = $(this).attr("url");
    });
  });
}(jQuery, mediaWiki));
